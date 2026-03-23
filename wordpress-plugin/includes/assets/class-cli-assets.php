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
     * --template=<template>
     * : Template slug (e.g., front-page, single, page, archive, default).
     *
     * --url=<url>
     * : URL to extract critical CSS from.
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
     *     wp cache-party generate-critical --template=page --url=https://example.com/about/ --dimensions=412x896,1300x900
     *
     * @subcommand generate-critical
     */
    public function generate_critical( $args, $assoc_args ) {
        $template = $assoc_args['template'] ?? '';
        $url      = $assoc_args['url'] ?? '';

        if ( ! $template ) {
            \WP_CLI::error( 'Please provide --template (e.g., front-page, single, page).' );
            return;
        }

        if ( ! $url ) {
            \WP_CLI::error( 'Please provide --url to extract critical CSS from.' );
            return;
        }

        // Parse dimensions.
        if ( ! empty( $assoc_args['dimensions'] ) ) {
            $dimensions = self::parse_dimensions( $assoc_args['dimensions'] );
        } else {
            $dimensions = Asset_Optimizer::get_critical_dimensions();
        }

        // Determine service URL.
        $service_url = $assoc_args['service-url'] ?? '';
        if ( ! $service_url ) {
            $warmer_settings = get_option( 'cache_party_warmer', [] );
            $service_url     = $warmer_settings['api_url'] ?? '';
        }

        if ( ! $service_url ) {
            \WP_CLI::error( 'No service URL configured. Use --service-url or set the warmer API URL in settings.' );
            return;
        }

        // Auth token.
        $warmer_settings = get_option( 'cache_party_warmer', [] );
        $api_key         = $warmer_settings['api_key'] ?? '';
        $headers         = [ 'Content-Type' => 'application/json' ];
        if ( $api_key ) {
            $headers['Authorization'] = 'Bearer ' . $api_key;
        }

        $endpoint    = rtrim( $service_url, '/' ) . '/api/critical-css';
        $dim_display = implode( ', ', array_map( function( $d ) {
            return $d['width'] . 'x' . $d['height'];
        }, $dimensions ) );

        \WP_CLI::log( sprintf( 'Template: %s', $template ) );
        \WP_CLI::log( sprintf( 'URL: %s', $url ) );
        \WP_CLI::log( sprintf( 'Viewports: %s', $dim_display ) );
        \WP_CLI::log( sprintf( 'Service: %s', $endpoint ) );
        \WP_CLI::log( '' );

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
            \WP_CLI::error( 'Request failed: ' . $response->get_error_message() );
            return;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );

        if ( $code !== 200 ) {
            \WP_CLI::error( sprintf( 'Service returned HTTP %d: %s', $code, $body ) );
            return;
        }

        $data = json_decode( $body, true );
        $css  = $data['css'] ?? '';

        if ( empty( $css ) ) {
            \WP_CLI::warning( 'Service returned empty CSS.' );
            return;
        }

        $meta = [
            'dimensions' => $data['dimensions'] ?? [],
            'source_url' => $url,
        ];

        $saved = Critical_CSS::save_critical_css( $template, $css, $meta );
        if ( ! $saved ) {
            \WP_CLI::error( 'Failed to save critical CSS file.' );
            return;
        }

        $path = Critical_CSS::get_css_dir() . '/' . sanitize_file_name( $template ) . '.css';
        \WP_CLI::success( sprintf(
            'Saved "%s" — %s (%s)',
            $template,
            size_format( strlen( $css ) ),
            $dim_display
        ) );
    }

    /**
     * List all generated critical CSS templates with metadata.
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
        \WP_CLI::log( 'Critical CSS Templates' );
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
