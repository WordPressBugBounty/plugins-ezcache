<?php
namespace Upress\EzCache\Utilities;

/** Replace a complete file, without exposing partial contents or public backups. */
class AtomicFile {
    /**
     * @param string $path
     * @param string $contents
     * @param int    $mode
     * @param bool   $durable Flush to disk before the rename. Configuration needs it;
     *                        a cached page does not.
     */
    public static function write( $path, $contents, $mode = 0644, $durable = true ) {
        if ( ! is_string( $contents ) || is_link( $path ) || ( file_exists( $path ) && ( ! is_file( $path ) || ! is_writable( $path ) ) ) ) {
            return false;
        }
        $dir = dirname( $path );
        if ( ! self::make_dir( $dir ) ) { return false; }
        // PHP configuration keeps an executable guard; public binary/text data
        // must never acquire a PHP extension, even temporarily.
        $suffix = strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'php' ? '.php' : '.tmp';
        $tmp = $dir . '/.ezcache-' . bin2hex(random_bytes(16)) . $suffix;
        $handle = @fopen( $tmp, 'x+b' );
        if ( ! $handle ) { return false; }
        if ( function_exists( 'chmod' ) ) { @chmod( $tmp, 0600 ); }
        try {
            $length = strlen( $contents );
            $offset = 0;
            while ( $offset < $length ) {
                $written = fwrite( $handle, substr( $contents, $offset ) );
                if ( ! $written ) { return false; }
                $offset += $written;
            }
            if ( ! fflush( $handle ) ) { return false; }
            if ( $durable && function_exists( 'fsync' ) && ! fsync( $handle ) ) { return false; }
            self::match_owner( $tmp, file_exists( $path ) ? $path : $dir );
            if ( function_exists( 'chmod' ) && ! @chmod( $tmp, $mode ) ) { return false; }
            fclose( $handle );
            $handle = null;
            return @rename( $tmp, $path );
        } finally {
            if ( is_resource( $handle ) ) { fclose( $handle ); }
            if ( is_file( $tmp ) ) { @unlink( $tmp ); }
        }
    }

    /** mkdir -p that hands each new folder to the owner of the folder it was created in. */
    public static function make_dir( $dir ) {
        if ( is_dir( $dir ) ) { return true; }
        $missing = [];
        $parent  = $dir;
        while ( ! is_dir( $parent ) && dirname( $parent ) !== $parent ) {
            $missing[] = $parent;
            $parent    = dirname( $parent );
        }
        if ( ! @mkdir( $dir, 0755, true ) && ! is_dir( $dir ) ) { return false; }
        foreach ( array_reverse( $missing ) as $created ) { self::match_owner( $created, $parent ); }
        return true;
    }

    /**
     * rename() and mkdir() give their result to whoever runs this process. When
     * that is not the account that owns the site (WP-CLI started as root is the
     * usual case) the web server can no longer replace the file, or even read
     * it: a root-owned wp-config.php takes the site down, a root-owned settings
     * file makes every later save fail. So the new file or folder gets the owner
     * and group of what it replaces, or of the folder it is in. Only a
     * privileged process can change an owner; for any other process the calls
     * fail silently and nothing needed correcting.
     */
    public static function match_owner( $target, $reference ) {
        // A host that disables these functions removes them altogether on PHP 8, where
        // calling one is a fatal error, "@" or not.
        foreach ( [ 'fileowner', 'filegroup', 'chown', 'chgrp' ] as $function ) {
            if ( ! function_exists( $function ) ) { return; }
        }
        if ( function_exists( 'posix_geteuid' ) && 0 !== posix_geteuid() ) { return; }
        $owner = @fileowner( $reference );
        $group = @filegroup( $reference );
        if ( false === $owner || false === $group ) { return; }
        clearstatcache( true, $target );
        if ( @fileowner( $target ) !== $owner ) { @chown( $target, $owner ); }
        if ( @filegroup( $target ) !== $group ) { @chgrp( $target, $group ); }
    }
}
