<?php

namespace CacheParty\Assets;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Bridge between Cache Party and Autoptimize.
 *
 * Responsibilities:
 * - Feed CP's critical CSS into AO's defer_inline (so AO inlines it)
 * - Set exclusion rules (data-cp-skip) so AO skips our elements
 * - Process delayed JS through AO's minification pipeline
 * - Send preload headers after AO finishes
 * - Configure AO cache limits
 */
class AO_Bridge {

    private $settings;
    private $delayed_scripts_tag = '';

    public function __construct( $settings ) {
        $this->settings = $settings;

        // Sync critical CSS to AO's defer_inline on activation/settings change.
        // Uses the static option approach — when CP generates critical CSS,
        // save_critical_css() calls sync_critical_css_to_ao() to update
        // AO's defer_inline option. No per-request filter needed.
        add_action( 'autoptimize_action_cachepurged', [ __CLASS__, 'sync_critical_css_to_ao' ] );

        // Exclusions: tell AO to skip our marked elements.
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

        // Before minify: extract delayed scripts, process through AO JS pipeline.
        add_filter( 'autoptimize_filter_html_before_minify', [ $this, 'before_minify' ] );

        // After minify: reinject delayed scripts and send preload headers.
        add_filter( 'autoptimize_html_after_minify', [ $this, 'after_minify' ] );
    }

    public function js_exclusions() {
        return 'data-cp-skip, ' . "'no-js','js'";
    }

    public function css_exclusions() {
        return 'data-cp-skip';
    }

    /**
     * Before AO minifies: extract delayed scripts noscript block,
     * process through AO's JS pipeline.
     *
     * CSS is no longer extracted here — AO handles CSS defer natively.
     */
    public function before_minify( $content ) {
        // Process delayed scripts through AO's JS pipeline.
        $content = preg_replace_callback(
            '#<noscript id="delayed-scripts">(.*)</noscript>#Usmi',
            function( $matches ) {
                $this->delayed_scripts_tag = '<noscript id="delayed-scripts">'
                    . self::ao_do_js( $matches[1] )
                    . '</noscript>';
                return '';
            },
            $content
        );

        return $content;
    }

    /**
     * After AO minifies: reinject delayed scripts and send preload headers.
     */
    public function after_minify( $content ) {
        $preload_by_http = ! empty( $this->settings['preload_css_http'] );
        $head = Resource_Hints::send_preload_hints( $content, $preload_by_http );

        $content = str_replace(
            [ '<head>', '<head >' ],
            '<head>' . $head,
            $content
        );

        if ( $this->delayed_scripts_tag !== '' ) {
            $content = str_replace(
                '</body>',
                "\r\n" . $this->delayed_scripts_tag . '</body>',
                $content
            );
        }

        return $content;
    }

    /**
     * Sync CP's critical CSS files into AO's defer_inline option.
     *
     * Called when critical CSS is generated or deleted, so AO's stored
     * option stays current. Note: the filter-based inject_critical_css()
     * is the primary mechanism — this is for AO's admin UI display.
     */
    public static function sync_critical_css_to_ao() {
        // Use front-page CSS as the baseline for AO's option field.
        $ccss = new Critical_CSS();
        $css = $ccss->get_critical_css( 'front-page' );

        if ( ! $css ) {
            $css = $ccss->get_critical_css( 'default' );
        }

        update_option( 'autoptimize_css_defer_inline', $css ?: '' );
    }

    /* ---------------------------------------------------------------
     *  AO Helper — process content through Autoptimize JS pipeline
     * ------------------------------------------------------------- */

    private static function ao_js_options( $conf ) {
        return [
            'aggregate'           => $conf->get( 'autoptimize_js_aggregate' ),
            'defer_not_aggregate' => $conf->get( 'autoptimize_js_defer_not_aggregate' ),
            'defer_inline'        => $conf->get( 'autoptimize_js_defer_inline' ),
            'justhead'            => $conf->get( 'autoptimize_js_justhead' ),
            'forcehead'           => $conf->get( 'autoptimize_js_forcehead' ),
            'trycatch'            => $conf->get( 'autoptimize_js_trycatch' ),
            'js_exclude'          => $conf->get( 'autoptimize_js_exclude' ),
            'cdn_url'             => $conf->get( 'autoptimize_cdn_url' ),
            'include_inline'      => $conf->get( 'autoptimize_js_include_inline' ),
            'minify_excluded'     => $conf->get( 'autoptimize_minify_excluded' ),
        ];
    }

    public static function ao_do_js( $content ) {
        if ( ! class_exists( 'autoptimizeConfig' ) ) {
            return $content;
        }

        $conf = \autoptimizeConfig::instance();
        if ( $conf->get( 'autoptimize_js' ) ) {
            $options  = self::ao_js_options( $conf );
            $instance = new \autoptimizeScripts( '<body>' . $content . '</body>' );
            if ( $instance->read( $options ) ) {
                $instance->minify();
                $instance->cache();
                $content = str_replace( [ '<body>', '</body>' ], '', $instance->getcontent() );
            }
            unset( $instance );
        }

        return $content;
    }
}
