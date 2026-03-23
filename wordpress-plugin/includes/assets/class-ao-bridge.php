<?php

namespace CacheParty\Assets;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Bridge between Cache Party and Autoptimize.
 *
 * AO handles CSS aggregation, minification, and defer.
 * CP's critical CSS is synced to AO's defer_inline option as a single
 * merged/deduped string (all templates combined via clean-css on Railway).
 *
 * CP handles: JS delay (data-src swap), image optimization, iframe lazy.
 */
class AO_Bridge {

    private $settings;

    public function __construct( $settings ) {
        $this->settings = $settings;

        // Exclusions: tell AO to skip our marked elements and delayed scripts.
        add_filter( 'autoptimize_filter_js_exclude', [ $this, 'js_exclusions' ], 100000 );
        add_filter( 'autoptimize_filter_css_exclude', [ $this, 'css_exclusions' ], 100000 );

        // Cache size: 1GB max.
        add_filter( 'autoptimize_filter_cachecheck_maxsize', function() {
            return 1 * 1024 * 1024 * 1024;
        } );

        // Don't minify excluded JS.
        add_filter( 'autoptimize_filter_js_minify_excluded', '__return_false' );

        // Don't noptimize our lazy-load JS.
        add_filter( 'autoptimize_filter_imgopt_lazyload_js_noptimize', '__return_empty_string' );

        // Remove data-cfasync from "don't move" list.
        add_filter( 'autoptimize_filter_js_dontmove', function( $dontmove ) {
            return array_diff( $dontmove, [ 'data-cfasync' ] );
        } );

        // After AO minifies: send preload headers.
        add_filter( 'autoptimize_html_after_minify', [ $this, 'after_minify' ] );
    }

    public function js_exclusions() {
        return 'data-cp-skip, data-type="cp-delay", ' . "'no-js','js'";
    }

    public function css_exclusions() {
        return 'data-cp-skip';
    }

    /**
     * After AO minifies: send preload headers for aggregated CSS/JS.
     */
    public function after_minify( $content ) {
        $preload_by_http = ! empty( $this->settings['preload_css_http'] );
        $head = Resource_Hints::send_preload_hints( $content, $preload_by_http );

        if ( $head !== '' ) {
            $content = str_replace(
                [ '<head>', '<head >' ],
                '<head>' . $head,
                $content
            );
        }

        return $content;
    }

    /**
     * Sync merged critical CSS to AO's defer_inline option.
     *
     * Called by CLI after generate-critical --all merges all templates
     * via the Railway merge-css endpoint. Stores the deduped result
     * directly in AO's option.
     */
    public static function set_ao_defer_inline( $css ) {
        update_option( 'autoptimize_css_defer_inline', $css );
    }
}
