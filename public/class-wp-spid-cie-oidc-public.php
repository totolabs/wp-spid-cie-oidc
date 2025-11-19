<?php

/**
 * La funzionalità specifica dell'area pubblica del plugin.
 *
 * @since      0.1.0
 * @package    WP_SPID_CIE_OIDC
 * @subpackage WP_SPID_CIE_OIDC/public
 * @author     Totolabs Srl <info@totolabs.it>
 */
class WP_SPID_CIE_OIDC_Public {

    private $plugin_name;
    private $version;

    public function __construct( $plugin_name, $version ) {
        $this->plugin_name = $plugin_name;
        $this->version = $version;

        // Shortcode per pagine custom
        add_shortcode('spid_cie_login', array($this, 'render_login_buttons'));
        
        // Hook per la pagina di login standard (wp-login.php)
        // 'login_message' è il punto standard sopra il form.
        add_action( 'login_form', array( $this, 'print_login_buttons_on_login_page' ) );
        add_action( 'login_message', array( $this, 'print_login_buttons_on_login_page' ) );

        // Caricamento stili
        add_action( 'login_enqueue_scripts', array( $this, 'enqueue_styles' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_styles' ) );

        // Endpoints e Login Flow
        add_action( 'init', array( $this, 'setup_federation_endpoints' ) );
        add_action( 'template_redirect', array( $this, 'serve_federation_endpoints' ) );
        add_action( 'template_redirect', array( $this, 'handle_login_flow' ) );
    }

    public function enqueue_styles() {
		// Carichiamo il file CSS esterno
        wp_enqueue_style(
            $this->plugin_name,
            plugin_dir_url( __FILE__ ) . 'css/wp-spid-cie-oidc-public.css',
            array(),
            $this->version,
            'all'
        );
    }

    public function setup_federation_endpoints() {
        add_rewrite_rule('^\.well-known/openid-federation/?$', 'index.php?oidc_federation=config', 'top');
        add_rewrite_rule('^jwks.json/?$', 'index.php?oidc_federation=jwks', 'top');
        
        add_filter( 'query_vars', function( $vars ) {
            $vars[] = 'oidc_federation';
            $vars[] = 'oidc_action';
            $vars[] = 'provider';
            return $vars;
        });
    }

    public function serve_federation_endpoints() {
        global $wp_query;
        $action = $wp_query->get('oidc_federation');

        if ( ! $action ) return;

        if (!class_exists('WP_SPID_CIE_OIDC_Factory')) {
            require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-wp-spid-cie-oidc-factory.php';
        }

        try {
            $client = WP_SPID_CIE_OIDC_Factory::get_client();

            if ( $action === 'config' ) {
                $jws = $client->getEntityStatement();
                // HEADER DI PRODUZIONE: Fondamentale per la federazione automatica
                header('Content-Type: application/entity-statement+jwt');
                echo $jws;
                exit;
            } 
            elseif ( $action === 'jwks' ) {
                $jwks = $client->getJwks();
                header('Content-Type: application/json');
                echo $jwks;
                exit;
            }

        } catch (Exception $e) {
            // In produzione meglio non mostrare dettagli tecnici, ma per ora va bene
            wp_die('Errore OIDC Federation: ' . esc_html($e->getMessage()), 'Errore OIDC', ['response' => 500]);
        }
    }

    public function handle_login_flow() {
        $action = isset($_GET['oidc_action']) ? $_GET['oidc_action'] : get_query_var('oidc_action');
        $provider = isset($_GET['provider']) ? $_GET['provider'] : get_query_var('provider');
        
        if ( $action !== 'login' || ! $provider ) return;

        if (!class_exists('WP_SPID_CIE_OIDC_Factory')) {
            require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-wp-spid-cie-oidc-factory.php';
        }

        try {
            $client = WP_SPID_CIE_OIDC_Factory::get_client();
            
            // In produzione CIE punta a id.cie.gov.it
            $trust_anchor = ($provider === 'cie') 
                ? 'https://id.cie.gov.it/' 
                : 'https://registry.spid.gov.it/';

            $auth_url = $client->getAuthorizationUrl($trust_anchor);
            
            wp_redirect($auth_url);
            exit;

        } catch (Exception $e) {
            wp_die("Errore durante l'avvio del login: " . esc_html($e->getMessage()));
        }
    }

    private static $buttons_printed = false;

    /**
     * Stampa i bottoni. Accetta argomenti opzionali per compatibilità con vari hook.
     */
    public function print_login_buttons_on_login_page($arg = null) {
        if (self::$buttons_printed) return $arg; // Ritorna l'argomento per non rompere la catena dei filtri
        
        // Se l'hook passa un messaggio (es. login_message), stampalo prima
        if (is_string($arg) && !empty($arg)) {
            echo $arg;
        }

        echo $this->render_login_buttons();
        self::$buttons_printed = true;
        
        return null; // login_message si aspetta un return
    }

    public function render_login_buttons() {
        $options = get_option( $this->plugin_name . '_options' ); 
        
        $spid_enabled = isset($options['spid_enabled']) && $options['spid_enabled'] === '1';
        $cie_enabled = isset($options['cie_enabled']) && $options['cie_enabled'] === '1';

        if ( ! $spid_enabled && ! $cie_enabled ) {
            return '';
        }

        $base_url = home_url('/');
        $login_url_spid = add_query_arg(['oidc_action' => 'login', 'provider' => 'spid'], $base_url);
        $login_url_cie  = add_query_arg(['oidc_action' => 'login', 'provider' => 'cie'], $base_url);

        ob_start();
        ?>
        <div class="spid-cie-container">
            <span class="spid-cie-title">Accedi con Identità Digitale</span>
            
            <?php if ($spid_enabled): ?>
                <a href="<?php echo esc_url($login_url_spid); ?>" class="spid-cie-button spid-button">
                    Entra con SPID
                </a>
            <?php endif; ?>

            <?php if ($cie_enabled): ?>
                <a href="<?php echo esc_url($login_url_cie); ?>" class="spid-cie-button cie-button">
                    Entra con CIE
                </a>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
}