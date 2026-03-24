<?php

namespace CacheParty\Assets;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * CSS aggregation pipeline.
 *
 * Collects all <link rel="stylesheet"> and <style> tags from the HTML,
 * concatenates them by media type, rewrites relative URLs, minifies via
 * YUI CSSMin, writes the result to a cache file, and replaces the original
 * tags with a single <link> per media group.
 *
 * Core internals transplanted from autoptimizeStyles.php and autoptimizeBase.php
 * (AO 3.1.15, GPL). Function bodies preserved faithfully.
 * Stripped: CDN rewriting, datauri inlining, font-face CDN handling.
 * Added: CP filter hooks, integration with CP output buffer + cache manager.
 */
class CSS_Aggregator {

    /**
     * Regex to match url() references in CSS, excluding data: URIs.
     * From autoptimizeStyles::ASSETS_REGEX.
     */
    const ASSETS_REGEX = '/url\s*\(\s*(?!["\']?data:)(?![\'|\"]?[\#|\%|])([^)]+)\s*\)([^;},\s]*)/i';

    /**
     * Unique hash for marker system (stays constant per request).
     * Equivalent to AO's AUTOPTIMIZE_HASH constant.
     */
    private static $hash;

    private $settings;
    private $content = '';

    // Collected CSS entries: [ [media_array, path_or_inline], ... ]
    // From autoptimizeStyles::$css
    private $css = [];

    // Aggregated CSS code keyed by media type.
    // From autoptimizeStyles::$csscode
    private $csscode = [];

    // Cache URL keyed by media type.
    // From autoptimizeStyles::$url
    private $url = [];

    // MD5 hashmap: md5(minified) => md5(unminified).
    // From autoptimizeStyles::$hashmap
    private $hashmap = [];

    // From autoptimizeStyles::$alreadyminified
    private $alreadyminified = false;

    // Configuration (from autoptimizeStyles properties).
    private $aggregate       = true;
    private $include_inline  = true;
    private $inject_min_late = true;
    private $minify_excluded = true;
    private $dontmove        = [];
    private $cssremovables   = [];
    private $allowlist       = [];

    public function __construct( $settings ) {
        $this->settings = $settings;

        if ( ! self::$hash ) {
            self::$hash = wp_hash( 'cache-party-markers' );
        }
    }

    /**
     * Output buffer processor — called by Output_Buffer at priority 1.
     */
    public function process_buffer( $content ) {
        if ( strpos( $content, '</head>' ) === false ) {
            return $content;
        }

        if ( apply_filters( 'cp_filter_css_noptimize', false, $content ) ) {
            return $content;
        }

        $this->content = $content;

        if ( ! $this->read() ) {
            return $this->content;
        }

        $this->minify_css();
        $this->cache_css();
        return $this->getcontent();
    }

    // ─── Phase 1: Read & Extract ─────────────────────────────────
    // Transplanted from autoptimizeStyles::read()

    private function read() {
        $this->aggregate       = (bool) ( $this->settings['css_aggregate_enabled'] ?? true );
        $this->include_inline  = (bool) ( $this->settings['css_aggregate_inline'] ?? true );
        $this->minify_excluded = (bool) ( $this->settings['css_minify_excluded'] ?? true );

        $this->aggregate = apply_filters( 'cp_filter_css_aggregate', $this->aggregate );

        if ( ! $this->aggregate ) {
            return false;
        }

        // Exclusions.
        $exclude_css = $this->settings['css_exclude'] ?? '';
        $exclude_css = apply_filters( 'cp_filter_css_exclude', $exclude_css, $this->content );
        if ( '' !== $exclude_css ) {
            $this->dontmove = array_filter( array_map( 'trim', explode( ',', $exclude_css ) ) );
        }

        // Hardcoded exclusions (from AO + CP).
        $this->dontmove[] = 'data-noptimize';
        $this->dontmove[] = 'data-cp-skip';
        $this->dontmove[] = '.wp-container-';

        // Removables.
        $removable_css = apply_filters( 'cp_filter_css_removables', '' );
        if ( ! empty( $removable_css ) ) {
            $this->cssremovables = array_filter( array_map( 'trim', explode( ',', $removable_css ) ) );
        }

        // === Content protection (from autoptimizeBase) ===

        // Hide noptimize sections.
        $this->content = self::replace_contents_with_marker_if_exists(
            'NOPTIMIZE',
            '/<!--\s?noptimize\s?-->/',
            '#<!--\s?noptimize\s?-->.*?<!--\s?/\s?noptimize\s?-->#is',
            $this->content
        );

        // Hide (no)script tags — CSS inside scripts must not be processed.
        $this->content = self::replace_contents_with_marker_if_exists(
            'SCRIPT',
            '<script',
            '#<(?:no)?script.*?<\/(?:no)?script>#is',
            $this->content
        );

        // Hide IE conditional comments.
        $this->content = self::replace_contents_with_marker_if_exists(
            'IEHACK',
            '<!--[if',
            '#<!--\[if.*?\[endif\]-->#is',
            $this->content
        );

        // Hide HTML comments.
        $this->content = self::replace_contents_with_marker_if_exists(
            'COMMENTS',
            '<!--',
            '#<!--.*?-->#is',
            $this->content
        );

        // === Extract CSS tags (from autoptimizeStyles::read()) ===

        if ( ! preg_match_all( '#(<style[^>]*>.*</style>)|(<link[^>]*stylesheet[^>]*>)#Usmi', $this->content, $matches ) ) {
            $this->restore_all();
            return false;
        }

        foreach ( $matches[0] as $tag ) {
            if ( $this->isremovable( $tag, $this->cssremovables ) ) {
                $this->content = str_replace( $tag, '', $this->content );
            } elseif ( $this->ismovable( $tag ) ) {
                // Get the media attribute.
                if ( false !== strpos( $tag, 'media=' ) ) {
                    preg_match( '#media=(?:"|\')([^>]*)(?:"|\')#Ui', $tag, $medias );
                    if ( ! empty( $medias ) ) {
                        $medias = explode( ',', $medias[1] );
                        $media  = array();
                        foreach ( $medias as $elem ) {
                            if ( empty( $elem ) ) {
                                $elem = 'all';
                            }
                            $media[] = $elem;
                        }
                    } else {
                        $media = array( 'all' );
                    }
                } else {
                    $media = array( 'all' );
                }

                if ( preg_match( '#<link.*href=("|\')(.*)("|\')#Usmi', $tag, $source ) ) {
                    // <link> tag.
                    $url  = current( explode( '?', $source[2], 2 ) );
                    $path = $this->getpath( $url );

                    if ( false !== $path && preg_match( '#\.css$#', $path ) ) {
                        // Good local CSS file.
                        $this->css[] = array( $media, $path );
                    } else {
                        // Dynamic/external — leave in place, don't remove.
                        $tag = '';
                    }
                } else {
                    // Inline <style> tag.
                    // Restore comments first (inline CSS may be wrapped in comment tags).
                    $tag_restored = self::restore_marked_content( 'COMMENTS', $tag );
                    preg_match( '#<style.*>(.*)</style>#Usmi', $tag_restored, $code );

                    if ( $this->include_inline && ! empty( $code[1] ) ) {
                        $code        = preg_replace( '#^.*<!\[CDATA\[(?:\s*\*/)?(.*)(?://|/\*)\s*?\]\]>.*$#sm', '$1', $code[1] );
                        $this->css[] = array( $media, 'INLINE;' . $code );
                    } else {
                        $tag = '';
                    }
                }

                // Remove the original tag from content.
                if ( '' !== $tag ) {
                    $this->content = str_replace( $tag, '', $this->content );
                }
            } else {
                // Excluded CSS — optionally minify individually.
                if ( preg_match( '#<link.*href=("|\')(.*)("|\')#Usmi', $tag, $source ) ) {
                    $url     = current( explode( '?', $source[2], 2 ) );
                    $path    = $this->getpath( $url );
                    $new_tag = $tag;

                    if ( $path && $this->minify_excluded ) {
                        $minified_url = $this->minify_single( $path );
                        if ( ! empty( $minified_url ) ) {
                            $new_tag = str_replace( $url, $minified_url, $tag );
                        }
                    }

                    if ( '' !== $new_tag && $new_tag !== $tag ) {
                        $this->content = str_replace( $tag, $new_tag, $this->content );
                    }
                }
            }
        }

        return ! empty( $this->css );
    }

    // ─── Phase 2: Minify (concat + @import + minify) ─────────────
    // Transplanted from autoptimizeStyles::minify()

    private function minify_css() {
        // Concatenate CSS by media group, apply fixurls, late-inject markers.
        foreach ( $this->css as $group ) {
            list( $media, $css ) = $group;
            if ( preg_match( '#^INLINE;#', $css ) ) {
                // <style> inline CSS.
                $css = preg_replace( '#^INLINE;#', '', $css );
                $css = self::fixurls( ABSPATH . 'index.php', $css );
            } else {
                // <link> external CSS file.
                if ( false !== $css && file_exists( $css ) && is_readable( $css ) ) {
                    $css_path = $css;
                    $css      = self::fixurls( $css_path, file_get_contents( $css_path ) );
                    $css      = preg_replace( '/\x{EF}\x{BB}\x{BF}/', '', $css ); // BOM

                    if ( $this->can_inject_late( $css_path, $css ) ) {
                        $css = self::build_injectlater_marker( $css_path, md5( $css ) );
                    }
                } else {
                    $css = '';
                }
            }

            foreach ( $media as $elem ) {
                if ( ! empty( $css ) ) {
                    if ( ! isset( $this->csscode[ $elem ] ) ) {
                        $this->csscode[ $elem ] = '';
                    }
                    $this->csscode[ $elem ] .= "\n/*FILESTART*/" . $css;
                }
            }
        }

        // Deduplicate media groups with identical content.
        $md5list = array();
        $tmpcss  = $this->csscode;
        foreach ( $tmpcss as $media => $code ) {
            $md5sum    = md5( $code );
            $medianame = $media;
            foreach ( $md5list as $med => $sum ) {
                if ( $sum === $md5sum ) {
                    $medianame                   = $med . ', ' . $media;
                    $this->csscode[ $medianame ] = $code;
                    $md5list[ $medianame ]       = $md5list[ $med ];
                    unset( $this->csscode[ $med ], $this->csscode[ $media ], $md5list[ $med ] );
                }
            }
            $md5list[ $medianame ] = $md5sum;
        }
        unset( $tmpcss );

        // Resolve @import statements (recursive while loop from AO).
        foreach ( $this->csscode as &$thiscss ) {
            $fiximports       = false;
            $external_imports = '';

            $thiscss_nocomments = preg_replace( '#/\*.*\*/#Us', '', $thiscss );
            while ( preg_match_all( '#@import +(?:url)?(?:(?:\((["\']?)(?:[^"\')]+)\1\)|(["\'])(?:[^"\']+)\2)(?:[^,;"\']+(?:,[^,;"\']+)*)?)(?:;)#mi', $thiscss_nocomments, $import_matches ) ) {
                foreach ( $import_matches[0] as $import ) {
                    if ( $this->isremovable( $import, $this->cssremovables ) ) {
                        $thiscss   = str_replace( $import, '', $thiscss );
                        $import_ok = true;
                    } else {
                        $url       = trim( preg_replace( '#^.*((?:https?:|ftp:)?//.*\.css).*$#', '$1', trim( $import ) ), " \t\n\r\0\x0B\"'" );
                        $path      = $this->getpath( $url );
                        $import_ok = false;
                        if ( file_exists( $path ) && is_readable( $path ) ) {
                            $code = addcslashes( self::fixurls( $path, file_get_contents( $path ) ), '\\' );
                            $code = preg_replace( '/\x{EF}\x{BB}\x{BF}/', '', $code );

                            if ( $this->can_inject_late( $path, $code ) ) {
                                $code = self::build_injectlater_marker( $path, md5( $code ) );
                            }

                            if ( ! empty( $code ) ) {
                                $tmp_thiscss = str_replace( $import, stripcslashes( $code ), $thiscss );
                                if ( ! empty( $tmp_thiscss ) ) {
                                    $thiscss   = $tmp_thiscss;
                                    $import_ok = true;
                                    unset( $tmp_thiscss );
                                }
                            }
                            unset( $code );
                        }
                    }
                    if ( ! $import_ok ) {
                        $external_imports .= $import;
                        $thiscss    = str_replace( $import, '', $thiscss );
                        $fiximports = true;
                    }
                }
                $thiscss = preg_replace( '#/\*FILESTART\*/#', '', $thiscss );
                $thiscss = preg_replace( '#/\*FILESTART2\*/#', '/*FILESTART*/', $thiscss );
                $thiscss_nocomments = preg_replace( '#/\*.*\*/#Us', '', $thiscss );
            }
            unset( $thiscss_nocomments );

            if ( $fiximports ) {
                $thiscss = $external_imports . $thiscss;
            }
        }
        unset( $thiscss );

        // Minify each media group (from AO's minify() final loop).
        foreach ( $this->csscode as &$code ) {
            $hash   = md5( $code );
            $ccheck = new Cache_Manager( $hash, 'css' );

            if ( $ccheck->check() ) {
                $code                          = $ccheck->retrieve();
                $this->hashmap[ md5( $code ) ] = $hash;
                continue;
            }

            // Minify via YUI CSSMin.
            if ( ! $this->alreadyminified ) {
                if ( apply_filters( 'cp_css_do_minify', true ) ) {
                    $cssmin   = new CSS_Minifier();
                    $tmp_code = trim( $cssmin->run( $code ) );
                    if ( ! empty( $tmp_code ) ) {
                        $code = $tmp_code;
                        unset( $tmp_code );
                    }
                }
            }

            // Bring back INJECTLATER content (already-minified files).
            $code = $this->inject_minified( $code );

            // Post-minify filter.
            $tmp_code = apply_filters( 'cp_css_after_minify', $code );
            if ( ! empty( $tmp_code ) ) {
                $code = $tmp_code;
                unset( $tmp_code );
            }

            $this->hashmap[ md5( $code ) ] = $hash;
        }
        unset( $code );
    }

    // ─── Phase 3: Write Cache Files ──────────────────────────────
    // Transplanted from autoptimizeStyles::cache()

    private function cache_css() {
        foreach ( $this->csscode as $media => $code ) {
            if ( empty( $code ) ) {
                continue;
            }

            $md5   = $this->hashmap[ md5( $code ) ];
            $cache = new Cache_Manager( $md5, 'css' );

            if ( ! $cache->check() ) {
                $cache->cache( $code, 'text/css' );
            }

            $this->url[ $media ] = $cache->get_url();
        }
    }

    // ─── Phase 4: Reconstruct HTML ───────────────────────────────
    // Transplanted from autoptimizeStyles::getcontent()

    private function getcontent() {
        // Determine injection point.
        $_strpos_ldjson = strpos( $this->content, '<script type="application/ld+json"' );
        if ( false !== $_strpos_ldjson && $_strpos_ldjson < strpos( $this->content, '</head' ) ) {
            $replace_tag = array( '<script type="application/ld+json"', 'before' );
        } else {
            $replace_tag = array( '<title', 'before' );
        }
        $replace_tag = apply_filters( 'cp_filter_css_replacetag', $replace_tag, $this->content );

        foreach ( $this->url as $media => $url ) {
            $link_tag = '<link media="' . esc_attr( $media ) . '" href="' . esc_url( $url ) . '" rel="stylesheet">';
            $this->inject_in_html( $link_tag, $replace_tag );
        }

        // Restore protected content (reverse order of hiding).
        $this->content = self::restore_marked_content( 'COMMENTS', $this->content );
        $this->content = self::restore_marked_content( 'IEHACK', $this->content );
        $this->content = self::restore_marked_content( 'SCRIPT', $this->content );
        $this->content = self::restore_marked_content( 'NOPTIMIZE', $this->content );

        return $this->content;
    }

    // ═══════════════════════════════════════════════════════════════
    // SHARED UTILITIES — transplanted from autoptimizeBase
    // ═══════════════════════════════════════════════════════════════

    /**
     * Replace content matching a regex with base64-encoded markers.
     * Public static so CSS_Minifier can use it for calc() protection.
     *
     * From autoptimizeBase::replace_contents_with_marker_if_exists()
     *
     * @param string $marker  Marker name (e.g. 'CALC', 'SCRIPT').
     * @param string $search  String or regex to search for (strpos or preg_match).
     * @param string $regex   Regex pattern for replacement.
     * @param string $content Content to process.
     * @return string
     */
    public static function replace_contents_with_marker_if_exists( $marker, $search, $regex, $content ) {
        $hash = self::get_hash();

        $found = false;

        // Check if $search is a valid regex or plain string.
        if ( @preg_match( $search, '' ) !== false ) {
            $found = preg_match( $search, $content );
        } else {
            $found = ( false !== strpos( $content, $search ) );
        }

        if ( $found ) {
            $content = preg_replace_callback(
                $regex,
                function ( $matches ) use ( $marker, $hash ) {
                    return '%%' . $marker . $hash . '%%' . base64_encode( $matches[0] ) . '%%' . $marker . '%%';
                },
                $content
            );
        }

        return $content;
    }

    /**
     * Restore base64-encoded markers to their original content.
     * Public static so CSS_Minifier can use it for calc() restoration.
     *
     * From autoptimizeBase::restore_marked_content()
     *
     * @param string $marker  Marker name.
     * @param string $content Content to process.
     * @return string
     */
    public static function restore_marked_content( $marker, $content ) {
        $hash = self::get_hash();

        if ( false !== strpos( $content, $marker ) ) {
            $content = preg_replace_callback(
                '#%%' . $marker . $hash . '%%(.*?)%%' . $marker . '%%#is',
                function ( $matches ) {
                    return base64_decode( $matches[1] );
                },
                $content
            );
        }

        return $content;
    }

    /**
     * Get or initialize the hash used in markers.
     */
    private static function get_hash() {
        if ( ! self::$hash ) {
            self::$hash = wp_hash( 'cache-party-markers' );
        }
        return self::$hash;
    }

    // ═══════════════════════════════════════════════════════════════
    // LATE INJECTION — transplanted from autoptimizeBase
    // ═══════════════════════════════════════════════════════════════

    /**
     * Build an INJECTLATER marker for already-minified files.
     * These are wrapped in /*! ... so they survive CSS minification.
     *
     * From autoptimizeBase::build_injectlater_marker()
     */
    private static function build_injectlater_marker( $filepath, $hash ) {
        $marker_hash = self::get_hash();
        $marker = '%%INJECTLATER' . $marker_hash . '%%' . base64_encode( $filepath ) . '|' . $hash . '%%INJECTLATER%%';
        return '/*!' . $marker . '*/';
    }

    /**
     * Replace INJECTLATER markers with actual file content.
     *
     * From autoptimizeBase::inject_minified()
     */
    private function inject_minified( $in ) {
        if ( false === strpos( $in, '%%INJECTLATER%%' ) ) {
            return $in;
        }

        $hash = self::get_hash();
        return preg_replace_callback(
            '#\/\*\!%%INJECTLATER' . $hash . '%%(.*?)%%INJECTLATER%%\*\/#is',
            [ $this, 'inject_minified_callback' ],
            $in
        );
    }

    /**
     * Callback for inject_minified — reads the file and applies fixurls.
     *
     * From autoptimizeBase::inject_minified_callback()
     */
    public function inject_minified_callback( $matches ) {
        $parts    = explode( '|', $matches[1] );
        $filepath = isset( $parts[0] ) ? base64_decode( $parts[0] ) : null;
        $filehash = isset( $parts[1] ) ? $parts[1] : null;

        if ( ! $filepath || ! $filehash ) {
            return "\n";
        }

        $filecontent = file_get_contents( $filepath );

        // BOM removal.
        $filecontent = preg_replace( "#\x{EF}\x{BB}\x{BF}#", '', $filecontent );

        // Remove non-important comments.
        $filecontent = preg_replace( '#^\s*\/\*[^!].*\*\/\s?#Um', '', $filecontent );

        // Normalize newlines.
        $filecontent = preg_replace( '#(^[\r\n]*|[\r\n]+)[\s\t]*[\r\n]+#', "\n", $filecontent );

        // Fix relative URLs for CSS files.
        if ( '.css' === substr( $filepath, -4, 4 ) ) {
            $filecontent = self::fixurls( $filepath, $filecontent );
        }

        return "\n" . $filecontent;
    }

    /**
     * Check if a file can be late-injected (already minified, safe to skip minification).
     *
     * From autoptimizeStyles::can_inject_late()
     */
    private function can_inject_late( $css_path, $css ) {
        if ( true !== $this->inject_min_late ) {
            return false;
        }

        // File not minified based on filename.
        if ( false === strpos( $css_path, 'min.css' ) ) {
            return false;
        }

        // Files with @import need to go through aggregation.
        if ( false !== strpos( $css, '@import' ) ) {
            return false;
        }

        return true;
    }

    // ═══════════════════════════════════════════════════════════════
    // URL REWRITING — transplanted from autoptimizeStyles::fixurls()
    // ═══════════════════════════════════════════════════════════════

    /**
     * Convert relative url() paths in CSS to absolute paths.
     *
     * When CSS is aggregated into /wp-content/cache/cache-party/css/,
     * relative paths like url(../images/bg.jpg) break. This function
     * rewrites them to absolute paths based on the original CSS file location.
     *
     * Faithfully transplanted from autoptimizeStyles::fixurls().
     *
     * @param string $file Original CSS file path.
     * @param string $code CSS content.
     * @return string CSS with fixed URLs.
     */
    public static function fixurls( $file, $code ) {
        // Switch all imports to the url() syntax.
        $code = preg_replace( '#@import ("|\')(.+?)\.css.*?("|\')#', '@import url("${2}.css")', $code );

        if ( preg_match_all( self::ASSETS_REGEX, $code, $matches ) ) {
            $wp_root     = defined( 'WP_ROOT_DIR' ) ? WP_ROOT_DIR : ABSPATH;
            $wp_root_url = defined( 'AUTOPTIMIZE_WP_ROOT_URL' ) ? AUTOPTIMIZE_WP_ROOT_URL : site_url();

            $file = str_replace( $wp_root, '/', $file );
            $dir  = dirname( $file ); // Like /themes/expound/css
            $dir  = str_replace( '\\', '/', $dir ); // Windows compat
            unset( $file );

            $replace = array();
            foreach ( $matches[1] as $k => $url ) {
                // Remove quotes.
                $url      = trim( $url, " \t\n\r\0\x0B\"'" );
                $no_q_url = trim( $url, "\"'" );
                if ( $url !== $no_q_url ) {
                    $removed_quotes = true;
                } else {
                    $removed_quotes = false;
                }

                if ( '' === $no_q_url ) {
                    continue;
                }

                $url = $no_q_url;
                if ( '/' === $url[0] || preg_match( '#^(https?://|ftp://|data:)#i', $url ) ) {
                    // URL is protocol-relative, host-relative, or something we don't touch.
                    continue;
                } else {
                    // Relative URL — convert to absolute.
                    $newurl = preg_replace( '/https?:/', '', str_replace( ' ', '%20', $wp_root_url . str_replace( '//', '/', $dir . '/' . $url ) ) );
                    $newurl = apply_filters( 'cp_filter_css_fixurl_newurl', $newurl );

                    // Hash the url + whatever was behind for sprite safety.
                    $hash = md5( $url . $matches[2][ $k ] );
                    $code = str_replace( $matches[0][ $k ], $hash, $code );

                    if ( $removed_quotes ) {
                        $replace[ $hash ] = "url('" . $newurl . "')" . $matches[2][ $k ];
                    } else {
                        $replace[ $hash ] = 'url(' . $newurl . ')' . $matches[2][ $k ];
                    }
                }
            }

            $code = self::replace_longest_matches_first( $code, $replace );
        }

        return $code;
    }

    /**
     * Replace strings by replacing the longest matches first.
     * From autoptimizeStyles::replace_longest_matches_first()
     */
    protected static function replace_longest_matches_first( $string, $replacements = array() ) {
        if ( ! empty( $replacements ) ) {
            $keys = array_map( 'strlen', array_keys( $replacements ) );
            array_multisort( $keys, SORT_DESC, $replacements );
            $string = str_replace( array_keys( $replacements ), array_values( $replacements ), $string );
        }
        return $string;
    }

    // ═══════════════════════════════════════════════════════════════
    // URL → LOCAL PATH RESOLUTION
    // Simplified from autoptimizeBase::getpath()
    // ═══════════════════════════════════════════════════════════════

    /**
     * Convert a URL to a local filesystem path.
     *
     * @param string $url URL to resolve.
     * @return string|false Local path or false if external/unreadable.
     */
    private function getpath( $url ) {
        if ( is_null( $url ) ) {
            return false;
        }

        if ( false !== strpos( $url, '%' ) ) {
            $url = urldecode( $url );
        }

        $site_url  = site_url();
        $site_host = parse_url( $site_url, PHP_URL_HOST );

        // Normalize protocol-relative URLs.
        $double_slash_pos = strpos( $url, '//' );
        if ( 0 === $double_slash_pos ) {
            $url = ( is_ssl() ? 'https:' : 'http:' ) . $url;
        } elseif ( false === $double_slash_pos && false === strpos( $url, $site_host ) ) {
            if ( 0 === strpos( $url, '/' ) ) {
                $url = '//' . $site_host . $url;
            } else {
                $url = $site_url . '/' . $url;
            }
        }

        // Hostname check.
        $url_host = @parse_url( $url, PHP_URL_HOST );
        if ( $url_host !== $site_host ) {
            return false;
        }

        // Strip the root URL to get relative path.
        $tmp_root = preg_replace( '/https?:/', '', $site_url );
        $tmp_url  = preg_replace( '/https?:/', '', $url );
        $path     = str_replace( $tmp_root, '', $tmp_url );

        // External URL check (still contains //).
        if ( preg_match( '#^:?//#', $path ) ) {
            return false;
        }

        $wp_root = defined( 'WP_ROOT_DIR' ) ? WP_ROOT_DIR : ABSPATH;
        $path    = str_replace( '//', '/', trailingslashit( $wp_root ) . $path );

        if ( file_exists( $path ) && is_file( $path ) && is_readable( $path ) ) {
            return $path;
        }

        return false;
    }

    // ═══════════════════════════════════════════════════════════════
    // INDIVIDUAL CSS MINIFICATION
    // From autoptimizeStyles::minify_single() + autoptimizeBase::prepare_minify_single()
    // ═══════════════════════════════════════════════════════════════

    /**
     * Minify a single excluded CSS file and return its cached URL.
     */
    private function minify_single( $filepath ) {
        // Skip if already minified (from autoptimizeBase::prepare_minify_single).
        if ( preg_match( '#[-.]min\.css$#', $filepath ) ) {
            return false;
        }

        $contents = file_get_contents( $filepath );
        if ( empty( $contents ) ) {
            return false;
        }

        $hash  = 'single_' . md5( $contents );
        $cache = new Cache_Manager( $hash, 'css' );

        if ( ! $cache->check() ) {
            $contents = self::fixurls( $filepath, $contents );

            $cssmin   = new CSS_Minifier();
            $contents = trim( $cssmin->run( $contents ) );

            if ( empty( $contents ) ) {
                return false;
            }

            $cache->cache( $contents, 'text/css' );
        }

        return $cache->get_url();
    }

    // ═══════════════════════════════════════════════════════════════
    // EXCLUSION CHECKS — from autoptimizeBase + autoptimizeStyles
    // ═══════════════════════════════════════════════════════════════

    /**
     * From autoptimizeBase::isremovable()
     */
    private function isremovable( $tag, $removables ) {
        foreach ( $removables as $match ) {
            if ( false !== strpos( $tag, $match ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * From autoptimizeStyles::ismovable()
     */
    private function ismovable( $tag ) {
        if ( ! $this->aggregate ) {
            return false;
        }

        if ( ! empty( $this->allowlist ) ) {
            foreach ( $this->allowlist as $match ) {
                if ( false !== strpos( $tag, $match ) ) {
                    return true;
                }
            }
            return false;
        } else {
            if ( is_array( $this->dontmove ) && ! empty( $this->dontmove ) ) {
                foreach ( $this->dontmove as $match ) {
                    if ( false !== strpos( $tag, $match ) ) {
                        return false;
                    }
                }
            }
            return true;
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // HTML INJECTION — from autoptimizeBase::inject_in_html()
    // ═══════════════════════════════════════════════════════════════

    private function inject_in_html( $payload, $where ) {
        $position = strpos( $this->content, $where[0] );
        if ( false !== $position ) {
            if ( 'after' === $where[1] ) {
                $content = $where[0] . $payload;
            } elseif ( 'replace' === $where[1] ) {
                $content = $payload;
            } else {
                $content = $payload . $where[0];
            }
            $this->content = substr_replace( $this->content, $content, $position, strlen( $where[0] ) );
        } else {
            // Fallback: inject before </head>.
            $this->content = str_replace( '</head>', $payload . '</head>', $this->content );
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // RESTORE ALL — convenience
    // ═══════════════════════════════════════════════════════════════

    private function restore_all() {
        $this->content = self::restore_marked_content( 'COMMENTS', $this->content );
        $this->content = self::restore_marked_content( 'IEHACK', $this->content );
        $this->content = self::restore_marked_content( 'SCRIPT', $this->content );
        $this->content = self::restore_marked_content( 'NOPTIMIZE', $this->content );
    }
}
