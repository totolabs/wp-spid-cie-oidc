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
    private $options;

    /**
     * Inizializza la classe e imposta le sue proprietà.
     */
    public function __construct( $plugin_name, $version ) {
        $this->plugin_name = $plugin_name;
        $this->version = $version;
        $this->options = get_option( $this->plugin_name . '_options' );

        // Hook per aggiungere i pulsanti nella pagina di login
        add_action( 'login_form', array( $this, 'display_login_buttons' ) );
    }

    /**
     * Aggiunge i fogli di stile per l'area pubblica.
     */
    public function enqueue_styles() {
        wp_enqueue_style(
            $this->plugin_name,
            plugin_dir_url( __FILE__ ) . 'css/wp-spid-cie-oidc-public.css',
            array(),
            $this->version,
            'all'
        );
    }

    /**
     * Mostra i pulsanti di login SPID/CIE se abilitati nelle impostazioni.
     */
    public function display_login_buttons() {
        $spid_enabled = isset($this->options['spid_enabled']) && $this->options['spid_enabled'] === '1';
        $cie_enabled = isset($this->options['cie_enabled']) && $this->options['cie_enabled'] === '1';

        // Se nessuno dei due è attivo, non mostrare nulla.
        if ( ! $spid_enabled && ! $cie_enabled ) {
            return;
        }

        // Inizializza il contenitore HTML
        echo '<div class="spid-cie-container">';

        // Link fittizi per ora, li renderemo funzionanti nel prossimo step
        $spid_login_url = '#'; 
        $cie_login_url = '#';

        if ( $spid_enabled ) {
            echo '<a href="' . esc_url($spid_login_url) . '" class="button button-primary spid-cie-button spid-button">Entra con SPID</a>';
        }

        if ( $cie_enabled ) {
            echo '<a href="' . esc_url($cie_login_url) . '" class="button button-primary spid-cie-button cie-button">Entra con CIE</a>';
        }

        echo '</div>';
    }
}