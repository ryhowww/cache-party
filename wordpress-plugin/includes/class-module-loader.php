<?php

namespace CacheParty;

if ( ! defined( 'ABSPATH' ) ) exit;

class Module_Loader {

    private $registry = [
        'images' => '\CacheParty\Images\Image_Optimizer',
        'warmer' => '\CacheParty\Warmer\Warmer_Client',
    ];

    public function __construct() {
        // Assets module is always loaded. Individual features on the Assets
        // tab (CSS aggregation, CSS deferral, JS delay, iframe lazy) are
        // gated by their own sub-toggles — no master toggle needed.
        if ( class_exists( '\CacheParty\Assets\Asset_Optimizer' ) ) {
            new \CacheParty\Assets\Asset_Optimizer();
        }

        $enabled = get_option( 'cache_party_modules', [ 'images' ] );

        foreach ( (array) $enabled as $slug ) {
            if ( isset( $this->registry[ $slug ] ) && class_exists( $this->registry[ $slug ] ) ) {
                new $this->registry[ $slug ]();
            }
        }
    }
}
