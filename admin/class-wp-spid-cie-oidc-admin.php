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
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_styles' ) );
    }

    /**
     * Carica gli stili CSS per il pannello di amministrazione.
     */
    public function enqueue_admin_styles($hook) {
        if (strpos($hook, $this->plugin_name) === false) {
            return;
        }

        wp_enqueue_style(
            $this->plugin_name . '-admin',
            plugin_dir_url( __FILE__ ) . 'css/wp-spid-cie-oidc-admin.css',
            array(),
            $this->version,
            'all'
        );
    }

    public function add_options_page() {
        add_options_page(
            'Configurazione SPID & CIE', // Titolo pagina browser
            'SPID & CIE Login',          // Titolo menu (Più user friendly)
            'manage_options', 
            $this->plugin_name, 
            array( $this, 'create_admin_page' )
        );
    }

    public function create_admin_page() {
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">SPID & CIE OIDC Login (PNRR 1.4.4)</h1>
            <hr class="wp-header-end">
            
            <?php settings_errors(); ?>

            <form method="post" action="options.php">
                <?php settings_fields( $this->plugin_name . '_options_group' ); ?>
                
                <div class="spid-admin-wrap">
                    <div class="spid-main-col">
                        
                        <div class="spid-card">
                            <?php do_settings_sections($this->plugin_name . '_ente'); ?>
                        </div>

                        <div class="spid-card">
                            <?php do_settings_sections($this->plugin_name . '_keys'); ?>
                        </div>

                        <div class="spid-card">
                            <?php do_settings_sections($this->plugin_name . '_providers'); ?>
                        </div>

                        <div class="spid-card">
                            <?php do_settings_sections($this->plugin_name . '_disclaimer'); ?>
                        </div>

                        <?php submit_button('Salva Tutte le Impostazioni', 'primary large'); ?>
                    </div>

                    <div class="spid-side-col">
                        
                        <div class="spid-side-box">
                            <div class="spid-side-header">Progetto Open Source</div>
                            <div class="spid-side-content">
                                <p>Questo plugin è sviluppato con filosofia <strong>Open Source</strong> per supportare la digitalizzazione della PA Italiana.</p>
                                <ul>
                                    <li><a href="https://github.com/totolabs/wp-spid-cie-oidc" target="_blank">Repository GitHub</a></li>
                                    <li><a href="https://github.com/totolabs/wp-spid-cie-oidc/wiki" target="_blank">Manuale & Wiki</a></li>
                                    <li><a href="https://wordpress.org/plugins/" target="_blank">Pagina Plugin WordPress</a></li>
                                </ul>
                                <p>Hai trovato un bug? Vuoi contribuire? Apri una Issue su GitHub!</p>
                            </div>
                        </div>

                        <div class="spid-side-box">
                            <div class="spid-side-header">Sviluppo & Supporto</div>
                            <div class="spid-side-content">
                                <img src="<?php echo plugin_dir_url(dirname(__FILE__)) . 'public/img/logo-totolabs.png'; ?>" alt="Totolabs" style="max-width: 100%; height: auto; margin-bottom: 10px; display:none;"> 
                                
                                <p>Sviluppato con ❤️ da <strong>Totolabs Srl</strong>.</p>
                                <p>Offriamo servizi specialistici per le PA:</p>
                                <ul>
                                    <li>Supporto all'installazione</li>
                                    <li>Consulenza accreditamento AgID/CIE</li>
                                    <li>Sviluppo personalizzato</li>
                                </ul>
                                <a href="https://www.totolabs.it" target="_blank" class="button button-secondary button-full">Visita Totolabs.it</a>
                            </div>
                        </div>

                    </div>
                </div>
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

        // --- 1. DATI ANAGRAFICI ---
        add_settings_section('ente_section', '1. Dati Anagrafici Ente', null, $this->plugin_name . '_ente');
        add_settings_field('organization_name', 'Denominazione Ente', array($this, 'render_text_field'), $this->plugin_name . '_ente', 'ente_section', 
            ['id' => 'organization_name', 'desc' => 'Es. Comune di Roma', 'placeholder' => 'Es. Comune di Roma']
        );
        add_settings_field('ipa_code', 'Codice IPA', array($this, 'render_text_field'), $this->plugin_name . '_ente', 'ente_section', 
            ['id' => 'ipa_code', 'desc' => 'Codice univoco IPA (es. c_h501)', 'placeholder' => 'c_h501']
        );
        add_settings_field('fiscal_number', 'Codice Fiscale Ente', array($this, 'render_text_field'), $this->plugin_name . '_ente', 'ente_section', 
            ['id' => 'fiscal_number', 'desc' => 'Codice Fiscale numerico (es. 80012345678)', 'placeholder' => '01234567890']
        );
        add_settings_field('contacts_email', 'Email Contatto Tecnico', array($this, 'render_text_field'), $this->plugin_name . '_ente', 'ente_section', 
            ['id' => 'contacts_email', 'type' => 'email', 'desc' => 'Email per comunicazioni tecniche.', 'placeholder' => 'ced@ente.it']
        );

        // --- 2. CRITTOGRAFIA ---
        add_settings_section('keys_section', '2. Crittografia e Federazione', array($this, 'print_keys_section_info'), $this->plugin_name . '_keys');
        add_settings_field('oidc_keys_manager', 'Stato Chiavi', array($this, 'render_keys_manager'), $this->plugin_name . '_keys', 'keys_section');

        // --- 3. ATTIVAZIONE SERVIZI ---
        add_settings_section('providers_section', '3. Attivazione Servizi', null, $this->plugin_name . '_providers');
        
        // SPID e relativo test
        add_settings_field('spid_enabled', 'Abilita SPID', array($this, 'render_checkbox_field'), $this->plugin_name . '_providers', 'providers_section', ['id' => 'spid_enabled', 'desc' => 'Mostra il pulsante "Entra con SPID".']);
        
        // Spostiamo qui sotto il Test Environment (logicamente collegato a SPID)
        add_settings_field('spid_test_env', 'Ambiente di Test (Validator)', array($this, 'render_checkbox_field'), $this->plugin_name . '_providers', 'providers_section', 
            ['id' => 'spid_test_env', 'desc' => 'Abilita il provider "SPID Validator" (solo per collaudo tecnico AgID).']
        );

        // CIE
        add_settings_field('cie_enabled', 'Abilita CIE', array($this, 'render_checkbox_field'), $this->plugin_name . '_providers', 'providers_section', ['id' => 'cie_enabled', 'desc' => 'Mostra il pulsante "Entra con CIE".']);
        

        // --- 4. DISCLAIMER ---
        add_settings_section('disclaimer_section', '4. Gestione Avvisi (Disclaimer)', null, $this->plugin_name . '_disclaimer');
        add_settings_field('disclaimer_enabled', 'Attiva Messaggio Avviso', array($this, 'render_checkbox_field'), $this->plugin_name . '_disclaimer', 'disclaimer_section', 
            ['id' => 'disclaimer_enabled', 'desc' => 'Mostra un box di avviso sopra i pulsanti di login.']
        );
        $default_msg = "⚠️ <strong>Avviso Tecnico:</strong><br>I servizi di accesso SPID e CIE sono in fase di <strong>aggiornamento programmato</strong>. Il login potrebbe essere temporaneamente non disponibile.";
        add_settings_field('disclaimer_text', 'Testo dell\'Avviso', array($this, 'render_textarea_field'), $this->plugin_name . '_disclaimer', 'disclaimer_section', 
            ['id' => 'disclaimer_text', 'default' => $default_msg, 'desc' => 'HTML consentito (es. &lt;strong&gt;, &lt;br&gt;).']
        );
    }

    // --- CALLBACK RENDERING ---

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
            echo '<div class="spid-status-ok"><span class="dashicons dashicons-yes"></span> Chiavi crittografiche presenti e valide.</div>';
        } else {
            echo '<div class="spid-status-ko"><span class="dashicons dashicons-warning"></span> Chiavi non trovate. È necessario generarle per attivare il servizio.</div>';
        }

        echo '<p style="margin: 15px 0;">';
        $generation_url = wp_nonce_url(admin_url('options-general.php?page=' . $this->plugin_name . '&action=generate_oidc_keys'), 'generate_oidc_keys_nonce');
        echo '<a href="' . esc_url($generation_url) . '" class="button button-secondary" onclick="return confirm(\'Sei sicuro? Rigenerare le chiavi renderà invalidi i metadata attuali sui portali AgID/CIE.\');">'. ($keys_exist ? 'Rigenera Chiavi' : 'Genera Chiavi') .'</a>';
        echo '</p>';

        if ($keys_exist) {
            echo '<hr>';
            $federation_url = home_url('/.well-known/openid-federation');
            echo '<label for="entity_statement_uri"><strong>Entity Statement URI (Metadata OIDC):</strong></label>';
            echo '<p class="description">Copia questo URL per la registrazione sui portali AgID e Federazione CIE.</p>';
            echo '<input type="text" id="entity_statement_uri" readonly class="large-text code" value="' . esc_url($federation_url) . '" onclick="this.select();">';
        }
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
        $placeholder = $args['placeholder'] ?? '';
        
        echo "<input type='text' name='{$this->plugin_name}_options[$id]' value='$val' class='regular-text' placeholder='$placeholder'>";
        if ($desc) { echo "<p class='description'>$desc</p>"; }
    }

    public function render_textarea_field( $args ) {
        $options = get_option( $this->plugin_name . '_options' );
        $id = $args['id'];
        $default = $args['default'] ?? '';
        $val = isset($options[$id]) ? $options[$id] : '';
        if (empty($val) && !empty($default)) { $val = $default; }
        
        $desc = $args['desc'] ?? '';
        echo "<textarea name='{$this->plugin_name}_options[$id]' class='large-text' rows='4'>" . esc_textarea($val) . "</textarea>";
        if ($desc) echo "<p class='description'>$desc</p>";
    }

    public function render_checkbox_field( $args ) {
        $options = get_option( $this->plugin_name . '_options' );
        $id = $args['id'];
        $checked = isset( $options[$id] ) && $options[$id] === '1' ? 'checked' : '';
        $desc = $args['desc'] ?? '';
        echo "<label><input type='checkbox' name='{$this->plugin_name}_options[$id]' value='1' $checked> $desc</label>";
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
        if (isset($input['disclaimer_text'])) {
            $new_input['disclaimer_text'] = wp_kses_post($input['disclaimer_text']);
        }
        return $new_input;
    }
}