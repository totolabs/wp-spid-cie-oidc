<?php

/**
 * La funzionalità specifica dell'area di amministrazione del plugin.
 *
 * @since      1.0.0
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
        add_options_page(
            'Impostazioni SPID/CIE OIDC', 
            'SPID/CIE OIDC', 
            'manage_options', 
            $this->plugin_name, 
            array( $this, 'create_admin_page' )
        );
    }

    public function create_admin_page() {
        ?>
        <div class="wrap">
            <h1>Impostazioni SPID & CIE OIDC (PNRR 1.4.4)</h1>
            <p>Configura qui i dati dell'Ente e gestisci le chiavi crittografiche per la federazione.</p>
            
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

    public function register_settings() {
        register_setting(
            $this->plugin_name . '_options_group', 
            $this->plugin_name . '_options', 
            array( $this, 'sanitize_options' )
        );

        // --- SEZIONE 1: DATI ENTE ---
        add_settings_section('ente_section', '1. Dati Anagrafici Ente', null, $this->plugin_name);

        add_settings_field('organization_name', 'Denominazione Ente', array($this, 'render_text_field'), $this->plugin_name, 'ente_section', 
            ['id' => 'organization_name', 'desc' => 'Es. Ordine TSRM di Salerno']
        );
        add_settings_field('ipa_code', 'Codice IPA', array($this, 'render_text_field'), $this->plugin_name, 'ente_section', 
            ['id' => 'ipa_code', 'desc' => 'Codice univoco dell\'ufficio (es. cpdtsrs)']
        );
        add_settings_field('fiscal_number', 'Codice Fiscale Ente', array($this, 'render_text_field'), $this->plugin_name, 'ente_section', 
            ['id' => 'fiscal_number', 'desc' => 'Codice Fiscale numerico dell\'Ente (es. 80028330654)']
        );
        add_settings_field('contacts_email', 'Email Contatto Tecnico', array($this, 'render_text_field'), $this->plugin_name, 'ente_section', 
            ['id' => 'contacts_email', 'type' => 'email', 'desc' => 'Email per comunicazioni tecniche relative alla federazione.']
        );

        // --- SEZIONE 2: GESTIONE CHIAVI ---
        add_settings_section('keys_section', '2. Crittografia e Federazione', array($this, 'print_keys_section_info'), $this->plugin_name);
        add_settings_field('oidc_keys_manager', 'Stato Chiavi', array($this, 'render_keys_manager'), $this->plugin_name, 'keys_section');

        // --- SEZIONE 3: CONFIGURAZIONE PROVIDER ---
        add_settings_section('providers_section', '3. Configurazione Provider', null, $this->plugin_name);
        add_settings_field('spid_enabled', 'Abilita SPID', array($this, 'render_checkbox_field'), $this->plugin_name, 'providers_section', ['id' => 'spid_enabled']);
        add_settings_field('cie_enabled', 'Abilita CIE', array($this, 'render_checkbox_field'), $this->plugin_name, 'providers_section', ['id' => 'cie_enabled']);
        add_settings_field('spid_test_env', 'Abilita Ambiente di Test', array($this, 'render_checkbox_field'), $this->plugin_name, 'providers_section', 
            ['id' => 'spid_test_env', 'desc' => 'Mostra "SPID Validator" nel menu di scelta (Utile per collaudo).']
        );

        // --- SEZIONE 4: DISCLAIMER ---
        add_settings_section('disclaimer_section', '4. Gestione Avvisi (Disclaimer)', null, $this->plugin_name);
        
        add_settings_field('disclaimer_enabled', 'Attiva Messaggio Avviso', array($this, 'render_checkbox_field'), $this->plugin_name, 'disclaimer_section', 
            ['id' => 'disclaimer_enabled', 'desc' => 'Mostra un box di avviso sopra i pulsanti di login.']
        );
        
        $default_msg = "⚠️ <strong>Avviso Tecnico:</strong><br>I servizi di accesso SPID e CIE sono in fase di <strong>aggiornamento programmato</strong>. Il login potrebbe essere temporaneamente non disponibile.";
        
        add_settings_field('disclaimer_text', 'Testo dell\'Avviso', array($this, 'render_textarea_field'), $this->plugin_name, 'disclaimer_section', 
            ['id' => 'disclaimer_text', 'default' => $default_msg, 'desc' => 'Puoi usare tag HTML come &lt;strong&gt;, &lt;br&gt;, &lt;a&gt;.']
        );
    }

    public function handle_key_generation() {
        if ( isset( $_GET['action'], $_GET['_wpnonce'] ) && $_GET['action'] === 'generate_oidc_keys' ) {
            if ( ! wp_verify_nonce( $_GET['_wpnonce'], 'generate_oidc_keys_nonce' ) ) { wp_die('Security check failed'); }
            
            if (!class_exists('WP_SPID_CIE_OIDC_Factory')) {
                 require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-wp-spid-cie-oidc-factory.php';
            }

            try {
                $client = WP_SPID_CIE_OIDC_Factory::get_client();
                $client->generateKeys();
                wp_redirect(admin_url('options-general.php?page=' . $this->plugin_name . '&keys-generated=true'));
                exit;
            } catch (Exception $e) {
                set_transient('spid_cie_oidc_error', $e->getMessage(), 45);
                wp_redirect(admin_url('options-general.php?page=' . $this->plugin_name . '&keys-error=true'));
                exit;
            }
        }
    }

    public function render_keys_manager() {
        $keys_exist = false;
        $upload_dir = wp_upload_dir();
        $keys_dir = trailingslashit($upload_dir['basedir']) . 'spid-cie-oidc-keys';
        
        if (file_exists($keys_dir . '/private.key') && file_exists($keys_dir . '/public.crt')) {
            $keys_exist = true;
        }

        if ($keys_exist) {
            echo '<span class="dashicons dashicons-yes" style="color: green; font-size: 2rem; vertical-align: middle;"></span> <strong style="color:green; vertical-align: middle;">Chiavi presenti e valide.</strong>';
        } else {
            echo '<span class="dashicons dashicons-warning" style="color: orange; font-size: 2rem; vertical-align: middle;"></span> <strong style="color:orange; vertical-align: middle;">Chiavi non trovate.</strong> È necessario generarle.';
        }

        echo '<br><br>';
        $generation_url = wp_nonce_url(admin_url('options-general.php?page=' . $this->plugin_name . '&action=generate_oidc_keys'), 'generate_oidc_keys_nonce');
        echo '<a href="' . esc_url($generation_url) . '" class="button button-secondary" onclick="return confirm(\'Sei sicuro? Se rigeneri le chiavi dovrai aggiornare la configurazione sui portali AGID/CIE.\');">Genera / Rigenera Chiavi</a>';

        echo '<hr>';
        $federation_url = home_url('/.well-known/openid-federation');
        echo '<p>Copia questo URL nel portale AGID/CIE come <strong>Entity Statement URI</strong>:</p>';
        echo '<input type="text" readonly class="large-text" value="' . esc_url($federation_url) . '" onclick="this.select();">';
        echo '<p class="description">Questo URL contiene i metadati tecnici necessari per la federazione.</p>';
    }

    public function print_keys_section_info() {
        if (isset($_GET['keys-generated'])) { echo '<div class="notice notice-success inline"><p>Chiavi generate con successo!</p></div>'; }
        if (isset($_GET['keys-error'])) {
            $error = get_transient('spid_cie_oidc_error');
            echo '<div class="notice notice-error inline"><p>Errore: ' . esc_html($error) . '</p></div>';
        }
        echo '<p>Il sistema gestisce automaticamente la creazione dei certificati crittografici richiesti dalla federazione.</p>';
    }

    public function render_text_field( $args ) {
        $options = get_option( $this->plugin_name . '_options' );
        $id = $args['id'];
        $val = isset( $options[$id] ) ? esc_attr( $options[$id] ) : '';
        $desc = $args['desc'] ?? '';
        echo "<input type='text' name='{$this->plugin_name}_options[$id]' value='$val' class='regular-text'>";
        if ($desc) echo "<p class='description'>$desc</p>";
    }

    // FIX: Logica migliorata per il default
    public function render_textarea_field( $args ) {
        $options = get_option( $this->plugin_name . '_options' );
        $id = $args['id'];
        $default = $args['default'] ?? '';
        
        // Se l'opzione esiste la usiamo
        $val = isset($options[$id]) ? $options[$id] : '';
        
        // Se è vuota, ma abbiamo un default, pre-compiliamo per comodità
        // (Solo visivamente, finché l'utente non salva)
        if (empty($val) && !empty($default)) { 
             $val = $default; 
        }
        
        $desc = $args['desc'] ?? '';
        echo "<textarea name='{$this->plugin_name}_options[$id]' class='large-text' rows='4'>" . esc_textarea($val) . "</textarea>";
        if ($desc) echo "<p class='description'>$desc</p>";
    }

    public function render_checkbox_field( $args ) {
        $options = get_option( $this->plugin_name . '_options' );
        $id = $args['id'];
        $checked = isset( $options[$id] ) && $options[$id] === '1' ? 'checked' : '';
        $desc = $args['desc'] ?? '';
        echo "<input type='checkbox' name='{$this->plugin_name}_options[$id]' value='1' $checked>";
        if ($desc) echo "<span class='description' style='margin-left:5px;'>$desc</span>";
    }

    public function sanitize_options( $input ) {
        $new_input = [];
        $text_fields = ['organization_name', 'ipa_code', 'fiscal_number', 'contacts_email'];
        foreach ($text_fields as $f) {
            if (isset($input[$f])) { $new_input[$f] = sanitize_text_field($input[$f]); }
        }
        
        $checkboxes = ['spid_enabled', 'cie_enabled', 'spid_test_env', 'disclaimer_enabled'];
        foreach ($checkboxes as $c) {
            $new_input[$c] = (isset($input[$c]) && $input[$c] === '1') ? '1' : '0';
        }

        // Sanificazione HTML per la textarea
        if (isset($input['disclaimer_text'])) {
            $new_input['disclaimer_text'] = wp_kses_post($input['disclaimer_text']);
        }
        
        return $new_input;
    }
}