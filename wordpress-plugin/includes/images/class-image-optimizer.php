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

        if ( $this->settings['picture_enabled'] ) {
            new Picture_Wrapper( $this->settings );
        }

        if ( $this->settings['lazy_enabled'] ) {
            new Lazy_Loader( $this->settings );
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
