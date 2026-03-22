<?php

namespace CacheParty\Images;

if ( ! defined( 'ABSPATH' ) ) exit;

class Picture_Wrapper {

    private $exclude_keywords;

    public function __construct( $settings ) {
        $this->exclude_keywords = array_filter( array_map( 'trim', explode( "\n", $settings['exclude_keywords'] ?? '' ) ) );

        // Use output buffer to catch ALL images on the page (theme templates,
        // widgets, shortcodes, header, footer) — not just the_content.
        add_action( 'template_redirect', [ $this, 'start_buffer' ], 999 );
    }

    public function start_buffer() {
        // Only run on frontend, skip admin/AJAX/feeds/REST.
        if ( is_admin() || wp_doing_ajax() || is_feed() || defined( 'REST_REQUEST' ) ) {
            return;
        }

        ob_start( [ $this, 'rewrite_images_to_picture' ] );
    }

    public function rewrite_images_to_picture( $content ) {
        if ( empty( $content ) || strpos( $content, '</body>' ) === false ) {
            return $content;
        }

        // Protect existing <picture> elements from double-wrapping.
        $placeholders = [];
        $content = preg_replace_callback(
            '/<picture[^>]*>.*?<\/picture>/is',
            function ( $match ) use ( &$placeholders ) {
                $key = '<!--CP_PICTURE_' . count( $placeholders ) . '-->';
                $placeholders[ $key ] = $match[0];
                return $key;
            },
            $content
        );

        $content = preg_replace_callback(
            '/<img\s[^>]+>/i',
            [ $this, 'maybe_wrap_in_picture' ],
            $content
        );

        if ( ! empty( $placeholders ) ) {
            $content = str_replace(
                array_keys( $placeholders ),
                array_values( $placeholders ),
                $content
            );
        }

        return $content;
    }

    private function maybe_wrap_in_picture( $matches ) {
        $img_tag = $matches[0];

        // Skip if marked to skip.
        if ( strpos( $img_tag, 'data-cp-skip' ) !== false || strpos( $img_tag, 'data-aoc-skip' ) !== false ) {
            return $img_tag;
        }

        if ( ! preg_match( '/\bsrc=["\']([^"\']+)["\']/i', $img_tag, $src_match ) ) {
            return $img_tag;
        }

        $src_url = $src_match[1];

        // Normalize relative URLs to absolute.
        if ( strpos( $src_url, '/' ) === 0 && strpos( $src_url, '//' ) !== 0 ) {
            $src_url = home_url( $src_url );
        }

        if ( ! preg_match( '/\.(jpe?g|png)(\?.*)?$/i', $src_url ) ) {
            return $img_tag;
        }

        if ( preg_match( '/^data:/i', $src_url ) ) {
            return $img_tag;
        }

        // Check exclude keywords.
        foreach ( $this->exclude_keywords as $kw ) {
            if ( $kw !== '' && stripos( $img_tag, $kw ) !== false ) {
                return $img_tag;
            }
        }

        $upload_dir = wp_get_upload_dir();
        $base_url   = $upload_dir['baseurl'];
        $base_path  = $upload_dir['basedir'];
        $site_url   = wp_parse_url( home_url(), PHP_URL_HOST );

        $src_host = wp_parse_url( $src_url, PHP_URL_HOST );
        if ( $src_host && $src_host !== $site_url ) {
            return $img_tag;
        }

        if ( strpos( $src_url, $base_url ) === false ) {
            return $img_tag;
        }

        $src_file  = str_replace( $base_url, $base_path, $src_url );
        $webp_file = preg_replace( '/\.(jpe?g|png)$/i', '.webp', $src_file );
        $webp_url  = preg_replace( '/\.(jpe?g|png)$/i', '.webp', $src_url );

        if ( $webp_file === $src_file || ! file_exists( $webp_file ) ) {
            return $img_tag;
        }

        $source_attrs = 'srcset="' . esc_url( $webp_url ) . '"';

        if ( preg_match( '/\bsrcset=["\']([^"\']+)["\']/i', $img_tag, $srcset_match ) ) {
            $webp_srcset = $this->convert_srcset_to_webp( $srcset_match[1], $base_url, $base_path );
            if ( $webp_srcset ) {
                $source_attrs = 'srcset="' . esc_attr( $webp_srcset ) . '"';
            }
        }

        $sizes_attr = '';
        if ( preg_match( '/\bsizes=["\']([^"\']+)["\']/i', $img_tag, $sizes_match ) ) {
            $sizes_attr = ' sizes="' . esc_attr( $sizes_match[1] ) . '"';
        }

        return '<picture>'
            . '<source type="image/webp" ' . $source_attrs . $sizes_attr . '>'
            . $img_tag
            . '</picture>';
    }

    private function convert_srcset_to_webp( $srcset, $base_url, $base_path ) {
        $entries    = explode( ',', $srcset );
        $webp_parts = [];

        foreach ( $entries as $entry ) {
            $entry = trim( $entry );
            $parts = preg_split( '/\s+/', $entry, 2 );
            $url   = $parts[0];
            $desc  = isset( $parts[1] ) ? $parts[1] : '';

            if ( strpos( $url, $base_url ) === false ) {
                return false;
            }

            $file      = str_replace( $base_url, $base_path, $url );
            $webp_file = preg_replace( '/\.(jpe?g|png)$/i', '.webp', $file );
            $webp_url  = preg_replace( '/\.(jpe?g|png)$/i', '.webp', $url );

            if ( $webp_file === $file || ! file_exists( $webp_file ) ) {
                return false;
            }

            $webp_parts[] = esc_url( $webp_url ) . ( $desc ? ' ' . $desc : '' );
        }

        return empty( $webp_parts ) ? false : implode( ', ', $webp_parts );
    }

    /**
     * Build a <picture> tag for a given image URL and attributes.
     * Used by the cache_party_img() theme helper.
     */
    public static function build_picture_tag( $src_url, $attrs = [] ) {
        $upload_dir = wp_get_upload_dir();
        $base_url   = $upload_dir['baseurl'];
        $base_path  = $upload_dir['basedir'];

        $attr_str = '';
        foreach ( $attrs as $key => $val ) {
            $attr_str .= ' ' . esc_attr( $key ) . '="' . esc_attr( $val ) . '"';
        }
        $img_tag = '<img src="' . esc_url( $src_url ) . '"' . $attr_str . '>';

        if ( ! preg_match( '/\.(jpe?g|png)(\?.*)?$/i', $src_url ) ) {
            return $img_tag;
        }
        if ( strpos( $src_url, $base_url ) === false ) {
            return $img_tag;
        }

        $src_file  = str_replace( $base_url, $base_path, $src_url );
        $webp_file = preg_replace( '/\.(jpe?g|png)$/i', '.webp', $src_file );
        $webp_url  = preg_replace( '/\.(jpe?g|png)$/i', '.webp', $src_url );

        if ( $webp_file === $src_file || ! file_exists( $webp_file ) ) {
            return $img_tag;
        }

        return '<picture>'
            . '<source type="image/webp" srcset="' . esc_url( $webp_url ) . '">'
            . $img_tag
            . '</picture>';
    }
}
