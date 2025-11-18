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

        // Bottoni di login
        add_shortcode('spid_cie_login', array($this, 'render_login_buttons'));
        add_action( 'login_form', array( $this, 'render_login_buttons' ) );
        
        // Gestione Endpoint di Federazione
        add_action( 'init', array( $this, 'setup_federation_endpoints' ) );
        add_action( 'template_redirect', array( $this, 'serve_federation_endpoints' ) );

        // Gestione Flusso Login
        add_action( 'template_redirect', array( $this, 'handle_login_flow' ) );
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

        // Carica la Factory
        if (!class_exists('WP_SPID_CIE_OIDC_Factory')) {
            require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-wp-spid-cie-oidc-factory.php';
        }

        try {
            $client = WP_SPID_CIE_OIDC_Factory::get_client();

            // Entity Statement (JWS)
            if ( $action === 'config' ) {
                $jws = $client->getEntityStatement();
                // Header corretto per OIDC Federation
                header('Content-Type: application/entity-statement+jwt');
                echo $jws;
                exit;
            } 
            // JWKS (Chiavi pubbliche JSON)
            elseif ( $action === 'jwks' ) {
                $jwks = $client->getJwks();
                header('Content-Type: application/json');
                echo $jwks;
                exit;
            }

        } catch (Exception $e) {
            wp_die('Errore OIDC Federation: ' . esc_html($e->getMessage()));
        }
    }

    public function handle_login_flow() {
        $action = isset($_GET['oidc_action']) ? $_GET['oidc_action'] : get_query_var('oidc_action');
        if ( ! $action ) return;

        // Qui implementeremo il login nel prossimo step
        // Per ora lasciamo vuoto per testare prima l'Entity Statement
    }

    public function render_login_buttons() {
        // Rendering pulsanti (semplificato per ora)
        return '<div class="spid-cie-buttons"></div>';
    }
}