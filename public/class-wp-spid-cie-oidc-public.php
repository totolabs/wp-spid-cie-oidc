<?php

/**
 * La funzionalità specifica dell'area pubblica del plugin.
 *
 * @package    WP_SPID_CIE_OIDC
 * @subpackage WP_SPID_CIE_OIDC/public
 */
class WP_SPID_CIE_OIDC_Public {

    private $plugin_name;
    private $version;

    public function __construct( $plugin_name, $version ) {
        $this->plugin_name = $plugin_name;
        $this->version = $version;

        add_shortcode('spid_cie_login', array($this, 'render_login_buttons'));
        add_action( 'login_form', array( $this, 'print_login_buttons_on_login_page' ) );
        add_action( 'login_message', array( $this, 'print_login_buttons_on_login_page' ) );
        add_action( 'login_enqueue_scripts', array( $this, 'enqueue_styles' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_styles' ) );
        add_action( 'init', array( $this, 'setup_federation_endpoints' ) );
        add_action( 'template_redirect', array( $this, 'serve_federation_endpoints' ) );
        add_action( 'template_redirect', array( $this, 'handle_login_flow' ) );
		add_filter('redirect_canonical', array($this, 'disable_canonical_for_federation'), 10, 2);
    }

    public function enqueue_styles() {
        wp_enqueue_style(
            $this->plugin_name,
            plugin_dir_url( __FILE__ ) . 'css/wp-spid-cie-oidc-public.css',
            array(),
            $this->version,
            'all'
        );

        echo '<script>
            function toggleSpidDropdown() {
                var dropdown = document.getElementById("spid-dropdown");
                if (dropdown.classList.contains("visible")) {
                    dropdown.classList.remove("visible");
                } else {
                    dropdown.classList.add("visible");
                }
            }
            document.addEventListener("click", function(event) {
                var wrapper = document.querySelector(".spid-button-wrapper");
                var dropdown = document.getElementById("spid-dropdown");
                if (wrapper && !wrapper.contains(event.target) && dropdown) {
                    dropdown.classList.remove("visible");
                }
            });
        </script>';
    }

    public function setup_federation_endpoints() {
		add_rewrite_rule('^\.well-known/openid-federation/?$', 'index.php?oidc_federation=config', 'top');
		add_rewrite_rule('^\.wellknown/openid-federation/?$',   'index.php?oidc_federation=config', 'top'); // alias (senza "-")
		add_rewrite_rule('^jwks.json/?$',                       'index.php?oidc_federation=jwks',   'top');

        add_filter( 'query_vars', function( $vars ) {
            $vars[] = 'oidc_federation';
            $vars[] = 'oidc_action';
            $vars[] = 'provider';
            $vars[] = 'idp';
            return $vars;
        });
    }
	
	public function disable_canonical_for_federation($redirect_url, $requested_url) {
			if (strpos($requested_url, '/.well-known/openid-federation') !== false) return false;
			if (strpos($requested_url, '/.wellknown/openid-federation') !== false) return false;
			if (strpos($requested_url, '/jwks.json') !== false) return false;
			return $redirect_url;
	}	

    public function serve_federation_endpoints() {
		global $wp_query;

		$action = $wp_query->get('oidc_federation');
		if ( ! $action ) {
			return;
		}

		// Per questi endpoint l'output deve essere "pulito":
		// niente Notice/Deprecated/HTML che romperebbero JWT/JSON
		@ini_set('display_errors', '0');
		@ini_set('log_errors', '1');
		error_reporting(0);

		// Svuota qualsiasi buffer già aperto (tema/plugin)
		while (ob_get_level() > 0) {
			@ob_end_clean();
		}

		// Log hits (senza dipendere dal Factory)
		$uploads = wp_upload_dir();
		$keyDir  = trailingslashit($uploads['basedir']) . 'spid-cie-oidc-keys';
		if ( ! is_dir($keyDir) ) {
			@wp_mkdir_p($keyDir);
		}
		@file_put_contents(
			$keyDir . '/hits.log',
			'[' . gmdate('c') . '] action=' . $action . ' host=' . ($_SERVER['HTTP_HOST'] ?? '') . ' uri=' . ($_SERVER['REQUEST_URI'] ?? '') . "\n",
			FILE_APPEND
		);

		if ( ! class_exists('WP_SPID_CIE_OIDC_Factory') ) {
			require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-wp-spid-cie-oidc-factory.php';
		}

		try {
			$client = WP_SPID_CIE_OIDC_Factory::get_client();

			nocache_headers();
			status_header(200);

			// evita che venga trattato come download
			header_remove('Content-Disposition');
			header('Content-Disposition: inline');
			header('X-Content-Type-Options: nosniff');

			if ( $action === 'config' ) {
				$jws = $client->getEntityStatement();

				header('Content-Type: application/entity-statement+jwt; charset=utf-8');
				echo is_string($jws) ? $jws : (string) $jws;
				exit;
			}

			if ( $action === 'jwks' ) {
				$jwks = $client->getJwks();

				header('Content-Type: application/jwk-set+json; charset=utf-8');

				// se getJwks ritorna array, lo serializziamo in JSON
				if (is_array($jwks) || is_object($jwks)) {
					echo wp_json_encode($jwks);
				} else {
					echo (string) $jwks;
				}
				exit;
			}

			// azione non supportata
			status_header(404);
			header('Content-Type: text/plain; charset=utf-8');
			echo 'Not found';
			exit;

		} catch (Exception $e) {
			status_header(500);
			header('Content-Type: text/plain; charset=utf-8');
			echo 'Errore OIDC Federation: ' . $e->getMessage();
			exit;
		}
	}
    public function handle_login_flow() {
        $action = isset($_GET['oidc_action']) ? $_GET['oidc_action'] : get_query_var('oidc_action');
        $provider = isset($_GET['provider']) ? $_GET['provider'] : get_query_var('provider');
        $idp = isset($_GET['idp']) ? $_GET['idp'] : get_query_var('idp');
        
        if ( $action !== 'login' || ! $provider ) return;

        if (!class_exists('WP_SPID_CIE_OIDC_Factory')) {
            require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-wp-spid-cie-oidc-factory.php';
        }

        try {
            $client = WP_SPID_CIE_OIDC_Factory::get_client();
            $trust_anchor = ($provider === 'cie') ? 'cie' : 'spid';
            $auth_url = $client->getAuthorizationUrl($trust_anchor, $idp);
            
            wp_redirect($auth_url);
            exit;

        } catch (Exception $e) {
            wp_die("Errore durante l'avvio del login: " . esc_html($e->getMessage()));
        }
    }

    private static $buttons_printed = false;

    public function print_login_buttons_on_login_page($arg = null) {
        if (self::$buttons_printed) return $arg;
        if (is_string($arg) && !empty($arg)) echo $arg;
        echo $this->render_login_buttons();
        self::$buttons_printed = true;
        return null;
    }

    public function render_login_buttons() {
        $options = get_option( $this->plugin_name . '_options' ); 
        
        $spid_enabled = isset($options['spid_enabled']) && $options['spid_enabled'] === '1';
        $cie_enabled = isset($options['cie_enabled']) && $options['cie_enabled'] === '1';
        
        // Gestione Disclaimer
        $disclaimer_enabled = isset($options['disclaimer_enabled']) && $options['disclaimer_enabled'] === '1';
        $disclaimer_text = !empty($options['disclaimer_text']) ? $options['disclaimer_text'] : '';

        if ( ! $spid_enabled && ! $cie_enabled ) return '';

        $base_url = home_url('/');
        $login_url_cie = add_query_arg(['oidc_action' => 'login', 'provider' => 'cie'], $base_url);

        if (!class_exists('WP_SPID_CIE_OIDC_Factory')) {
            require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-wp-spid-cie-oidc-factory.php';
        }
        $client = WP_SPID_CIE_OIDC_Factory::get_client();
        $spid_idps = $client->getSpidProviders();

        $keys = array_keys($spid_idps);
        shuffle($keys);
        $shuffled_idps = [];
        foreach ($keys as $key) {
            $shuffled_idps[$key] = $spid_idps[$key];
        }

        $assets_url = plugin_dir_url(__FILE__) . 'img/';

        ob_start();
        ?>
        <div class="spid-cie-container">
            
            <?php if ($disclaimer_enabled && !empty($disclaimer_text)): ?>
            <div style="background-color: #fff8e5; border: 1px solid #faebcc; color: #8a6d3b; padding: 10px; margin-bottom: 15px; font-size: 13px; border-radius: 4px; line-height: 1.4; text-align: left;">
                <?php echo wp_kses_post($disclaimer_text); ?>
            </div>
            <?php endif; ?>

            <span class="spid-cie-title">Accedi con Identità Digitale</span>
            
            <?php if ($spid_enabled): ?>
                <div class="spid-button-wrapper">
                    <a href="javascript:void(0)" onclick="toggleSpidDropdown()" class="spid-cie-button spid-button">
                        Entra con SPID
                    </a>
                    <ul id="spid-dropdown">
                        <?php foreach($shuffled_idps as $key => $idp): ?>
                            <?php 
                                $url = add_query_arg(['oidc_action' => 'login', 'provider' => 'spid', 'idp' => $key], $base_url);
                                $logo_src = $assets_url . $idp['logo']; 
                            ?>
                            <li class="spid-idp-item">
                                <a href="<?php echo esc_url($url); ?>" class="spid-idp-link">
                                    <?php if(!empty($idp['logo'])): ?>
                                        <img src="<?php echo esc_url($logo_src); ?>" alt="<?php echo esc_attr($idp['name']); ?>" class="spid-idp-icon">
                                    <?php endif; ?>
                                    <span class="spid-idp-label"><?php echo esc_html($idp['name']); ?></span>
                                </a>

                            </li>
                        <?php endforeach; ?>
                        <li class="spid-dropdown-footer">
                            <a href="https://www.spid.gov.it/cos-e-spid/come-attivare-spid/" target="_blank">Non hai SPID?</a>
                            &nbsp;|&nbsp; 
                            <a href="https://www.spid.gov.it/" target="_blank">Maggiori info</a>
                        </li>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if ($cie_enabled): ?>
                <a href="<?php echo esc_url($login_url_cie); ?>" class="spid-cie-button cie-button">
                    Entra con CIE
                </a>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
}