<?php

namespace CacheParty\Assets;

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
    return;
}

class CLI_Assets {

    /**
     * Generate critical CSS for a template via the Railway service (multi-viewport).
     *
     * ## OPTIONS
     *
     * [--template=<template>]
     * : Template slug (e.g., front-page, page-service, single). Required unless --all.
     *
     * [--url=<url>]
     * : URL to extract critical CSS from. Required unless --all.
     *
     * [--all]
     * : Generate for all discovered templates. Uses auto-detected sample URLs.
     *
     * [--dimensions=<dimensions>]
     * : Override viewports (comma-separated WxH). Default: 412x896,900x1024,1300x900.
     *
     * [--service-url=<url>]
     * : Railway service URL. Defaults to warmer API URL from settings.
     *
     * ## EXAMPLES
     *
     *     wp cache-party generate-critical --template=front-page --url=https://example.com/
     *     wp cache-party generate-critical --all
     *     wp cache-party generate-critical --all --dimensions=412x896,1300x900
     *
     * @subcommand generate-critical
     */
    public function generate_critical( $args, $assoc_args ) {
        $all = isset( $assoc_args['all'] );

        if ( $all ) {
            $this->generate_all( $assoc_args );
            return;
        }

        $template = $assoc_args['template'] ?? '';
        $url      = $assoc_args['url'] ?? '';

        if ( ! $template ) {
            \WP_CLI::error( 'Please provide --template or --all.' );
            return;
        }

        if ( ! $url ) {
            \WP_CLI::error( 'Please provide --url to extract critical CSS from.' );
            return;
        }

        $dimensions = ! empty( $assoc_args['dimensions'] )
            ? self::parse_dimensions( $assoc_args['dimensions'] )
            : Asset_Optimizer::get_critical_dimensions();

        $this->generate_single( $template, $url, $dimensions, $assoc_args );
    }

    /**
     * Generate critical CSS for all discovered templates.
     */
    private function generate_all( $assoc_args ) {
        $templates = Critical_CSS::discover_templates();

        if ( empty( $templates ) ) {
            \WP_CLI::error( 'No templates discovered.' );
            return;
        }

        $dimensions = ! empty( $assoc_args['dimensions'] )
            ? self::parse_dimensions( $assoc_args['dimensions'] )
            : Asset_Optimizer::get_critical_dimensions();

        $dim_display = implode( ', ', array_map( function( $d ) {
            return $d['width'] . 'x' . $d['height'];
        }, $dimensions ) );

        \WP_CLI::log( '' );
        \WP_CLI::log( sprintf( 'Generating critical CSS for %d templates (%s)', count( $templates ), $dim_display ) );
        \WP_CLI::log( str_repeat( '-', 70 ) );

        $success = 0;
        $skipped = 0;
        $failed  = 0;

        foreach ( $templates as $t ) {
            if ( empty( $t['sample_url'] ) ) {
                \WP_CLI::warning( sprintf( '  %-18s — skipped (no sample URL)', $t['slug'] ) );
                $skipped++;
                continue;
            }

            \WP_CLI::log( sprintf( '  %-18s — %s', $t['slug'], $t['sample_url'] ) );

            $result = $this->generate_single( $t['slug'], $t['sample_url'], $dimensions, $assoc_args, false );
            if ( $result ) {
                $success++;
            } else {
                $failed++;
            }
        }

        \WP_CLI::log( '' );
        \WP_CLI::log( sprintf( 'Done: %d generated, %d skipped, %d failed', $success, $skipped, $failed ) );
        \WP_CLI::log( '' );
    }

    /**
     * Generate critical CSS for a single template.
     *
     * @return bool Success
     */
    private function generate_single( $template, $url, $dimensions, $assoc_args, $standalone = true ) {
        $service_url = $assoc_args['service-url'] ?? \CacheParty\Warmer\Warmer_Client::API_URL;

        $api_key = get_option( 'cache_party_api_key', '' );
        if ( empty( $api_key ) ) {
            $msg = 'API key not configured. Add it on the General tab.';
            $standalone ? \WP_CLI::error( $msg ) : \WP_CLI::warning( '    ' . $msg );
            return false;
        }

        $headers = [
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . $api_key,
        ];

        $endpoint    = rtrim( $service_url, '/' ) . '/api/critical-css';
        $dim_display = implode( ', ', array_map( function( $d ) {
            return $d['width'] . 'x' . $d['height'];
        }, $dimensions ) );

        if ( $standalone ) {
            \WP_CLI::log( sprintf( 'Template: %s', $template ) );
            \WP_CLI::log( sprintf( 'URL: %s', $url ) );
            \WP_CLI::log( sprintf( 'Viewports: %s', $dim_display ) );
            \WP_CLI::log( '' );
        }

        $response = wp_remote_post( $endpoint, [
            'timeout' => 120,
            'headers' => $headers,
            'body'    => wp_json_encode( [
                'url'        => $url,
                'template'   => $template,
                'dimensions' => $dimensions,
            ] ),
        ] );

        if ( is_wp_error( $response ) ) {
            $msg = 'Request failed: ' . $response->get_error_message();
            $standalone ? \WP_CLI::error( $msg ) : \WP_CLI::warning( '    ' . $msg );
            return false;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );

        if ( $code !== 200 ) {
            $msg = sprintf( 'HTTP %d: %s', $code, substr( $body, 0, 200 ) );
            $standalone ? \WP_CLI::error( $msg ) : \WP_CLI::warning( '    ' . $msg );
            return false;
        }

        $data = json_decode( $body, true );
        $css  = $data['css'] ?? '';

        if ( empty( $css ) ) {
            $msg = 'Empty CSS returned.';
            $standalone ? \WP_CLI::warning( $msg ) : \WP_CLI::warning( '    ' . $msg );
            return false;
        }

        $meta = [
            'dimensions' => $data['dimensions'] ?? [],
            'source_url' => $url,
        ];

        $saved = Critical_CSS::save_critical_css( $template, $css, $meta );
        if ( ! $saved ) {
            $msg = 'Failed to save CSS file.';
            $standalone ? \WP_CLI::error( $msg ) : \WP_CLI::warning( '    ' . $msg );
            return false;
        }

        $size = size_format( strlen( $css ) );
        if ( $standalone ) {
            \WP_CLI::success( sprintf( 'Saved "%s" — %s (%s)', $template, $size, $dim_display ) );
        } else {
            \WP_CLI::log( sprintf( '    Saved — %s', $size ) );
        }

        return true;
    }

    /**
     * List all page templates in the active theme with sample URLs and critical CSS status.
     *
     * ## EXAMPLES
     *
     *     wp cache-party list-templates
     *
     * @subcommand list-templates
     */
    public function list_templates( $args, $assoc_args ) {
        $templates = Critical_CSS::discover_templates();

        if ( empty( $templates ) ) {
            \WP_CLI::log( 'No templates found.' );
            return;
        }

        $all_meta = get_option( 'cache_party_critical_meta', [] );

        \WP_CLI::log( '' );
        \WP_CLI::log( 'Theme Templates' );
        \WP_CLI::log( str_repeat( '-', 90 ) );

        foreach ( $templates as $t ) {
            $status = $t['has_critical_css'] ? 'YES' : ' - ';
            $meta   = $all_meta[ $t['slug'] ] ?? [];
            $age    = '';

            if ( ! empty( $meta['generated_at'] ) ) {
                $gen_time = strtotime( $meta['generated_at'] );
                $days     = round( ( time() - $gen_time ) / DAY_IN_SECONDS );
                $age      = $days === 0 ? 'today' : $days . 'd ago';
                if ( $days > 30 ) {
                    $age = '** ' . $age . ' (stale)';
                }
            }

            $url_short = $t['sample_url'] ? preg_replace( '#^https?://[^/]+#', '', $t['sample_url'] ) : '(no URL)';

            \WP_CLI::log( sprintf(
                '  [%s] %-18s %3d pages  %-30s  %s',
                $status,
                $t['slug'],
                $t['count'],
                $url_short,
                $age
            ) );
        }

        \WP_CLI::log( '' );
        \WP_CLI::log( '  [YES] = critical CSS generated, [ - ] = not yet generated' );
        \WP_CLI::log( '  Run: wp cache-party generate-critical --all' );
        \WP_CLI::log( '' );
    }

    /**
     * List generated critical CSS files with metadata.
     *
     * ## EXAMPLES
     *
     *     wp cache-party list-critical
     *
     * @subcommand list-critical
     */
    public function list_critical( $args, $assoc_args ) {
        $templates = Critical_CSS::list_templates();

        if ( empty( $templates ) ) {
            \WP_CLI::log( 'No critical CSS files found.' );
            return;
        }

        $all_meta = get_option( 'cache_party_critical_meta', [] );

        \WP_CLI::log( '' );
        \WP_CLI::log( 'Generated Critical CSS' );
        \WP_CLI::log( str_repeat( '-', 70 ) );

        foreach ( $templates as $t ) {
            $meta      = $all_meta[ $t['template'] ] ?? [];
            $generated = $meta['generated_at'] ?? 'unknown';
            $dims      = ! empty( $meta['dimensions'] ) ? implode( ', ', $meta['dimensions'] ) : 'unknown';

            \WP_CLI::log( sprintf(
                '  %-18s %6s   viewports: %-30s  generated: %s',
                $t['template'],
                size_format( $t['size'] ),
                $dims,
                $generated
            ) );
        }

        \WP_CLI::log( '' );
    }

    /**
     * Delete critical CSS for a template.
     *
     * ## OPTIONS
     *
     * --template=<template>
     * : Template slug to delete.
     *
     * ## EXAMPLES
     *
     *     wp cache-party delete-critical --template=front-page
     *
     * @subcommand delete-critical
     */
    public function delete_critical( $args, $assoc_args ) {
        $template = $assoc_args['template'] ?? '';
        if ( ! $template ) {
            \WP_CLI::error( 'Please provide --template.' );
            return;
        }

        Critical_CSS::delete_critical_css( $template );
        \WP_CLI::success( sprintf( 'Deleted critical CSS for "%s".', $template ) );
    }

    /**
     * Parse a dimensions string like "412x896,1300x900" into an array.
     */
    private static function parse_dimensions( $str ) {
        $dims = [];
        foreach ( explode( ',', $str ) as $pair ) {
            $pair = trim( $pair );
            if ( preg_match( '/^(\d+)x(\d+)$/i', $pair, $m ) ) {
                $dims[] = [ 'width' => (int) $m[1], 'height' => (int) $m[2] ];
            }
        }
        return $dims;
    }
}
