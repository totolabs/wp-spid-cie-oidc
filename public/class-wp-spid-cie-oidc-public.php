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

        add_action( 'login_form', array( $this, 'display_login_buttons' ) );

        // Aggiungiamo i nostri due gestori di endpoint
        add_action( 'template_redirect', array( $this, 'initiate_login_flow' ) );
        add_action( 'template_redirect', array( $this, 'handle_oidc_callback' ) );
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
        $nonce = wp_create_nonce( 'oidc_login_nonce_' . $provider );
        return add_query_arg(
            [
                'oidc_action' => 'login',
                'provider'    => $provider,
                '_wpnonce'    => $nonce
            ],
            home_url( '/' )// Puntiamo alla home page, sarà intercettato da 'template_redirect'
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
        if ( ! isset( $_GET['oidc_action'] ) || $_GET['oidc_action'] !== 'login' || ! isset( $_GET['provider'] ) ) {
            return;
        }

        $provider = sanitize_key( $_GET['provider'] );
        if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'oidc_login_nonce_' . $provider ) ) {
            wp_die( 'La richiesta non è valida per motivi di sicurezza.' );
        }

		// Carica le impostazioni corrette in base al provider
        $client_id = $this->options[ $provider . '_client_id' ] ?? '';
        $client_secret = $this->options[ $provider . '_client_secret' ] ?? '';
        $metadata_url = $this->options[ $provider . '_metadata_url' ] ?? '';

        if ( empty( $client_id ) || empty( $metadata_url ) ) {
            wp_die( 'Configurazione OIDC mancante per il provider: ' . esc_html( $provider ) );
        }

        try {
			// Usiamo la libreria
            $oidc = new Jumbojett\OpenIDConnectClient( $metadata_url, $client_id, $client_secret );
            
            // Definiamo l'URL a cui il provider deve rimandare l'utente.
            // Sarà il nostro endpoint di callback.
            $redirect_url = add_query_arg( ['oidc_action' => 'callback', 'provider' => $provider], home_url('/') );
            $oidc->setRedirectURL($redirect_url);
            
            $oidc->addScope('openid profile offline_access');
            $oidc->authenticate();

        } catch ( Exception $e ) {
            wp_die( 'Errore durante l\'avvio dell\'autenticazione OIDC: ' . $e->getMessage() );
        }
        exit;
    }

    /**
     * Gestisce il ritorno dall'Identity Provider (callback).
     */
    public function handle_oidc_callback() {
        if ( ! isset( $_GET['oidc_action'] ) || $_GET['oidc_action'] !== 'callback' || ! isset( $_GET['provider'] ) ) {
            return;
        }

        $provider = sanitize_key( $_GET['provider'] );
        
        $client_id = $this->options[ $provider . '_client_id' ] ?? '';
        $client_secret = $this->options[ $provider . '_client_secret' ] ?? '';
        $metadata_url = $this->options[ $provider . '_metadata_url' ] ?? '';

        try {
            $oidc = new Jumbojett\OpenIDConnectClient( $metadata_url, $client_id, $client_secret );
            $redirect_url = add_query_arg( ['oidc_action' => 'callback', 'provider' => $provider], home_url('/') );
            $oidc->setRedirectURL($redirect_url);

            // Questa funzione valida la risposta e recupera i dati dell'utente
            if ($oidc->authenticate()) {
                $user_info = $oidc->requestUserInfo();
                
                // Il Codice Fiscale è il dato chiave per identificare l'utente
                $fiscal_number = $user_info->fiscalNumber;

                // Cerchiamo un utente esistente con lo stesso codice fiscale
                $user = get_users([
                    'meta_key'   => 'fiscal_number',
                    'meta_value' => $fiscal_number,
                    'number'     => 1,
                    'count_total' => false
                ]);

                if ( ! empty($user) ) {
                    // Utente trovato, prendiamo il suo ID
                    $user_id = $user[0]->ID;
                } else {
                    // Utente non trovato, dobbiamo crearlo
                    $email = $user_info->email ?? 'utente_' . time() . '@placeholder.it';
                    $username = 'spid-' . sanitize_user($fiscal_number);

                    // Controlla se l'username esiste già (caso limite)
                    if(username_exists($username)){
                        $username = $username . '_' . time();
                    }

                    $user_id = wp_create_user( $username, wp_generate_password(), $email );

                    if ( is_wp_error( $user_id ) ) {
                        wp_die( 'Impossibile creare un nuovo utente. Dettagli: ' . $user_id->get_error_message() );
                    }

                    // Aggiorniamo i dati del nuovo utente
                    wp_update_user([
                        'ID'         => $user_id,
                        'first_name' => $user_info->given_name ?? '',
                        'last_name'  => $user_info->family_name ?? '',
                        'display_name' => ($user_info->given_name ?? '') . ' ' . ($user_info->family_name ?? ''),
                    ]);

                    // Salviamo il codice fiscale nel suo profilo!
                    update_user_meta( $user_id, 'fiscal_number', $fiscal_number );
                }

                // Autentichiamo l'utente in WordPress
                wp_set_current_user($user_id);
                wp_set_auth_cookie($user_id, true);
                
                // Reindirizziamo l'utente alla bacheca di WordPress
                wp_redirect( admin_url() );
                exit;
            }

        } catch ( Exception $e ) {
            wp_die( 'Errore durante la validazione della risposta OIDC: ' . $e->getMessage() );
        }
    }// Chiude handle_oidc_callback()
}// Chiude la classe WP_SPID_CIE_OIDC_Public