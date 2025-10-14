<?php

/**
 * La classe core del plugin.
 *
 * @since      0.1.0
 * @package    WP_SPID_CIE_OIDC
 * @subpackage WP_SPID_CIE_OIDC/includes
 * @author     Totolabs Srl <info@totolabs.it>
 */
class WP_SPID_CIE_OIDC {

    /**
     * L'identificatore univoco di questo plugin.
     *
     * @since    0.1.0
     * @access   protected
     * @var      string    $plugin_name    Il nome del plugin.
     */
    protected $plugin_name;

    /**
     * La versione corrente del plugin.
     *
     * @since    0.1.0
     * @access   protected
     * @var      string    $version    La versione corrente.
     */
    protected $version;

    /**
     * Definisce la funzionalità core del plugin.
     *
     * @since    0.1.0
     */
    public function __construct() {
        $this->version = '0.1.0';
        $this->plugin_name = 'wp-spid-cie-oidc';

        $this->load_dependencies();
        $this->set_locale();
    }

    /**
     * Carica le dipendenze richieste dal plugin.
     *
     * @since    0.1.0
     * @access   private
     */
    private function load_dependencies() {
        // Qui caricheremo i file per l'area admin e l'area pubblica.
        // Per ora è vuoto.
    }

    /**
     * Definisce le azioni di internazionalizzazione.
     *
     * @since    0.1.0
     * @access   private
     */
    private function set_locale() {
        add_action( 'plugins_loaded', array( $this, 'load_plugin_textdomain' ) );
    }
    
    /**
     * Carica il text domain del plugin per la traduzione.
     *
     * @since    0.1.0
     */
    public function load_plugin_textdomain() {
        load_plugin_textdomain(
            'wp-spid-cie-oidc',
            false,
            dirname( dirname( plugin_basename( __FILE__ ) ) ) . '/languages/'
        );
    }

    /**
     * Il punto di ingresso principale per eseguire il plugin.
     *
     * @since    0.1.0
     */
    public function run() {
        // Qui avvieremo l'ascolto degli "hooks" di WordPress.
        // Per ora è vuoto.
    }
}