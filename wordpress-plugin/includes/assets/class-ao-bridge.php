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
     * AO's free version has one defer_inline value for all pages.
     * We merge all generated critical CSS files into a single deduped
     * string so AO covers all template types. Clean-css is not available
     * server-side, but simple concatenation with WordPress still works —
     * duplicate rules are harmless (browser ignores them) and the total
     * size stays manageable since critical CSS is already minimal.
     *
     * Called when critical CSS is generated or deleted, and when AO's
     * cache is purged.
     */
    public static function sync_critical_css_to_ao() {
        $templates = Critical_CSS::list_templates();

        if ( empty( $templates ) ) {
            update_option( 'autoptimize_css_defer_inline', '' );
            return;
        }

        // Prioritize front-page, then merge others.
        $parts = [];
        $front = null;

        foreach ( $templates as $t ) {
            $css = file_get_contents( $t['file'] );
            if ( ! $css ) {
                continue;
            }

            if ( $t['template'] === 'front-page' ) {
                $front = $css;
            } else {
                $parts[] = $css;
            }
        }

        // Front-page first (most common landing page), then others.
        $merged = $front ?: '';
        if ( ! empty( $parts ) ) {
            $merged .= "\n" . implode( "\n", $parts );
        }

        update_option( 'autoptimize_css_defer_inline', $merged );
    }
}
