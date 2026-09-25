<?php
/**
 * Plugin Name: Taisa Amazon Creators
 * Description: Amazon Creators API integration with ES/DE affiliate links, AAWP-compatible shortcodes, product cards, searches and legacy AAWP tables.
 * Version: 0.2.3
 * Author: Taisa - Raquel Garcia Arevalo
 * Author URI: https://www.taisadigital.com
 * Requires at least: 6.3
 * Requires PHP: 7.4
 * Text Domain: taisa-amazon-creators
 * Update URI: https://github.com/TaisaDigital/taisa-amazon-creators
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'TAC_VERSION', '0.2.3' );
define( 'TAC_OPTION', 'taisa_amazon_creators_settings' );
define( 'TAC_LOG_OPTION', 'taisa_amazon_creators_log' );
define( 'TAC_GITHUB_REPO', 'TaisaDigital/taisa-amazon-creators' );
define( 'TAC_UPDATE_TRANSIENT', 'tac_github_latest_release' );

final class Taisa_Amazon_Creators {
    private static $instance = null;
    private $runtime_items = [];

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu', [ $this, 'admin_menu' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'admin_assets' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'front_assets' ] );
        add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'check_github_update' ] );
        add_filter( 'plugins_api', [ $this, 'github_plugin_information' ], 20, 3 );

        add_shortcode( 'taisa_amazon', [ $this, 'shortcode_product' ] );
        add_shortcode( 'taisa_amazon_search', [ $this, 'shortcode_search' ] );
        add_action( 'init', [ $this, 'maybe_register_aawp_compat' ], 99 );
    }

    public function defaults() {
        return [
            'credential_id'       => '',
            'credential_secret'   => '',
            'credential_version'  => '3.2',
            'default_marketplace' => 'es',
            'partner_tag_es'      => '',
            'partner_tag_de'      => '',
            'show_secondary'      => 1,
            'cache_hours'         => 24,
            'aawp_compat'         => 0,
            'diagnostic_log'      => 1,
        ];
    }

    public function settings() {
        $saved = get_option( TAC_OPTION, [] );
        return wp_parse_args( is_array( $saved ) ? $saved : [], $this->defaults() );
    }

    public function register_settings() {
        register_setting(
            'tac_settings_group',
            TAC_OPTION,
            [
                'type'              => 'array',
                'sanitize_callback' => [ $this, 'sanitize_settings' ],
                'default'           => $this->defaults(),
            ]
        );
    }

    public function sanitize_settings( $input ) {
        $old = $this->settings();
        $out = $this->defaults();

        $out['credential_id'] = defined( 'TAC_CREDENTIAL_ID' )
            ? $old['credential_id']
            : sanitize_text_field( $input['credential_id'] ?? '' );

        if ( defined( 'TAC_CREDENTIAL_SECRET' ) ) {
            $out['credential_secret'] = $old['credential_secret'];
        } else {
            $secret = isset( $input['credential_secret'] ) ? trim( (string) $input['credential_secret'] ) : '';
            $out['credential_secret'] = '' !== $secret ? $secret : $old['credential_secret'];
        }

        $version = sanitize_text_field( $input['credential_version'] ?? '3.2' );
        $out['credential_version'] = in_array( $version, [ '3.1', '3.2', '3.3' ], true ) ? $version : '3.2';

        $market = sanitize_key( $input['default_marketplace'] ?? 'es' );
        $out['default_marketplace'] = in_array( $market, [ 'es', 'de' ], true ) ? $market : 'es';

        $out['partner_tag_es'] = sanitize_text_field( $input['partner_tag_es'] ?? '' );
        $out['partner_tag_de'] = sanitize_text_field( $input['partner_tag_de'] ?? '' );
        $out['show_secondary'] = empty( $input['show_secondary'] ) ? 0 : 1;
        $out['cache_hours']    = max( 1, min( 24, absint( $input['cache_hours'] ?? 24 ) ) );
        $out['aawp_compat']    = empty( $input['aawp_compat'] ) ? 0 : 1;
        $out['diagnostic_log'] = empty( $input['diagnostic_log'] ) ? 0 : 1;

        $this->clear_token_cache();
        return $out;
    }

    public function admin_menu() {
        add_options_page(
            'Amazon Creators',
            'Amazon Creators',
            'manage_options',
            'taisa-amazon-creators',
            [ $this, 'settings_page' ]
        );
    }

    public function admin_assets( $hook ) {
        if ( 'settings_page_taisa-amazon-creators' !== $hook ) {
            return;
        }
        wp_enqueue_style( 'tac-admin', plugin_dir_url( __FILE__ ) . 'assets/admin.css', [], TAC_VERSION );
    }

    public function front_assets() {
        wp_register_style( 'tac-front', plugin_dir_url( __FILE__ ) . 'assets/front.css', [], TAC_VERSION );
    }

    public function check_github_update( $transient ) {
        if ( ! is_object( $transient ) ) {
            return $transient;
        }

        $release = $this->github_latest_release();
        if ( ! $release || empty( $release['version'] ) || version_compare( TAC_VERSION, $release['version'], '>=' ) ) {
            return $transient;
        }

        $plugin_file = plugin_basename( __FILE__ );
        $transient->response[ $plugin_file ] = (object) [
            'id'          => 'github.com/' . TAC_GITHUB_REPO,
            'slug'        => dirname( $plugin_file ),
            'plugin'      => $plugin_file,
            'new_version' => $release['version'],
            'url'         => $release['url'],
            'package'     => $release['package'],
            'tested'      => '6.8',
            'requires_php'=> '7.4',
        ];

        return $transient;
    }

    public function github_plugin_information( $result, $action, $args ) {
        if ( 'plugin_information' !== $action || empty( $args->slug ) || dirname( plugin_basename( __FILE__ ) ) !== $args->slug ) {
            return $result;
        }

        $release = $this->github_latest_release();
        if ( ! $release ) {
            return $result;
        }

        return (object) [
            'name'           => 'Taisa Amazon Creators',
            'slug'           => dirname( plugin_basename( __FILE__ ) ),
            'version'        => $release['version'],
            'author'         => '<a href="https://www.taisadigital.com">Taisa - Raquel Garcia Arevalo</a>',
            'homepage'       => 'https://github.com/' . TAC_GITHUB_REPO,
            'download_link'  => $release['package'],
            'sections'       => [
                'description' => 'Plugin ligero de Amazon afiliados para WordPress basado en Amazon Creators API, con soporte actual para Amazon.es y Amazon.de.',
                'changelog'   => $release['body'],
            ],
            'banners'        => [],
            'requires'      => '6.3',
            'requires_php'  => '7.4',
        ];
    }

    private function github_latest_release() {
        $cached = get_site_transient( TAC_UPDATE_TRANSIENT );
        if ( false !== $cached && is_array( $cached ) ) {
            return $cached;
        }

        $response = wp_remote_get(
            'https://api.github.com/repos/' . TAC_GITHUB_REPO . '/releases/latest',
            [
                'timeout' => 10,
                'headers' => [
                    'Accept'     => 'application/vnd.github+json',
                    'User-Agent' => 'Taisa-Amazon-Creators/' . TAC_VERSION,
                ],
            ]
        );

        if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
            set_site_transient( TAC_UPDATE_TRANSIENT, [], 6 * HOUR_IN_SECONDS );
            return [];
        }

        $json = json_decode( wp_remote_retrieve_body( $response ), true );
        $tag = sanitize_text_field( $json['tag_name'] ?? '' );
        $version = preg_replace( '/^v/i', '', $tag );
        if ( ! $version || ! preg_match( '/^\\d+\\.\\d+\\.\\d+([-.][0-9A-Za-z.-]+)?$/', $version ) ) {
            set_site_transient( TAC_UPDATE_TRANSIENT, [], 6 * HOUR_IN_SECONDS );
            return [];
        }

        $package = '';
        foreach ( (array) ( $json['assets'] ?? [] ) as $asset ) {
            $name = sanitize_file_name( $asset['name'] ?? '' );
            if ( preg_match( '/\\.zip$/i', $name ) && ! empty( $asset['browser_download_url'] ) ) {
                $package = esc_url_raw( $asset['browser_download_url'] );
                break;
            }
        }
        if ( ! $package ) {
            $package = esc_url_raw( $json['zipball_url'] ?? '' );
        }

        $release = [
            'version' => $version,
            'package' => $package,
            'url'     => esc_url_raw( $json['html_url'] ?? 'https://github.com/' . TAC_GITHUB_REPO ),
            'body'    => wp_kses_post( $json['body'] ?? '' ),
        ];
        set_site_transient( TAC_UPDATE_TRANSIENT, $release, 12 * HOUR_IN_SECONDS );
        return $release;
    }

    public function settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $settings = $this->settings();
        $tests = [];
        $oauth_test = null;
        $log_cleared = false;

        if ( isset( $_POST['tac_test_oauth'] ) ) {
            check_admin_referer( 'tac_test_oauth' );
            $this->clear_token_cache();
            $oauth_test = $this->access_token( true );
        }

        if ( isset( $_POST['tac_clear_log'] ) ) {
            check_admin_referer( 'tac_clear_log' );
            delete_option( TAC_LOG_OPTION );
            $log_cleared = true;
        }

        if ( isset( $_POST['tac_test_connection'] ) ) {
            check_admin_referer( 'tac_test_connection' );
            $test_asin = $this->sanitize_asin( $_POST['tac_test_asin'] ?? '' );
            if ( ! $test_asin ) {
                $tests['general'] = new WP_Error( 'invalid_asin', 'Introduce un ASIN valido de 10 caracteres.' );
            } else {
                foreach ( [ 'es', 'de' ] as $market ) {
                    $config = $this->marketplace_config( $market );
                    if ( ! empty( $config['tag'] ) ) {
                        $tests[ $market ] = $this->get_items( [ $test_asin ], $market, true );
                    }
                }
            }
        }
        ?>
        <div class="wrap tac-admin-wrap">
            <h1>Amazon Creators</h1>
            <p>Una credencial de Creators API y Partner Tags independientes para Amazon.es y Amazon.de.</p>

            <?php if ( $oauth_test instanceof WP_Error ) : ?>
                <div class="notice notice-error"><p><strong>OAuth:</strong> <?php echo esc_html( $oauth_test->get_error_message() ); ?></p></div>
            <?php elseif ( is_string( $oauth_test ) && '' !== $oauth_test ) : ?>
                <div class="notice notice-success"><p><strong>OAuth:</strong> autenticacion correcta. Token obtenido y guardado temporalmente.</p></div>
            <?php endif; ?>

            <?php if ( $log_cleared ) : ?>
                <div class="notice notice-success"><p>Registro de diagnostico vaciado.</p></div>
            <?php endif; ?>

            <?php foreach ( $tests as $market => $test ) : ?>
                <?php if ( $test instanceof WP_Error ) : ?>
                    <div class="notice notice-error"><p><strong><?php echo esc_html( strtoupper( $market ) ); ?>:</strong> <?php echo esc_html( $test->get_error_message() ); ?></p></div>
                <?php else : ?>
                    <div class="notice notice-success"><p><strong><?php echo esc_html( strtoupper( $market ) ); ?>:</strong> conexion correcta, <?php echo esc_html( count( $test ) ); ?> producto(s).</p></div>
                <?php endif; ?>
            <?php endforeach; ?>

            <form method="post" action="options.php">
                <?php settings_fields( 'tac_settings_group' ); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="tac_credential_id">Credential ID</label></th>
                        <td>
                            <?php if ( defined( 'TAC_CREDENTIAL_ID' ) ) : ?>
                                <code>Definido en wp-config.php</code>
                            <?php else : ?>
                                <input id="tac_credential_id" name="<?php echo esc_attr( TAC_OPTION ); ?>[credential_id]" type="text" class="regular-text" value="<?php echo esc_attr( $settings['credential_id'] ); ?>" autocomplete="off">
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="tac_credential_secret">Credential Secret</label></th>
                        <td>
                            <?php if ( defined( 'TAC_CREDENTIAL_SECRET' ) ) : ?>
                                <code>Definido en wp-config.php</code>
                            <?php else : ?>
                                <input id="tac_credential_secret" name="<?php echo esc_attr( TAC_OPTION ); ?>[credential_secret]" type="password" class="regular-text" value="" placeholder="<?php echo empty( $settings['credential_secret'] ) ? 'Introduce el secreto' : 'Guardado. Dejalo vacio para conservarlo'; ?>" autocomplete="new-password">
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="tac_version">Version de credencial</label></th>
                        <td>
                            <select id="tac_version" name="<?php echo esc_attr( TAC_OPTION ); ?>[credential_version]">
                                <?php foreach ( [ '3.1', '3.2', '3.3' ] as $v ) : ?>
                                    <option value="<?php echo esc_attr( $v ); ?>" <?php selected( $settings['credential_version'], $v ); ?>><?php echo esc_html( $v ); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">Europa (incluidos ES y DE): 3.2.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="tac_default_market">Marketplace principal</label></th>
                        <td>
                            <select id="tac_default_market" name="<?php echo esc_attr( TAC_OPTION ); ?>[default_marketplace]">
                                <option value="es" <?php selected( $settings['default_marketplace'], 'es' ); ?>>Amazon.es</option>
                                <option value="de" <?php selected( $settings['default_marketplace'], 'de' ); ?>>Amazon.de</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="tac_tag_es">Partner Tag Amazon.es</label></th>
                        <td><input id="tac_tag_es" name="<?php echo esc_attr( TAC_OPTION ); ?>[partner_tag_es]" type="text" class="regular-text" value="<?php echo esc_attr( $settings['partner_tag_es'] ); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="tac_tag_de">Partner Tag Amazon.de</label></th>
                        <td><input id="tac_tag_de" name="<?php echo esc_attr( TAC_OPTION ); ?>[partner_tag_de]" type="text" class="regular-text" value="<?php echo esc_attr( $settings['partner_tag_de'] ); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row">Enlace secundario</th>
                        <td><label><input name="<?php echo esc_attr( TAC_OPTION ); ?>[show_secondary]" type="checkbox" value="1" <?php checked( $settings['show_secondary'], 1 ); ?>> Mostrar tambien el otro marketplace cuando tenga Partner Tag.</label></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="tac_cache">Cache de producto</label></th>
                        <td><input id="tac_cache" name="<?php echo esc_attr( TAC_OPTION ); ?>[cache_hours]" type="number" min="1" max="24" value="<?php echo esc_attr( $settings['cache_hours'] ); ?>"> horas</td>
                    </tr>
                    <tr>
                        <th scope="row">Registro de diagnostico</th>
                        <td>
                            <label><input name="<?php echo esc_attr( TAC_OPTION ); ?>[diagnostic_log]" type="checkbox" value="1" <?php checked( $settings['diagnostic_log'], 1 ); ?>> Guardar los ultimos eventos de OAuth/API sin secretos ni tokens.</label>
                            <p class="description">Util para diagnosticar elegibilidad, credenciales y errores de Amazon. Maximo 50 eventos.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Compatibilidad AAWP</th>
                        <td>
                            <label><input name="<?php echo esc_attr( TAC_OPTION ); ?>[aawp_compat]" type="checkbox" value="1" <?php checked( $settings['aawp_compat'], 1 ); ?>> Registrar tambien <code>[aawp]</code> y <code>[amazon]</code></label>
                            <p class="description"><strong>No activar mientras AAWP siga siendo el plugin principal.</strong></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>

            <hr>
            <h2>Diagnostico OAuth</h2>
            <p>Prueba solo la autenticacion, sin consultar productos. No muestra ni registra el secreto o el token.</p>
            <form method="post">
                <?php wp_nonce_field( 'tac_test_oauth' ); ?>
                <p><button type="submit" class="button button-secondary" name="tac_test_oauth" value="1">Probar OAuth</button></p>
            </form>

            <h2>Probar conexion completa</h2>
            <p>Guarda primero los ajustes. Si hay tags ES y DE, prueba ambos mercados con el mismo ASIN.</p>
            <form method="post">
                <?php wp_nonce_field( 'tac_test_connection' ); ?>
                <p>
                    <label>ASIN <input type="text" name="tac_test_asin" maxlength="10" value="B0CJDSQYND"></label>
                    <button type="submit" class="button button-secondary" name="tac_test_connection" value="1">Probar API</button>
                </p>
            </form>

            <hr>
            <h2>Registro de diagnostico</h2>
            <?php $diagnostic_log = get_option( TAC_LOG_OPTION, [] ); ?>
            <?php if ( empty( $diagnostic_log ) ) : ?>
                <p>No hay eventos registrados.</p>
            <?php else : ?>
                <div class="tac-log-wrap">
                    <table class="widefat striped">
                        <thead><tr><th>Fecha</th><th>Nivel</th><th>Evento</th><th>Datos seguros</th></tr></thead>
                        <tbody>
                        <?php foreach ( array_slice( (array) $diagnostic_log, 0, 50 ) as $entry ) : ?>
                            <tr>
                                <td><?php echo esc_html( $entry['time'] ?? '' ); ?></td>
                                <td><?php echo esc_html( strtoupper( $entry['level'] ?? '' ) ); ?></td>
                                <td><?php echo esc_html( $entry['event'] ?? '' ); ?></td>
                                <td><code><?php echo esc_html( wp_json_encode( $entry['context'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ); ?></code></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <form method="post" style="margin-top:12px">
                    <?php wp_nonce_field( 'tac_clear_log' ); ?>
                    <button type="submit" class="button" name="tac_clear_log" value="1">Vaciar registro</button>
                </form>
            <?php endif; ?>

            <hr>
            <h2>Shortcodes propios</h2>
            <p><code>[taisa_amazon asin="B0CJDSQYND"]</code></p>
            <p><code>[taisa_amazon_search keywords="plastificadora" items="3"]</code></p>
        </div>
        <?php
    }

    public function marketplace_config( $market = '' ) {
        $settings = $this->settings();
        $market = $market ? sanitize_key( $market ) : $settings['default_marketplace'];
        $configs = [
            'es' => [
                'key'      => 'es',
                'domain'   => 'www.amazon.es',
                'url'      => 'https://www.amazon.es',
                'language' => 'es_ES',
                'tag'      => $settings['partner_tag_es'],
                'label'    => 'Amazon.es',
                'flag'     => '&#x1F1EA;&#x1F1F8;',
            ],
            'de' => [
                'key'      => 'de',
                'domain'   => 'www.amazon.de',
                'url'      => 'https://www.amazon.de',
                'language' => 'de_DE',
                'tag'      => $settings['partner_tag_de'],
                'label'    => 'Amazon.de',
                'flag'     => '&#x1F1E9;&#x1F1EA;',
            ],
        ];
        $configs = apply_filters( 'tac_marketplaces', $configs, $settings );
        return $configs[ $market ] ?? $configs[ $settings['default_marketplace'] ] ?? $configs['es'];
    }

    private function other_market_key( $market = '' ) {
        $primary = $this->marketplace_config( $market );
        return 'es' === $primary['key'] ? 'de' : 'es';
    }

    private function credential_id() {
        if ( defined( 'TAC_CREDENTIAL_ID' ) ) {
            return (string) TAC_CREDENTIAL_ID;
        }
        $s = $this->settings();
        return (string) $s['credential_id'];
    }

    private function credential_secret() {
        if ( defined( 'TAC_CREDENTIAL_SECRET' ) ) {
            return (string) TAC_CREDENTIAL_SECRET;
        }
        $s = $this->settings();
        return (string) $s['credential_secret'];
    }

    private function credential_version() {
        if ( defined( 'TAC_CREDENTIAL_VERSION' ) ) {
            return (string) TAC_CREDENTIAL_VERSION;
        }
        $s = $this->settings();
        return (string) $s['credential_version'];
    }

    private function token_endpoint() {
        $version = $this->credential_version();
        if ( '3.1' === $version ) {
            return 'https://api.amazon.com/auth/o2/token';
        }
        if ( '3.3' === $version ) {
            return 'https://api.amazon.co.jp/auth/o2/token';
        }
        return 'https://api.amazon.co.uk/auth/o2/token';
    }

    private function token_cache_key() {
        return 'tac_token_' . substr( md5( $this->credential_id() . '|' . $this->credential_version() ), 0, 16 );
    }

    private function clear_token_cache() {
        delete_transient( $this->token_cache_key() );
    }

    private function diagnostic_context() {
        $client_id = $this->credential_id();
        $secret = $this->credential_secret();
        $id_len = strlen( $client_id );
        $masked = '';
        if ( $id_len > 0 ) {
            $masked = $id_len <= 8
                ? str_repeat( '*', $id_len )
                : substr( $client_id, 0, 4 ) . str_repeat( '*', max( 1, $id_len - 8 ) ) . substr( $client_id, -4 );
        }
        return [
            'credential_version'    => $this->credential_version(),
            'token_endpoint'        => $this->token_endpoint(),
            'credential_id_masked'  => $masked,
            'credential_id_length'  => $id_len,
            'credential_secret_len' => strlen( $secret ),
            'partner_tag_es_set'    => '' !== trim( (string) $this->settings()['partner_tag_es'] ),
            'partner_tag_de_set'    => '' !== trim( (string) $this->settings()['partner_tag_de'] ),
        ];
    }

    private function log_event( $level, $event, array $context = [] ) {
        $settings = $this->settings();
        if ( empty( $settings['diagnostic_log'] ) ) {
            return;
        }
        unset( $context['access_token'], $context['client_secret'], $context['credential_secret'], $context['authorization'] );
        $log = get_option( TAC_LOG_OPTION, [] );
        $log = is_array( $log ) ? $log : [];
        array_unshift( $log, [
            'time'    => current_time( 'mysql' ),
            'level'   => sanitize_key( $level ),
            'event'   => sanitize_text_field( $event ),
            'context' => $context,
        ] );
        update_option( TAC_LOG_OPTION, array_slice( $log, 0, 50 ), false );
    }

    private function access_token( $force = false ) {
        $key = $this->token_cache_key();
        if ( ! $force ) {
            $cached = get_transient( $key );
            if ( is_string( $cached ) && '' !== $cached ) {
                return $cached;
            }
        }

        $client_id = $this->credential_id();
        $secret = $this->credential_secret();
        if ( '' === $client_id || '' === $secret ) {
            $this->log_event( 'error', 'oauth_missing_credentials', $this->diagnostic_context() );
            return new WP_Error( 'tac_missing_credentials', 'Faltan Credential ID o Credential Secret.' );
        }

        $response = wp_remote_post(
            $this->token_endpoint(),
            [
                'timeout' => 15,
                'headers' => [ 'Content-Type' => 'application/json' ],
                'body'    => wp_json_encode(
                    [
                        'grant_type'    => 'client_credentials',
                        'client_id'     => $client_id,
                        'client_secret' => $secret,
                        'scope'         => 'creatorsapi::default',
                    ]
                ),
            ]
        );

        if ( is_wp_error( $response ) ) {
            $ctx = $this->diagnostic_context();
            $ctx['wp_error'] = $response->get_error_message();
            $this->log_event( 'error', 'oauth_transport_error', $ctx );
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $json = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( $code < 200 || $code >= 300 || empty( $json['access_token'] ) ) {
            $message = $json['error_description'] ?? $json['error'] ?? 'No se pudo obtener token OAuth.';
            $ctx = $this->diagnostic_context();
            $ctx['http_status'] = $code;
            $ctx['error'] = sanitize_text_field( (string) ( $json['error'] ?? '' ) );
            $ctx['error_description'] = sanitize_text_field( (string) ( $json['error_description'] ?? '' ) );
            $this->log_event( 'error', 'oauth_rejected', $ctx );
            return new WP_Error( 'tac_token_error', sanitize_text_field( (string) $message ), [ 'status' => $code, 'diagnostic' => $ctx ] );
        }

        $ttl = max( 300, absint( $json['expires_in'] ?? 3600 ) - 120 );
        set_transient( $key, (string) $json['access_token'], $ttl );
        $ctx = $this->diagnostic_context();
        $ctx['http_status'] = $code;
        $ctx['expires_in'] = absint( $json['expires_in'] ?? 3600 );
        $this->log_event( 'success', 'oauth_token_obtained', $ctx );
        return (string) $json['access_token'];
    }

    private function api_request( $path, array $payload, $market = '', $retry = true ) {
        $config = $this->marketplace_config( $market );
        if ( empty( $config['tag'] ) ) {
            return new WP_Error( 'tac_missing_partner_tag', 'Falta el Partner Tag para ' . $config['domain'] . '.' );
        }

        $token = $this->access_token();
        if ( is_wp_error( $token ) ) {
            return $token;
        }

        $payload['marketplace'] = $config['domain'];
        $payload['partnerTag'] = $config['tag'];
        if ( empty( $payload['languagesOfPreference'] ) ) {
            $payload['languagesOfPreference'] = [ $config['language'] ];
        }

        $response = wp_remote_post(
            'https://creatorsapi.amazon' . $path,
            [
                'timeout' => 20,
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type'  => 'application/json',
                    'x-marketplace' => $config['domain'],
                ],
                'body' => wp_json_encode( $payload ),
            ]
        );

        if ( is_wp_error( $response ) ) {
            $this->log_event( 'error', 'api_transport_error', [
                'path' => $path,
                'marketplace' => $config['domain'],
                'wp_error' => $response->get_error_message(),
            ] );
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $json = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( 401 === $code && $retry ) {
            $this->clear_token_cache();
            $token = $this->access_token( true );
            if ( is_wp_error( $token ) ) {
                return $token;
            }
            return $this->api_request( $path, $payload, $market, false );
        }

        if ( $code < 200 || $code >= 300 ) {
            $message = 'Error Creators API (' . $code . ').';
            if ( ! empty( $json['message'] ) ) {
                $message .= ' ' . sanitize_text_field( $json['message'] );
            } elseif ( ! empty( $json['errors'][0]['message'] ) ) {
                $message .= ' ' . sanitize_text_field( $json['errors'][0]['message'] );
            }
            $ctx = [
                'path'        => $path,
                'marketplace' => $config['domain'],
                'http_status' => $code,
                'message'     => sanitize_text_field( (string) ( $json['message'] ?? ( $json['errors'][0]['message'] ?? '' ) ) ),
                'error_code'  => sanitize_text_field( (string) ( $json['errors'][0]['code'] ?? ( $json['code'] ?? '' ) ) ),
            ];
            $this->log_event( 'error', 'api_rejected', $ctx );
            return new WP_Error( 'tac_api_error', $message, [ 'status' => $code, 'response' => $json, 'diagnostic' => $ctx ] );
        }

        $this->log_event( 'success', 'api_request_ok', [
            'path' => $path,
            'marketplace' => $config['domain'],
            'http_status' => $code,
        ] );
        return is_array( $json ) ? $json : [];
    }

    public function sanitize_asin( $asin ) {
        $asin = strtoupper( preg_replace( '/[^A-Z0-9]/i', '', trim( (string) $asin ) ) );
        return preg_match( '/^[A-Z0-9]{10}$/', $asin ) ? $asin : '';
    }

    private function item_cache_key( $asin, $market ) {
        $config = $this->marketplace_config( $market );
        return 'tac_item_' . md5( $config['domain'] . '|' . $asin );
    }

    public function get_items( array $asins, $market = '', $force = false ) {
        $asins = array_values( array_unique( array_filter( array_map( [ $this, 'sanitize_asin' ], $asins ) ) ) );
        if ( empty( $asins ) ) {
            return [];
        }
        $asins = array_slice( $asins, 0, 10 );
        $config = $this->marketplace_config( $market );
        $market_key = $config['key'];
        $items = [];
        $missing = [];

        foreach ( $asins as $asin ) {
            $runtime_key = $market_key . ':' . $asin;
            if ( ! $force && isset( $this->runtime_items[ $runtime_key ] ) ) {
                $items[ $asin ] = $this->runtime_items[ $runtime_key ];
                continue;
            }
            if ( ! $force ) {
                $cached = get_transient( $this->item_cache_key( $asin, $market_key ) );
                if ( is_array( $cached ) ) {
                    if ( ! empty( $cached['missing'] ) ) {
                        continue;
                    }
                    $items[ $asin ] = $cached;
                    $this->runtime_items[ $runtime_key ] = $cached;
                    continue;
                }
            }
            $missing[] = $asin;
        }

        if ( ! empty( $missing ) ) {
            $json = $this->api_request(
                '/catalog/v1/getItems',
                [
                    'itemIds'    => $missing,
                    'itemIdType' => 'ASIN',
                    'resources'  => [
                        'images.primary.medium',
                        'itemInfo.title',
                        'offersV2.listings.availability',
                    ],
                ],
                $market_key
            );
            if ( is_wp_error( $json ) ) {
                if ( empty( $items ) ) {
                    return $json;
                }
                return $items;
            }

            $received = [];
            foreach ( (array) ( $json['itemsResult']['items'] ?? [] ) as $item ) {
                if ( empty( $item['asin'] ) ) {
                    continue;
                }
                $asin = strtoupper( $item['asin'] );
                $normalized = $this->normalize_item( $item, $market_key );
                $items[ $asin ] = $normalized;
                $this->runtime_items[ $market_key . ':' . $asin ] = $normalized;
                set_transient( $this->item_cache_key( $asin, $market_key ), $normalized, HOUR_IN_SECONDS * $this->cache_hours() );
                $received[ $asin ] = true;
            }
            foreach ( $missing as $asin ) {
                if ( empty( $received[ $asin ] ) ) {
                    set_transient( $this->item_cache_key( $asin, $market_key ), [ 'asin' => $asin, 'missing' => true ], HOUR_IN_SECONDS );
                }
            }
        }

        return $items;
    }

    public function search_items( $keywords, $count = 3, $market = '', $force = false ) {
        $keywords = sanitize_text_field( $keywords );
        $count = max( 1, min( 10, absint( $count ) ) );
        if ( '' === $keywords ) {
            return [];
        }
        $config = $this->marketplace_config( $market );
        $cache_key = 'tac_search_' . md5( $config['domain'] . '|' . strtolower( $keywords ) . '|' . $count );
        if ( ! $force ) {
            $cached = get_transient( $cache_key );
            if ( is_array( $cached ) ) {
                return $cached;
            }
        }

        $json = $this->api_request(
            '/catalog/v1/searchItems',
            [
                'keywords'    => $keywords,
                'searchIndex' => 'All',
                'itemCount'   => $count,
                'resources'   => [
                    'images.primary.medium',
                    'itemInfo.title',
                    'offersV2.listings.availability',
                ],
            ],
            $config['key']
        );
        if ( is_wp_error( $json ) ) {
            return $json;
        }

        $items = [];
        foreach ( (array) ( $json['searchResult']['items'] ?? [] ) as $item ) {
            if ( empty( $item['asin'] ) ) {
                continue;
            }
            $asin = strtoupper( $item['asin'] );
            $normalized = $this->normalize_item( $item, $config['key'] );
            $items[ $asin ] = $normalized;
            $this->runtime_items[ $config['key'] . ':' . $asin ] = $normalized;
            set_transient( $this->item_cache_key( $asin, $config['key'] ), $normalized, HOUR_IN_SECONDS * $this->cache_hours() );
        }
        set_transient( $cache_key, $items, HOUR_IN_SECONDS * $this->cache_hours() );
        return $items;
    }

    private function cache_hours() {
        return max( 1, min( 24, absint( $this->settings()['cache_hours'] ) ) );
    }

    private function normalize_item( array $item, $market = '' ) {
        $asin = strtoupper( sanitize_text_field( $item['asin'] ?? '' ) );
        $config = $this->marketplace_config( $market );
        $detail = ! empty( $item['detailPageURL'] ) ? esc_url_raw( $item['detailPageURL'] ) : $this->fallback_product_url( $asin, $market );
        $title = sanitize_text_field( $item['itemInfo']['title']['displayValue'] ?? $asin );
        $image = esc_url_raw( $item['images']['primary']['medium']['url'] ?? '' );
        $availability = sanitize_text_field( $item['offersV2']['listings'][0]['availability']['type'] ?? '' );
        return [
            'asin'         => $asin,
            'title'        => $title,
            'url'          => $detail,
            'image'        => $image,
            'availability' => $availability,
            'marketplace'  => $config['domain'],
            'market_key'   => $config['key'],
        ];
    }

    private function fallback_product_url( $asin, $market = '' ) {
        $config = $this->marketplace_config( $market );
        $url = $config['url'] . '/dp/' . rawurlencode( $asin ) . '/';
        if ( ! empty( $config['tag'] ) ) {
            $url = add_query_arg( 'tag', $config['tag'], $url );
        }
        return $url;
    }

    private function fallback_search_url( $keywords, $market = '' ) {
        $config = $this->marketplace_config( $market );
        $url = add_query_arg( 'k', $keywords, $config['url'] . '/s' );
        if ( ! empty( $config['tag'] ) ) {
            $url = add_query_arg( 'tag', $config['tag'], $url );
        }
        return $url;
    }

    private function should_show_secondary( $primary_market ) {
        $settings = $this->settings();
        if ( empty( $settings['show_secondary'] ) ) {
            return false;
        }
        $secondary = $this->marketplace_config( $this->other_market_key( $primary_market ) );
        return ! empty( $secondary['tag'] );
    }

    private function get_secondary_items( array $asins, $primary_market ) {
        if ( ! $this->should_show_secondary( $primary_market ) ) {
            return [];
        }
        $secondary_key = $this->other_market_key( $primary_market );
        $items = $this->get_items( $asins, $secondary_key );
        return is_wp_error( $items ) ? [] : $items;
    }

    private function secondary_url_for_asin( $asin, $secondary_items, $primary_market ) {
        if ( ! $this->should_show_secondary( $primary_market ) ) {
            return '';
        }
        $secondary_key = $this->other_market_key( $primary_market );
        if ( ! empty( $secondary_items[ $asin ]['url'] ) ) {
            return $secondary_items[ $asin ]['url'];
        }
        return $this->fallback_search_url( $asin, $secondary_key );
    }

    private function rel_attr() {
        return 'sponsored nofollow noopener noreferrer';
    }

    private function render_market_actions( $asin, $primary_url, $primary_market, array $secondary_items = [], $button_text = '', $compact = false ) {
        $primary = $this->marketplace_config( $primary_market );
        $label = $button_text ? $button_text : 'Ver en ' . $primary['label'];
        $actions_class = $compact ? 'tac-market-actions tac-market-actions-compact' : 'tac-market-actions';
        $button_class = $compact ? 'wp-block-button tac-compact-button' : 'wp-block-button';
        $html = '<div class="' . esc_attr( $actions_class ) . '">';
        $html .= '<div class="wp-block-buttons"><div class="' . esc_attr( $button_class ) . '">';
        $html .= '<a class="wp-block-button__link wp-element-button tac-product-button" href="' . esc_url( $primary_url ) . '" target="_blank" rel="' . esc_attr( $this->rel_attr() ) . '">' . esc_html( $label ) . '</a>';
        $html .= '</div></div>';
        if ( $this->should_show_secondary( $primary_market ) ) {
            $secondary = $this->marketplace_config( $this->other_market_key( $primary_market ) );
            $secondary_url = $this->secondary_url_for_asin( $asin, $secondary_items, $primary_market );
            if ( $secondary_url ) {
                $html .= '<a class="tac-market-flag" href="' . esc_url( $secondary_url ) . '" target="_blank" rel="' . esc_attr( $this->rel_attr() ) . '" aria-label="Ver en ' . esc_attr( $secondary['label'] ) . '" title="Ver en ' . esc_attr( $secondary['label'] ) . '"><span aria-hidden="true">' . $secondary['flag'] . '</span></a>';
            }
        }
        $html .= '</div>';
        return $html;
    }

    private function render_inline_link( $asin, $url, $label, $primary_market, array $secondary_items = [] ) {
        $html = '<a href="' . esc_url( $url ) . '" target="_blank" rel="' . esc_attr( $this->rel_attr() ) . '">' . esc_html( $label ) . '</a>';
        if ( $this->should_show_secondary( $primary_market ) ) {
            $secondary = $this->marketplace_config( $this->other_market_key( $primary_market ) );
            $secondary_url = $this->secondary_url_for_asin( $asin, $secondary_items, $primary_market );
            if ( $secondary_url ) {
                $html .= ' <a class="tac-inline-flag" href="' . esc_url( $secondary_url ) . '" target="_blank" rel="' . esc_attr( $this->rel_attr() ) . '" aria-label="Ver en ' . esc_attr( $secondary['label'] ) . '" title="Ver en ' . esc_attr( $secondary['label'] ) . '"><span aria-hidden="true">' . $secondary['flag'] . '</span></a>';
            }
        }
        return $html;
    }

    private function render_card( array $item, $primary_market, array $secondary_items = [], $button_text = '', $image_override = '' ) {
        wp_enqueue_style( 'tac-front' );
        $image = $image_override ? esc_url_raw( $image_override ) : ( $item['image'] ?? '' );
        $html = '<article class="tac-product-card">';
        if ( $image ) {
            $html .= '<a class="tac-product-image" href="' . esc_url( $item['url'] ) . '" target="_blank" rel="' . esc_attr( $this->rel_attr() ) . '">';
            $html .= '<img loading="lazy" src="' . esc_url( $image ) . '" alt="' . esc_attr( $item['title'] ?? '' ) . '">';
            $html .= '</a>';
        }
        $html .= '<div class="tac-product-body">';
        if ( ! empty( $item['title'] ) ) {
            $html .= '<div class="tac-product-title">' . esc_html( $item['title'] ) . '</div>';
        }
        $html .= $this->render_market_actions( $item['asin'], $item['url'], $primary_market, $secondary_items, $button_text );
        $html .= '</div></article>';
        return $html;
    }

    private function render_cards( array $items, $primary_market, $button_text = '', $grid = 0, $image_override = '' ) {
        if ( empty( $items ) ) {
            return '';
        }
        wp_enqueue_style( 'tac-front' );
        $asins = array_keys( $items );
        $secondary_items = $this->get_secondary_items( $asins, $primary_market );
        $grid = max( 0, min( 6, absint( $grid ) ) );
        $style = $grid ? ' style="--tac-columns:' . $grid . '"' : '';
        $html = '<div class="tac-product-grid"' . $style . '>';
        foreach ( $items as $item ) {
            $html .= $this->render_card( $item, $primary_market, $secondary_items, $button_text, count( $items ) === 1 ? $image_override : '' );
        }
        $html .= '</div>';
        return $html;
    }

    private function render_fallback_buttons( array $asins, $primary_market, $button_text = '' ) {
        wp_enqueue_style( 'tac-front' );
        $primary = $this->marketplace_config( $primary_market );
        $secondary_items = [];
        $html = '<div class="tac-fallback-list">';
        foreach ( $asins as $asin ) {
            $html .= '<div class="tac-fallback">' . $this->render_market_actions( $asin, $this->fallback_product_url( $asin, $primary['key'] ), $primary['key'], $secondary_items, $button_text ) . '</div>';
        }
        $html .= '</div>';
        return $html;
    }

    public function shortcode_product( $atts ) {
        $atts = shortcode_atts(
            [
                'asin'        => '',
                'box'         => '',
                'link'        => '',
                'title'       => '',
                'marketplace' => '',
                'button'      => '',
                'grid'        => 0,
                'template'    => '',
                'image'       => '',
                'price'       => '',
            ],
            $atts,
            'taisa_amazon'
        );

        $primary = $this->marketplace_config( $atts['marketplace'] );
        $ids_raw = $atts['asin'] ?: ( $atts['box'] ?: $atts['link'] );
        $asins = array_values( array_filter( array_map( [ $this, 'sanitize_asin' ], preg_split( '/\s*,\s*/', (string) $ids_raw ) ) ) );
        if ( empty( $asins ) ) {
            return '';
        }

        if ( ! empty( $atts['link'] ) && 1 === count( $asins ) ) {
            $asin = $asins[0];
            $items = $this->get_items( [ $asin ], $primary['key'] );
            $url = ! is_wp_error( $items ) && ! empty( $items[ $asin ]['url'] ) ? $items[ $asin ]['url'] : $this->fallback_product_url( $asin, $primary['key'] );
            $secondary_items = $this->get_secondary_items( [ $asin ], $primary['key'] );
            $label = $atts['title'] ? $atts['title'] : ( $atts['button'] ? $atts['button'] : 'Ver en ' . $primary['label'] );
            return $this->render_inline_link( $asin, $url, $label, $primary['key'], $secondary_items );
        }

        $items = $this->get_items( $asins, $primary['key'] );
        if ( is_wp_error( $items ) || empty( $items ) ) {
            return $this->render_fallback_buttons( $asins, $primary['key'], $atts['button'] );
        }
        return $this->render_cards( $items, $primary['key'], $atts['button'], $atts['grid'], $atts['image'] );
    }

    public function shortcode_search( $atts ) {
        $atts = shortcode_atts(
            [
                'keywords'    => '',
                'bestseller'  => '',
                'items'       => 3,
                'marketplace' => '',
                'button'      => '',
                'grid'        => 0,
                'template'    => '',
                'price'       => '',
            ],
            $atts,
            'taisa_amazon_search'
        );
        $keywords = $atts['keywords'] ?: $atts['bestseller'];
        if ( '' === trim( $keywords ) ) {
            return '';
        }
        $primary = $this->marketplace_config( $atts['marketplace'] );
        $items = $this->search_items( $keywords, $atts['items'], $primary['key'] );
        if ( is_wp_error( $items ) || empty( $items ) ) {
            wp_enqueue_style( 'tac-front' );
            $label = 'Ver resultados en ' . $primary['label'];
            return '<div class="tac-fallback"><div class="wp-block-buttons"><div class="wp-block-button"><a class="wp-block-button__link wp-element-button tac-product-button" href="' . esc_url( $this->fallback_search_url( $keywords, $primary['key'] ) ) . '" target="_blank" rel="' . esc_attr( $this->rel_attr() ) . '">' . esc_html( $label ) . '</a></div></div></div>';
        }
        $html = '<p class="tac-search-context">Resultados para: <strong>' . esc_html( $keywords ) . '</strong></p>';
        $html .= $this->render_cards( $items, $primary['key'], $atts['button'], $atts['grid'] );
        return $html;
    }

    public function maybe_register_aawp_compat() {
        $settings = $this->settings();
        if ( empty( $settings['aawp_compat'] ) ) {
            return;
        }
        remove_shortcode( 'aawp' );
        remove_shortcode( 'amazon' );
        add_shortcode( 'aawp', [ $this, 'shortcode_aawp_compat' ] );
        add_shortcode( 'amazon', [ $this, 'shortcode_aawp_compat' ] );
    }

    public function shortcode_aawp_compat( $atts ) {
        $atts = is_array( $atts ) ? $atts : [];
        if ( ! empty( $atts['bestseller'] ) ) {
            return $this->shortcode_search( $atts );
        }
        if ( ! empty( $atts['table'] ) ) {
            return $this->render_legacy_table( absint( $atts['table'] ), $atts['marketplace'] ?? '' );
        }
        if ( empty( $atts['asin'] ) && ! empty( $atts['box'] ) ) {
            $atts['asin'] = $atts['box'];
        }
        if ( empty( $atts['asin'] ) && ! empty( $atts['link'] ) ) {
            $atts['asin'] = $atts['link'];
        }
        return $this->shortcode_product( $atts );
    }

    private function render_legacy_table( $table_id, $market = '' ) {
        if ( ! $table_id ) {
            return '';
        }
        $rows = get_post_meta( $table_id, '_aawp_table_rows', true );
        $products = get_post_meta( $table_id, '_aawp_table_products', true );
        if ( ! is_array( $rows ) || ! is_array( $products ) || empty( $products ) ) {
            return '';
        }

        $primary = $this->marketplace_config( $market );
        $asins = [];
        foreach ( $products as $product ) {
            $asin = $this->sanitize_asin( $product['asin'] ?? '' );
            if ( $asin ) {
                $asins[] = $asin;
            }
        }
        if ( empty( $asins ) ) {
            return '';
        }

        $api_items = $this->get_items( $asins, $primary['key'] );
        if ( is_wp_error( $api_items ) ) {
            $api_items = [];
        }
        $secondary_items = $this->get_secondary_items( $asins, $primary['key'] );
        wp_enqueue_style( 'tac-front' );

        $visible_rows = [];
        foreach ( $rows as $row_index => $row ) {
            if ( isset( $row['status'] ) && ! $row['status'] ) {
                continue;
            }
            $type = sanitize_key( $row['type'] ?? '' );
            if ( in_array( $type, [ 'prime', 'star_rating' ], true ) && ! $this->legacy_row_has_values( $products, $row_index ) ) {
                continue;
            }
            $visible_rows[ $row_index ] = $row;
        }

        $html = '<div class="tac-table-wrap"><table class="tac-legacy-table"><tbody>';
        foreach ( $visible_rows as $row_index => $row ) {
            $type = sanitize_key( $row['type'] ?? '' );
            $label = sanitize_text_field( $row['label'] ?? '' );
            if ( 'thumb' === $type && '' === $label ) {
                $label = 'Producto';
            }
            if ( 'button' === $type && '' === $label ) {
                $label = 'Comprar';
            }
            $html .= '<tr class="tac-table-row tac-table-row-' . esc_attr( $type ) . '">';
            $html .= '<th scope="row">' . esc_html( $label ) . '</th>';
            foreach ( $products as $product ) {
                $asin = $this->sanitize_asin( $product['asin'] ?? '' );
                if ( ! $asin ) {
                    $html .= '<td></td>';
                    continue;
                }
                $item = $api_items[ $asin ] ?? [
                    'asin'  => $asin,
                    'title' => $asin,
                    'url'   => $this->fallback_product_url( $asin, $primary['key'] ),
                    'image' => '',
                ];
                $html .= '<td>' . $this->render_legacy_table_cell( $type, $row_index, $product, $item, $primary['key'], $secondary_items ) . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table></div>';
        return $html;
    }

    private function legacy_row_has_values( array $products, $row_index ) {
        foreach ( $products as $product ) {
            $values = $product['rows'][ $row_index ]['values'] ?? [];
            if ( is_array( $values ) && array_filter( $values, static function( $value ) { return '' !== trim( (string) $value ); } ) ) {
                return true;
            }
        }
        return false;
    }

    private function render_legacy_table_cell( $type, $row_index, array $product, array $item, $primary_market, array $secondary_items ) {
        $values = $product['rows'][ $row_index ]['values'] ?? [];
        if ( 'thumb' === $type ) {
            if ( empty( $item['image'] ) ) {
                return '';
            }
            return '<a href="' . esc_url( $item['url'] ) . '" target="_blank" rel="' . esc_attr( $this->rel_attr() ) . '"><img class="tac-table-image" loading="lazy" src="' . esc_url( $item['image'] ) . '" alt="' . esc_attr( $item['title'] ) . '"></a>';
        }
        if ( 'title' === $type ) {
            return '<a href="' . esc_url( $item['url'] ) . '" target="_blank" rel="' . esc_attr( $this->rel_attr() ) . '">' . esc_html( $item['title'] ) . '</a>';
        }
        if ( 'button' === $type ) {
            return $this->render_market_actions( $item['asin'], $item['url'], $primary_market, $secondary_items, '', true );
        }
        if ( is_array( $values ) && ! empty( $values ) ) {
            $clean = [];
            foreach ( $values as $value ) {
                $value = trim( wp_strip_all_tags( (string) $value ) );
                if ( '' !== $value ) {
                    $clean[] = $value;
                }
            }
            return esc_html( implode( ' ', $clean ) );
        }
        return '';
    }
}

Taisa_Amazon_Creators::instance();

