<?php

/**
 * La funzionalità specifica dell'area di amministrazione del plugin.
 *
 * @since      0.1.0
 * @package    WP_SPID_CIE_OIDC
 * @subpackage WP_SPID_CIE_OIDC/admin
 * @author     Totolabs Srl <info@totolabs.it>
 */
class WP_SPID_CIE_OIDC_Admin {

    private $plugin_name;
    private $version;

    public function __construct( $plugin_name, $version ) {
        $this->plugin_name = $plugin_name;
        $this->version = $version;

        add_action( 'admin_menu', array( $this, 'add_options_page' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_init', array( $this, 'handle_key_generation' ) );
    }

    public function add_options_page() {
        add_options_page('Impostazioni SPID/CIE OIDC', 'SPID/CIE OIDC', 'manage_options', $this->plugin_name, array( $this, 'create_admin_page' ));
    }

    public function create_admin_page() {
        ?>
        <div class="wrap">
            <h2>Impostazioni SPID & CIE OIDC Login</h2>
            <p>Configura qui le credenziali e le chiavi per l'autenticazione tramite OpenID Connect.</p>
            <form method="post" action="options.php">
                <?php
                settings_fields( $this->plugin_name . '_options_group' );
                do_settings_sections( $this->plugin_name );
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    /**
     * Registra tutte le impostazioni, sezioni e campi del plugin.
     */
    public function register_settings() {
        $option_group = $this->plugin_name . '_options_group';
        $option_name = $this->plugin_name . '_options';

        register_setting($option_group, $option_name, array( $this, 'sanitize_options' ));

        // Sezione per la generazione e visualizzazione delle chiavi crittografiche
        add_settings_section('keys_section', 'Gestione Chiavi Crittografiche', array( $this, 'print_keys_section_info' ), $this->plugin_name);
        add_settings_field('oidc_keys', 'Chiavi di Federazione', array( $this, 'render_keys_field' ), $this->plugin_name, 'keys_section');

        // Sezione per le impostazioni di SPID
        add_settings_section('spid_section', 'Impostazioni SPID', array( $this, 'print_spid_section_info' ), $this->plugin_name);
        add_settings_field('spid_enabled', 'Abilita SPID', array( $this, 'render_checkbox_field' ), $this->plugin_name, 'spid_section', ['id' => 'spid_enabled']);
        add_settings_field('spid_client_id', 'SPID Client ID', array( $this, 'render_text_field' ), $this->plugin_name, 'spid_section', ['id' => 'spid_client_id']);
        add_settings_field('spid_client_secret', 'SPID Client Secret', array( $this, 'render_text_field' ), $this->plugin_name, 'spid_section', ['id' => 'spid_client_secret', 'type' => 'password']);

        // Sezione per le impostazioni di CIE
        add_settings_section('cie_section', 'Impostazioni CIE', array( $this, 'print_cie_section_info' ), $this->plugin_name);
        add_settings_field('cie_enabled', 'Abilita CIE', array( $this, 'render_checkbox_field' ), $this->plugin_name, 'cie_section', ['id' => 'cie_enabled']);
        add_settings_field('cie_client_id', 'CIE Client ID', array( $this, 'render_text_field' ), $this->plugin_name, 'cie_section', ['id' => 'cie_client_id']);
        add_settings_field('cie_client_secret', 'CIE Client Secret', array( $this, 'render_text_field' ), $this->plugin_name, 'cie_section', ['id' => 'cie_client_secret', 'type' => 'password']);
    }

    /**
     * Gestisce la richiesta di generazione delle chiavi crittografiche.
     */
    public function handle_key_generation() {
        if ( isset( $_GET['action'], $_GET['_wpnonce'] ) && $_GET['action'] === 'generate_oidc_keys' && wp_verify_nonce( $_GET['_wpnonce'], 'generate_oidc_keys_nonce' ) ) {
            
            $privateKey = \phpseclib3\Crypt\RSA::createKey(2048);
            $publicKey = $privateKey->getPublicKey();
            
            // Sintassi corretta per phpseclib3: accesso diretto alle proprietà
            $n = $publicKey->n;
            $e = $publicKey->e;
            
            $base64url_encode = function($data) { return rtrim(strtr(base64_encode($data), '+/', '-_'), '='); };

            $jwkPublicKey = [
                'kty' => 'RSA', 'alg' => 'RS256', 'use' => 'sig',
                'n'   => $base64url_encode($n->toBytes()),
                'e'   => $base64url_encode($e->toBytes()),
            ];

            $options = get_option($this->plugin_name . '_options', []);
            $options['oidc_public_key_jwk'] = json_encode($jwkPublicKey, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            $options['oidc_private_key_pem'] = (string)$privateKey;
            update_option($this->plugin_name . '_options', $options);

            wp_redirect(admin_url('options-general.php?page=' . $this->plugin_name . '&settings-updated=true&keys-generated=true'));
            exit;
        }
    }

    /**
     * Mostra l'interfaccia per la gestione delle chiavi.
     */
    public function render_keys_field() {
        $options = get_option($this->plugin_name . '_options');
        $public_key = $options['oidc_public_key_jwk'] ?? '';
        $generation_url = wp_nonce_url(admin_url('options-general.php?page=' . $this->plugin_name . '&action=generate_oidc_keys'), 'generate_oidc_keys_nonce');

        if (isset($_GET['keys-generated']) && $_GET['keys-generated'] === 'true') {
            echo '<div class="notice notice-success is-dismissible"><p><strong>Nuove chiavi generate e salvate con successo.</strong></p></div>';
        }

        echo '<textarea readonly class="large-text" rows="8" id="oidc-public-key">' . esc_textarea($public_key) . '</textarea>';
        echo '<p class="description">Copia e incolla questa chiave pubblica nel campo "Chiave pubblica di federazione" del portale CIE/SPID.</p>';
        echo '<a href="' . esc_url($generation_url) . '" class="button button-secondary">Genera Nuove Chiavi</a>';
        echo '<p class="description" style="color: red;"><strong>Attenzione:</strong> generando nuove chiavi, le precedenti verranno sovrascritte. Dovrai aggiornare la chiave pubblica sui portali di SPID e CIE.</p>';
    }
    
    /**
     * Renderizza un campo di testo standard per la Settings API.
     */
    public function render_text_field( $args ) {
        $options = get_option( $this->plugin_name . '_options' );
        $id = $args['id'];
        $type = isset($args['type']) ? $args['type'] : 'text';
        $placeholder = isset($args['placeholder']) ? $args['placeholder'] : '';
        printf( '<input type="%s" id="%s" name="%s[%s]" value="%s" class="regular-text" placeholder="%s" />', esc_attr($type), esc_attr($id), esc_attr( $this->plugin_name . '_options' ), esc_attr($id), isset( $options[$id] ) ? esc_attr( $options[$id] ) : '', esc_attr($placeholder) );
    }
    
    /**
     * Renderizza un campo checkbox per la Settings API.
     */
    public function render_checkbox_field( $args ) {
        $options = get_option( $this->plugin_name . '_options' );
        $id = $args['id'];
        $checked = isset( $options[$id] ) && $options[$id] === '1' ? 'checked' : '';
        printf( '<input type="checkbox" id="%s" name="%s[%s]" value="1" %s />', esc_attr($id), esc_attr( $this->plugin_name . '_options' ), esc_attr($id), $checked );
    }
	
    /**
     * Renderizza le descrizioni per le varie sezioni.
     */
    public function print_keys_section_info() { print 'Le chiavi crittografiche sono necessarie per garantire la comunicazione sicura con gli Identity Provider. Se non hai ancora generato le chiavi, clicca sul pulsante qui sotto.'; }
	public function print_spid_section_info() { print 'Inserisci le credenziali fornite da AgID per la federazione OIDC SPID.'; }
    public function print_cie_section_info() { print 'Inserisci le credenziali fornite dal Ministero dell\'Interno per la federazione OIDC CIE.'; }
    	
    /**
     * Sanifica i dati in input prima di salvarli nel database.
     */
    public function sanitize_options( $input ) {
        $new_input = array();
        $current_options = get_option($this->plugin_name . '_options');

        // Mantiene le chiavi crittografiche esistenti, che non vengono inviate dal form.
        if (isset($current_options['oidc_public_key_jwk'])) { $new_input['oidc_public_key_jwk'] = $current_options['oidc_public_key_jwk']; }
        if (isset($current_options['oidc_private_key_pem'])) { $new_input['oidc_private_key_pem'] = $current_options['oidc_private_key_pem']; }

        // Sanifica i campi di testo
        $fields = ['spid_client_id', 'spid_client_secret', 'cie_client_id', 'cie_client_secret'];
        foreach ( $fields as $field ) { if ( isset( $input[$field] ) ) { $new_input[$field] = sanitize_text_field( $input[$field] ); } }
        
        // Sanifica le checkbox
        $checkboxes = ['spid_enabled', 'cie_enabled'];
        foreach ( $checkboxes as $checkbox ) { $new_input[$checkbox] = ( isset( $input[$checkbox] ) && $input[$checkbox] === '1' ) ? '1' : '0'; }

        return $new_input;
    }
}