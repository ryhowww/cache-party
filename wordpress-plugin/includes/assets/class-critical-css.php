<?php

namespace CacheParty\Assets;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Template-based critical CSS.
 *
 * Static CSS files stored in wp-content/uploads/cache-party/critical-css/{template}.css.
 *
 * When AO's "Inline & Defer CSS" is active, the AO_Bridge feeds our critical
 * CSS into AO's defer_inline filter — AO handles the inlining. We skip our
 * own wp_head injection to avoid double-inlining.
 *
 * When AO is not active (or AO defer is off), we inline directly via wp_head.
 *
 * Generate via WP-CLI:
 *   wp cache-party generate-critical --template=front-page --url=https://example.com/
 *
 * Or via admin UI "Generate" button (sends request to Railway headless Chrome service).
 */
class Critical_CSS {

    const CACHE_DIR = 'cache-party/critical-css';

    public function __construct() {
        // Skip wp_head inlining when AO handles it via defer_inline.
        if ( ! self::ao_defer_active() ) {
            add_action( 'wp_head', [ $this, 'inline_critical_css' ], 2 );
        }
    }

    /**
     * Check if AO's "Inline & Defer CSS" is active.
     * When it is, AO_Bridge feeds our critical CSS into AO's pipeline.
     */
    public static function ao_defer_active() {
        return defined( 'AUTOPTIMIZE_PLUGIN_VERSION' )
            && get_option( 'autoptimize_css_defer', '' ) === 'on';
    }

    /**
     * Detect current template and inline matching critical CSS.
     * Only runs when AO defer is NOT active.
     */
    public function inline_critical_css() {
        $template = $this->detect_template();
        $css      = $this->get_critical_css( $template );

        if ( ! $css ) {
            $css = $this->get_critical_css( 'default' );
        }

        if ( $css ) {
            echo '<!--noptimize--><style id="cp-critical-css" data-template="' . esc_attr( $template ) . '" data-cp-skip>' . "\n" . $css . "\n" . '</style><!--/noptimize-->' . "\n";
        }
    }

    /**
     * Detect the current WordPress template type.
     */
    public function detect_template() {
        if ( is_page_template() ) {
            $slug = get_page_template_slug();
            if ( $slug ) {
                $slug = sanitize_file_name( pathinfo( $slug, PATHINFO_FILENAME ) );
                if ( $this->has_critical_css( $slug ) ) {
                    return $slug;
                }
            }
        }

        if ( is_front_page() && $this->has_critical_css( 'front-page' ) ) {
            return 'front-page';
        }

        if ( is_home() && $this->has_critical_css( 'home' ) ) {
            return 'home';
        }

        if ( is_singular() ) {
            $type = get_post_type();
            if ( $type && $this->has_critical_css( 'single-' . $type ) ) {
                return 'single-' . $type;
            }
            if ( $this->has_critical_css( 'single' ) ) {
                return 'single';
            }
        }

        if ( is_page() && $this->has_critical_css( 'page' ) ) {
            return 'page';
        }

        if ( is_archive() ) {
            if ( is_category() && $this->has_critical_css( 'category' ) ) {
                return 'category';
            }
            if ( is_tag() && $this->has_critical_css( 'tag' ) ) {
                return 'tag';
            }
            if ( $this->has_critical_css( 'archive' ) ) {
                return 'archive';
            }
        }

        if ( is_search() && $this->has_critical_css( 'search' ) ) {
            return 'search';
        }

        if ( is_404() && $this->has_critical_css( '404' ) ) {
            return '404';
        }

        return 'default';
    }

    public function get_critical_css( $template ) {
        $file = $this->get_css_path( $template );
        if ( $file && file_exists( $file ) ) {
            return file_get_contents( $file );
        }
        return false;
    }

    public function has_critical_css( $template ) {
        $file = $this->get_css_path( $template );
        return $file && file_exists( $file );
    }

    public function get_css_path( $template ) {
        $template = sanitize_file_name( $template );
        if ( ! $template ) {
            return false;
        }
        $upload_dir = wp_get_upload_dir();
        return $upload_dir['basedir'] . '/' . self::CACHE_DIR . '/' . $template . '.css';
    }

    public static function get_css_dir() {
        $upload_dir = wp_get_upload_dir();
        return $upload_dir['basedir'] . '/' . self::CACHE_DIR;
    }

    /**
     * Save critical CSS and optional metadata for a template.
     *
     * @param string $template Template slug.
     * @param string $css      CSS content.
     * @param array  $meta     Optional metadata (dimensions, source_url, etc.).
     * @return bool
     */
    public static function save_critical_css( $template, $css, $meta = [] ) {
        $dir = self::get_css_dir();
        if ( ! wp_mkdir_p( $dir ) ) {
            return false;
        }

        $template = sanitize_file_name( $template );
        $file     = $dir . '/' . $template . '.css';

        $saved = (bool) file_put_contents( $file, $css );

        if ( $saved ) {
            // Store metadata alongside the CSS.
            $meta = array_merge( [
                'template'     => $template,
                'generated_at' => gmdate( 'c' ),
                'size_kb'      => round( strlen( $css ) / 1024 ),
            ], $meta );

            $all_meta = get_option( 'cache_party_critical_meta', [] );
            $all_meta[ $template ] = $meta;
            update_option( 'cache_party_critical_meta', $all_meta, false );

            // Sync to AO if active.
            if ( self::ao_defer_active() ) {
                AO_Bridge::sync_critical_css_to_ao();
            }
        }

        return $saved;
    }

    public static function delete_critical_css( $template ) {
        $dir      = self::get_css_dir();
        $template = sanitize_file_name( $template );
        $file     = $dir . '/' . $template . '.css';

        if ( file_exists( $file ) ) {
            wp_delete_file( $file );
        }

        // Re-sync to AO after deletion.
        if ( self::ao_defer_active() ) {
            AO_Bridge::sync_critical_css_to_ao();
        }
    }

    public static function list_templates() {
        $dir  = self::get_css_dir();
        $list = [];

        if ( ! is_dir( $dir ) ) {
            return $list;
        }

        foreach ( glob( $dir . '/*.css' ) as $file ) {
            $list[] = [
                'template' => pathinfo( $file, PATHINFO_FILENAME ),
                'file'     => $file,
                'size'     => filesize( $file ),
            ];
        }

        return $list;
    }
}
