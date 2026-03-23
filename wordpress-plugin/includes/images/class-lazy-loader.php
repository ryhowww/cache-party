<?php

namespace CacheParty\Images;

if ( ! defined( 'ABSPATH' ) ) exit;

class Lazy_Loader {

    private $eager_count;
    private $exclude_keywords;

    /**
     * Static counter that persists across all the_content calls on a page
     * (e.g., archive pages with multiple posts). The first N images on the
     * entire page are eager, not the first N per post.
     */
    private static $image_index = 0;

    public function __construct( $settings ) {
        $this->eager_count      = isset( $settings['eager_count'] ) ? (int) $settings['eager_count'] : 2;
        $this->exclude_keywords = array_filter( array_map( 'trim', explode( "\n", $settings['exclude_keywords'] ?? '' ) ) );
    }

    public function process_images( $content ) {
        if ( empty( $content ) || strpos( $content, '</body>' ) === false ) {
            return $content;
        }

        return preg_replace_callback(
            '/<img\s[^>]+>/i',
            [ $this, 'process_img_tag' ],
            $content
        );
    }

    private function process_img_tag( $matches ) {
        $img = $matches[0];

        // Skip if marked.
        if ( strpos( $img, 'data-cp-skip' ) !== false || strpos( $img, 'data-aoc-skip' ) !== false ) {
            return $img;
        }

        // Check exclude keywords.
        foreach ( $this->exclude_keywords as $kw ) {
            if ( $kw !== '' && stripos( $img, $kw ) !== false ) {
                return $img;
            }
        }

        // Respect existing eager/fetchpriority set by the template — never downgrade to lazy.
        $has_eager        = preg_match( '/\bloading=["\']eager["\']/i', $img );
        $has_fetchpriority = preg_match( '/\bfetchpriority=["\']high["\']/i', $img );

        if ( $has_eager || $has_fetchpriority ) {
            // Template explicitly wants this image eager — don't touch it, just count it.
            self::$image_index++;
            return $this->maybe_add_dimensions( $img );
        }

        $is_eager = self::$image_index < $this->eager_count;
        self::$image_index++;

        // Remove existing loading attribute (e.g. WP core's default lazy).
        $img = preg_replace( '/\s+loading=["\'][^"\']*["\']/i', '', $img );

        if ( $is_eager ) {
            $img = str_replace( '<img ', '<img loading="eager" fetchpriority="high" ', $img );
        } else {
            $img = str_replace( '<img ', '<img loading="lazy" ', $img );
        }

        // Inject missing width/height from attachment metadata for CLS prevention.
        $img = $this->maybe_add_dimensions( $img );

        return $img;
    }

    /**
     * If an <img> is missing width or height, try to resolve them from
     * WordPress attachment metadata via the wp-image-{id} class.
     */
    private function maybe_add_dimensions( $img ) {
        // Already has both.
        if ( preg_match( '/\bwidth=["\']/', $img ) && preg_match( '/\bheight=["\']/', $img ) ) {
            return $img;
        }

        // Try to extract attachment ID from class="wp-image-123".
        if ( ! preg_match( '/\bwp-image-(\d+)\b/', $img, $id_match ) ) {
            return $img;
        }

        $attachment_id = (int) $id_match[1];
        $metadata = wp_get_attachment_metadata( $attachment_id );
        if ( ! $metadata || empty( $metadata['width'] ) || empty( $metadata['height'] ) ) {
            return $img;
        }

        $width  = $metadata['width'];
        $height = $metadata['height'];

        // If it's a sized image (e.g. image-300x200.jpg), use those dimensions.
        if ( preg_match( '/\bsrc=["\']([^"\']+)["\']/i', $img, $src_match ) ) {
            $src = $src_match[1];
            if ( preg_match( '/-(\d+)x(\d+)\.[a-z]+(\?.*)?$/i', $src, $dim_match ) ) {
                $width  = (int) $dim_match[1];
                $height = (int) $dim_match[2];
            }
        }

        if ( ! preg_match( '/\bwidth=["\']/', $img ) ) {
            $img = str_replace( '<img ', '<img width="' . $width . '" ', $img );
        }
        if ( ! preg_match( '/\bheight=["\']/', $img ) ) {
            $img = str_replace( '<img ', '<img height="' . $height . '" ', $img );
        }

        return $img;
    }
}
