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
        register_setting( 'cache_party', 'cache_party_modules', [
            'type'              => 'array',
            'sanitize_callback' => [ $this, 'sanitize_modules' ],
            'default'           => [ 'images' ],
            'show_in_rest'      => false,
        ] );

        register_setting( 'cache_party', 'cache_party_images', [
            'type'              => 'array',
            'sanitize_callback' => [ $this, 'sanitize_images' ],
            'default'           => self::image_defaults(),
            'show_in_rest'      => false,
        ] );

        register_setting( 'cache_party', 'cache_party_assets', [
            'type'              => 'array',
            'sanitize_callback' => [ $this, 'sanitize_assets' ],
            'default'           => self::asset_defaults(),
            'show_in_rest'      => false,
        ] );

        register_setting( 'cache_party', 'cache_party_warmer', [
            'type'              => 'array',
            'sanitize_callback' => [ $this, 'sanitize_warmer' ],
            'default'           => [],
            'show_in_rest'      => false,
        ] );

        register_setting( 'cache_party', 'cache_party_cleanup', [
            'type'              => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default'           => false,
            'show_in_rest'      => false,
        ] );
    }

    public static function image_defaults() {
        return [
            'webp_enabled'     => true,
            'webp_quality'     => 80,
            'picture_enabled'  => true,
            'lazy_enabled'     => true,
            'eager_count'      => 2,
            'auto_alt_enabled' => true,
            'exclude_keywords' => '',
        ];
    }

    public static function asset_defaults() {
        return [
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
        ];
    }

    public function sanitize_assets( $input ) {
        $defaults = self::asset_defaults();
        $clean    = [];

        $clean['css_defer_enabled']       = ! empty( $input['css_defer_enabled'] );
        $clean['css_defer_keywords']      = isset( $input['css_defer_keywords'] ) ? sanitize_textarea_field( $input['css_defer_keywords'] ) : '';
        $clean['css_defer_except']        = isset( $input['css_defer_except'] ) ? sanitize_textarea_field( $input['css_defer_except'] ) : '';
        $clean['css_delete_keywords']     = isset( $input['css_delete_keywords'] ) ? sanitize_textarea_field( $input['css_delete_keywords'] ) : '';
        $clean['js_delay_enabled']        = ! empty( $input['js_delay_enabled'] );
        $clean['js_delay_tag_keywords']   = isset( $input['js_delay_tag_keywords'] ) ? sanitize_textarea_field( $input['js_delay_tag_keywords'] ) : '';
        $clean['js_delay_code_keywords']  = isset( $input['js_delay_code_keywords'] ) ? sanitize_textarea_field( $input['js_delay_code_keywords'] ) : '';
        $clean['js_delete_keywords']      = isset( $input['js_delete_keywords'] ) ? sanitize_textarea_field( $input['js_delete_keywords'] ) : '';
        $clean['js_move_to_end_keywords'] = isset( $input['js_move_to_end_keywords'] ) ? sanitize_textarea_field( $input['js_move_to_end_keywords'] ) : '';
        $clean['iframe_lazy_enabled']     = ! empty( $input['iframe_lazy_enabled'] );
        $clean['iframe_exclude_keywords'] = isset( $input['iframe_exclude_keywords'] ) ? sanitize_textarea_field( $input['iframe_exclude_keywords'] ) : '';
        $clean['idle_timeout']            = isset( $input['idle_timeout'] ) ? max( 0, min( 30, (int) $input['idle_timeout'] ) ) : $defaults['idle_timeout'];
        $clean['preload_css_http']        = ! empty( $input['preload_css_http'] );
        $clean['auto_detect_plugins']     = ! empty( $input['auto_detect_plugins'] );

        return $clean;
    }

    public function sanitize_warmer( $input ) {
        return [
            'api_url'   => isset( $input['api_url'] ) ? esc_url_raw( $input['api_url'] ) : '',
            'api_key'   => isset( $input['api_key'] ) ? sanitize_text_field( $input['api_key'] ) : '',
            'site_name' => isset( $input['site_name'] ) ? sanitize_text_field( $input['site_name'] ) : '',
        ];
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

    public function render_page() {
        $tabs = apply_filters( 'cache_party_settings_tabs', [
            'general' => 'General',
            'images'  => 'Images',
            'assets'  => 'Assets',
            'warmer'  => 'Warmer',
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
                settings_fields( 'cache_party' );

                // Hidden field to redirect back to current tab after save.
                echo '<input type="hidden" name="_wp_http_referer" value="' . esc_attr( add_query_arg( 'tab', $current_tab, admin_url( 'admin.php?page=cache-party' ) ) ) . '" />';

                $method = 'render_tab_' . $current_tab;
                if ( method_exists( $this, $method ) ) {
                    $this->$method();
                }

                do_action( 'cache_party_settings_tab_' . $current_tab );

                submit_button( 'Save Changes' );
                ?>
            </form>

            <?php do_action( 'cache_party_after_settings_form', $current_tab ); ?>
        </div>
        <?php
    }

    private function render_tab_general() {
        $modules = get_option( 'cache_party_modules', [ 'images' ] );
        $cleanup = (bool) get_option( 'cache_party_cleanup', false );
        ?>
        <h2>Modules</h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">Image Optimizer</th>
                <td>
                    <input type="hidden" name="cache_party_modules[]" value="" />
                    <label>
                        <input type="checkbox" name="cache_party_modules[]" value="images"
                            <?php checked( in_array( 'images', $modules, true ) ); ?> />
                        WebP conversion, picture wrapping, smart lazy loading, auto alt text
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row">Asset Optimizer</th>
                <td>
                    <label>
                        <input type="checkbox" name="cache_party_modules[]" value="assets"
                            <?php checked( in_array( 'assets', $modules, true ) ); ?> />
                        CSS deferral, JS delay, iframe lazy loading, resource hints
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row">Cache Warmer</th>
                <td>
                    <label>
                        <input type="checkbox" name="cache_party_modules[]" value="warmer"
                            <?php checked( in_array( 'warmer', $modules, true ) ); ?> />
                        Automatic cache warming on content changes
                    </label>
                </td>
            </tr>
        </table>

        <h2>Cleanup</h2>
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

    private function render_tab_images() {
        $settings   = wp_parse_args( get_option( 'cache_party_images', [] ), self::image_defaults() );
        $webp_engine = \CacheParty\Images\WebP_Converter::is_conversion_available();
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
                    <p class="description">Number of above-the-fold images to load eagerly (0-20). Default: 2.</p>
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
        $ao_active = defined( 'AUTOPTIMIZE_PLUGIN_VERSION' );
        ?>

        <?php if ( ! $ao_active ) : ?>
            <div class="notice notice-info inline" style="margin: 20px 0 10px;">
                <p>Autoptimize is not active. CSS/JS deferral and delay still work, but assets will not be minified.</p>
            </div>
        <?php endif; ?>

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
                    <p class="description">One per line. Stylesheets matching these keywords will be deferred. Leave empty to defer all (except those in "Keep in head").</p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="cp_css_defer_except">Keep in head</label></th>
                <td>
                    <textarea name="cache_party_assets[css_defer_except]" id="cp_css_defer_except"
                              rows="3" class="large-text"><?php echo esc_textarea( $settings['css_defer_except'] ); ?></textarea>
                    <p class="description">One per line. Stylesheets matching these keywords will NOT be deferred (loaded normally in head).</p>
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

        <h2>Advanced</h2>
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

        <?php do_action( 'cache_party_after_assets_settings' ); ?>
        <?php
    }

    private function render_tab_warmer() {
        $settings  = wp_parse_args( get_option( 'cache_party_warmer', [] ), \CacheParty\Warmer\Warmer_Client::defaults() );
        $last_warm = get_option( 'cache_party_last_warm', '' );
        ?>
        <h2>Warmer Connection</h2>
        <p class="description">Connect to your Railway-hosted cache warmer service. Content changes will trigger non-blocking warm requests.</p>

        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="cp_warmer_api_url">API URL</label></th>
                <td>
                    <input type="url" name="cache_party_warmer[api_url]" id="cp_warmer_api_url"
                           value="<?php echo esc_attr( $settings['api_url'] ); ?>"
                           class="regular-text" placeholder="https://cacheparty.com" />
                    <p class="description">Base URL of your cache warmer service.</p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="cp_warmer_api_key">API Key</label></th>
                <td>
                    <input type="text" name="cache_party_warmer[api_key]" id="cp_warmer_api_key"
                           value="<?php echo esc_attr( $settings['api_key'] ); ?>"
                           class="regular-text" placeholder="Bearer token" />
                    <p class="description">AUTH_TOKEN configured in your Railway warmer environment.</p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="cp_warmer_site_name">Site name</label></th>
                <td>
                    <input type="text" name="cache_party_warmer[site_name]" id="cp_warmer_site_name"
                           value="<?php echo esc_attr( $settings['site_name'] ); ?>"
                           class="regular-text" placeholder="<?php echo esc_attr( wp_parse_url( home_url(), PHP_URL_HOST ) ); ?>" />
                    <p class="description">Site identifier in the warmer's sites.json. Defaults to domain if empty.</p>
                </td>
            </tr>
        </table>

        <h2>Status</h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">Connection</th>
                <td>
                    <button type="button" class="button" id="cp-test-warmer">Test Connection</button>
                    <span id="cp-warmer-test-result" style="margin-left:8px;"></span>
                </td>
            </tr>
            <tr>
                <th scope="row">Manual Warm</th>
                <td>
                    <button type="button" class="button" id="cp-trigger-warm">Trigger Warm Now</button>
                    <span id="cp-warmer-warm-result" style="margin-left:8px;"></span>
                </td>
            </tr>
            <?php if ( $last_warm ) : ?>
            <tr>
                <th scope="row">Last warm</th>
                <td><?php echo esc_html( $last_warm ); ?></td>
            </tr>
            <?php endif; ?>
        </table>

        <h2>Hooks</h2>
        <p class="description">The warmer is notified automatically on:</p>
        <ul style="list-style:disc;padding-left:20px;">
            <li><code>save_post</code> — URL-specific warm when a post is published/updated</li>
            <li><code>trashed_post</code> / <code>deleted_post</code> — homepage warm</li>
            <li><code>wp_update_nav_menu</code> — full site warm</li>
            <li><code>customize_save_after</code> — full site warm</li>
        </ul>

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

            $('#cp-trigger-warm').on('click', function() {
                var $btn = $(this), $result = $('#cp-warmer-warm-result');
                $btn.prop('disabled', true);
                $result.text('Triggering...');
                $.post(ajaxurl, {
                    action: 'cache_party_trigger_warm',
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

        <?php do_action( 'cache_party_after_warmer_settings' ); ?>
        <?php
    }
}
