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
            <h2>Impostazioni SPID & CIE OIDC Login</h2>
            <p>Configura qui le credenziali e gli endpoint per l'autenticazione tramite OpenID Connect.</p>
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
     * Registra tutte le impostazioni, sezioni e campi.
     */
    public function register_settings() {
        $option_group = $this->plugin_name . '_options_group';
        $option_name = $this->plugin_name . '_options';

        register_setting(
            $option_group,
            $option_name,
            array( $this, 'sanitize_options' )
        );

        // --- SEZIONE SPID ---
        add_settings_section(
            'spid_section',
            'Impostazioni SPID',
            array( $this, 'print_spid_section_info' ),
            $this->plugin_name
        );

        add_settings_field('spid_enabled', 'Abilita SPID', array( $this, 'render_checkbox_field' ), $this->plugin_name, 'spid_section', ['id' => 'spid_enabled']);
        add_settings_field('spid_client_id', 'SPID Client ID', array( $this, 'render_text_field' ), $this->plugin_name, 'spid_section', ['id' => 'spid_client_id']);
        add_settings_field('spid_client_secret', 'SPID Client Secret', array( $this, 'render_text_field' ), $this->plugin_name, 'spid_section', ['id' => 'spid_client_secret', 'type' => 'password']);
        add_settings_field('spid_metadata_url', 'URL Metadati SPID', array( $this, 'render_text_field' ), $this->plugin_name, 'spid_section', ['id' => 'spid_metadata_url', 'placeholder' => 'https://registry.spid.gov.it/openid-providers/']);

        // --- SEZIONE CIE ---
        add_settings_section(
            'cie_section',
            'Impostazioni CIE',
            array( $this, 'print_cie_section_info' ),
            $this->plugin_name
        );

        add_settings_field('cie_enabled', 'Abilita CIE', array( $this, 'render_checkbox_field' ), $this->plugin_name, 'cie_section', ['id' => 'cie_enabled']);
        add_settings_field('cie_client_id', 'CIE Client ID', array( $this, 'render_text_field' ), $this->plugin_name, 'cie_section', ['id' => 'cie_client_id']);
        add_settings_field('cie_client_secret', 'CIE Client Secret', array( $this, 'render_text_field' ), $this->plugin_name, 'cie_section', ['id' => 'cie_client_secret', 'type' => 'password']);
        add_settings_field('cie_metadata_url', 'URL Metadati CIE', array( $this, 'render_text_field' ), $this->plugin_name, 'cie_section', ['id' => 'cie_metadata_url', 'placeholder' => 'https://preproduzione.id.cie.gov.it/.well-known/openid-federation']);
    }

    /**
     * Funzioni "callback" per renderizzare i campi (DRY - Don't Repeat Yourself)
     */
    public function render_text_field( $args ) {
        $options = get_option( $this->plugin_name . '_options' );
        $id = $args['id'];
        $type = isset($args['type']) ? $args['type'] : 'text';
        $placeholder = isset($args['placeholder']) ? $args['placeholder'] : '';
        printf(
            '<input type="%s" id="%s" name="%s[%s]" value="%s" class="regular-text" placeholder="%s" />',
            esc_attr($type),
            esc_attr($id),
            esc_attr( $this->plugin_name . '_options' ),
            esc_attr($id),
            isset( $options[$id] ) ? esc_attr( $options[$id] ) : '',
            esc_attr($placeholder)
        );
    }
    
    public function render_checkbox_field( $args ) {
        $options = get_option( $this->plugin_name . '_options' );
        $id = $args['id'];
        $checked = isset( $options[$id] ) && $options[$id] === '1' ? 'checked' : '';
        printf(
            '<input type="checkbox" id="%s" name="%s[%s]" value="1" %s />',
            esc_attr($id),
            esc_attr( $this->plugin_name . '_options' ),
            esc_attr($id),
            $checked
        );
    }

    /**
     * Funzioni "callback" per le descrizioni delle sezioni
     */
    public function print_spid_section_info() {
        print 'Inserisci le credenziali fornite da AgID per la federazione OIDC SPID. L\'URL dei metadati di produzione è solitamente `https://registry.spid.gov.it/openid-providers/`.';
    }

    public function print_cie_section_info() {
        print 'Inserisci le credenziali fornite dal Ministero dell\'Interno per la federazione OIDC CIE. L\'URL dei metadati di pre-produzione è `https://preproduzione.id.cie.gov.it/.well-known/openid-federation`.';
    }
    
    /**
     * Sanifica tutte le opzioni prima di salvarle.
     */
    public function sanitize_options( $input ) {
        $new_input = array();
        $fields = ['spid_client_id', 'spid_client_secret', 'spid_metadata_url', 'cie_client_id', 'cie_client_secret', 'cie_metadata_url'];
        $checkboxes = ['spid_enabled', 'cie_enabled'];

        foreach ( $fields as $field ) {
            if ( isset( $input[$field] ) ) {
                $new_input[$field] = sanitize_text_field( $input[$field] );
            }
        }
        
        foreach ( $checkboxes as $checkbox ) {
            $new_input[$checkbox] = ( isset( $input[$checkbox] ) && $input[$checkbox] === '1' ) ? '1' : '0';
        }

        return $new_input;
    }
}