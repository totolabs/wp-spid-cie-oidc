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
        $current_tab = $this->get_current_tab();
        $tabs = $this->get_admin_tabs();
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">SPID & CIE OIDC Login (PNRR 1.4.4)</h1>
            <hr class="wp-header-end">
            
            <?php settings_errors(); ?>

            <nav class="nav-tab-wrapper spid-tabs" aria-label="Sezioni configurazione SPID CIE OIDC">
                <?php foreach ($tabs as $tab_key => $tab): ?>
                    <?php $url = add_query_arg(['page' => $this->plugin_name, 'tab' => $tab_key], admin_url('options-general.php')); ?>
                    <a href="<?php echo esc_url($url); ?>" class="nav-tab <?php echo $current_tab === $tab_key ? 'nav-tab-active' : ''; ?>">
                        <?php echo esc_html($tab['label']); ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <p class="description spid-tab-help"><?php echo esc_html($tabs[$current_tab]['help']); ?></p>

            <form method="post" action="options.php">
                <?php settings_fields( $this->plugin_name . '_options_group' ); ?>
                <input type="hidden" name="<?php echo esc_attr($this->plugin_name . '_options[_current_tab]'); ?>" value="<?php echo esc_attr($current_tab); ?>" />
                
                <div class="spid-admin-wrap">
                    <div class="spid-main-col">

                        <?php if ($current_tab === 'a_ente'): ?>
                            <div class="spid-card">
                                <?php do_settings_sections($this->plugin_name . '_ente'); ?>
                            </div>
                        <?php elseif ($current_tab === 'b_federazione'): ?>
                            <div class="spid-card">
                                <?php do_settings_sections($this->plugin_name . '_keys'); ?>
                            </div>
                        <?php elseif ($current_tab === 'c_provider'): ?>
                            <div class="spid-card">
                                <?php do_settings_sections($this->plugin_name . '_providers'); ?>
                            </div>
                        <?php elseif ($current_tab === 'd_disclaimer'): ?>
                            <div class="spid-card">
                                <?php do_settings_sections($this->plugin_name . '_disclaimer'); ?>
                                <?php $this->render_disclaimer_preview(); ?>
                            </div>
                        <?php elseif ($current_tab === 'e_operativo'): ?>
                            <div class="spid-card">
                                <?php $this->render_operational_help(); ?>
                            </div>
                        <?php elseif ($current_tab === 'f_spid_saml'): ?>
                            <div class="spid-card">
                                <?php do_settings_sections($this->plugin_name . '_saml'); ?>
                            </div>
                        <?php else: ?>
                            <div class="spid-card">
                                <?php $this->render_operational_help(); ?>
                            </div>
                        <?php endif; ?>

                        <?php submit_button('Salva Impostazioni Tab Corrente', 'primary large'); ?>
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
        add_settings_field('issuer_override', 'Issuer / Identificativo componente', array($this, 'render_text_field'), $this->plugin_name . '_ente', 'ente_section',
            ['id' => 'issuer_override', 'desc' => 'URL base HTTPS usato per endpoint e fallback metadata OIDC Federation.', 'placeholder' => 'https://demo.ente.it']
        );
        add_settings_field('entity_id', 'Entity ID Federation (iss/sub)', array($this, 'render_text_field'), $this->plugin_name . '_ente', 'ente_section',
            ['id' => 'entity_id', 'desc' => 'Identificativo entità usato come iss/sub/client_id. Mantiene lo slash finale se presente (es. https://demo.ente.it/).', 'placeholder' => 'https://demo.ente.it/']
        );

        // --- 2. CRITTOGRAFIA ---
        add_settings_section('keys_section', '2. Crittografia e Federazione', array($this, 'print_keys_section_info'), $this->plugin_name . '_keys');
        add_settings_field('oidc_keys_manager', 'Stato Chiavi', array($this, 'render_keys_manager'), $this->plugin_name . '_keys', 'keys_section');
		add_settings_field(
		  'cie_trust_anchor_preprod',
		  'Trust Anchor CIE (Pre-produzione)',
		  array($this, 'render_text_field'),
		  $this->plugin_name . '_keys',
		  'keys_section',
		  ['id' => 'cie_trust_anchor_preprod', 'desc' => 'URL Trust Anchor CIE pre-produzione', 'placeholder' => 'https://...']
		);
		add_settings_field(
		  'cie_trust_anchor_prod',
		  'Trust Anchor CIE (Produzione)',
		  array($this, 'render_text_field'),
		  $this->plugin_name . '_keys',
		  'keys_section',
		  ['id' => 'cie_trust_anchor_prod', 'desc' => 'URL Trust Anchor CIE produzione', 'placeholder' => 'https://...']
		);
		add_settings_field(
		  'spid_trust_anchor',
		  'Trust Anchor SPID (futuro)',
		  array($this, 'render_text_field'),
		  $this->plugin_name . '_keys',
		  'keys_section',
		  ['id' => 'spid_trust_anchor', 'desc' => 'URL Trust Anchor SPID (quando OIDC sarà operativo)', 'placeholder' => 'https://...']
		);
		add_settings_field(
		  'trust_anchor_preview',
		  'Trust anchor configurati (read-only)',
		  array($this, 'render_trust_anchor_preview_field'),
		  $this->plugin_name . '_keys',
		  'keys_section'
		);
		add_settings_field(
		  'cie_trust_mark_preprod',
		  'Trust Mark CIE (Pre-produzione)',
		  array($this, 'render_textarea_field'),
		  $this->plugin_name . '_keys',
		  'keys_section',
		  ['id' => 'cie_trust_mark_preprod', 'desc' => 'Incolla qui il JWT Trust Mark rilasciato dal portale CIE pre-prod.']
		);
		add_settings_field(
		  'cie_trust_mark_prod',
		  'Trust Mark CIE (Produzione)',
		  array($this, 'render_textarea_field'),
		  $this->plugin_name . '_keys',
		  'keys_section',
		  ['id' => 'cie_trust_mark_prod', 'desc' => 'Incolla qui il JWT Trust Mark rilasciato dal portale CIE prod.']
		);
		add_settings_field(
		  'public_key_pem',
		  'Chiave pubblica di federazione (PEM)',
		  array($this, 'render_public_key_field'),
		  $this->plugin_name . '_keys',
		  'keys_section'
		);
		add_settings_field(
		  'cie_certificate_pem',
		  'Certificato pubblico (X.509) per portale CIE',
		  array($this, 'render_cie_certificate_field'),
		  $this->plugin_name . '_keys',
		  'keys_section'
		);

		add_settings_field(
		  'public_key_raw_pem',
		  'Chiave pubblica (PEM) – raw',
		  array($this, 'render_public_key_raw_field'),
		  $this->plugin_name . '_keys',
		  'keys_section'
		);
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

        // Milestone 2: Provider profiles + discovery + LoA/ACR policy
        add_settings_field('provider_mode', 'Modalità Provider', array($this, 'render_select_field'), $this->plugin_name . '_providers', 'providers_section', [
            'id' => 'provider_mode',
            'options' => ['both' => 'SPID + CIE', 'spid_only' => 'Solo SPID', 'cie_only' => 'Solo CIE'],
            'default' => 'both',
            'desc' => 'Definisce quali provider sono autorizzati al login OIDC.'
        ]);
        add_settings_field('discovery_mode', 'Modalità Discovery', array($this, 'render_select_field'), $this->plugin_name . '_providers', 'providers_section', [
            'id' => 'discovery_mode',
            'options' => ['auto' => 'Auto (.well-known)', 'manual' => 'Manual endpoints'],
            'default' => 'auto',
            'desc' => 'Auto: usa issuer/.well-known/openid-configuration. Manual: usa endpoint configurati sotto.'
        ]);

        add_settings_field('min_loa', 'Livello minimo LoA/ACR', array($this, 'render_select_field'), $this->plugin_name . '_providers', 'providers_section', [
            'id' => 'min_loa',
            'options' => ['SpidL1' => 'SpidL1', 'SpidL2' => 'SpidL2 (consigliato)', 'SpidL3' => 'SpidL3'],
            'default' => 'SpidL2',
            'desc' => 'Valore minimo accettato nel claim acr.'
        ]);
        add_settings_field('auto_provisioning', 'Provisioning automatico utenti', array($this, 'render_checkbox_field'), $this->plugin_name . '_providers', 'providers_section', [
            'id' => 'auto_provisioning',
            'desc' => 'Se attivo, crea utenti WordPress quando non esiste un match identità.'
        ]);
        add_settings_field('default_role', 'Ruolo di default nuovi utenti', array($this, 'render_select_field'), $this->plugin_name . '_providers', 'providers_section', [
            'id' => 'default_role',
            'options' => $this->get_role_options(),
            'default' => get_option('default_role', 'subscriber'),
            'desc' => 'Ruolo assegnato ai nuovi utenti creati via provisioning.'
        ]);
        add_settings_field('spid_issuer', 'SPID Issuer', array($this, 'render_text_field'), $this->plugin_name . '_providers', 'providers_section', ['id' => 'spid_issuer', 'placeholder' => 'https://...', 'desc' => 'Issuer SPID (usato per discovery auto e validazione iss).']);
        add_settings_field('cie_issuer', 'CIE Issuer', array($this, 'render_text_field'), $this->plugin_name . '_providers', 'providers_section', ['id' => 'cie_issuer', 'placeholder' => 'https://...', 'desc' => 'Issuer CIE (usato per discovery auto e validazione iss).']);

        add_settings_field('spid_scope', 'SPID Scope', array($this, 'render_text_field'), $this->plugin_name . '_providers', 'providers_section', ['id' => 'spid_scope', 'placeholder' => 'openid profile', 'desc' => 'Scope base SPID.']);
        add_settings_field('cie_scope', 'CIE Scope', array($this, 'render_text_field'), $this->plugin_name . '_providers', 'providers_section', ['id' => 'cie_scope', 'placeholder' => 'openid profile email', 'desc' => 'Scope base CIE.']);
        add_settings_field('spid_acr_values', 'SPID acr_values', array($this, 'render_text_field'), $this->plugin_name . '_providers', 'providers_section', ['id' => 'spid_acr_values', 'placeholder' => 'https://www.spid.gov.it/SpidL2', 'desc' => 'Override opzionale acr_values SPID.']);
        add_settings_field('cie_acr_values', 'CIE acr_values', array($this, 'render_text_field'), $this->plugin_name . '_providers', 'providers_section', ['id' => 'cie_acr_values', 'placeholder' => 'https://www.spid.gov.it/SpidL2', 'desc' => 'Override opzionale acr_values CIE.']);

        // Manual endpoints (discovery_mode=manual)
        add_settings_field('spid_authorization_endpoint', 'SPID Authorization endpoint', array($this, 'render_text_field'), $this->plugin_name . '_providers', 'providers_section', ['id' => 'spid_authorization_endpoint', 'placeholder' => 'https://...', 'desc' => 'Usato solo in modalità manual.']);
        add_settings_field('spid_token_endpoint', 'SPID Token endpoint', array($this, 'render_text_field'), $this->plugin_name . '_providers', 'providers_section', ['id' => 'spid_token_endpoint', 'placeholder' => 'https://...', 'desc' => 'Usato solo in modalità manual.']);
        add_settings_field('spid_jwks_uri', 'SPID JWKS URI', array($this, 'render_text_field'), $this->plugin_name . '_providers', 'providers_section', ['id' => 'spid_jwks_uri', 'placeholder' => 'https://...', 'desc' => 'Usato solo in modalità manual.']);
        add_settings_field('spid_userinfo_endpoint', 'SPID UserInfo endpoint', array($this, 'render_text_field'), $this->plugin_name . '_providers', 'providers_section', ['id' => 'spid_userinfo_endpoint', 'placeholder' => 'https://...', 'desc' => 'Opzionale.']);
        add_settings_field('spid_end_session_endpoint', 'SPID End Session endpoint', array($this, 'render_text_field'), $this->plugin_name . '_providers', 'providers_section', ['id' => 'spid_end_session_endpoint', 'placeholder' => 'https://...', 'desc' => 'Opzionale.']);

        add_settings_field('cie_authorization_endpoint', 'CIE Authorization endpoint', array($this, 'render_text_field'), $this->plugin_name . '_providers', 'providers_section', ['id' => 'cie_authorization_endpoint', 'placeholder' => 'https://...', 'desc' => 'Usato solo in modalità manual.']);
        add_settings_field('cie_token_endpoint', 'CIE Token endpoint', array($this, 'render_text_field'), $this->plugin_name . '_providers', 'providers_section', ['id' => 'cie_token_endpoint', 'placeholder' => 'https://...', 'desc' => 'Usato solo in modalità manual.']);
        add_settings_field('cie_jwks_uri', 'CIE JWKS URI', array($this, 'render_text_field'), $this->plugin_name . '_providers', 'providers_section', ['id' => 'cie_jwks_uri', 'placeholder' => 'https://...', 'desc' => 'Usato solo in modalità manual.']);
        add_settings_field('cie_userinfo_endpoint', 'CIE UserInfo endpoint', array($this, 'render_text_field'), $this->plugin_name . '_providers', 'providers_section', ['id' => 'cie_userinfo_endpoint', 'placeholder' => 'https://...', 'desc' => 'Opzionale.']);
        add_settings_field('cie_end_session_endpoint', 'CIE End Session endpoint', array($this, 'render_text_field'), $this->plugin_name . '_providers', 'providers_section', ['id' => 'cie_end_session_endpoint', 'placeholder' => 'https://...', 'desc' => 'Opzionale.']);

        // --- 4. DISCLAIMER ---
        add_settings_section('disclaimer_section', '4. Gestione Avvisi (Disclaimer)', null, $this->plugin_name . '_disclaimer');
        add_settings_field('disclaimer_enabled', 'Attiva Messaggio Avviso', array($this, 'render_checkbox_field'), $this->plugin_name . '_disclaimer', 'disclaimer_section', 
            ['id' => 'disclaimer_enabled', 'desc' => 'Mostra un box di avviso sopra i pulsanti di login.']
        );
        $default_msg = "⚠️ <strong>Avviso Tecnico:</strong><br>I servizi di accesso SPID e CIE sono in fase di <strong>aggiornamento programmato</strong>. Il login potrebbe essere temporaneamente non disponibile.";
        add_settings_field('disclaimer_text', 'Testo dell\'Avviso', array($this, 'render_textarea_field'), $this->plugin_name . '_disclaimer', 'disclaimer_section', 
            ['id' => 'disclaimer_text', 'default' => $default_msg, 'desc' => 'HTML consentito (es. &lt;strong&gt;, &lt;br&gt;).']
        );

        // --- 5. SPID SAML (STEP 1) ---
        add_settings_section('spid_saml_section', '5. SPID SAML (Step 1)', null, $this->plugin_name . '_saml');
        add_settings_field('spid_saml_enabled', 'Abilita SPID SAML', array($this, 'render_checkbox_field'), $this->plugin_name . '_saml', 'spid_saml_section', [
            'id' => 'spid_saml_enabled',
            'desc' => 'Attiva il modulo SPID SAML e i relativi endpoint metadata/ACS/SLS.'
        ]);
        add_settings_field('spid_saml_entity_id', 'SPID SAML EntityID', array($this, 'render_text_field'), $this->plugin_name . '_saml', 'spid_saml_section', [
            'id' => 'spid_saml_entity_id',
            'placeholder' => 'https://demo.ente.it/spid/saml/metadata',
            'desc' => 'EntityID del Service Provider SAML. Se vuoto usa Issuer/Identificativo componente.'
        ]);
        add_settings_field('spid_saml_auto_provisioning', 'Provisioning automatico utenti', array($this, 'render_checkbox_field'), $this->plugin_name . '_saml', 'spid_saml_section', [
            'id' => 'spid_saml_auto_provisioning',
            'desc' => 'Se attivo, crea utenti WordPress mancanti al primo login SPID SAML.'
        ]);
        add_settings_field('spid_saml_default_role', 'Ruolo default nuovi utenti', array($this, 'render_select_field'), $this->plugin_name . '_saml', 'spid_saml_section', [
            'id' => 'spid_saml_default_role',
            'options' => $this->get_role_options(),
            'default' => get_option('default_role', 'subscriber'),
            'desc' => 'Ruolo assegnato ai nuovi utenti creati via provisioning SPID SAML.'
        ]);
        add_settings_field('spid_saml_debug', 'Debug SPID SAML', array($this, 'render_checkbox_field'), $this->plugin_name . '_saml', 'spid_saml_section', [
            'id' => 'spid_saml_debug',
            'desc' => 'Abilita log diagnostici sicuri e header diagnostici sugli endpoint SAML.'
        ]);
        add_settings_field('spid_saml_endpoints_preview', 'Endpoint SPID SAML (read-only)', array($this, 'render_spid_saml_endpoints_preview'), $this->plugin_name . '_saml', 'spid_saml_section');
    }

    private function get_admin_tabs(): array {
        return [
            'a_ente' => ['label' => 'A. Ente', 'help' => 'Configurazione anagrafica dell\'ente e trust anchor ufficiali.'],
            'b_federazione' => ['label' => 'B. Federazione', 'help' => 'Chiavi, entity statement e dati operativi per il portale CIE/SPID.'],
            'c_provider' => ['label' => 'C. Provider OIDC', 'help' => 'Modalità discovery, endpoint e policy di provisioning utenti.'],
            'd_disclaimer' => ['label' => 'D. Disclaimer', 'help' => 'Messaggi informativi mostrati sopra i pulsanti di login.'],
            'e_operativo' => ['label' => 'E. Operatività', 'help' => 'Shortcode, callback e checklist rapida di messa in esercizio.'],
            'f_spid_saml' => ['label' => 'F. SPID SAML', 'help' => 'Modulo SPID SAML (step 1): settings base ed endpoint tecnici placeholder.'],
        ];
    }

    private function get_current_tab(): string {
        $tabs = $this->get_admin_tabs();
        $requested = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'a_ente';
        return isset($tabs[$requested]) ? $requested : 'a_ente';
    }

    private function render_disclaimer_preview(): void {
        $options = get_option($this->plugin_name . '_options', []);
        $default_msg = "⚠️ <strong>Avviso Tecnico:</strong><br>I servizi di accesso SPID e CIE sono in fase di <strong>aggiornamento programmato</strong>. Il login potrebbe essere temporaneamente non disponibile.";
        $text = !empty($options['disclaimer_text']) ? (string) $options['disclaimer_text'] : $default_msg;

        echo '<h3>Anteprima disclaimer</h3>';
        echo '<div class="spid-disclaimer-preview">' . wp_kses_post($text) . '</div>';
    }

    private function render_operational_help(): void {
        $shortcode = '[spid_cie_login]';
        $callback = add_query_arg(['oidc_action' => 'callback', 'provider' => 'spid'], home_url('/'));

        echo '<h2>Checklist rapida</h2>';
        echo '<ol>';
        echo '<li>Inserisci lo shortcode <code>' . esc_html($shortcode) . '</code> in una pagina pubblica.</li>';
        echo '<li>Configura callback OIDC con URL <code>' . esc_html($callback) . '</code>.</li>';
        echo '<li>Verifica endpoint federation: <code>' . esc_html(home_url('/.well-known/openid-federation')) . '</code>.</li>';
        echo '</ol>';
        echo '<p class="description">Compatibilità login: se il path standard di WordPress è nascosto (es. WPS Hide Login), lo shortcode resta il fallback supportato.</p>';
    }

    public function render_spid_saml_endpoints_preview() {
        $metadata = home_url('/spid/saml/metadata');
        $acs = home_url('/spid/saml/acs');
        $sls = home_url('/spid/saml/sls');

        echo '<ul class="spid-readonly-list">';
        echo '<li><strong>Metadata:</strong> <code>' . esc_html($metadata) . '</code></li>';
        echo '<li><strong>ACS:</strong> <code>' . esc_html($acs) . '</code></li>';
        echo '<li><strong>SLS:</strong> <code>' . esc_html($sls) . '</code></li>';
        echo '</ul>';
        echo '<p class="description">Step 1: metadata XML placeholder + endpoint ACS/SLS con risposta TODO.</p>';
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
            $options = get_option( $this->plugin_name . '_options', [] );
            $entity_id = !empty($options['entity_id']) ? trim((string) $options['entity_id']) : '';
            $base = $entity_id !== '' ? untrailingslashit($entity_id) : (!empty($options['issuer_override']) ? untrailingslashit($options['issuer_override']) : home_url());
            $federation_url = $base . '/.well-known/openid-federation';
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
	
    public function render_select_field( $args ) {
        $options = get_option( $this->plugin_name . '_options' );
        $id = $args['id'];
        $choices = $args['options'] ?? [];
        $default = $args['default'] ?? '';
        $val = isset($options[$id]) ? (string) $options[$id] : (string) $default;
        $desc = $args['desc'] ?? '';

        echo "<select name='{$this->plugin_name}_options[$id]'>";
        foreach ($choices as $k => $label) {
            $selected = selected($val, (string) $k, false);
            echo "<option value='" . esc_attr($k) . "' $selected>" . esc_html($label) . "</option>";
        }
        echo "</select>";
        if ($desc) {
            echo "<p class='description'>" . esc_html($desc) . "</p>";
        }
    }

	public function render_public_key_field() {
		$upload_dir = wp_upload_dir();
		$keys_dir = trailingslashit($upload_dir['basedir']) . 'spid-cie-oidc-keys';

		$public_file = $keys_dir . '/public.crt';

		if (!file_exists($public_file)) {
			echo '<p style="color:#b32d2e;">Chiave pubblica non disponibile. Genera prima le chiavi.</p>';
			return;
		}

		$public_pem = file_get_contents($public_file);
		if (!$public_pem) {
			echo '<p style="color:#b32d2e;">Impossibile leggere la chiave pubblica. Verifica permessi in uploads/spid-cie-oidc-keys.</p>';
			return;
		}

		$id = 'wp_spid_cie_oidc_public_key_pem';

		echo '<textarea id="'.esc_attr($id).'" class="large-text code" rows="8" readonly>'
			. esc_textarea($public_pem)
			. '</textarea>';

		echo '<p><button type="button" class="button" onclick="(function(){const el=document.getElementById(\''.esc_js($id).'\'); el.select(); document.execCommand(\'copy\');})();">Copia</button></p>';

		echo '<p class="description"><strong>Nota:</strong> se rigeneri le chiavi, devi aggiornare questa chiave anche sul portale CIE.</p>';
	}
	
	public function render_trust_anchor_preview_field() {
		$options = get_option($this->plugin_name . '_options', []);
		$rows = [
			'CIE pre-produzione' => $options['cie_trust_anchor_preprod'] ?? '',
			'CIE produzione' => $options['cie_trust_anchor_prod'] ?? '',
			'SPID' => $options['spid_trust_anchor'] ?? '',
		];

		echo '<ul class="spid-readonly-list">';
		foreach ($rows as $label => $url) {
			echo '<li><strong>' . esc_html($label) . ':</strong> <code>' . esc_html($url !== '' ? $url : 'non impostato') . '</code></li>';
		}
		echo '</ul>';
		echo '<p class="description">Valori mostrati in sola lettura per verifica rapida pre-invio al portale di federazione.</p>';
	}

	private function get_keys_dir_path(): string {
		$upload_dir = wp_upload_dir();
		return trailingslashit($upload_dir['basedir']) . 'spid-cie-oidc-keys';
	}

	private function render_copyable_textarea(string $id, string $value, int $rows = 8, string $help = ''): void {
		echo '<textarea id="'.esc_attr($id).'" class="large-text code" rows="'.intval($rows).'" readonly>'
			. esc_textarea($value)
			. '</textarea>';

		echo '<p><button type="button" class="button" onclick="(function(){const el=document.getElementById(\''.esc_js($id).'\'); el.focus(); el.select(); document.execCommand(\'copy\');})();">Copia</button></p>';

		if ($help) {
			echo '<p class="description">'.$help.'</p>';
		}
	}

	public function render_cie_certificate_field() {
		$keys_dir = $this->get_keys_dir_path();
		$cert_file = $keys_dir . '/public.crt';

		if (!file_exists($cert_file)) {
			echo '<p style="color:#b32d2e;">Certificato non disponibile. Genera prima le chiavi.</p>';
			return;
		}

		$cert_pem = file_get_contents($cert_file);
		if (!$cert_pem) {
			echo '<p style="color:#b32d2e;">Impossibile leggere il certificato. Verifica permessi in uploads/spid-cie-oidc-keys.</p>';
			return;
		}

		// Controllo "sanity": deve essere un CERTIFICATE
		if (stripos($cert_pem, 'BEGIN CERTIFICATE') === false) {
			echo '<p style="color:#b32d2e;"><strong>Attenzione:</strong> public.crt non sembra essere un certificato X.509 (BEGIN CERTIFICATE). Rigenera le chiavi con la nuova versione del plugin.</p>';
			// Mostriamo comunque il contenuto per debug/copia
		}

		$help = '<strong>Da incollare nel portale Federazione CIE</strong> nel campo “Chiave pubblica di federazione”.'
			  . '<br><strong>Nota:</strong> se rigeneri le chiavi, devi aggiornare anche questa chiave sul portale CIE.';

		$this->render_copyable_textarea(
			'wp_spid_cie_oidc_cert_pem',
			$cert_pem,
			10,
			$help
		);
	}

	public function render_public_key_raw_field() {
		$keys_dir = $this->get_keys_dir_path();
		$pub_file = $keys_dir . '/public.key';

		if (!file_exists($pub_file)) {
			echo '<p style="color:#b32d2e;">Public key non disponibile. Genera prima le chiavi.</p>';
			return;
		}

		$pub_pem = file_get_contents($pub_file);
		if (!$pub_pem) {
			echo '<p style="color:#b32d2e;">Impossibile leggere la public key. Verifica permessi in uploads/spid-cie-oidc-keys.</p>';
			return;
		}

		$help = 'Chiave pubblica “raw” (PEM). <em>Di solito NON va usata nel portale</em> (che preferisce il certificato X.509), ma è utile per debug o interoperabilità.';

		$this->render_copyable_textarea(
			'wp_spid_cie_oidc_public_key_raw',
			$pub_pem,
			8,
			$help
		);
	}
	
    private function get_role_options(): array {
        $roles = wp_roles()->roles;
        $out = [];
        foreach ($roles as $key => $role) {
            $out[$key] = translate_user_role($role['name']);
        }
        return $out;
    }

    public function sanitize_options( $input ) {
        $existing = get_option($this->plugin_name . '_options', []);
        if (!is_array($existing)) {
            $existing = [];
        }

        $current_tab = isset($input['_current_tab']) ? sanitize_key($input['_current_tab']) : 'a_ente';
        $allowed_by_tab = [
            'a_ente' => ['organization_name', 'ipa_code', 'fiscal_number', 'contacts_email', 'issuer_override', 'entity_id'],
            'b_federazione' => ['cie_trust_anchor_preprod', 'cie_trust_anchor_prod', 'spid_trust_anchor', 'cie_trust_mark_preprod', 'cie_trust_mark_prod'],
            'c_provider' => [
                'spid_enabled', 'cie_enabled', 'spid_test_env', 'provider_mode', 'discovery_mode', 'default_role', 'auto_provisioning', 'min_loa',
                'spid_issuer', 'cie_issuer', 'spid_scope', 'cie_scope', 'spid_acr_values', 'cie_acr_values',
                'spid_authorization_endpoint', 'spid_token_endpoint', 'spid_jwks_uri', 'spid_userinfo_endpoint', 'spid_end_session_endpoint',
                'cie_authorization_endpoint', 'cie_token_endpoint', 'cie_jwks_uri', 'cie_userinfo_endpoint', 'cie_end_session_endpoint'
            ],
            'd_disclaimer' => ['disclaimer_enabled', 'disclaimer_text'],
            'e_operativo' => [],
            'f_spid_saml' => ['spid_saml_enabled', 'spid_saml_entity_id', 'spid_saml_auto_provisioning', 'spid_saml_default_role', 'spid_saml_debug'],
        ];

        $new_input = $existing;
        $allowed = $allowed_by_tab[$current_tab] ?? [];

        $text_fields = ['organization_name', 'ipa_code', 'fiscal_number', 'contacts_email', 'spid_scope', 'cie_scope', 'spid_acr_values', 'cie_acr_values'];
        foreach ($text_fields as $f) {
            if (in_array($f, $allowed, true) && isset($input[$f])) {
                $new_input[$f] = sanitize_text_field($input[$f]);
            }
        }

        $checkboxes = ['spid_enabled', 'cie_enabled', 'spid_test_env', 'disclaimer_enabled', 'auto_provisioning', 'spid_saml_enabled', 'spid_saml_auto_provisioning', 'spid_saml_debug'];
        foreach ($checkboxes as $c) {
            if (in_array($c, $allowed, true)) {
                $new_input[$c] = (isset($input[$c]) && $input[$c] === '1') ? '1' : '0';
            }
        }

        if (in_array('disclaimer_text', $allowed, true) && isset($input['disclaimer_text'])) {
            $new_input['disclaimer_text'] = wp_kses_post($input['disclaimer_text']);
        }

        $tm_fields = ['cie_trust_mark_preprod', 'cie_trust_mark_prod'];
        foreach ($tm_fields as $f) {
            if (in_array($f, $allowed, true) && isset($input[$f])) {
                $new_input[$f] = trim(sanitize_textarea_field($input[$f]));
            }
        }

        $url_fields = [
            'issuer_override', 'entity_id',
            'cie_trust_anchor_preprod', 'cie_trust_anchor_prod', 'spid_trust_anchor',
            'spid_saml_entity_id',
            'spid_issuer', 'cie_issuer',
            'spid_authorization_endpoint', 'spid_token_endpoint', 'spid_jwks_uri', 'spid_userinfo_endpoint', 'spid_end_session_endpoint',
            'cie_authorization_endpoint', 'cie_token_endpoint', 'cie_jwks_uri', 'cie_userinfo_endpoint', 'cie_end_session_endpoint'
        ];
        foreach ($url_fields as $f) {
            if (in_array($f, $allowed, true) && isset($input[$f])) {
                $new_input[$f] = esc_url_raw(trim((string) $input[$f]));
            }
        }

        if (in_array('provider_mode', $allowed, true)) {
            $provider_mode = isset($input['provider_mode']) ? sanitize_key($input['provider_mode']) : 'both';
            $new_input['provider_mode'] = in_array($provider_mode, ['both', 'spid_only', 'cie_only'], true) ? $provider_mode : 'both';
        }

        if (in_array('discovery_mode', $allowed, true)) {
            $discovery_mode = isset($input['discovery_mode']) ? sanitize_key($input['discovery_mode']) : 'auto';
            $new_input['discovery_mode'] = in_array($discovery_mode, ['auto', 'manual'], true) ? $discovery_mode : 'auto';
        }

        if (in_array('min_loa', $allowed, true)) {
            $loa = isset($input['min_loa']) ? sanitize_text_field($input['min_loa']) : 'SpidL2';
            $new_input['min_loa'] = in_array($loa, ['SpidL1', 'SpidL2', 'SpidL3'], true) ? $loa : 'SpidL2';
        }

        if (in_array('default_role', $allowed, true)) {
            $role = isset($input['default_role']) ? sanitize_key($input['default_role']) : get_option('default_role', 'subscriber');
            $new_input['default_role'] = get_role($role) ? $role : get_option('default_role', 'subscriber');
        }

        if (in_array('spid_saml_default_role', $allowed, true)) {
            $role = isset($input['spid_saml_default_role']) ? sanitize_key($input['spid_saml_default_role']) : get_option('default_role', 'subscriber');
            $new_input['spid_saml_default_role'] = get_role($role) ? $role : get_option('default_role', 'subscriber');
        }

        return $new_input;
    }
}
