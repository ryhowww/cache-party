<?php

namespace CacheParty;

if ( ! defined( 'ABSPATH' ) ) exit;

class Module_Loader {

    private $registry = [
        'images' => '\CacheParty\Images\Image_Optimizer',
        'assets' => '\CacheParty\Assets\Asset_Optimizer',
        'warmer' => '\CacheParty\Warmer\Warmer_Client',
    ];

    public function __construct() {
        $enabled = get_option( 'cache_party_modules', [ 'images' ] );

        foreach ( (array) $enabled as $slug ) {
            if ( isset( $this->registry[ $slug ] ) && class_exists( $this->registry[ $slug ] ) ) {
                new $this->registry[ $slug ]();
            }
        }
    }
}
