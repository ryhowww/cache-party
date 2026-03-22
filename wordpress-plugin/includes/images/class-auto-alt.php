<?php

namespace CacheParty\Images;

if ( ! defined( 'ABSPATH' ) ) exit;

class Auto_Alt {

    public function __construct() {
        add_action( 'add_attachment', [ $this, 'maybe_set_alt_from_filename' ], 9999 );
    }

    public function maybe_set_alt_from_filename( $attachment_id ) {
        if ( ! wp_attachment_is_image( $attachment_id ) ) {
            return;
        }

        $current_alt = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
        if ( is_string( $current_alt ) && $current_alt !== '' ) {
            return;
        }

        $file_path = get_attached_file( $attachment_id );
        if ( ! $file_path ) {
            $raw = (string) get_post_field( 'post_title', $attachment_id );
        } else {
            $raw = wp_basename( $file_path );
            $raw = preg_replace( '/\.[^.]+$/', '', $raw );
        }

        $alt = strtr( $raw, [
            '-' => ' ',
            '_' => ' ',
            '+' => ' ',
            '.' => ' ',
        ] );
        $alt = preg_replace( '/\s+/', ' ', (string) $alt );
        $alt = trim( (string) $alt );
        $alt = sanitize_text_field( $alt );

        if ( $alt !== '' ) {
            update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt );
        }
    }
}
