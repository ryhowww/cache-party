<?php

namespace CacheParty\Assets;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * CSS minification wrapper around YUI CSSMin.
 *
 * Hides calc()/min()/max()/clamp() expressions before minification
 * (the YUI compressor incorrectly strips spaces around +/- operators
 * inside these functions) and restores them afterward.
 *
 * Uses the same base64 marker approach as autoptimizeCSSmin.php to
 * ensure expressions survive the minification pipeline.
 *
 * Transplanted from autoptimizeCSSmin.php (AO 3.1.15, GPL).
 */
class CSS_Minifier {

    private $minifier;

    public function __construct() {
        // Load vendor files if not already loaded.
        $vendor = CACHE_PARTY_PATH . 'includes/assets/vendor/yui-cssmin/';
        require_once $vendor . 'Utils.php';
        require_once $vendor . 'Colors.php';
        require_once $vendor . 'Minifier.php';

        $this->minifier = new \CacheParty\Vendor\CssMin\Minifier( true );
    }

    /**
     * Minify a CSS string.
     *
     * @param string $css Raw CSS.
     * @return string Minified CSS.
     */
    public function run( $css ) {
        // Hide calc/min/max/clamp expressions using base64 markers.
        // The marker format %%CALC...%% survives the YUI minifier because
        // it doesn't look like a CSS comment, string, or value.
        $css = CSS_Aggregator::replace_contents_with_marker_if_exists(
            'CALC',
            'calc(',
            '#(calc|min|max|clamp)\([^;}]*\)#m',
            $css
        );

        // Run the YUI minifier.
        $result = $this->minifier->run( $css );

        // Restore calc/min/max/clamp expressions.
        $result = CSS_Aggregator::restore_marked_content( 'CALC', $result );

        return $result;
    }

    /**
     * Static convenience method.
     */
    public static function minify( $css ) {
        $instance = new self();
        return $instance->run( $css );
    }
}
