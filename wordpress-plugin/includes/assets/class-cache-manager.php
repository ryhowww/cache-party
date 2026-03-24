<?php

namespace CacheParty\Assets;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Manages the aggregated CSS/JS cache on disk.
 *
 * Cache directory: wp-content/cache/cache-party/{css,js}/
 * Filenames: cp_{md5}.{css,js}
 *
 * Transplanted from autoptimizeCache.php, stripped of PHP-gzip mode,
 * .htaccess generation, 404 handler, and multisite per-blog separation.
 */
class Cache_Manager {

    const PREFIX = 'cp_';

    private $hash;
    private $ext;
    private $filename;
    private $cachedir;

    /**
     * @param string $hash MD5 hash of the content.
     * @param string $ext  File extension: 'css' or 'js'.
     */
    public function __construct( $hash, $ext = 'css' ) {
        $this->hash     = $hash;
        $this->ext      = $ext;
        $this->cachedir = self::get_cache_dir( $ext );
        $this->filename = self::PREFIX . $hash . '.' . $ext;
    }

    /**
     * Check if cached file exists.
     */
    public function check() {
        return file_exists( $this->cachedir . $this->filename );
    }

    /**
     * Retrieve cached content.
     */
    public function retrieve() {
        $path = $this->cachedir . $this->filename;
        if ( file_exists( $path ) ) {
            return file_get_contents( $path );
        }
        return false;
    }

    /**
     * Write content to cache file.
     *
     * @param string $data Minified content.
     * @param string $mime MIME type (unused, kept for API compat).
     * @return bool
     */
    public function cache( $data, $mime = 'text/css' ) {
        $dir = $this->cachedir;

        if ( ! wp_mkdir_p( $dir ) ) {
            return false;
        }

        $filepath = $dir . $this->filename;
        $written  = file_put_contents( $filepath, $data );

        if ( false !== $written ) {
            do_action( 'cp_action_cache_file_created', $this->filename, $this->ext );
        }

        return false !== $written;
    }

    /**
     * Get the cache filename (without directory).
     */
    public function getname() {
        return $this->filename;
    }

    /**
     * Get the public URL for this cache file.
     */
    public function get_url() {
        return self::get_cache_url( $this->ext ) . $this->filename;
    }

    // ─── Static helpers ──────────────────────────────────────────

    /**
     * Get the cache directory path for a given extension.
     */
    public static function get_cache_dir( $ext = 'css' ) {
        return WP_CONTENT_DIR . '/cache/cache-party/' . $ext . '/';
    }

    /**
     * Get the cache directory URL for a given extension.
     */
    public static function get_cache_url( $ext = 'css' ) {
        return content_url( '/cache/cache-party/' . $ext . '/' );
    }

    /**
     * Ensure cache directories exist and are writable.
     */
    public static function cache_available() {
        $base = WP_CONTENT_DIR . '/cache/cache-party/';
        foreach ( [ '', 'css/', 'js/' ] as $sub ) {
            if ( ! wp_mkdir_p( $base . $sub ) ) {
                return false;
            }
        }
        return true;
    }

    /**
     * Clear all cached files for a given type (or all types).
     *
     * @param string $ext 'css', 'js', or 'all'.
     */
    public static function clearall( $ext = 'all' ) {
        $types = $ext === 'all' ? [ 'css', 'js' ] : [ $ext ];

        foreach ( $types as $type ) {
            $dir = self::get_cache_dir( $type );
            if ( ! is_dir( $dir ) ) {
                continue;
            }
            $files = glob( $dir . self::PREFIX . '*.' . $type );
            if ( is_array( $files ) ) {
                foreach ( $files as $file ) {
                    wp_delete_file( $file );
                }
            }
        }

        // Delete stats transient.
        delete_transient( 'cp_cache_stats' );

        do_action( 'cp_action_cache_purged', $ext );
    }

    /**
     * Get cache statistics.
     *
     * @return array { count, size, size_human }
     */
    public static function stats() {
        $cached = get_transient( 'cp_cache_stats' );
        if ( false !== $cached ) {
            return $cached;
        }

        $count = 0;
        $size  = 0;

        foreach ( [ 'css', 'js' ] as $type ) {
            $dir = self::get_cache_dir( $type );
            if ( ! is_dir( $dir ) ) {
                continue;
            }
            $files = glob( $dir . self::PREFIX . '*' );
            if ( is_array( $files ) ) {
                $count += count( $files );
                foreach ( $files as $file ) {
                    $size += filesize( $file );
                }
            }
        }

        $stats = [
            'count'      => $count,
            'size'       => $size,
            'size_human' => size_format( $size ),
        ];

        set_transient( 'cp_cache_stats', $stats, HOUR_IN_SECONDS );

        return $stats;
    }

    /**
     * Clean up cache if it exceeds the configured maximum size.
     *
     * @param int $max_size Maximum cache size in bytes. Default 512MB.
     */
    public static function cleanup( $max_size = 0 ) {
        if ( ! $max_size ) {
            $max_size = apply_filters( 'cp_cache_max_size', 512 * 1024 * 1024 );
        }

        $stats = self::stats();
        if ( $stats['size'] <= $max_size ) {
            return;
        }

        // Over limit — clear everything. Simple and safe.
        self::clearall();
    }

    /**
     * Schedule the cleanup cron if not already scheduled.
     */
    public static function schedule_cleanup() {
        if ( ! wp_next_scheduled( 'cp_cache_cleanup' ) ) {
            wp_schedule_event( time(), 'twicedaily', 'cp_cache_cleanup' );
        }
    }

    /**
     * Unschedule the cleanup cron.
     */
    public static function unschedule_cleanup() {
        $timestamp = wp_next_scheduled( 'cp_cache_cleanup' );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, 'cp_cache_cleanup' );
        }
    }
}
