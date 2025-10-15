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

    /**
     * L'ID di questo plugin.
     * @var      string    $plugin_name
     */
    private $plugin_name;

    /**
     * La versione di questo plugin.
     * @var      string    $version
     */
    private $version;

    /**
     * Inizializza la classe e imposta le sue proprietà.
     *
     * @param      string    $plugin_name       Il nome del plugin.
     * @param      string    $version    La versione del plugin.
     */
    public function __construct( $plugin_name, $version ) {

        $this->plugin_name = $plugin_name;
        $this->version = $version;

        // Hook per aggiungere la pagina delle opzioni
        add_action( 'admin_menu', array( $this, 'add_options_page' ) );

        // Hook per registrare le impostazioni
        add_action( 'admin_init', array( $this, 'register_settings' ) );
    }

    /**
     * Aggiunge la pagina di opzioni nel menu di amministrazione.
     */
    public function add_options_page() {
        add_options_page(
            'Impostazioni SPID/CIE OIDC', // Titolo della pagina
            'SPID/CIE OIDC',             // Titolo nel menu
            'manage_options',            // Capability richiesta
            $this->plugin_name,          // Slug della pagina
            array( $this, 'create_admin_page' ) // Funzione che renderizza la pagina
        );
    }

    /**
     * Crea la pagina di amministrazione.
     */
    public function create_admin_page() {
        ?>
        <div class="wrap">
            <h2>Impostazioni SPID & CIE OIDC Login</h2>
            <p>Configura qui le credenziali per l'autenticazione tramite OpenID Connect.</p>
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
     * Registra le impostazioni, le sezioni e i campi.
     */
    public function register_settings() {
        // Registra il gruppo di impostazioni
        register_setting(
            $this->plugin_name . '_options_group', // Nome del gruppo
            $this->plugin_name . '_options',       // Nome dell'opzione nel database
            array( $this, 'sanitize_options' )    // Funzione di sanificazione (per sicurezza)
        );

        // Sezione per SPID
        add_settings_section(
            'spid_section',
            'Impostazioni SPID',
            array( $this, 'print_spid_section_info' ),
            $this->plugin_name
        );

        // Campo SPID Client ID
        add_settings_field(
            'spid_client_id',
            'SPID Client ID',
            array( $this, 'spid_client_id_callback' ),
            $this->plugin_name,
            'spid_section'
        );

        // Aggiungi qui altri campi se necessario (es. Client Secret, URL metadati, etc.)

    }

    /**
     * Sanifica ogni impostazione prima di salvarla nel database.
     * @param array $input Contiene tutte le impostazioni da sanificare.
     * @return array L'array sanificato.
     */
    public function sanitize_options( $input ) {
        $new_input = array();
        if ( isset( $input['spid_client_id'] ) ) {
            $new_input['spid_client_id'] = sanitize_text_field( $input['spid_client_id'] );
        }
        // Aggiungi qui la sanificazione per gli altri campi

        return $new_input;
    }

    /**
     * Stampa le informazioni per la sezione SPID.
     */
    public function print_spid_section_info() {
        print 'Inserisci le credenziali fornite dal portale di federazione di AgID:';
    }

    /**
     * Callback per il campo SPID Client ID.
     */
    public function spid_client_id_callback() {
        $options = get_option( $this->plugin_name . '_options' );
        printf(
            '<input type="text" id="spid_client_id" name="%s[spid_client_id]" value="%s" class="regular-text" />',
            esc_attr( $this->plugin_name . '_options' ),
            isset( $options['spid_client_id'] ) ? esc_attr( $options['spid_client_id'] ) : ''
        );
    }

} // Fine classe