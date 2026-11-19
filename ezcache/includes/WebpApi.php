<?php
namespace Upress\EzCache;

/** Bounded local conversion, with an explicitly enabled HTTPS fallback. */
class WebpApi {
    const MAX_BYTES = 10485760;
    const MAX_PIXELS = 16000000;
    /** After a network error, timeout or 5xx from the external service, pause it this long. */
    const PAUSE_SECONDS = 600;
    const PAUSE_TRANSIENT = 'ezcache_webp_api_paused';

    public function __construct($unused_key = '') {}

    /** Whether the external conversion service is paused after a recent failure. */
    public static function external_paused() {
        return (bool) get_transient(self::PAUSE_TRANSIENT);
    }

    /** An upload to the service is not started with less than this many seconds left in the batch. */
    const MIN_EXTERNAL_SECONDS = 4;
    /** Failures in a row (network error, timeout, HTTP 5xx or 429) before the service is paused. */
    const FAILURES_TO_PAUSE = 3;
    const FAILURES_TRANSIENT = 'ezcache_webp_api_failures';

    /**
     * One failed request is not an outage: a single large image that needs more
     * than the request timeout used to stop every conversion for ten minutes.
     */
    private static function external_failed() {
        $failures = (int) get_transient(self::FAILURES_TRANSIENT) + 1;
        if ($failures >= self::FAILURES_TO_PAUSE) {
            delete_transient(self::FAILURES_TRANSIENT);
            set_transient(self::PAUSE_TRANSIENT, time(), self::PAUSE_SECONDS);
            return;
        }
        set_transient(self::FAILURES_TRANSIENT, $failures, self::PAUSE_SECONDS);
    }

    private static function external_answered() {
        if (get_transient(self::FAILURES_TRANSIENT)) { delete_transient(self::FAILURES_TRANSIENT); }
    }

    public static function is_available() {
        return self::local_available() || !empty(Settings::get_settings()->webp_external_api);
    }

    public static function local_available() {
        $available=wp_image_editor_supports(['mime_type'=>'image/webp']) || (function_exists('imagewebp') && (imagetypes() & IMG_WEBP));
        return (bool)apply_filters('ezcache_webp_local_encoder_available',$available);
    }

    public static function allowed_path($path, $existing = true) {
        $uploads = wp_get_upload_dir();
        $root = realpath($uploads['basedir']);
        if (!$root || !is_string($path) || is_link($path)) { return false; }
        $real = $existing ? realpath($path) : realpath(dirname($path));
        return $real && strpos($real . '/', rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) === 0;
    }

    public static function valid_webp($data) {
        if (!is_string($data) || strlen($data)<20 || strlen($data)>self::MAX_BYTES || substr($data,0,4)!=='RIFF' || substr($data,8,4)!=='WEBP') { return false; }
        $size = @getimagesizefromstring($data);
        return $size && ($size['mime'] ?? '')==='image/webp' && $size[0]>0 && $size[1]>0 && $size[0]*$size[1]<=self::MAX_PIXELS;
    }

    public function convert($image_path, $timeout = 8) {
        if (!self::allowed_path($image_path) || !is_readable($image_path) || filesize($image_path)>self::MAX_BYTES) {
            return new \WP_Error('invalid_input_file','Image must be a readable upload of at most 10 MiB.');
        }
        $size = @getimagesize($image_path);
        if (!$size || !in_array($size['mime'],['image/jpeg','image/png'],true) || $size[0]*$size[1]>self::MAX_PIXELS) {
            return new \WP_Error('invalid_input_image','Only JPEG/PNG images up to 16 megapixels are supported.');
        }
        wp_raise_memory_limit('image');
        $limit=wp_convert_hr_to_bytes(ini_get('memory_limit'));
        if ($limit>0 && $size[0]*$size[1]*8+filesize($image_path)*2>$limit-memory_get_usage(true)) {
            return new \WP_Error('image_memory_limit','The image exceeds the available conversion memory budget.');
        }
        if (self::local_available() && wp_image_editor_supports(['mime_type'=>'image/webp'])) {
            // Ask for an editor that can write WebP: the first one able to read the
            // file may be an Imagick build without it (ignored before WordPress 6.0).
            $editor = wp_get_image_editor($image_path, ['mime_type'=>$size['mime'], 'output_mime_type'=>'image/webp']);
            if (!is_wp_error($editor)) {
                $temp = dirname($image_path).'/.ezcache-'.bin2hex(random_bytes(12)).'.webp';
                $written = $temp;
                try {
                    $editor->set_quality(80);
                    $saved = $editor->save($temp,'image/webp');
                    // An editor that cannot write WebP saves in its own format under another
                    // extension (.ezcache-….jpg). That file used to stay in the uploads folder.
                    if (is_array($saved) && !empty($saved['path']) && is_string($saved['path'])) { $written = $saved['path']; }
                    if (!is_wp_error($saved) && is_file($written)) {
                        $data = file_get_contents($written);
                        if (self::valid_webp($data)) { return ['data'=>$data,'info'=>['content-type'=>'image/webp','source'=>'local']]; }
                    }
                } finally {
                    foreach (array_unique([$temp, $written]) as $file) {
                        if (is_file($file) && 0 === strpos(basename($file), '.ezcache-') && dirname($file) === dirname($temp)) { @unlink($file); }
                    }
                }
            }
        }
        // WordPress before 5.8 cannot ask its editor for WebP, even when GD can encode it.
        if (self::local_available() && function_exists('imagewebp')) {
            $image=$size['mime']==='image/png' ? @imagecreatefrompng($image_path) : @imagecreatefromjpeg($image_path);
            if ($image) {
                $temp=dirname($image_path).'/.ezcache-'.bin2hex(random_bytes(12)).'.webp';
                try {
                    if (!imageistruecolor($image)) { imagepalettetotruecolor($image); }
                    imagealphablending($image,false); imagesavealpha($image,true);
                    if (imagewebp($image,$temp,80) && is_file($temp)) {
                        $data=file_get_contents($temp);
                        if (self::valid_webp($data)) { return ['data'=>$data,'info'=>['content-type'=>'image/webp','source'=>'local']]; }
                    }
                } finally { if (is_file($temp)) { @unlink($temp); } }
            }
        }
        if (empty(Settings::get_settings()->webp_external_api)) {
            return new \WP_Error('webp_encoder_unavailable','Enable WebP in GD/Imagick, or explicitly enable the external conversion service.');
        }
        if (self::external_paused()) {
            return new \WP_Error('webp_api_paused','The conversion service failed recently; it is retried in a few minutes.');
        }
        // With less time it could only time out, and the timeout would be blamed on the service.
        if ($timeout < self::MIN_EXTERNAL_SECONDS) {
            return new \WP_Error('webp_no_time','Not enough time left in this batch for the conversion service.');
        }
        $response = wp_safe_remote_post('https://api.ezcache-wp.com/convert?quality=80',[
            'headers'=>['Content-Type'=>$size['mime']], 'body'=>file_get_contents($image_path),
            'timeout'=>max(1,min(8,$timeout)), 'redirection'=>0, 'sslverify'=>true,
            'limit_response_size'=>self::MAX_BYTES+1,
        ]);
        if (is_wp_error($response)) {
            self::external_failed();
            // Whether this is the image (too heavy for the service within the limit) or an
            // outage is decided by what follows: see WebpController::convert_batch().
            $timed_out = (bool) preg_match('/cURL error 28|timed out|timeout/i', $response->get_error_message());
            return new \WP_Error($timed_out ? 'webp_api_timeout' : 'webp_api_unreachable', 'The conversion service could not be reached: '.$response->get_error_message());
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code >= 500 || 429 === $code) {
            // The service itself is failing or asking us to slow down.
            self::external_failed();
            return new \WP_Error('webp_api_error','The conversion service returned HTTP '.$code.'.');
        }
        self::external_answered();
        $data = wp_remote_retrieve_body($response);
        if ($code!==200 || !self::valid_webp($data)) {
            return new \WP_Error('invalid_webp_response','The conversion service did not return a valid WebP image.');
        }
        return ['data'=>$data,'info'=>['content-type'=>'image/webp','source'=>'external']];
    }
}
