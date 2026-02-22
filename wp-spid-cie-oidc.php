<?php

/**
 * The plugin bootstrap file
 *
 * This file is read by WordPress to generate the plugin information in the plugin
 * admin area. This file also includes all of the dependencies used by the plugin,
 * registers the activation and deactivation functions, and defines a function
 * that starts the plugin.
 *
 * @link              https://totolabs.it
 * @since             0.1.0
 * @package           WP_SPID_CIE_OIDC
 *
 * @wordpress-plugin
 * Plugin Name:       SPID & CIE OIDC Login per WordPress
 * Plugin URI:        https://github.com/totolabs/wp-spid-cie-oidc
 * Description:       Abilita l'autenticazione tramite SPID e CIE con protocollo OpenID Connect per le Pubbliche Amministrazioni italiane. Conforme PNRR 1.4.4. Sviluppato da Totolabs Srl.
 * Version:           1.0.0
 * Author:            Totolabs Srl
 * Author URI:        https://totolabs.it
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-spid-cie-oidc
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      7.4
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

// 1. Carica l'autoloader di Composer (Librerie esterne)
if ( file_exists( plugin_dir_path( __FILE__ ) . 'vendor/autoload.php' ) ) {
    require_once plugin_dir_path( __FILE__ ) . 'vendor/autoload.php';
}

// 2. Carica la nostra Factory (Gestione Configurazione e Chiavi)
require_once plugin_dir_path( __FILE__ ) . 'includes/class-wp-spid-cie-oidc-factory.php';

// 2.b Core OIDC runtime services (Milestone 1)
require_once plugin_dir_path( __FILE__ ) . 'includes/Logging/Logger.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/Core/PkceService.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/Core/StateNonceStore.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/Core/TokenValidator.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/Core/OidcClient.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/Providers/ProviderProfileInterface.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/Providers/DiscoveryResolver.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/Providers/SpidProviderProfile.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/Providers/CieProviderProfile.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/Providers/ProviderRegistry.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/WP/WpUserMapper.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/WP/WpAuthService.php';

// 3. Carica le classi Admin e Public
require_once plugin_dir_path( __FILE__ ) . 'admin/class-wp-spid-cie-oidc-admin.php';
require_once plugin_dir_path( __FILE__ ) . 'public/class-wp-spid-cie-oidc-public.php';


/**
 * Esegue il plugin.
 * Inizializza le classi Admin (se siamo nel backend) e Public (sempre).
 */
function run_wp_spid_cie_oidc() {

    $plugin_name = 'wp-spid-cie-oidc';
    $version = '1.0.0';

    // Avvia la parte Admin (solo se l'utente è amministratore o sta caricando /wp-admin/)
    if ( is_admin() ) {
        $plugin_admin = new WP_SPID_CIE_OIDC_Admin( $plugin_name, $version );
    }

    // Bootstrap runtime services once (OIDC client, mapper, auth, provider registry)
    WP_SPID_CIE_OIDC_Factory::get_runtime_services();
    WP_SPID_CIE_OIDC_Factory::get_provider_registry();

    // Avvia la parte Public (Login, Callback, Shortcodes, Endpoint API)
    $plugin_public = new WP_SPID_CIE_OIDC_Public( $plugin_name, $version );

}

/**
 * Imposta valori di default alla prima attivazione.
 */
function wp_spid_cie_oidc_activate() {
    $option_name = 'wp-spid-cie-oidc_options';
    $options = get_option($option_name, []);

    // Imposta i default solo se il campo è vuoto/non esiste
    $defaults = [
        'cie_trust_anchor_preprod' => 'https://registry.interno.gov.it/',
        'cie_trust_anchor_prod'    => 'https://registry.interno.gov.it/',
        'spid_trust_anchor'        => 'https://registry.agid.gov.it/',
    ];

    $updated = false;
    foreach ($defaults as $k => $v) {
        if (empty($options[$k])) {
            $options[$k] = $v;
            $updated = true;
        }
    }

    if ($updated) {
        update_option($option_name, $options);
    }
}

register_activation_hook(__FILE__, 'wp_spid_cie_oidc_activate');

add_action('plugins_loaded', function () {
    // Imposta i default anche su installazioni già attive (upgrade-safe)
    wp_spid_cie_oidc_activate();
});

// Avvia tutto
run_wp_spid_cie_oidc();
