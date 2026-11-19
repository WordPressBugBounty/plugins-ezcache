<?php
namespace Upress\EzCache\BackgroundProcesses;

use Upress\EzCache\Rest\WebpController;

/** Compatibility adapter: all producers now share the persistent database queue. */
class ConvertWebpProcess {
    public function __construct() {
        add_action('ezcache_convert_images_to_webp_hook',[WebpController::class,'cron_process']);
    }
    public function schedule() { WebpController::schedule_cron(); }
    public function add_to_queue($image_id,$cache_file) { return true; }
    public function process_batch() { return WebpController::cron_process(); }
}
