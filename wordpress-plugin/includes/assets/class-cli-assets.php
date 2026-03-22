<?php

namespace CacheParty\Assets;

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
    return;
}

class CLI_Assets {

    /**
     * Generate critical CSS for a template via the Railway headless Chrome service.
     *
     * ## OPTIONS
     *
     * --template=<template>
     * : Template slug (e.g., front-page, single, page, archive, default).
     *
     * --url=<url>
     * : URL to extract critical CSS from.
     *
     * [--viewport=<width>]
     * : Viewport width for above-the-fold detection. Default: 1300.
     *
     * [--service-url=<url>]
     * : Railway service URL. Defaults to warmer API URL from settings.
     *
     * ## EXAMPLES
     *
     *     wp cache-party generate-critical --template=front-page --url=https://example.com/
     *     wp cache-party generate-critical --template=single --url=https://example.com/sample-post/ --viewport=1440
     *
     * @subcommand generate-critical
     */
    public function generate_critical( $args, $assoc_args ) {
        $template = $assoc_args['template'] ?? '';
        $url      = $assoc_args['url'] ?? '';
        $viewport = isset( $assoc_args['viewport'] ) ? (int) $assoc_args['viewport'] : 1300;

        if ( ! $template ) {
            \WP_CLI::error( 'Please provide --template (e.g., front-page, single, page).' );
            return;
        }

        if ( ! $url ) {
            \WP_CLI::error( 'Please provide --url to extract critical CSS from.' );
            return;
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

        $endpoint = rtrim( $service_url, '/' ) . '/api/critical-css';

        \WP_CLI::log( sprintf( 'Requesting critical CSS for template "%s" from %s', $template, $url ) );
        \WP_CLI::log( sprintf( 'Service: %s (viewport: %dpx)', $endpoint, $viewport ) );

        $response = wp_remote_post( $endpoint, [
            'timeout' => 60,
            'body'    => wp_json_encode( [
                'url'            => $url,
                'viewport_width' => $viewport,
            ] ),
            'headers' => [
                'Content-Type' => 'application/json',
            ],
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
        $css  = $data['css'] ?? $body;

        if ( empty( $css ) ) {
            \WP_CLI::warning( 'Service returned empty CSS.' );
            return;
        }

        $saved = Critical_CSS::save_critical_css( $template, $css );
        if ( ! $saved ) {
            \WP_CLI::error( 'Failed to save critical CSS file.' );
            return;
        }

        $path = Critical_CSS::get_css_dir() . '/' . sanitize_file_name( $template ) . '.css';
        \WP_CLI::success( sprintf(
            'Critical CSS saved for "%s" (%s, %s)',
            $template,
            $path,
            size_format( strlen( $css ) )
        ) );
    }

    /**
     * List all generated critical CSS templates.
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

        \WP_CLI::log( '' );
        \WP_CLI::log( 'Critical CSS Templates' );
        \WP_CLI::log( str_repeat( '-', 50 ) );

        foreach ( $templates as $t ) {
            \WP_CLI::log( sprintf(
                '  %-20s %s',
                $t['template'],
                size_format( $t['size'] )
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
}
