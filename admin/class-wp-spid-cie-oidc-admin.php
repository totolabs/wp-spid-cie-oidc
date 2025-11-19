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
		// Hook per intercettare la generazione delle chiavi
        add_action( 'admin_init', array( $this, 'handle_key_generation' ) );
    }

	/**
     * Aggiunge la pagina di opzioni al menu.
     */
    public function add_options_page() {
        add_options_page(
            'Impostazioni SPID/CIE OIDC', 
            'SPID/CIE OIDC', 
            'manage_options', 
            $this->plugin_name, 
            array( $this, 'create_admin_page' )
        );
    }

	/**
     * Costruisce l'HTML della pagina di amministrazione.
     */
    public function create_admin_page() {
        ?>
        <div class="wrap">
            <h1>Impostazioni SPID & CIE OIDC (Conforme alla misura PNRR 1.4.4)</h1>
            <p>Configura qui i dati dell'Ente e gestisci le chiavi crittografiche per la Federazione CIE.</p>
            
            <?php settings_errors(); ?>

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
     * Registra le impostazioni.
     */
    public function register_settings() {
        register_setting(
            $this->plugin_name . '_options_group', 
            $this->plugin_name . '_options', 
            array( $this, 'sanitize_options' )
        );

        // --- SEZIONE 1: DATI ENTE ---
        add_settings_section(
            'ente_section', 
            '1. Dati Anagrafici Ente', 
            null, 
            $this->plugin_name
        );

        add_settings_field('organization_name', 'Denominazione Ente', array($this, 'render_text_field'), $this->plugin_name, 'ente_section', 
            ['id' => 'organization_name', 'desc' => 'Es. Comune di Napoli']
        );
        add_settings_field('ipa_code', 'Codice IPA', array($this, 'render_text_field'), $this->plugin_name, 'ente_section', 
            ['id' => 'ipa_code', 'desc' => 'Codice univoco dell\'ufficio (es. c_f839)']
        );
        add_settings_field('fiscal_number', 'Codice Fiscale Ente', array($this, 'render_text_field'), $this->plugin_name, 'ente_section', 
            ['id' => 'fiscal_number', 'desc' => 'Codice Fiscale numerico dell\'Ente (es. 80014890638)']
        );
        add_settings_field('contacts_email', 'Email Contatto Tecnico', array($this, 'render_text_field'), $this->plugin_name, 'ente_section', 
            ['id' => 'contacts_email', 'type' => 'email', 'desc' => 'Email per comunicazioni tecniche.']
        );

        // --- SEZIONE 2: GESTIONE CHIAVI ---
        add_settings_section(
            'keys_section', 
            '2. Crittografia e Federazione', 
            array($this, 'print_keys_section_info'), 
            $this->plugin_name
        );

        add_settings_field('oidc_keys_manager', 'Stato Chiavi', array($this, 'render_keys_manager'), $this->plugin_name, 'keys_section');

        // --- SEZIONE 3: SPID & CIE ---
        add_settings_section(
            'providers_section', 
            '3. Configurazione Provider', 
            null, 
            $this->plugin_name
        );

        add_settings_field('spid_enabled', 'Abilita SPID', array($this, 'render_checkbox_field'), $this->plugin_name, 'providers_section', ['id' => 'spid_enabled']);
        add_settings_field('cie_enabled', 'Abilita CIE', array($this, 'render_checkbox_field'), $this->plugin_name, 'providers_section', ['id' => 'cie_enabled']);
    }

	/**
     * Gestisce la generazione delle chiavi usando la Factory e la Libreria ufficiale.
     */
    public function handle_key_generation() {
        if ( isset( $_GET['action'], $_GET['_wpnonce'] ) && $_GET['action'] === 'generate_oidc_keys' ) {
            
            if ( ! wp_verify_nonce( $_GET['_wpnonce'], 'generate_oidc_keys_nonce' ) ) {
                wp_die('Security check failed');
            }
			
			// Carichiamo la Factory (che definiremo nel prossimo passaggio)
            if (!class_exists('WP_SPID_CIE_OIDC_Factory')) {
                 require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-wp-spid-cie-oidc-factory.php';
            }

            try {
				// Otteniamo il client dalla Factory
                $client = WP_SPID_CIE_OIDC_Factory::get_client();
				
                // La libreria genera e salva le chiavi nei percorsi definiti dalla Factory
                $client->generateKeys();
				
				// Redirect con successo
                wp_redirect(admin_url('options-general.php?page=' . $this->plugin_name . '&keys-generated=true'));
                exit;
            } catch (Exception $e) {
				// Salviamo l'errore in un transient per mostrarlo
                set_transient('spid_cie_oidc_error', $e->getMessage(), 45);
                wp_redirect(admin_url('options-general.php?page=' . $this->plugin_name . '&keys-error=true'));
                exit;
            }
        }
    }

	/**
     * Renderizza l'area di gestione chiavi.
     */
    public function render_keys_manager() {
		
		// Verifica se le chiavi esistono
        $keys_exist = false;
        $keys_dir = trailingslashit(wp_upload_dir()['basedir']) . 'spid-cie-oidc-keys';
        if (file_exists($keys_dir . '/private.key') && file_exists($keys_dir . '/public.crt')) {
            $keys_exist = true;
        }

        if ($keys_exist) {
            echo '<span class="dashicons dashicons-yes" style="color: green; font-size: 2rem; vertical-align: middle;"></span> <strong style="color:green; vertical-align: middle;">Chiavi presenti e valide.</strong>';
        } else {
            echo '<span class="dashicons dashicons-warning" style="color: orange; font-size: 2rem; vertical-align: middle;"></span> <strong style="color:orange; vertical-align: middle;">Chiavi non trovate.</strong> È necessario generarle.';
        }

        echo '<br><br>';
		
        // Pulsante Genera
        $generation_url = wp_nonce_url(admin_url('options-general.php?page=' . $this->plugin_name . '&action=generate_oidc_keys'), 'generate_oidc_keys_nonce');
        echo '<a href="' . esc_url($generation_url) . '" class="button button-secondary" onclick="return confirm(\'Sei sicuro? Se rigeneri le chiavi dovrai aggiornare la configurazione sui portali AGID/CIE.\');">Genera / Rigenera Chiavi</a>';

        echo '<hr>';
		
        // Mostra l'URL per la Federazione CIE
        $federation_url = home_url('/.well-known/openid-federation');
        echo '<p>Copia questo URL nel portale AGID/CIE come <strong>Entity Statement URI</strong>:</p>';
        echo '<input type="text" readonly class="large-text" value="' . esc_url($federation_url) . '" onclick="this.select();">';
        echo '<p class="description">Questo URL contiene i metadati tecnici necessari per la Federazione CIE.</p>';
    }

    public function print_keys_section_info() {
        if (isset($_GET['keys-generated'])) {
            echo '<div class="notice notice-success inline"><p>Chiavi generate con successo!</p></div>';
        }
        if (isset($_GET['keys-error'])) {
            $error = get_transient('spid_cie_oidc_error');
            echo '<div class="notice notice-error inline"><p>Errore: ' . esc_html($error) . '</p></div>';
        }
        echo '<p>Il sistema gestisce automaticamente la creazione dei certificati crittografici richiesti dalla Federazione CIE.</p>';
    }

    // --- Helper per i campi ---

    public function render_text_field( $args ) {
        $options = get_option( $this->plugin_name . '_options' );
        $id = $args['id'];
        $val = isset( $options[$id] ) ? esc_attr( $options[$id] ) : '';
        $desc = $args['desc'] ?? '';
        
        echo "<input type='text' name='{$this->plugin_name}_options[$id]' value='$val' class='regular-text'>";
        if ($desc) {
            echo "<p class='description'>$desc</p>";
        }
    }

    public function render_checkbox_field( $args ) {
        $options = get_option( $this->plugin_name . '_options' );
        $id = $args['id'];
        $checked = isset( $options[$id] ) && $options[$id] === '1' ? 'checked' : '';
        echo "<input type='checkbox' name='{$this->plugin_name}_options[$id]' value='1' $checked>";
    }

    public function sanitize_options( $input ) {
        $new_input = [];
        
        $text_fields = ['organization_name', 'ipa_code', 'fiscal_number', 'contacts_email'];
        foreach ($text_fields as $f) {
            if (isset($input[$f])) {
                $new_input[$f] = sanitize_text_field($input[$f]);
            }
        }
        
        $checkboxes = ['spid_enabled', 'cie_enabled'];
        foreach ($checkboxes as $c) {
            $new_input[$c] = (isset($input[$c]) && $input[$c] === '1') ? '1' : '0';
        }
        
        return $new_input;
    }
}