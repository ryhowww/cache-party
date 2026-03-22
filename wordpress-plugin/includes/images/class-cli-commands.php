<?php

namespace CacheParty\Images;

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
    return;
}

class CLI_Commands {

    const BATCH_SIZE = 50;

    /**
     * Generate WebP versions for existing media library images.
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : Report how many images would be converted without actually doing it.
     *
     * [--id=<attachment_id>]
     * : Convert a single attachment by ID.
     *
     * ## EXAMPLES
     *
     *     wp cache-party convert-webp
     *     wp cache-party convert-webp --dry-run
     *     wp cache-party convert-webp --id=1234
     *
     * @subcommand convert-webp
     */
    public function convert_webp( $args, $assoc_args ) {
        $dry_run = \WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );
        $single  = isset( $assoc_args['id'] ) ? absint( $assoc_args['id'] ) : 0;

        $engine = WebP_Converter::is_conversion_available();
        if ( ! $engine ) {
            \WP_CLI::error( 'No WebP conversion library available. Install Imagick or GD with WebP support.' );
            return;
        }

        \WP_CLI::log( sprintf( 'Using %s for WebP conversion.', $engine ) );

        if ( $single ) {
            $post = get_post( $single );
            if ( ! $post || 'attachment' !== $post->post_type ) {
                \WP_CLI::error( sprintf( 'Attachment %d not found.', $single ) );
                return;
            }
            if ( $dry_run ) {
                $needs = $this->attachment_needs_webp( $single );
                \WP_CLI::success( $needs ? 'This image needs conversion.' : 'Already converted.' );
                return;
            }
            \WP_CLI::log( sprintf( 'Converting attachment %d: %s', $single, wp_basename( (string) get_attached_file( $single ) ) ) );
            $r = WebP_Converter::convert_attachment( $single );
            if ( $r['error'] ) {
                \WP_CLI::error( $r['error'] );
                return;
            }
            $stats = WebP_Converter::get_attachment_webp_stats( $single );
            \WP_CLI::success( sprintf(
                'Done. %d files converted, %d skipped. Savings: %s (%s%%).',
                $r['converted'],
                $r['skipped'],
                size_format( $stats['total_savings'] ),
                $stats['total_savings_pct']
            ) );
            return;
        }

        // Bulk mode.
        $attachment_ids = $this->get_convertible_attachments();
        $total          = count( $attachment_ids );

        if ( 0 === $total ) {
            \WP_CLI::success( 'No JPEG or PNG images found in the media library.' );
            return;
        }

        \WP_CLI::log( sprintf( 'Found %d JPEG/PNG images in the media library.', $total ) );

        if ( $dry_run ) {
            $need = 0;
            foreach ( $attachment_ids as $id ) {
                if ( $this->attachment_needs_webp( (int) $id ) ) {
                    $need++;
                }
            }
            \WP_CLI::success( sprintf(
                'Dry run complete. %d of %d images would be converted.',
                $need,
                $total
            ) );
            return;
        }

        $converted = 0;
        $skipped   = 0;
        $errors    = 0;
        $counter   = 0;
        $batches   = array_chunk( $attachment_ids, self::BATCH_SIZE );

        foreach ( $batches as $batch ) {
            foreach ( $batch as $id ) {
                $id = (int) $id;
                $counter++;

                if ( ! $this->attachment_needs_webp( $id ) ) {
                    $skipped++;
                    continue;
                }

                $file = get_attached_file( $id );
                if ( ! $file || ! file_exists( $file ) ) {
                    $skipped++;
                    continue;
                }

                \WP_CLI::log( sprintf( 'Converting %d/%d: %s', $counter, $total, wp_basename( $file ) ) );

                $r = WebP_Converter::convert_attachment( $id );
                if ( $r['error'] ) {
                    \WP_CLI::warning( sprintf( 'ID %d: %s', $id, $r['error'] ) );
                    $errors++;
                } elseif ( $r['converted'] > 0 ) {
                    $converted++;
                } else {
                    $skipped++;
                }
            }

            if ( function_exists( 'wp_cache_flush' ) ) {
                wp_cache_flush();
            }
        }

        \WP_CLI::success( sprintf( 'Done. Converted: %d, Skipped: %d, Errors: %d', $converted, $skipped, $errors ) );
    }

    /**
     * Show WebP conversion statistics.
     *
     * ## EXAMPLES
     *
     *     wp cache-party image-stats
     *
     * @subcommand image-stats
     */
    public function image_stats( $args, $assoc_args ) {
        $engine = WebP_Converter::is_conversion_available();
        $stats  = WebP_Converter::get_bulk_stats();

        \WP_CLI::log( '' );
        \WP_CLI::log( 'Cache Party — Image Stats' );
        \WP_CLI::log( str_repeat( '-', 35 ) );
        \WP_CLI::log( sprintf( 'Conversion engine:  %s', $engine ? ucfirst( $engine ) : 'Not available' ) );
        \WP_CLI::log( sprintf( 'Total JPEG/PNG:     %d', $stats['total'] ) );
        \WP_CLI::log( sprintf( 'Converted to WebP:  %d', $stats['converted'] ) );
        \WP_CLI::log( sprintf( 'Need conversion:    %d', $stats['unconverted'] ) );

        if ( $stats['total'] > 0 ) {
            $pct = round( ( $stats['converted'] / $stats['total'] ) * 100, 1 );
            \WP_CLI::log( sprintf( 'Coverage:           %s%%', $pct ) );
        }

        \WP_CLI::log( '' );
    }

    private function get_convertible_attachments() {
        global $wpdb;

        return $wpdb->get_col(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type = 'attachment'
               AND post_mime_type IN ('image/jpeg', 'image/png')
             ORDER BY ID ASC"
        );
    }

    private function attachment_needs_webp( $attachment_id ) {
        $webp_data = get_post_meta( $attachment_id, WebP_Converter::META_KEY, true );

        if ( ! empty( $webp_data['original'] ) ) {
            $file = get_attached_file( $attachment_id );
            if ( $file ) {
                $webp_path = dirname( $file ) . '/' . $webp_data['original'];
                if ( file_exists( $webp_path ) ) {
                    return false;
                }
            }
        }

        return true;
    }
}
