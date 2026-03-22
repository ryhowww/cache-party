<?php

namespace CacheParty\Images;

if ( ! defined( 'ABSPATH' ) ) exit;

class WebP_Converter {

    const META_KEY = '_cache_party_webp';

    private $quality;
    private $enabled;

    public function __construct( $settings ) {
        $this->quality = isset( $settings['webp_quality'] ) ? (int) $settings['webp_quality'] : 80;
        $this->enabled = ! empty( $settings['webp_enabled'] );

        // Always clean up on delete.
        add_action( 'delete_attachment', [ __CLASS__, 'delete_webp_files' ] );

        if ( $this->enabled ) {
            add_filter( 'wp_generate_attachment_metadata', [ $this, 'generate_webp_on_upload' ], 10, 2 );
        }

        // One-time migration from ImageOptimizer.ai meta.
        add_action( 'admin_init', [ __CLASS__, 'maybe_migrate_meta' ] );
    }

    /* ---------------------------------------------------------------
     *  Migration
     * ------------------------------------------------------------- */

    public static function maybe_migrate_meta() {
        if ( get_option( 'cache_party_migrated_webp_meta' ) ) {
            return;
        }

        global $wpdb;

        $count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_imageoptimizerai_webp'"
        );

        if ( $count === 0 ) {
            update_option( 'cache_party_migrated_webp_meta', true );
            return;
        }

        // Copy meta in a single query.
        $wpdb->query(
            "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value)
             SELECT post_id, '" . self::META_KEY . "', meta_value
             FROM {$wpdb->postmeta}
             WHERE meta_key = '_imageoptimizerai_webp'
               AND post_id NOT IN (
                   SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '" . self::META_KEY . "'
               )"
        );

        update_option( 'cache_party_migrated_webp_meta', true );
    }

    /* ---------------------------------------------------------------
     *  Conversion on upload
     * ------------------------------------------------------------- */

    public function generate_webp_on_upload( $metadata, $attachment_id ) {
        $file = get_attached_file( $attachment_id );
        if ( ! $file || ! file_exists( $file ) ) {
            return $metadata;
        }

        $mime = get_post_mime_type( $attachment_id );
        if ( ! in_array( $mime, [ 'image/jpeg', 'image/png' ], true ) ) {
            return $metadata;
        }

        $upload_dir = dirname( $file );
        $webp_data  = [
            'original' => '',
            'sizes'    => [],
        ];

        $webp_path = $this->convert_to_webp( $file );
        if ( $webp_path ) {
            $webp_data['original'] = wp_basename( $webp_path );
        }

        if ( ! empty( $metadata['sizes'] ) ) {
            foreach ( $metadata['sizes'] as $size_name => $size_info ) {
                $size_file = $upload_dir . '/' . $size_info['file'];
                if ( file_exists( $size_file ) ) {
                    $webp_path = $this->convert_to_webp( $size_file );
                    if ( $webp_path ) {
                        $webp_data['sizes'][ $size_name ] = wp_basename( $webp_path );
                    }
                }
            }
        }

        if ( $webp_data['original'] || ! empty( $webp_data['sizes'] ) ) {
            update_post_meta( $attachment_id, self::META_KEY, $webp_data );
        }

        return $metadata;
    }

    /* ---------------------------------------------------------------
     *  Core conversion
     * ------------------------------------------------------------- */

    public function convert_to_webp( $source_path ) {
        $webp_path = preg_replace( '/\.(jpe?g|png)$/i', '.webp', $source_path );

        if ( $webp_path === $source_path ) {
            return false;
        }

        if ( file_exists( $webp_path ) ) {
            return $webp_path;
        }

        $converted = false;

        if ( extension_loaded( 'imagick' ) && class_exists( '\Imagick' ) ) {
            $converted = $this->convert_with_imagick( $source_path, $webp_path );
        }

        if ( ! $converted && function_exists( 'imagewebp' ) ) {
            $converted = $this->convert_with_gd( $source_path, $webp_path );
        }

        if ( ! $converted ) {
            return false;
        }

        clearstatcache( true, $webp_path );
        clearstatcache( true, $source_path );
        if ( filesize( $webp_path ) >= filesize( $source_path ) ) {
            wp_delete_file( $webp_path );
            return false;
        }

        return $webp_path;
    }

    private function convert_with_imagick( $source, $dest ) {
        try {
            $image = new \Imagick( $source );
            $image->setImageFormat( 'webp' );
            $image->setImageCompressionQuality( $this->quality );
            $image->stripImage();
            $result = $image->writeImage( $dest );
            $image->clear();
            $image->destroy();
            return $result;
        } catch ( \Exception $e ) {
            return false;
        }
    }

    private function convert_with_gd( $source, $dest ) {
        $info = wp_check_filetype( $source );
        $mime = isset( $info['type'] ) ? $info['type'] : '';

        if ( 'image/jpeg' === $mime ) {
            $image = @imagecreatefromjpeg( $source );
        } elseif ( 'image/png' === $mime ) {
            $image = @imagecreatefrompng( $source );
            if ( $image ) {
                imagepalettetotruecolor( $image );
                imagealphablending( $image, true );
                imagesavealpha( $image, true );
            }
        } else {
            return false;
        }

        if ( ! $image ) {
            return false;
        }

        $result = @imagewebp( $image, $dest, $this->quality );
        imagedestroy( $image );

        return $result;
    }

    /* ---------------------------------------------------------------
     *  Cleanup utilities
     * ------------------------------------------------------------- */

    public static function delete_webp_files( $attachment_id ) {
        $webp_data = get_post_meta( $attachment_id, self::META_KEY, true );
        if ( empty( $webp_data ) ) {
            return;
        }

        $file = get_attached_file( $attachment_id );
        if ( ! $file ) {
            delete_post_meta( $attachment_id, self::META_KEY );
            return;
        }

        $dir = dirname( $file );

        if ( ! empty( $webp_data['original'] ) ) {
            $path = $dir . '/' . $webp_data['original'];
            if ( file_exists( $path ) ) {
                wp_delete_file( $path );
            }
        }

        if ( ! empty( $webp_data['sizes'] ) ) {
            foreach ( $webp_data['sizes'] as $webp_file ) {
                $path = $dir . '/' . $webp_file;
                if ( file_exists( $path ) ) {
                    wp_delete_file( $path );
                }
            }
        }

        delete_post_meta( $attachment_id, self::META_KEY );
    }

    public static function delete_all_webp_files() {
        global $wpdb;

        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s",
                self::META_KEY
            )
        );

        foreach ( $ids as $attachment_id ) {
            self::delete_webp_files( (int) $attachment_id );
        }
    }

    /* ---------------------------------------------------------------
     *  Shared utilities
     * ------------------------------------------------------------- */

    public static function is_conversion_available() {
        if ( extension_loaded( 'imagick' ) && class_exists( '\Imagick' ) ) {
            return 'imagick';
        }

        if ( function_exists( 'imagewebp' ) ) {
            return 'gd';
        }

        return false;
    }

    public static function convert_attachment( $attachment_id, $force = false ) {
        $result = [ 'converted' => 0, 'skipped' => 0, 'error' => null ];

        $file = get_attached_file( $attachment_id );
        if ( ! $file || ! file_exists( $file ) ) {
            $result['error'] = 'File not found.';
            return $result;
        }

        $mime = get_post_mime_type( $attachment_id );
        if ( ! in_array( $mime, [ 'image/jpeg', 'image/png' ], true ) ) {
            $result['error'] = 'Not a JPEG or PNG image.';
            return $result;
        }

        if ( $force ) {
            self::delete_webp_files( $attachment_id );
        }

        $metadata = wp_get_attachment_metadata( $attachment_id );
        if ( ! $metadata ) {
            $result['error'] = 'No attachment metadata.';
            return $result;
        }

        $settings   = wp_parse_args( get_option( 'cache_party_images', [] ), \CacheParty\Settings::image_defaults() );
        $instance   = new self( $settings );
        $upload_dir = dirname( $file );
        $webp_data  = [
            'original' => '',
            'sizes'    => [],
        ];

        $webp_path = $instance->convert_to_webp( $file );
        if ( $webp_path ) {
            $webp_data['original'] = wp_basename( $webp_path );
            $result['converted']++;
        } else {
            $result['skipped']++;
        }

        if ( ! empty( $metadata['sizes'] ) ) {
            foreach ( $metadata['sizes'] as $size_name => $size_info ) {
                $size_file = $upload_dir . '/' . $size_info['file'];
                if ( file_exists( $size_file ) ) {
                    $webp_path = $instance->convert_to_webp( $size_file );
                    if ( $webp_path ) {
                        $webp_data['sizes'][ $size_name ] = wp_basename( $webp_path );
                        $result['converted']++;
                    } else {
                        $result['skipped']++;
                    }
                }
            }
        }

        if ( $webp_data['original'] || ! empty( $webp_data['sizes'] ) ) {
            update_post_meta( $attachment_id, self::META_KEY, $webp_data );
        }

        return $result;
    }

    public static function get_attachment_webp_stats( $attachment_id ) {
        $stats = [
            'has_webp'          => false,
            'sizes'             => [],
            'total_original'    => 0,
            'total_webp'        => 0,
            'total_savings'     => 0,
            'total_savings_pct' => 0,
        ];

        $webp_data = get_post_meta( $attachment_id, self::META_KEY, true );
        if ( empty( $webp_data ) ) {
            return $stats;
        }

        $file = get_attached_file( $attachment_id );
        if ( ! $file ) {
            return $stats;
        }

        $dir      = dirname( $file );
        $metadata = wp_get_attachment_metadata( $attachment_id );
        $has_any  = false;

        if ( ! empty( $webp_data['original'] ) ) {
            $webp_path = $dir . '/' . $webp_data['original'];
            if ( file_exists( $file ) && file_exists( $webp_path ) ) {
                $orig_size = filesize( $file );
                $webp_size = filesize( $webp_path );
                $stats['sizes'][] = [
                    'name'          => 'original',
                    'original_size' => $orig_size,
                    'webp_size'     => $webp_size,
                    'savings'       => $orig_size - $webp_size,
                ];
                $stats['total_original'] += $orig_size;
                $stats['total_webp']     += $webp_size;
                $has_any = true;
            }
        }

        if ( ! empty( $webp_data['sizes'] ) && ! empty( $metadata['sizes'] ) ) {
            foreach ( $webp_data['sizes'] as $size_name => $webp_file ) {
                if ( ! isset( $metadata['sizes'][ $size_name ] ) ) {
                    continue;
                }
                $orig_path = $dir . '/' . $metadata['sizes'][ $size_name ]['file'];
                $webp_path = $dir . '/' . $webp_file;
                if ( file_exists( $orig_path ) && file_exists( $webp_path ) ) {
                    $orig_size = filesize( $orig_path );
                    $webp_size = filesize( $webp_path );
                    $stats['sizes'][] = [
                        'name'          => $size_name,
                        'original_size' => $orig_size,
                        'webp_size'     => $webp_size,
                        'savings'       => $orig_size - $webp_size,
                    ];
                    $stats['total_original'] += $orig_size;
                    $stats['total_webp']     += $webp_size;
                    $has_any = true;
                }
            }
        }

        $stats['has_webp']      = $has_any;
        $stats['total_savings'] = $stats['total_original'] - $stats['total_webp'];
        if ( $stats['total_original'] > 0 ) {
            $stats['total_savings_pct'] = round(
                ( $stats['total_savings'] / $stats['total_original'] ) * 100,
                1
            );
        }

        return $stats;
    }

    public static function get_bulk_stats() {
        global $wpdb;

        $total = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_type = 'attachment'
               AND post_mime_type IN ('image/jpeg', 'image/png')"
        );

        $converted = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(DISTINCT p.ID)
                 FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
                 WHERE p.post_type = 'attachment'
                   AND p.post_mime_type IN ('image/jpeg', 'image/png')
                   AND pm.meta_key = %s",
                self::META_KEY
            )
        );

        return [
            'total'       => $total,
            'converted'   => $converted,
            'unconverted' => $total - $converted,
        ];
    }

    public static function get_unconverted_ids( $limit = 10, $offset = 0 ) {
        global $wpdb;

        return $wpdb->get_col(
            $wpdb->prepare(
                "SELECT p.ID FROM {$wpdb->posts} p
                 LEFT JOIN {$wpdb->postmeta} pm
                   ON p.ID = pm.post_id AND pm.meta_key = %s
                 WHERE p.post_type = 'attachment'
                   AND p.post_mime_type IN ('image/jpeg', 'image/png')
                   AND pm.meta_id IS NULL
                 ORDER BY p.ID ASC
                 LIMIT %d OFFSET %d",
                self::META_KEY,
                $limit,
                $offset
            )
        );
    }
}
