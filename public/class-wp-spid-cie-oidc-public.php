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

		// Hook per intercettare le richieste di login
		add_action( 'template_redirect', array( $this, 'initiate_login_flow' ) );
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
     * Genera l'URL per avviare il login per un dato provider e mostra i pulsanti di login SPID/CIE se abilitati nelle impostazioni.
     * @param string $provider 'spid' o 'cie'.
     * @return string L'URL completo.
     */
    private function get_login_url( $provider ) {
        // Aggiungiamo un "nonce" di WordPress per sicurezza
        $nonce = wp_create_nonce( 'oidc_login_nonce' );
        return add_query_arg(
            [
                'oidc_action' => 'login',
                'provider'    => $provider,
                '_wpnonce'    => $nonce
            ],
            home_url( '/' ) // Puntiamo alla home page, sarà intercettato da 'template_redirect'
        );
    }

    public function display_login_buttons() {
        $spid_enabled = isset($this->options['spid_enabled']) && $this->options['spid_enabled'] === '1';
        $cie_enabled = isset($this->options['cie_enabled']) && $this->options['cie_enabled'] === '1';

		// Se nessuno dei due è attivo, non mostrare nulla.
        if ( ! $spid_enabled && ! $cie_enabled ) {
            return;
        }
		
		// Inizializza il contenitore HTML
        echo '<div class="spid-cie-container">';

        // Usiamo il nuovo metodo per generare gli URL corretti
        if ( $spid_enabled ) {
            echo '<a href="' . esc_url( $this->get_login_url('spid') ) . '" class="button button-primary spid-cie-button spid-button">Entra con SPID</a>';
        }

        if ( $cie_enabled ) {
            echo '<a href="' . esc_url( $this->get_login_url('cie') ) . '" class="button button-primary spid-cie-button cie-button">Entra con CIE</a>';
        }

        echo '</div>';
    }

    /**
     * Intercetta la richiesta di login e avvia il flusso OIDC.
     */
    public function initiate_login_flow() {
        // Esegui solo se i nostri parametri sono presenti nell'URL
        if ( ! isset( $_GET['oidc_action'] ) || $_GET['oidc_action'] !== 'login' || ! isset( $_GET['provider'] ) ) {
            return;
        }

        // Verifica di sicurezza (Nonce)
        if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'oidc_login_nonce' ) ) {
            wp_die( 'La richiesta non è valida per motivi di sicurezza.' );
        }

        $provider = sanitize_key( $_GET['provider'] );

        // Carica le impostazioni corrette in base al provider
        $client_id = $this->options[ $provider . '_client_id' ] ?? '';
        $client_secret = $this->options[ $provider . '_client_secret' ] ?? '';
        $metadata_url = $this->options[ $provider . '_metadata_url' ] ?? '';

        if ( empty( $client_id ) || empty( $metadata_url ) ) {
            wp_die( 'Configurazione OIDC mancante per il provider: ' . esc_html( $provider ) );
        }

        try {
            // Usiamo la libreria installata con Composer!
            $oidc = new Jumbojett\OpenIDConnectClient( $metadata_url, $client_id, $client_secret );
            
            // Definiamo gli "scope", cioè i dati che chiediamo al provider.
            // "openid", "profile" e "offline_access" sono standard.
            $oidc->addScope('openid profile offline_access');

            // Questa funzione magica gestisce il redirect verso l'Identity Provider
            $oidc->authenticate();

        } catch ( Exception $e ) {
            wp_die( 'Errore durante l\'avvio dell\'autenticazione OIDC: ' . $e->getMessage() );
        }

        // Il codice si ferma qui perché authenticate() esegue un redirect.
        exit;
    }
}