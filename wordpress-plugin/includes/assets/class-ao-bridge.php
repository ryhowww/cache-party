<?php

namespace CacheParty\Assets;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Bridge between Cache Party and Autoptimize.
 *
 * Responsibilities:
 * - Sync CP's critical CSS into AO's defer_inline option
 * - Set exclusion rules so AO skips CP's elements
 * - Send preload headers after AO finishes
 * - Configure AO cache limits
 *
 * JS delay no longer needs AO Bridge processing — scripts use data-src
 * swap and stay in the DOM. AO sees them without src and skips them.
 * CSS defer is handled natively by AO's "Inline & Defer CSS" feature.
 */
class AO_Bridge {

    private $settings;

    public function __construct( $settings ) {
        $this->settings = $settings;

        // Re-sync critical CSS to AO when AO's cache is purged.
        add_action( 'autoptimize_action_cachepurged', [ __CLASS__, 'sync_critical_css_to_ao' ] );

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

    /**
     * JS exclusions — tell AO to skip our elements.
     * data-cp-skip: our interaction loader and critical CSS
     * data-type="cp-delay": delayed scripts (no src, AO would skip anyway)
     */
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
     * Sync CP's critical CSS files into AO's defer_inline option.
     *
     * Called when critical CSS is generated or deleted, and when AO's
     * cache is purged. Uses front-page CSS as the baseline.
     */
    public static function sync_critical_css_to_ao() {
        $ccss = new Critical_CSS();
        $css = $ccss->get_critical_css( 'front-page' );

        if ( ! $css ) {
            $css = $ccss->get_critical_css( 'default' );
        }

        update_option( 'autoptimize_css_defer_inline', $css ?: '' );
    }
}
