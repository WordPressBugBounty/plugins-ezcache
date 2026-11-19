<?php
namespace Upress\EzCache\Utilities;

/** A process-owned lock: released by the OS even after a fatal error. */
class Lock {
    private static $held = [];
    public static function acquire( $name ) {
        if ( isset(self::$held[$name]) ) { self::$held[$name][1]++; return self::$held[$name][0]; }
        $dir = WP_CONTENT_DIR . '/ezcache-locks';
        // Folder and lock file go to the owner of wp-content: created by a root WP-CLI
        // run they would otherwise be unwritable for the web server, and every later
        // settings save would report "another update is in progress".
        // method_exists: right after an update PHP can still hold an older AtomicFile.
        $made = method_exists( '\Upress\EzCache\Utilities\AtomicFile', 'make_dir' ) ? AtomicFile::make_dir( $dir ) : ( is_dir( $dir ) || @mkdir( $dir, 0755, true ) || is_dir( $dir ) );
        if ( ! $made ) { return false; }
        $file = $dir . '/' . hash( 'sha256', $name ) . '.php';
        $created = ! file_exists( $file );
        $handle = @fopen( $file, 'c+' );
        if ( ! $handle ) { return false; }
        if ( $created && method_exists( '\Upress\EzCache\Utilities\AtomicFile', 'match_owner' ) ) { AtomicFile::match_owner( $file, $dir ); }
        if ( ! flock( $handle, LOCK_EX | LOCK_NB ) ) { fclose( $handle ); return false; }
        if ( 0 === (int) fstat( $handle )['size'] ) { fwrite( $handle, '<?php exit; ?>' ); fflush( $handle ); }
        self::$held[$name] = [$handle, 1];
        return $handle;
    }
    public static function release( $handle ) {
        foreach ( self::$held as $name => $entry ) {
            if ( $entry[0] === $handle ) {
                if ( --self::$held[$name][1] > 0 ) { return; }
                unset(self::$held[$name]);
                break;
            }
        }
        if ( is_resource( $handle ) ) { flock( $handle, LOCK_UN ); fclose( $handle ); }
    }
}
