<?php

namespace CacheParty\Assets;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Bridge between Cache Party and Autoptimize.
 *
 * Hooks into Autoptimize's filter pipeline to:
 * - Process deferred CSS/delayed JS through AO's minification
 * - Set exclusion rules (data-cp-skip, data-aoc-skip)
 * - Configure AO cache limits
 * - Send preload headers after minification
 */
class AO_Bridge {

    private $settings;
    private $deferred_styles_tag  = '';
    private $delayed_scripts_tag  = '';

    public function __construct( $settings ) {
        $this->settings = $settings;

        // Exclusions: tell AO to skip our marked elements.
        add_filter( 'autoptimize_filter_js_exclude', [ $this, 'js_exclusions' ], 100000 );
        add_filter( 'autoptimize_filter_css_exclude', [ $this, 'css_exclusions' ], 100000 );

        // Cache size: 1GB max.
        add_filter( 'autoptimize_filter_cachecheck_maxsize', function() {
            return 1 * 1024 * 1024 * 1024;
        } );

        // Don't minify excluded JS (let AO handle it).
        add_filter( 'autoptimize_filter_js_minify_excluded', '__return_false' );

        // Don't noptimize our lazy-load JS.
        add_filter( 'autoptimize_filter_imgopt_lazyload_js_noptimize', '__return_empty_string' );

        // Remove data-cfasync from "don't move" list.
        add_filter( 'autoptimize_filter_js_dontmove', function( $dontmove ) {
            return array_diff( $dontmove, [ 'data-cfasync' ] );
        } );

        // Before minify: extract deferred/delayed blocks, process through AO.
        add_filter( 'autoptimize_filter_html_before_minify', [ $this, 'before_minify' ] );

        // After minify: reinject blocks and send preload headers.
        add_filter( 'autoptimize_html_after_minify', [ $this, 'after_minify' ] );
    }

    public function js_exclusions() {
        return 'data-aoc=skip, data-aoc-skip, data-cp-skip, ' . "'no-js','js'";
    }

    public function css_exclusions() {
        return 'data-aoc=skip, data-aoc-skip, data-cp-skip';
    }

    /**
     * Before AO minifies: extract noscript blocks, process their content
     * through AO's own CSS/JS pipeline, then remove from main content.
     */
    public function before_minify( $content ) {
        // Process deferred styles through AO's CSS pipeline.
        $content = preg_replace_callback(
            '#<noscript id="deferred-styles">(.*)</noscript>#Usmi',
            function( $matches ) {
                $this->deferred_styles_tag = '<noscript id="deferred-styles">'
                    . self::ao_do_css( $matches[1] )
                    . '</noscript>';
                return '';
            },
            $content
        );

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
     * After AO minifies: reinject processed blocks and send preload headers.
     */
    public function after_minify( $content ) {
        $preload_by_http = ! empty( $this->settings['preload_css_http'] );
        $head = Resource_Hints::send_preload_hints( $content, $preload_by_http );

        $content = str_replace(
            [ '<head>', '<head >' ],
            '<head>' . $head,
            $content
        );

        $content = str_replace(
            '</body>',
            "\r\n" . $this->deferred_styles_tag . "\r\n" . $this->delayed_scripts_tag . '</body>',
            $content
        );

        return $content;
    }

    /* ---------------------------------------------------------------
     *  AO Helper methods — process content through Autoptimize
     * ------------------------------------------------------------- */

    private static function ao_css_options( $conf ) {
        return [
            'aggregate'       => $conf->get( 'autoptimize_css_aggregate' ),
            'justhead'        => $conf->get( 'autoptimize_css_justhead' ),
            'datauris'        => $conf->get( 'autoptimize_css_datauris' ),
            'defer'           => $conf->get( 'autoptimize_css_defer' ),
            'defer_inline'    => $conf->get( 'autoptimize_css_defer_inline' ),
            'inline'          => $conf->get( 'autoptimize_css_inline' ),
            'css_exclude'     => $conf->get( 'autoptimize_css_exclude' ),
            'cdn_url'         => $conf->get( 'autoptimize_cdn_url' ),
            'include_inline'  => $conf->get( 'autoptimize_css_include_inline' ),
            'nogooglefont'    => $conf->get( 'autoptimize_css_nogooglefont' ),
            'minify_excluded' => $conf->get( 'autoptimize_minify_excluded' ),
        ];
    }

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

    public static function ao_do_css( $content ) {
        if ( ! class_exists( 'autoptimizeConfig' ) ) {
            return $content;
        }

        $conf = \autoptimizeConfig::instance();
        if ( $conf->get( 'autoptimize_css' ) ) {
            $options  = self::ao_css_options( $conf );
            $instance = new \autoptimizeStyles( $content );
            if ( $instance->read( $options ) ) {
                $instance->minify();
                $instance->cache();
                $content = $instance->getcontent();
            }
            unset( $instance );
        }

        return $content;
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
