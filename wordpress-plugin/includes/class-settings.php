<?php

namespace CacheParty;

if ( ! defined( 'ABSPATH' ) ) exit;

class Settings {

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'register_menu' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_filter( 'plugin_action_links_' . plugin_basename( CACHE_PARTY_FILE ), [ $this, 'add_settings_link' ] );
    }

    public function register_menu() {
        add_menu_page(
            'Cache Party',
            'Cache Party',
            'manage_options',
            'cache-party',
            [ $this, 'render_page' ],
            'dashicons-performance',
            80
        );
    }

    public function register_settings() {
        // Each tab uses its own settings group so saving one tab
        // doesn't clear options from other tabs.
        //
        // cache_party_modules is registered in every tab that renders its own
        // "Enable X" checkbox so that tab's form can save it. WP dedupes the
        // sanitize filter callback so sanitize_modules still runs once per save.
        $module_args = [
            'type'              => 'array',
            'sanitize_callback' => [ $this, 'sanitize_modules' ],
            'default'           => [ 'images' ],
            'show_in_rest'      => false,
        ];
        foreach ( [ 'general', 'images', 'assets', 'warmer' ] as $group ) {
            register_setting( "cache_party_{$group}", 'cache_party_modules', $module_args );
        }

        register_setting( 'cache_party_general', 'cache_party_api_key', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
            'show_in_rest'      => false,
        ] );

        register_setting( 'cache_party_general', 'cache_party_skip_logged', [
            'type'              => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default'           => true,
            'show_in_rest'      => false,
        ] );

        // cache_party_cleanup now lives on the Advanced tab. Option key unchanged.
        register_setting( 'cache_party_advanced', 'cache_party_cleanup', [
            'type'              => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default'           => false,
            'show_in_rest'      => false,
        ] );

        register_setting( 'cache_party_images', 'cache_party_images', [
            'type'              => 'array',
            'sanitize_callback' => [ $this, 'sanitize_images' ],
            'default'           => self::image_defaults(),
            'show_in_rest'      => false,
        ] );

        // cache_party_assets is saved from two tabs (Assets + Advanced). Both
        // register the same option; sanitize_assets merges with existing so the
        // tabs don't clobber each other. Idempotent when the filter re-runs.
        $assets_args = [
            'type'              => 'array',
            'sanitize_callback' => [ $this, 'sanitize_assets' ],
            'default'           => self::asset_defaults(),
            'show_in_rest'      => false,
        ];
        register_setting( 'cache_party_assets', 'cache_party_assets', $assets_args );
        register_setting( 'cache_party_advanced', 'cache_party_assets', $assets_args );

        register_setting( 'cache_party_cloudflare', 'cache_party_cloudflare', [
            'type'              => 'array',
            'sanitize_callback' => [ $this, 'sanitize_cloudflare' ],
            'default'           => [],
            'show_in_rest'      => false,
        ] );
    }

    public static function image_defaults() {
        return [
            'webp_enabled'     => true,
            'webp_quality'     => 80,
            'picture_enabled'  => true,
            'lazy_enabled'     => true,
            'eager_count'      => 1,
            'auto_alt_enabled' => true,
            'exclude_keywords' => '',
        ];
    }

    public static function asset_defaults() {
        return [
            'css_aggregate_enabled'   => true,
            'css_aggregate_inline'    => true,
            'css_exclude'             => '',
            'css_minify_excluded'     => true,
            'css_defer_enabled'       => true,
            'css_defer_keywords'      => '',
            'css_defer_except'        => '',
            'css_delete_keywords'     => '',
            'js_delay_enabled'        => true,
            'js_delay_tag_keywords'   => "recaptcha",
            'js_delay_code_keywords'  => "googletagmanager.com/gtm.js\nconnect.facebook.net\nrecaptcha\nhotjar.com",
            'js_delete_keywords'      => '',
            'js_move_to_end_keywords' => '',
            'iframe_lazy_enabled'     => true,
            'iframe_exclude_keywords' => '',
            'idle_timeout'            => 5,
            'preload_css_http'        => true,
            'auto_detect_plugins'     => true,
            'remove_emojis'           => true,
            'remove_block_styles'     => false,
        ];
    }

    public function sanitize_assets( $input ) {
        // Start from the current stored value (falling back to defaults) so
        // tabs that only post a subset of fields — e.g. the Advanced tab —
        // don't wipe out fields they don't render. The Assets tab still posts
        // every field, so its save behavior is identical to before.
        $defaults = self::asset_defaults();
        $clean    = wp_parse_args( get_option( 'cache_party_assets', [] ), $defaults );

        $input = (array) $input;

        $bool_fields = [
            'css_aggregate_enabled',
            'css_aggregate_inline',
            'css_minify_excluded',
            'css_defer_enabled',
            'js_delay_enabled',
            'iframe_lazy_enabled',
            'preload_css_http',
            'auto_detect_plugins',
            'remove_emojis',
            'remove_block_styles',
        ];
        foreach ( $bool_fields as $field ) {
            if ( array_key_exists( $field, $input ) ) {
                $clean[ $field ] = ! empty( $input[ $field ] );
            }
        }

        $text_fields = [
            'css_exclude',
            'css_defer_keywords',
            'css_defer_except',
            'css_delete_keywords',
            'js_delay_tag_keywords',
            'js_delay_code_keywords',
            'js_delete_keywords',
            'js_move_to_end_keywords',
            'iframe_exclude_keywords',
        ];
        foreach ( $text_fields as $field ) {
            if ( array_key_exists( $field, $input ) ) {
                $clean[ $field ] = sanitize_textarea_field( $input[ $field ] );
            }
        }

        if ( array_key_exists( 'idle_timeout', $input ) ) {
            $clean['idle_timeout'] = max( 0, min( 30, (int) $input['idle_timeout'] ) );
        }

        return $clean;
    }

    public function sanitize_cloudflare( $input ) {
        $clean = [
            'email'  => isset( $input['email'] ) ? sanitize_email( $input['email'] ) : '',
            'api_key' => isset( $input['api_key'] ) ? sanitize_text_field( $input['api_key'] ) : '',
            'domain' => isset( $input['domain'] ) ? sanitize_text_field( $input['domain'] ) : '',
        ];

        // Clear cached zone ID when credentials change.
        delete_transient( 'cache_party_cf_zone_id' );

        return $clean;
    }

    public function sanitize_modules( $input ) {
        $valid = [ 'images', 'assets', 'warmer' ];
        return array_values( array_intersect( (array) $input, $valid ) );
    }

    public function sanitize_images( $input ) {
        $defaults = self::image_defaults();
        $clean    = [];

        $clean['webp_enabled']     = ! empty( $input['webp_enabled'] );
        $clean['webp_quality']     = isset( $input['webp_quality'] ) ? max( 1, min( 100, (int) $input['webp_quality'] ) ) : $defaults['webp_quality'];
        $clean['picture_enabled']  = ! empty( $input['picture_enabled'] );
        $clean['lazy_enabled']     = ! empty( $input['lazy_enabled'] );
        $clean['eager_count']      = isset( $input['eager_count'] ) ? max( 0, min( 20, (int) $input['eager_count'] ) ) : $defaults['eager_count'];
        $clean['auto_alt_enabled'] = ! empty( $input['auto_alt_enabled'] );
        $clean['exclude_keywords'] = isset( $input['exclude_keywords'] ) ? sanitize_textarea_field( $input['exclude_keywords'] ) : '';

        return $clean;
    }

    public function add_settings_link( $links ) {
        $url = admin_url( 'admin.php?page=cache-party' );
        array_unshift( $links, '<a href="' . esc_url( $url ) . '">Settings</a>' );
        return $links;
    }

    /**
     * Render the "Enable X" module toggle row used at the top of each module's
     * settings tab. Emits hidden inputs for every currently-enabled OTHER module
     * so this tab's save doesn't drop them from cache_party_modules.
     */
    private function render_module_toggle( $slug, $label, $description = '' ) {
        $modules = (array) get_option( 'cache_party_modules', [ 'images' ] );
        $enabled = in_array( $slug, $modules, true );
        ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php echo esc_html( $label ); ?></th>
                <td>
                    <?php
                    // Preserve every other currently-enabled module so saving
                    // this tab only flips the one we own.
                    foreach ( $modules as $m ) {
                        if ( $m === $slug ) {
                            continue;
                        }
                        echo '<input type="hidden" name="cache_party_modules[]" value="' . esc_attr( $m ) . '" />';
                    }
                    ?>
                    <label>
                        <input type="checkbox" name="cache_party_modules[]" value="<?php echo esc_attr( $slug ); ?>"
                            <?php checked( $enabled ); ?> />
                        <?php echo esc_html( $description ); ?>
                    </label>
                </td>
            </tr>
        </table>
        <?php
    }

    public function render_page() {
        $tabs = apply_filters( 'cache_party_settings_tabs', [
            'general'      => 'General',
            'images'       => 'Images',
            'assets'       => 'Assets',
            'critical_css' => 'Critical CSS',
            'warmer'       => 'Cache Warming',
            'advanced'     => 'Advanced',
            'cloudflare'   => 'Cloudflare',
        ] );

        $current_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'general';
        if ( ! isset( $tabs[ $current_tab ] ) ) {
            $current_tab = 'general';
        }
        ?>
        <div class="wrap">
            <h1>Cache Party</h1>

            <nav class="nav-tab-wrapper">
                <?php foreach ( $tabs as $slug => $label ) : ?>
                    <a href="<?php echo esc_url( add_query_arg( 'tab', $slug, admin_url( 'admin.php?page=cache-party' ) ) ); ?>"
                       class="nav-tab <?php echo $current_tab === $slug ? 'nav-tab-active' : ''; ?>">
                        <?php echo esc_html( $label ); ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>">
                <?php
                settings_fields( 'cache_party_' . $current_tab );

                // Hidden field to redirect back to current tab after save.
                echo '<input type="hidden" name="_wp_http_referer" value="' . esc_attr( add_query_arg( 'tab', $current_tab, admin_url( 'admin.php?page=cache-party' ) ) ) . '" />';

                $method = 'render_tab_' . $current_tab;
                if ( method_exists( $this, $method ) ) {
                    $this->$method();
                }

                do_action( 'cache_party_settings_tab_' . $current_tab );

                // AJAX-only tabs — no form settings to save.
                if ( ! in_array( $current_tab, [ 'critical_css' ], true ) ) {
                    submit_button( 'Save Changes' );
                }
                ?>
            </form>

            <?php do_action( 'cache_party_after_settings_form', $current_tab ); ?>
        </div>
        <?php
    }

    private function render_tab_general() {
        $api_key = get_option( 'cache_party_api_key', '' );
        ?>
        <h2>API Key</h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="cache_party_api_key">Cache Party API Key</label></th>
                <td>
                    <input type="password" name="cache_party_api_key" id="cache_party_api_key"
                           value="<?php echo esc_attr( $api_key ); ?>"
                           class="regular-text" placeholder="Paste your API key" />
                    <p class="description">Connects this site to Cache Party cloud services (cache warming, critical CSS generation). One key works across all sites.</p>
                </td>
            </tr>
            <tr>
                <th scope="row">Connection</th>
                <td>
                    <button type="button" class="button" id="cp-test-warmer" <?php disabled( empty( $api_key ) ); ?>>Test Connection</button>
                    <span id="cp-warmer-test-result" style="margin-left:8px;"></span>
                    <p class="description">Verifies this site can reach the Cache Party cloud service.</p>
                </td>
            </tr>
            <tr>
                <th scope="row">Registration</th>
                <td>
                    <button type="button" class="button" id="cp-register-site" <?php disabled( empty( $api_key ) ); ?>>Register Site</button>
                    <span id="cp-warmer-register-result" style="margin-left:8px;"></span>
                    <p class="description">Manually register this site with the warmer service. Usually runs automatically after saving the API key.</p>
                </td>
            </tr>
        </table>

        <h2>Optimization</h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">
                    <label for="cache_party_skip_logged">Skip optimization for logged-in users</label>
                </th>
                <td>
                    <input type="hidden" name="cache_party_skip_logged" value="0" />
                    <input type="checkbox" name="cache_party_skip_logged" id="cache_party_skip_logged" value="1"
                        <?php checked( get_option( 'cache_party_skip_logged', true ) ); ?> />
                    <p class="description">
                        When checked, logged-in editors and administrators see the unoptimized site (no CSS aggregation, no JS delay, no critical CSS inlining). Useful for debugging and page builder compatibility.
                    </p>
                </td>
            </tr>
        </table>

        <input type="hidden" id="cp-warmer-nonce" value="<?php echo esc_attr( wp_create_nonce( 'cache_party_warmer' ) ); ?>">

        <script>
        jQuery(function($) {
            $('#cp-test-warmer').on('click', function() {
                var $btn = $(this), $result = $('#cp-warmer-test-result');
                $btn.prop('disabled', true);
                $result.text('Testing...');
                $.post(ajaxurl, {
                    action: 'cache_party_test_warmer',
                    nonce: $('#cp-warmer-nonce').val()
                }).done(function(res) {
                    $result.html('<span style="color:' + (res.success ? '#46b450' : '#dc3232') + ';">' + (res.data ? res.data.message : 'Unknown') + '</span>');
                }).fail(function() {
                    $result.html('<span style="color:#dc3232;">Request failed.</span>');
                }).always(function() {
                    $btn.prop('disabled', false);
                });
            });

            $('#cp-register-site').on('click', function() {
                var $btn = $(this), $result = $('#cp-warmer-register-result');
                $btn.prop('disabled', true);
                $result.text('Registering...');
                $.post(ajaxurl, {
                    action: 'cache_party_register_site',
                    nonce: $('#cp-warmer-nonce').val()
                }).done(function(res) {
                    $result.html('<span style="color:' + (res.success ? '#46b450' : '#dc3232') + ';">' + (res.data ? res.data.message : 'Unknown') + '</span>');
                }).fail(function() {
                    $result.html('<span style="color:#dc3232;">Request failed.</span>');
                }).always(function() {
                    $btn.prop('disabled', false);
                });
            });
        });
        </script>
        <?php
    }

    private function render_tab_images() {
        $settings   = wp_parse_args( get_option( 'cache_party_images', [] ), self::image_defaults() );
        $webp_engine = \CacheParty\Images\WebP_Converter::is_conversion_available();

        $this->render_module_toggle(
            'images',
            'Enable Image Optimizer',
            'WebP conversion, picture wrapping, smart lazy loading, auto alt text'
        );
        ?>
        <h2>WebP Conversion</h2>

        <?php if ( $webp_engine ) : ?>
            <p class="description" style="margin-bottom: 1em;">
                Conversion engine: <strong><?php echo esc_html( ucfirst( $webp_engine ) ); ?></strong>
            </p>
        <?php else : ?>
            <div class="notice notice-warning inline" style="margin-bottom: 1em;">
                <p>No WebP conversion library detected. Install Imagick or GD with WebP support to enable this feature.</p>
            </div>
        <?php endif; ?>

        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="cp_webp_enabled">Enable WebP conversion</label></th>
                <td>
                    <input type="hidden" name="cache_party_images[webp_enabled]" value="0" />
                    <input type="checkbox" name="cache_party_images[webp_enabled]" id="cp_webp_enabled" value="1"
                        <?php checked( $settings['webp_enabled'] ); ?>
                        <?php disabled( ! $webp_engine ); ?> />
                    <p class="description">Convert images to WebP format on upload.</p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="cp_webp_quality">WebP quality</label></th>
                <td>
                    <input type="number" name="cache_party_images[webp_quality]" id="cp_webp_quality"
                           value="<?php echo esc_attr( $settings['webp_quality'] ); ?>"
                           min="1" max="100" step="1" class="small-text" />
                    <p class="description">Quality level for WebP output (1-100). Default: 80.</p>
                </td>
            </tr>
        </table>

        <h2>Picture Wrapping</h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="cp_picture_enabled">Enable picture wrapping</label></th>
                <td>
                    <input type="hidden" name="cache_party_images[picture_enabled]" value="0" />
                    <input type="checkbox" name="cache_party_images[picture_enabled]" id="cp_picture_enabled" value="1"
                        <?php checked( $settings['picture_enabled'] ); ?> />
                    <p class="description">Wrap &lt;img&gt; tags in &lt;picture&gt; elements with WebP sources on the front end.</p>
                </td>
            </tr>
        </table>

        <h2>Lazy Loading</h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="cp_lazy_enabled">Enable smart lazy loading</label></th>
                <td>
                    <input type="hidden" name="cache_party_images[lazy_enabled]" value="0" />
                    <input type="checkbox" name="cache_party_images[lazy_enabled]" id="cp_lazy_enabled" value="1"
                        <?php checked( $settings['lazy_enabled'] ); ?> />
                    <p class="description">Add loading="lazy" to images. First N images are set to eager with fetchpriority="high".</p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="cp_eager_count">Eager image count</label></th>
                <td>
                    <input type="number" name="cache_party_images[eager_count]" id="cp_eager_count"
                           value="<?php echo esc_attr( $settings['eager_count'] ); ?>"
                           min="0" max="20" step="1" class="small-text" />
                    <p class="description">Number of above-the-fold images to load eagerly (0-20). Default: 1.</p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="cp_exclude_keywords">Exclude keywords</label></th>
                <td>
                    <textarea name="cache_party_images[exclude_keywords]" id="cp_exclude_keywords"
                              rows="3" class="large-text"><?php echo esc_textarea( $settings['exclude_keywords'] ); ?></textarea>
                    <p class="description">One keyword per line. Images matching these keywords will skip lazy loading and picture wrapping.</p>
                </td>
            </tr>
        </table>

        <h2>Auto Alt Text</h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="cp_auto_alt_enabled">Auto-generate alt text</label></th>
                <td>
                    <input type="hidden" name="cache_party_images[auto_alt_enabled]" value="0" />
                    <input type="checkbox" name="cache_party_images[auto_alt_enabled]" id="cp_auto_alt_enabled" value="1"
                        <?php checked( $settings['auto_alt_enabled'] ); ?> />
                    <p class="description">Automatically set alt text from the filename when images are uploaded (only if alt is empty).</p>
                </td>
            </tr>
        </table>

        <?php do_action( 'cache_party_after_images_settings' ); ?>
        <?php
    }

    private function render_tab_assets() {
        $settings = wp_parse_args( get_option( 'cache_party_assets', [] ), self::asset_defaults() );
        $cache_stats = \CacheParty\Assets\Cache_Manager::stats();

        $this->render_module_toggle(
            'assets',
            'Enable Asset Optimizer',
            'CSS aggregation, CSS deferral, JS delay, iframe lazy loading'
        );
        ?>

        <h2>CSS Aggregation</h2>
        <p class="description">Combines multiple stylesheets into a single minified file. Cache: <?php echo esc_html( $cache_stats['count'] ); ?> files (<?php echo esc_html( $cache_stats['size_human'] ); ?>).
            <button type="button" class="button button-small" id="cp-purge-css-cache" style="margin-left:8px;">Purge Cache</button>
        </p>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="cp_css_aggregate_enabled">Enable CSS aggregation</label></th>
                <td>
                    <input type="hidden" name="cache_party_assets[css_aggregate_enabled]" value="0" />
                    <input type="checkbox" name="cache_party_assets[css_aggregate_enabled]" id="cp_css_aggregate_enabled" value="1"
                        <?php checked( $settings['css_aggregate_enabled'] ); ?> />
                    <p class="description">Combine and minify all CSS into a single cached file per media type.</p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="cp_css_aggregate_inline">Also aggregate inline CSS</label></th>
                <td>
                    <input type="hidden" name="cache_party_assets[css_aggregate_inline]" value="0" />
                    <input type="checkbox" name="cache_party_assets[css_aggregate_inline]" id="cp_css_aggregate_inline" value="1"
                        <?php checked( $settings['css_aggregate_inline'] ); ?> />
                    <p class="description">Include inline &lt;style&gt; blocks in the aggregated file.</p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="cp_css_exclude">CSS exclusions</label></th>
                <td>
                    <textarea name="cache_party_assets[css_exclude]" id="cp_css_exclude"
                              rows="3" class="large-text"><?php echo esc_textarea( $settings['css_exclude'] ); ?></textarea>
                    <p class="description">Comma-separated. Stylesheets matching these keywords will not be aggregated (but may still be individually minified).</p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="cp_css_minify_excluded">Minify excluded CSS</label></th>
                <td>
                    <input type="hidden" name="cache_party_assets[css_minify_excluded]" value="0" />
                    <input type="checkbox" name="cache_party_assets[css_minify_excluded]" id="cp_css_minify_excluded" value="1"
                        <?php checked( $settings['css_minify_excluded'] ); ?> />
                    <p class="description">Individually minify CSS files that are excluded from aggregation.</p>
                </td>
            </tr>
        </table>

        <h2>CSS Deferral</h2>
        <p class="description">Extracts stylesheets and defers loading until first user interaction.</p>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="cp_css_defer_enabled">Enable CSS deferral</label></th>
                <td>
                    <input type="hidden" name="cache_party_assets[css_defer_enabled]" value="0" />
                    <input type="checkbox" name="cache_party_assets[css_defer_enabled]" id="cp_css_defer_enabled" value="1"
                        <?php checked( $settings['css_defer_enabled'] ); ?> />
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="cp_css_defer_keywords">Defer by keyword</label></th>
                <td>
                    <textarea name="cache_party_assets[css_defer_keywords]" id="cp_css_defer_keywords"
                              rows="3" class="large-text"><?php echo esc_textarea( $settings['css_defer_keywords'] ); ?></textarea>
                    <p class="description">One per line. Only defer stylesheets matching these keywords (allowlist mode). Leave empty to defer all CSS.</p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="cp_css_defer_except">Keep in head</label></th>
                <td>
                    <textarea name="cache_party_assets[css_defer_except]" id="cp_css_defer_except"
                              rows="3" class="large-text"><?php echo esc_textarea( $settings['css_defer_except'] ); ?></textarea>
                    <p class="description">One per line. Stylesheets matching these keywords stay in head (not deferred). Only used when "Defer by keyword" is empty.</p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="cp_css_delete_keywords">Delete by keyword</label></th>
                <td>
                    <textarea name="cache_party_assets[css_delete_keywords]" id="cp_css_delete_keywords"
                              rows="2" class="large-text"><?php echo esc_textarea( $settings['css_delete_keywords'] ); ?></textarea>
                    <p class="description">One per line. Stylesheets matching these keywords will be removed entirely.</p>
                </td>
            </tr>
        </table>

        <h2>JS Delay</h2>
        <p class="description">Delays script execution until first user interaction (mouse, scroll, or touch).</p>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="cp_js_delay_enabled">Enable JS delay</label></th>
                <td>
                    <input type="hidden" name="cache_party_assets[js_delay_enabled]" value="0" />
                    <input type="checkbox" name="cache_party_assets[js_delay_enabled]" id="cp_js_delay_enabled" value="1"
                        <?php checked( $settings['js_delay_enabled'] ); ?> />
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="cp_js_delay_tag_keywords">Delay by tag attribute</label></th>
                <td>
                    <textarea name="cache_party_assets[js_delay_tag_keywords]" id="cp_js_delay_tag_keywords"
                              rows="3" class="large-text"><?php echo esc_textarea( $settings['js_delay_tag_keywords'] ); ?></textarea>
                    <p class="description">One per line. Scripts with these keywords in their tag attributes (src, id, class) will be delayed.</p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="cp_js_delay_code_keywords">Delay by code content</label></th>
                <td>
                    <textarea name="cache_party_assets[js_delay_code_keywords]" id="cp_js_delay_code_keywords"
                              rows="4" class="large-text"><?php echo esc_textarea( $settings['js_delay_code_keywords'] ); ?></textarea>
                    <p class="description">One per line. Inline scripts containing these keywords will be delayed. Defaults: GTM, Facebook, reCAPTCHA, Hotjar.</p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="cp_js_delete_keywords">Delete by keyword</label></th>
                <td>
                    <textarea name="cache_party_assets[js_delete_keywords]" id="cp_js_delete_keywords"
                              rows="2" class="large-text"><?php echo esc_textarea( $settings['js_delete_keywords'] ); ?></textarea>
                    <p class="description">One per line. Scripts matching these keywords will be removed entirely.</p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="cp_js_move_to_end_keywords">Move to end</label></th>
                <td>
                    <textarea name="cache_party_assets[js_move_to_end_keywords]" id="cp_js_move_to_end_keywords"
                              rows="2" class="large-text"><?php echo esc_textarea( $settings['js_move_to_end_keywords'] ); ?></textarea>
                    <p class="description">One per line. Scripts matching these keywords will be moved before &lt;/body&gt; (not delayed, just repositioned).</p>
                </td>
            </tr>
        </table>

        <h2>Iframe Lazy Loading</h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="cp_iframe_lazy_enabled">Enable iframe lazy loading</label></th>
                <td>
                    <input type="hidden" name="cache_party_assets[iframe_lazy_enabled]" value="0" />
                    <input type="checkbox" name="cache_party_assets[iframe_lazy_enabled]" id="cp_iframe_lazy_enabled" value="1"
                        <?php checked( $settings['iframe_lazy_enabled'] ); ?> />
                    <p class="description">Replace iframe src with data-lazy-src, loaded on first interaction.</p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="cp_iframe_exclude_keywords">Exclude keywords</label></th>
                <td>
                    <textarea name="cache_party_assets[iframe_exclude_keywords]" id="cp_iframe_exclude_keywords"
                              rows="2" class="large-text"><?php echo esc_textarea( $settings['iframe_exclude_keywords'] ); ?></textarea>
                    <p class="description">One per line. Iframes matching these keywords will not be lazy loaded.</p>
                </td>
            </tr>
        </table>

        <h2>Interaction Loader</h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="cp_idle_timeout">Idle timeout (seconds)</label></th>
                <td>
                    <input type="number" name="cache_party_assets[idle_timeout]" id="cp_idle_timeout"
                           value="<?php echo esc_attr( $settings['idle_timeout'] ); ?>"
                           min="0" max="30" step="1" class="small-text" />
                    <p class="description">If no interaction after this many seconds, load deferred assets anyway. 0 = wait forever. Default: 5.</p>
                </td>
            </tr>
        </table>

        <input type="hidden" id="cp-assets-nonce" value="<?php echo esc_attr( wp_create_nonce( 'cache_party_critical' ) ); ?>">
        <script>
        jQuery(function($) {
            $('#cp-purge-css-cache').on('click', function() {
                var $btn = $(this);
                $btn.prop('disabled', true).text('Purging...');
                $.post(ajaxurl, {
                    action: 'cache_party_purge_css_cache',
                    nonce: $('#cp-assets-nonce').val()
                }).done(function(res) {
                    $btn.text(res.success ? 'Purged!' : 'Failed');
                    setTimeout(function() { $btn.text('Purge Cache').prop('disabled', false); }, 2000);
                }).fail(function() {
                    $btn.text('Failed').prop('disabled', false);
                });
            });
        });
        </script>

        <?php do_action( 'cache_party_after_assets_settings' ); ?>
        <?php
    }

    private function render_tab_critical_css() {
        $generated   = \CacheParty\Assets\Critical_CSS::list_templates();
        $discovered  = \CacheParty\Assets\Critical_CSS::discover_templates();
        $has_api     = ! empty( get_option( 'cache_party_api_key', '' ) );
        $all_meta    = get_option( 'cache_party_critical_meta', [] );

        // Build lookup of generated templates by slug.
        $gen_lookup = [];
        foreach ( $generated as $g ) {
            $gen_lookup[ $g['template'] ] = $g;
        }

        // Merge discovered templates with generated ones. Always include 'default'.
        $all_templates    = $discovered;
        $discovered_slugs = array_column( $discovered, 'slug' );
        if ( ! in_array( 'default', $discovered_slugs, true ) ) {
            $all_templates[] = [
                'slug'             => 'default',
                'name'             => 'Default Fallback',
                'file'             => '',
                'count'            => 0,
                'sample_url'       => home_url( '/' ),
                'has_critical_css' => isset( $gen_lookup['default'] ),
            ];
        }
        ?>

        <h2>Critical CSS</h2>
        <p class="description">Generate above-the-fold CSS per template type. Inlined in &lt;head&gt; to prevent FOUC when CSS deferral is active.</p>

        <?php
        // Cloudflare detection notice.
        if ( function_exists( 'is_plugin_active' ) && is_plugin_active( 'cloudflare/cloudflare.php' ) ) {
            $dismissed = get_user_meta( get_current_user_id(), 'cp_dismiss_cf_critical_notice', true );
            if ( ! $dismissed ) : ?>
                <div class="notice notice-info inline is-dismissible" id="cp-cf-notice" style="margin-bottom:1em;">
                    <p>This site uses Cloudflare. If you have a Cache Everything rule, add <code>cp-nocache</code> to the bypass conditions for reliable critical CSS generation.
                    <a href="#" id="cp-cf-notice-dismiss" style="margin-left:4px;">Don't show again</a></p>
                </div>
            <?php endif;
        }
        ?>

        <?php if ( ! $has_api ) : ?>
            <div class="notice notice-warning inline" style="margin-bottom:1em;">
                <p>Add your API key on the <a href="<?php echo esc_url( add_query_arg( 'tab', 'general', admin_url( 'admin.php?page=cache-party' ) ) ); ?>">General tab</a> to enable critical CSS generation.</p>
            </div>
        <?php endif; ?>

        <?php if ( $has_api ) : ?>
            <p>
                <button type="button" class="button button-primary" id="cp-generate-all-critical">Generate All</button>
                <span id="cp-generate-all-status" style="margin-left:8px;"></span>
            </p>
        <?php endif; ?>

        <table class="widefat striped" style="max-width:1100px;">
            <thead>
                <tr>
                    <th>Template</th>
                    <th>URL to analyze</th>
                    <th>Status</th>
                    <th>Size</th>
                    <th>Generated</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $all_templates as $tpl ) :
                    $slug     = $tpl['slug'];
                    $existing = $gen_lookup[ $slug ] ?? null;
                    $meta     = $all_meta[ $slug ] ?? [];
                    // Prefer the URL used for last generation over the auto-discovered sample.
                    $url      = ! empty( $meta['source_url'] ) ? $meta['source_url'] : ( $tpl['sample_url'] ?? '' );

                    // Staleness display.
                    $age_html = '&mdash;';
                    if ( ! empty( $meta['generated_at'] ) ) {
                        $gen_time = strtotime( $meta['generated_at'] );
                        $days     = max( 0, round( ( time() - $gen_time ) / DAY_IN_SECONDS ) );
                        if ( $days === 0 ) {
                            $age_html = '<span style="color:#46b450;">today</span>';
                        } elseif ( $days <= 30 ) {
                            $age_html = esc_html( $days . 'd ago' );
                        } else {
                            $age_html = '<span style="color:#dba617;">' . esc_html( $days . 'd ago' ) . ' (stale)</span>';
                        }
                    }
                ?>
                <tr data-template="<?php echo esc_attr( $slug ); ?>">
                    <td>
                        <code><?php echo esc_html( $slug ); ?></code>
                        <?php if ( ! empty( $tpl['name'] ) && $tpl['name'] !== $slug ) : ?>
                            <br><small style="color:#666;"><?php echo esc_html( $tpl['name'] ); ?></small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <input type="text" class="regular-text cp-critical-url" data-template="<?php echo esc_attr( $slug ); ?>"
                               value="<?php echo esc_attr( $url ); ?>"
                               placeholder="https://..." style="width:100%;" />
                    </td>
                    <td class="cp-col-status"><?php echo $existing ? '<span style="color:#46b450;">Generated</span>' : '<span style="color:#999;">Not generated</span>'; ?></td>
                    <td class="cp-col-size"><?php echo $existing ? esc_html( size_format( $existing['size'] ) ) : '&mdash;'; ?></td>
                    <td class="cp-col-age"><?php echo $age_html; ?></td>
                    <td>
                        <?php if ( $has_api ) : ?>
                            <button type="button" class="button cp-generate-critical" data-template="<?php echo esc_attr( $slug ); ?>" <?php echo empty( $url ) ? 'disabled' : ''; ?>>
                                <?php echo $existing ? 'Regenerate' : 'Generate'; ?>
                            </button>
                            <?php if ( $existing ) : ?>
                                <button type="button" class="button cp-view-critical" data-template="<?php echo esc_attr( $slug ); ?>" style="margin-left:4px;">View/Edit</button>
                                <button type="button" class="button cp-delete-critical" data-template="<?php echo esc_attr( $slug ); ?>" style="margin-left:4px;color:#a00;">Delete</button>
                            <?php endif; ?>
                            <span class="cp-critical-status" style="margin-left:8px;"></span>
                        <?php else : ?>
                            <button type="button" class="button" disabled>Generate</button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php if ( $existing ) : ?>
                <tr class="cp-editor-row" data-template="<?php echo esc_attr( $slug ); ?>" style="display:none;">
                    <td colspan="6" style="padding:12px 20px;background:#f9f9f9;">
                        <textarea class="large-text cp-critical-editor" rows="12" style="font-family:monospace;font-size:12px;"></textarea>
                        <p style="margin-top:8px;">
                            <button type="button" class="button button-primary cp-save-critical" data-template="<?php echo esc_attr( $slug ); ?>">Save Changes</button>
                            <button type="button" class="button cp-cancel-edit" data-template="<?php echo esc_attr( $slug ); ?>" style="margin-left:4px;">Cancel</button>
                            <span class="cp-editor-status" style="margin-left:8px;"></span>
                        </p>
                    </td>
                </tr>
                <?php endif; ?>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php
        // Per-page critical CSS section.
        $page_entries = [];
        foreach ( $generated as $g ) {
            if ( preg_match( '/^page-(\d+)$/', $g['template'], $m ) ) {
                $pid   = (int) $m[1];
                $title = get_the_title( $pid );
                $url   = get_permalink( $pid );
                $meta  = $all_meta[ $g['template'] ] ?? [];
                $page_entries[] = [
                    'slug'  => $g['template'],
                    'title' => $title ?: "(ID {$pid})",
                    'url'   => $url ?: '',
                    'size'  => $g['size'],
                    'meta'  => $meta,
                ];
            }
        }
        ?>

        <h3 style="margin-top:2em;">Page-Specific Critical CSS</h3>
        <p class="description">Override template critical CSS for individual pages (useful for page-builder pages with unique layouts).</p>

        <?php if ( $has_api ) : ?>
        <p style="margin-bottom:8px;">
            <input type="text" id="cp-perpage-url" class="regular-text" placeholder="https://example.com/my-page/" style="width:400px;" />
            <button type="button" class="button" id="cp-perpage-add">Add Page</button>
            <span id="cp-perpage-status" style="margin-left:8px;"></span>
        </p>
        <?php endif; ?>

        <table class="widefat striped" id="cp-perpage-table" style="max-width:1100px;">
            <thead>
                <tr>
                    <th>Page</th>
                    <th>URL</th>
                    <th>Status</th>
                    <th>Size</th>
                    <th>Generated</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $page_entries ) ) : ?>
                <tr class="cp-perpage-empty"><td colspan="6" style="color:#999;">No page-specific critical CSS generated yet.</td></tr>
                <?php endif; ?>
                <?php foreach ( $page_entries as $pe ) :
                    $age_html = '&mdash;';
                    if ( ! empty( $pe['meta']['generated_at'] ) ) {
                        $gen_time = strtotime( $pe['meta']['generated_at'] );
                        $days     = max( 0, round( ( time() - $gen_time ) / DAY_IN_SECONDS ) );
                        if ( $days === 0 ) {
                            $age_html = '<span style="color:#46b450;">today</span>';
                        } elseif ( $days <= 30 ) {
                            $age_html = esc_html( $days . 'd ago' );
                        } else {
                            $age_html = '<span style="color:#dba617;">' . esc_html( $days . 'd ago' ) . ' (stale)</span>';
                        }
                    }
                ?>
                <tr data-template="<?php echo esc_attr( $pe['slug'] ); ?>">
                    <td><strong><?php echo esc_html( $pe['title'] ); ?></strong><br><code><?php echo esc_html( $pe['slug'] ); ?></code></td>
                    <td><input type="text" class="regular-text cp-critical-url" data-template="<?php echo esc_attr( $pe['slug'] ); ?>" value="<?php echo esc_attr( $pe['url'] ); ?>" style="width:100%;" /></td>
                    <td class="cp-col-status"><span style="color:#46b450;">Generated</span></td>
                    <td class="cp-col-size"><?php echo esc_html( size_format( $pe['size'] ) ); ?></td>
                    <td class="cp-col-age"><?php echo $age_html; ?></td>
                    <td>
                        <button type="button" class="button cp-generate-critical" data-template="<?php echo esc_attr( $pe['slug'] ); ?>">Regenerate</button>
                        <button type="button" class="button cp-view-critical" data-template="<?php echo esc_attr( $pe['slug'] ); ?>" style="margin-left:4px;">View/Edit</button>
                        <button type="button" class="button cp-delete-critical" data-template="<?php echo esc_attr( $pe['slug'] ); ?>" style="margin-left:4px;color:#a00;">Delete</button>
                        <span class="cp-critical-status" style="margin-left:8px;"></span>
                    </td>
                </tr>
                <tr class="cp-editor-row" data-template="<?php echo esc_attr( $pe['slug'] ); ?>" style="display:none;">
                    <td colspan="6" style="padding:12px 20px;background:#f9f9f9;">
                        <textarea class="large-text cp-critical-editor" rows="12" style="font-family:monospace;font-size:12px;"></textarea>
                        <p style="margin-top:8px;">
                            <button type="button" class="button button-primary cp-save-critical" data-template="<?php echo esc_attr( $pe['slug'] ); ?>">Save Changes</button>
                            <button type="button" class="button cp-cancel-edit" data-template="<?php echo esc_attr( $pe['slug'] ); ?>" style="margin-left:4px;">Cancel</button>
                            <span class="cp-editor-status" style="margin-left:8px;"></span>
                        </p>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <input type="hidden" id="cp-critical-nonce" value="<?php echo esc_attr( wp_create_nonce( 'cache_party_critical' ) ); ?>">

        <script>
        jQuery(function($) {
            var nonce = $('#cp-critical-nonce').val();

            // Dismiss CF notice permanently.
            $('#cp-cf-notice-dismiss').on('click', function(e) {
                e.preventDefault();
                $('#cp-cf-notice').fadeOut();
                $.post(ajaxurl, { action: 'cache_party_dismiss_cf_notice', nonce: nonce });
            });

            // Enable/disable Generate button based on URL field.
            $('.cp-critical-url').on('input', function() {
                var tpl = $(this).data('template');
                var $btn = $('.cp-generate-critical[data-template="' + tpl + '"]');
                $btn.prop('disabled', !$(this).val().trim());
            });

            // Generate (clean regeneration: delete old → purge cache → generate fresh).
            function generateTemplate(template, url, $btn, $status) {
                if (!url) {
                    $status.html('<span style="color:#dc3232;">Enter a URL first.</span>');
                    return $.Deferred().reject().promise();
                }

                $btn.prop('disabled', true);
                $status.text('Clearing caches...');

                // Step 1: Delete existing + purge aggregation cache.
                return $.post(ajaxurl, {
                    action: 'cache_party_delete_critical',
                    nonce: nonce,
                    template: template
                }).then(function() {
                    return $.post(ajaxurl, {
                        action: 'cache_party_purge_css_cache',
                        nonce: nonce
                    });
                }).then(function() {
                    // Step 2: Brief pause for caches to clear, then generate.
                    $status.text('Generating...');
                    return $.post(ajaxurl, {
                        action: 'cache_party_generate_critical',
                        nonce: nonce,
                        template: template,
                        url: url
                    });
                }).then(function(res) {
                    if (res.success) {
                        var $row = $btn.closest('tr');
                        $row.find('.cp-col-status').html('<span style="color:#46b450;">Generated</span>');
                        $row.find('.cp-col-size').text(res.data.size ? (Math.round(res.data.size / 1024) + ' KB') : '—');
                        $row.find('.cp-col-age').html('<span style="color:#46b450;">today</span>');
                        $btn.text('Regenerate');
                        $status.html('<span style="color:#46b450;">' + res.data.message + '</span>');

                        // Show View/Edit and Delete buttons if not present.
                        if (!$row.find('.cp-view-critical').length) {
                            $btn.after(
                                ' <button type="button" class="button cp-view-critical" data-template="' + template + '" style="margin-left:4px;">View/Edit</button>' +
                                ' <button type="button" class="button cp-delete-critical" data-template="' + template + '" style="margin-left:4px;color:#a00;">Delete</button>'
                            );
                        }
                    } else {
                        $status.html('<span style="color:#dc3232;">' + (res.data ? res.data.message : 'Failed') + '</span>');
                    }
                }).fail(function() {
                    $status.html('<span style="color:#dc3232;">Request failed.</span>');
                }).always(function() {
                    $btn.prop('disabled', false);
                });
            }

            // Single template generate.
            $(document).on('click', '.cp-generate-critical', function() {
                var $btn = $(this);
                var $status = $btn.siblings('.cp-critical-status');
                var template = $btn.data('template');
                var url = $('.cp-critical-url[data-template="' + template + '"]').val().trim();
                generateTemplate(template, url, $btn, $status);
            });

            // Generate All — sequential per template.
            $('#cp-generate-all-critical').on('click', function() {
                var $allBtn = $(this).prop('disabled', true);
                var $allStatus = $('#cp-generate-all-status');
                var rows = [];

                $('tr[data-template]').not('.cp-editor-row').each(function() {
                    var $row = $(this);
                    var tpl = $row.data('template');
                    var url = $row.find('.cp-critical-url').val().trim();
                    if (url) {
                        rows.push({ template: tpl, url: url, $row: $row });
                    }
                });

                if (rows.length === 0) {
                    $allStatus.html('<span style="color:#dc3232;">No templates with URLs.</span>');
                    $allBtn.prop('disabled', false);
                    return;
                }

                var idx = 0;
                function next() {
                    if (idx >= rows.length) {
                        $allStatus.html('<span style="color:#46b450;">Done! ' + rows.length + ' templates generated.</span>');
                        $allBtn.prop('disabled', false);
                        return;
                    }
                    var r = rows[idx];
                    $allStatus.text('Generating ' + r.template + '... (' + (idx + 1) + '/' + rows.length + ')');
                    var $btn = r.$row.find('.cp-generate-critical');
                    var $status = r.$row.find('.cp-critical-status');
                    generateTemplate(r.template, r.url, $btn, $status).always(function() {
                        idx++;
                        next();
                    });
                }
                next();
            });

            // Delete critical CSS.
            $(document).on('click', '.cp-delete-critical', function() {
                var $btn = $(this);
                var template = $btn.data('template');
                if (!confirm('Delete critical CSS for "' + template + '"?')) return;

                $btn.prop('disabled', true);
                $.post(ajaxurl, {
                    action: 'cache_party_delete_critical',
                    nonce: nonce,
                    template: template
                }).done(function(res) {
                    if (res.success) {
                        var $row = $btn.closest('tr');
                        $row.find('.cp-col-status').html('<span style="color:#999;">Not generated</span>');
                        $row.find('.cp-col-size').text('—');
                        $row.find('.cp-col-age').text('—');
                        $row.find('.cp-generate-critical').text('Generate');
                        $row.find('.cp-view-critical, .cp-delete-critical').remove();
                        // Hide editor row if open.
                        $('.cp-editor-row[data-template="' + template + '"]').hide();
                    }
                }).always(function() {
                    $btn.prop('disabled', false);
                });
            });

            // View/Edit — load CSS content into inline editor.
            $(document).on('click', '.cp-view-critical', function() {
                var template = $(this).data('template');
                var $editorRow = $('.cp-editor-row[data-template="' + template + '"]');

                if ($editorRow.is(':visible')) {
                    $editorRow.hide();
                    return;
                }

                var $textarea = $editorRow.find('.cp-critical-editor');
                $textarea.val('Loading...');
                $editorRow.show();

                $.post(ajaxurl, {
                    action: 'cache_party_get_critical',
                    nonce: nonce,
                    template: template
                }).done(function(res) {
                    if (res.success) {
                        $textarea.val(res.data.css);
                    } else {
                        $textarea.val('Error loading CSS.');
                    }
                });
            });

            // Save edited CSS.
            $(document).on('click', '.cp-save-critical', function() {
                var $btn = $(this);
                var template = $btn.data('template');
                var $editorRow = $('.cp-editor-row[data-template="' + template + '"]');
                var css = $editorRow.find('.cp-critical-editor').val();
                var $status = $editorRow.find('.cp-editor-status');

                $btn.prop('disabled', true);
                $status.text('Saving...');

                $.post(ajaxurl, {
                    action: 'cache_party_save_critical',
                    nonce: nonce,
                    template: template,
                    css: css
                }).done(function(res) {
                    if (res.success) {
                        $status.html('<span style="color:#46b450;">Saved!</span>');
                        // Update size in the main row.
                        var $mainRow = $('tr[data-template="' + template + '"]').not('.cp-editor-row');
                        $mainRow.find('.cp-col-size').text(res.data.size ? (Math.round(res.data.size / 1024) + ' KB') : '—');
                        $mainRow.find('.cp-col-age').html('<span style="color:#46b450;">today</span>');
                    } else {
                        $status.html('<span style="color:#dc3232;">' + (res.data ? res.data.message : 'Failed') + '</span>');
                    }
                }).fail(function() {
                    $status.html('<span style="color:#dc3232;">Request failed.</span>');
                }).always(function() {
                    $btn.prop('disabled', false);
                });
            });

            // Cancel edit.
            $(document).on('click', '.cp-cancel-edit', function() {
                var template = $(this).data('template');
                $('.cp-editor-row[data-template="' + template + '"]').hide();
            });

            // Per-page: Add Page button.
            $('#cp-perpage-add').on('click', function() {
                var $btn = $(this).prop('disabled', true);
                var $status = $('#cp-perpage-status');
                var url = $('#cp-perpage-url').val().trim();

                if (!url) {
                    $status.html('<span style="color:#dc3232;">Enter a page URL.</span>');
                    $btn.prop('disabled', false);
                    return;
                }

                $status.text('Resolving...');

                $.post(ajaxurl, {
                    action: 'cache_party_resolve_url',
                    nonce: nonce,
                    url: url
                }).done(function(res) {
                    if (!res.success) {
                        $status.html('<span style="color:#dc3232;">' + res.data.message + '</span>');
                        $btn.prop('disabled', false);
                        return;
                    }

                    var slug = res.data.slug;
                    var title = res.data.title;

                    // Check if already exists.
                    if ($('tr[data-template="' + slug + '"]').length) {
                        $status.html('<span style="color:#dba617;">Already exists — use Regenerate.</span>');
                        $btn.prop('disabled', false);
                        return;
                    }

                    // Remove empty-state row.
                    $('.cp-perpage-empty').remove();

                    // Add row to per-page table.
                    var row = '<tr data-template="' + slug + '">' +
                        '<td><strong>' + $('<span>').text(title).html() + '</strong><br><code>' + slug + '</code></td>' +
                        '<td><input type="text" class="regular-text cp-critical-url" data-template="' + slug + '" value="' + $('<span>').text(url).html() + '" style="width:100%;" /></td>' +
                        '<td class="cp-col-status"><span style="color:#999;">Generating...</span></td>' +
                        '<td class="cp-col-size">&mdash;</td>' +
                        '<td class="cp-col-age">&mdash;</td>' +
                        '<td><button type="button" class="button cp-generate-critical" data-template="' + slug + '" disabled>Regenerate</button>' +
                        '<span class="cp-critical-status" style="margin-left:8px;"></span></td></tr>';
                    $('#cp-perpage-table tbody').append(row);

                    var $row = $('tr[data-template="' + slug + '"]').not('.cp-editor-row');
                    var $genBtn = $row.find('.cp-generate-critical');
                    var $genStatus = $row.find('.cp-critical-status');

                    // Generate immediately.
                    generateTemplate(slug, url, $genBtn, $genStatus).always(function() {
                        $btn.prop('disabled', false);
                        $status.text('');
                        $('#cp-perpage-url').val('');
                    });
                }).fail(function() {
                    $status.html('<span style="color:#dc3232;">Request failed.</span>');
                    $btn.prop('disabled', false);
                });
            });
        });
        </script>
        <?php
    }

    private function render_tab_warmer() {
        $api_key   = get_option( 'cache_party_api_key', '' );
        $last_warm = get_option( 'cache_party_last_warm', '' );

        $this->render_module_toggle(
            'warmer',
            'Enable Cache Warmer',
            'Automatic cache warming on content changes (requires a Cache Party API key on the General tab)'
        );
        ?>

        <?php if ( empty( $api_key ) ) : ?>
            <div class="notice notice-warning inline" style="margin: 20px 0 10px;">
                <p>Add your API key on the <a href="<?php echo esc_url( add_query_arg( 'tab', 'general', admin_url( 'admin.php?page=cache-party' ) ) ); ?>">General tab</a> to enable cache warming.</p>
            </div>
        <?php endif; ?>

        <h2>Manual Controls</h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">Manual Warm</th>
                <td>
                    <button type="button" class="button" id="cp-trigger-warm" <?php disabled( empty( $api_key ) ); ?>>Trigger Warm Now</button>
                    <span id="cp-warmer-warm-result" style="margin-left:8px;"></span>
                    <p class="description">Kick off a full-site warm immediately. Test Connection and Register Site live on the <a href="<?php echo esc_url( add_query_arg( 'tab', 'general', admin_url( 'admin.php?page=cache-party' ) ) ); ?>">General tab</a>.</p>
                </td>
            </tr>
            <?php if ( $last_warm ) : ?>
            <tr>
                <th scope="row">Last warm</th>
                <td><?php echo esc_html( $last_warm ); ?></td>
            </tr>
            <?php endif; ?>
        </table>

        <h2>Automatic Warming</h2>
        <p class="description">When enabled, the warmer is notified automatically on:</p>
        <ul style="list-style:disc;padding-left:20px;">
            <li><code>save_post</code> — URL-specific warm when a post is published/updated</li>
            <li><code>trashed_post</code> / <code>deleted_post</code> — homepage warm</li>
            <li><code>wp_update_nav_menu</code> — full site warm</li>
            <li><code>customize_save_after</code> — full site warm</li>
        </ul>

        <input type="hidden" id="cp-warmer-trigger-nonce" value="<?php echo esc_attr( wp_create_nonce( 'cache_party_warmer' ) ); ?>">

        <script>
        jQuery(function($) {
            $('#cp-trigger-warm').on('click', function() {
                var $btn = $(this), $result = $('#cp-warmer-warm-result');
                $btn.prop('disabled', true);
                $result.text('Triggering...');
                $.post(ajaxurl, {
                    action: 'cache_party_trigger_warm',
                    nonce: $('#cp-warmer-trigger-nonce').val()
                }).done(function(res) {
                    $result.html('<span style="color:' + (res.success ? '#46b450' : '#dc3232') + ';">' + (res.data ? res.data.message : 'Unknown') + '</span>');
                }).fail(function() {
                    $result.html('<span style="color:#dc3232;">Request failed.</span>');
                }).always(function() {
                    $btn.prop('disabled', false);
                });
            });
        });
        </script>
        <?php
    }

    private function render_tab_advanced() {
        $settings = wp_parse_args( get_option( 'cache_party_assets', [] ), self::asset_defaults() );
        $cleanup  = (bool) get_option( 'cache_party_cleanup', false );
        ?>
        <p class="description" style="margin-top:1em;">Opinionated defaults that squeeze out more performance but may have side effects on some sites. Toggle with intention.</p>

        <h2>CSS Preload</h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="cp_preload_css_http">CSS preload via HTTP headers</label></th>
                <td>
                    <input type="hidden" name="cache_party_assets[preload_css_http]" value="0" />
                    <input type="checkbox" name="cache_party_assets[preload_css_http]" id="cp_preload_css_http" value="1"
                        <?php checked( $settings['preload_css_http'] ); ?> />
                    <p class="description">Send CSS preload as HTTP Link headers (faster) instead of &lt;link&gt; tags.</p>
                </td>
            </tr>
        </table>

        <h2>Plugin Detection</h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="cp_auto_detect_plugins">Auto-detect plugins</label></th>
                <td>
                    <input type="hidden" name="cache_party_assets[auto_detect_plugins]" value="0" />
                    <input type="checkbox" name="cache_party_assets[auto_detect_plugins]" id="cp_auto_detect_plugins" value="1"
                        <?php checked( $settings['auto_detect_plugins'] ); ?> />
                    <p class="description">Automatically add delay rules for known plugins (PixelYourSite, GTM, etc.).</p>
                </td>
            </tr>
        </table>

        <h2>WordPress Bloat Removal</h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="cp_remove_emojis">Remove WordPress emojis</label></th>
                <td>
                    <input type="hidden" name="cache_party_assets[remove_emojis]" value="0" />
                    <input type="checkbox" name="cache_party_assets[remove_emojis]" id="cp_remove_emojis" value="1"
                        <?php checked( $settings['remove_emojis'] ); ?> />
                    <p class="description">Remove WordPress core emoji inline CSS, inline JavaScript, and DNS prefetch.</p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="cp_remove_block_styles">Remove WordPress block styles</label></th>
                <td>
                    <input type="hidden" name="cache_party_assets[remove_block_styles]" value="0" />
                    <input type="checkbox" name="cache_party_assets[remove_block_styles]" id="cp_remove_block_styles" value="1"
                        <?php checked( $settings['remove_block_styles'] ); ?> />
                    <p class="description">Remove block editor frontend CSS (~30KB) and global styles preset variables (~2KB). Safe for sites not using Gutenberg blocks for content.</p>
                </td>
            </tr>
        </table>

        <h2>Uninstall</h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">
                    <label for="cache_party_cleanup">Delete data on uninstall</label>
                </th>
                <td>
                    <input type="hidden" name="cache_party_cleanup" value="0" />
                    <input type="checkbox" name="cache_party_cleanup" id="cache_party_cleanup" value="1"
                        <?php checked( $cleanup ); ?> />
                    <p class="description">
                        If enabled, all generated WebP files, settings, and metadata will be removed when this plugin is deleted.
                    </p>
                </td>
            </tr>
        </table>
        <?php
    }

    private function render_tab_cloudflare() {
        $cf_settings = get_option( 'cache_party_cloudflare', [] );
        $cf_email    = defined( 'CLOUDFLARE_EMAIL' ) ? CLOUDFLARE_EMAIL : ( $cf_settings['email'] ?? '' );
        $cf_key      = defined( 'CLOUDFLARE_API_KEY' ) ? CLOUDFLARE_API_KEY : ( $cf_settings['api_key'] ?? '' );
        $cf_domain   = defined( 'CLOUDFLARE_DOMAIN_NAME' ) ? CLOUDFLARE_DOMAIN_NAME : ( $cf_settings['domain'] ?? '' );
        $cf_const    = defined( 'CLOUDFLARE_EMAIL' ) || defined( 'CLOUDFLARE_API_KEY' );
        ?>

        <p class="description">Auto-purge Cloudflare cache on content changes. After purge, the warmer is notified to re-prime.</p>

        <?php if ( $cf_const ) : ?>
            <div class="notice notice-info inline" style="margin-bottom: 1em;">
                <p>Cloudflare credentials are defined as constants in <code>wp-config.php</code>. Fields below are read-only.</p>
            </div>
        <?php endif; ?>

        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="cp_cf_email">Cloudflare email</label></th>
                <td>
                    <input type="email" name="cache_party_cloudflare[email]" id="cp_cf_email"
                           value="<?php echo esc_attr( $cf_email ); ?>"
                           class="regular-text" <?php echo $cf_const ? 'readonly' : ''; ?> />
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="cp_cf_api_key">API key / token</label></th>
                <td>
                    <input type="password" name="cache_party_cloudflare[api_key]" id="cp_cf_api_key"
                           value="<?php echo esc_attr( $cf_key ); ?>"
                           class="regular-text" autocomplete="off" <?php echo $cf_const ? 'readonly' : ''; ?> />
                    <p class="description">
                        <strong>Recommended:</strong> Create a scoped <a href="https://dash.cloudflare.com/profile/api-tokens" target="_blank">API Token</a> with <code>Zone &gt; Cache Purge &gt; Purge</code> permission for this zone only. Email field is not needed for tokens.<br>
                        Also accepts a Global API Key (requires email above). Auth method is auto-detected.
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="cp_cf_domain">Domain</label></th>
                <td>
                    <input type="text" name="cache_party_cloudflare[domain]" id="cp_cf_domain"
                           value="<?php echo esc_attr( $cf_domain ); ?>"
                           class="regular-text" placeholder="<?php echo esc_attr( wp_parse_url( home_url(), PHP_URL_HOST ) ); ?>" />
                    <p class="description">Cloudflare zone domain. Defaults to your site domain if empty.</p>
                </td>
            </tr>
        </table>

        <h2>Auto-Purge Triggers</h2>
        <ul style="list-style:disc;padding-left:20px;">
            <li><code>transition_post_status</code> — selective purge: post URL + taxonomies + homepage + feeds</li>
            <li><code>deleted_post</code> / <code>delete_attachment</code> — homepage + feeds</li>
            <li><code>comment_post</code> / <code>transition_comment_status</code> — post URL</li>
            <li><code>switch_theme</code> / <code>customize_save_after</code> — full purge</li>
        </ul>
        <p class="description">The admin bar "Purge CF Cache" button triggers a full zone purge.</p>
        <?php
    }
}
