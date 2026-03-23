<?php

namespace CacheParty\Images;

if ( ! defined( 'ABSPATH' ) ) exit;

class Image_Optimizer {

    private $settings;

    public function __construct() {
        $this->settings = wp_parse_args(
            get_option( 'cache_party_images', [] ),
            \CacheParty\Settings::image_defaults()
        );

        // WebP converter always loads (needs delete_attachment hook even if disabled).
        new WebP_Converter( $this->settings );

        // Register image processors with the single Output_Buffer.
        $buffer = \CacheParty\Output_Buffer::instance();

        if ( $this->settings['picture_enabled'] ) {
            $picture = new Picture_Wrapper( $this->settings );
            $buffer->add_processor( 999, [ $picture, 'rewrite_images_to_picture' ] );
        }

        if ( $this->settings['lazy_enabled'] ) {
            $lazy = new Lazy_Loader( $this->settings );
            $buffer->add_processor( 1000, [ $lazy, 'process_images' ] );
        }

        if ( $this->settings['auto_alt_enabled'] ) {
            new Auto_Alt();
        }

        if ( is_admin() ) {
            new WebP_Admin();
        }

        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            $cli = new CLI_Commands();
            \WP_CLI::add_command( 'cache-party convert-webp', [ $cli, 'convert_webp' ] );
            \WP_CLI::add_command( 'cache-party image-stats', [ $cli, 'image_stats' ] );
        }
    }
}
