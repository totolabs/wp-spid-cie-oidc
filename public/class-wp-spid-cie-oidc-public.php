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
		add_rewrite_rule('^spid/saml/metadata/?$',              'index.php?spid_saml_route=metadata', 'top');
		add_rewrite_rule('^spid/saml/acs/?$',                   'index.php?spid_saml_route=acs',      'top');
		add_rewrite_rule('^spid/saml/sls/?$',                   'index.php?spid_saml_route=sls',      'top');
		add_rewrite_rule('^\.well-known/openid-federation/?$', 'index.php?oidc_federation=config', 'top');
		add_rewrite_rule('^\.wellknown/openid-federation/?$',   'index.php?oidc_federation=config', 'top'); // alias (senza "-")
		add_rewrite_rule('^jwks.json/?$',                       'index.php?oidc_federation=jwks',   'top');
		add_rewrite_rule('^resolve/?$',                         'index.php?oidc_federation=resolve','top');

        add_filter( 'query_vars', function( $vars ) {
            $vars[] = 'oidc_federation';
            $vars[] = 'spid_saml_route';
            $vars[] = 'oidc_action';
            $vars[] = 'provider';
            $vars[] = 'idp';
            return $vars;
        });
    }
	
	public function disable_canonical_for_federation($redirect_url, $requested_url) {
			if (strpos($requested_url, '/spid/saml/metadata') !== false) return false;
			if (strpos($requested_url, '/spid/saml/acs') !== false) return false;
			if (strpos($requested_url, '/spid/saml/sls') !== false) return false;
			if (strpos($requested_url, '/.well-known/openid-federation') !== false) return false;
			if (strpos($requested_url, '/.wellknown/openid-federation') !== false) return false;
			if (strpos($requested_url, '/jwks.json') !== false) return false;
			if (strpos($requested_url, '/resolve') !== false) return false;
			return $redirect_url;
	}	

    public function serve_federation_endpoints() {
		global $wp_query;

		$saml_route = $wp_query->get('spid_saml_route');
		if (!is_string($saml_route) || $saml_route === '') {
			$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
			$path = '/' . ltrim((string) $path, '/');
			if ($path === '/spid/saml/metadata') {
				$saml_route = 'metadata';
			} elseif ($path === '/spid/saml/acs') {
				$saml_route = 'acs';
			} elseif ($path === '/spid/saml/sls') {
				$saml_route = 'sls';
			}
		}

		if (is_string($saml_route) && in_array($saml_route, ['metadata', 'acs', 'sls'], true)) {
			$this->serve_spid_saml_route($saml_route);
		}

		$action = $wp_query->get('oidc_federation');
		if ( ! $action ) {
			$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
			$path = '/' . ltrim((string) $path, '/');
			if ($path === '/.well-known/openid-federation' || $path === '/.wellknown/openid-federation') {
				$action = 'config';
			} elseif ($path === '/jwks.json') {
				$action = 'jwks';
			} elseif ($path === '/resolve') {
				$action = 'resolve';
			} else {
				return;
			}
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
			$entity_id = method_exists($client, 'getEntityId') ? (string) $client->getEntityId() : '';
			$remote_ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
			$user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
			@error_log('[wp-spid-cie-oidc federation] action=' . $action . ' path=' . ($_SERVER['REQUEST_URI'] ?? '') . ' ip=' . $remote_ip . ' ua=' . $user_agent . ' entity_id=' . $entity_id);

			nocache_headers();
			status_header(200);

			// evita che venga trattato come download
			header_remove('Content-Disposition');
			header('Content-Disposition: inline');
			header('X-Content-Type-Options: nosniff');
			if (defined('WP_DEBUG') && WP_DEBUG && !empty($entity_id)) {
				header('X-SPIDCIE-Entity-ID: ' . $entity_id);
			}

			if ( $action === 'config' ) {
				$jws = $client->getEntityStatement();
				$payload = $this->extract_jwt_payload((string) $jws);
				if (is_array($payload)) {
					@error_log('[wp-spid-cie-oidc federation] entity-config iss=' . ($payload['iss'] ?? '') . ' sub=' . ($payload['sub'] ?? '') . ' client_id=' . ($payload['metadata']['openid_relying_party']['client_id'] ?? ''));
				}

				$debug_mode = isset($_GET['debug']) && $_GET['debug'] === '1';
				if ($debug_mode && defined('WP_DEBUG') && WP_DEBUG && current_user_can('manage_options')) {
					header('Content-Type: application/json; charset=utf-8');
					echo wp_json_encode([
						'entity_id_configured' => $entity_id,
						'iss' => is_array($payload) ? ($payload['iss'] ?? '') : '',
						'sub' => is_array($payload) ? ($payload['sub'] ?? '') : '',
						'client_id' => is_array($payload) ? ($payload['metadata']['openid_relying_party']['client_id'] ?? '') : '',
						'metadata_url' => home_url('/.well-known/openid-federation'),
						'resolve_url' => home_url('/resolve'),
						'note' => 'Debug enabled via ?debug=1 (WP_DEBUG + admin only)'
					], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
					exit;
				}

				header('Content-Type: application/entity-statement+jwt');
				echo trim(is_string($jws) ? $jws : (string) $jws);
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

			if ( $action === 'resolve' ) {
				$sub = isset($_GET['sub']) ? esc_url_raw(wp_unslash($_GET['sub'])) : '';
				$trust_anchor = isset($_GET['trust_anchor']) ? esc_url_raw(wp_unslash($_GET['trust_anchor'])) : '';
				$jws = $client->getResolveResponse($sub, $trust_anchor);

				header('Content-Type: application/resolve-response+jwt');
				echo trim(is_string($jws) ? $jws : (string) $jws);
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

	private function serve_spid_saml_route(string $route): void {
		$options = get_option($this->plugin_name . '_options', []);
		$enabled = !empty($options['spid_saml_enabled']) && $options['spid_saml_enabled'] === '1';
		$debug_enabled = !empty($options['spid_saml_debug']) && $options['spid_saml_debug'] === '1';

		if (!$enabled) {
			status_header(404);
			header('Content-Type: text/plain; charset=utf-8');
			echo 'Not found';
			exit;
		}

		$method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';
		$content_length = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
		if ($debug_enabled) {
			@error_log('[wp-spid-cie-oidc saml] route=' . $route . ' method=' . $method . ' content_length=' . $content_length);
		}

		nocache_headers();
		header('X-Content-Type-Options: nosniff');
		if ($debug_enabled && defined('WP_DEBUG') && WP_DEBUG) {
			header('X-SPIDCIE-SAML-Route: ' . $route);
			header('X-SPIDCIE-SAML-Method: ' . $method);
		}

		if ($route === 'metadata') {
			if ($method !== 'GET') {
				status_header(405);
				header('Allow: GET');
				header('Content-Type: text/plain; charset=utf-8');
				echo 'Method Not Allowed';
				exit;
			}
			$this->serve_spid_saml_metadata($options);
		}

		if ($route === 'acs' && $method !== 'POST') {
			status_header(405);
			header('Allow: POST');
			header('Content-Type: text/plain; charset=utf-8');
			echo 'Method Not Allowed';
			exit;
		}

		if ($route === 'sls' && !in_array($method, ['GET', 'POST'], true)) {
			status_header(405);
			header('Allow: GET, POST');
			header('Content-Type: text/plain; charset=utf-8');
			echo 'Method Not Allowed';
			exit;
		}

		status_header(200);
		header('Content-Type: text/plain; charset=utf-8');
		echo 'TODO: implement SPID SAML ' . strtoupper($route) . ' handler';
		exit;
	}

	private function serve_spid_saml_metadata(array $options): void {
		$entity_id = isset($options['spid_saml_entity_id']) && $options['spid_saml_entity_id'] !== ''
			? esc_url_raw((string) $options['spid_saml_entity_id'])
			: (!empty($options['issuer_override']) ? esc_url_raw((string) $options['issuer_override']) : home_url('/'));

		$acs_url = home_url('/spid/saml/acs');
		$sls_url = home_url('/spid/saml/sls');
		$organization_name = isset($options['organization_name']) ? sanitize_text_field((string) $options['organization_name']) : get_bloginfo('name');
		$contacts_email = isset($options['contacts_email']) ? sanitize_email((string) $options['contacts_email']) : get_option('admin_email');
		$ipa_code = isset($options['ipa_code']) ? sanitize_text_field((string) $options['ipa_code']) : '';
		$fiscal_number = isset($options['fiscal_number']) ? sanitize_text_field((string) $options['fiscal_number']) : '';
		$cert = $this->get_saml_signing_cert();
		$authn_requests_signed = $cert !== '' ? 'true' : 'false';

		status_header(200);
		header('Content-Type: application/samlmetadata+xml; charset=utf-8');

		echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		echo '<md:EntityDescriptor xmlns:md="urn:oasis:names:tc:SAML:2.0:metadata" xmlns:ds="http://www.w3.org/2000/09/xmldsig#" xmlns:spid="https://spid.gov.it/saml-extensions" entityID="' . esc_attr($entity_id) . '">' . "\n";
		echo '  <md:SPSSODescriptor protocolSupportEnumeration="urn:oasis:names:tc:SAML:2.0:protocol" AuthnRequestsSigned="' . esc_attr($authn_requests_signed) . '" WantAssertionsSigned="true">' . "\n";
		if ($cert !== '') {
			echo '    <md:KeyDescriptor use="signing">' . "\n";
			echo '      <ds:KeyInfo>' . "\n";
			echo '        <ds:X509Data><ds:X509Certificate>' . esc_html($cert) . '</ds:X509Certificate></ds:X509Data>' . "\n";
			echo '      </ds:KeyInfo>' . "\n";
			echo '    </md:KeyDescriptor>' . "\n";
		}
		echo '    <md:NameIDFormat>urn:oasis:names:tc:SAML:2.0:nameid-format:transient</md:NameIDFormat>' . "\n";
		echo '    <md:AssertionConsumerService Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST" Location="' . esc_url($acs_url) . '" index="0" isDefault="true" />' . "\n";
		echo '    <md:SingleLogoutService Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect" Location="' . esc_url($sls_url) . '" />' . "\n";
		echo '    <md:AttributeConsumingService index="0">' . "\n";
		echo '      <md:ServiceName xml:lang="it">Set base SPID</md:ServiceName>' . "\n";
		echo '      <md:RequestedAttribute Name="name" />' . "\n";
		echo '      <md:RequestedAttribute Name="familyName" />' . "\n";
		echo '      <md:RequestedAttribute Name="fiscalNumber" />' . "\n";
		echo '      <md:RequestedAttribute Name="email" />' . "\n";
		echo '    </md:AttributeConsumingService>' . "\n";
		echo '  </md:SPSSODescriptor>' . "\n";
		echo '  <md:Organization>' . "\n";
		echo '    <md:OrganizationName xml:lang="it">' . esc_html($organization_name) . '</md:OrganizationName>' . "\n";
		echo '    <md:OrganizationDisplayName xml:lang="it">' . esc_html($organization_name) . '</md:OrganizationDisplayName>' . "\n";
		echo '    <md:OrganizationURL xml:lang="it">' . esc_url(home_url('/')) . '</md:OrganizationURL>' . "\n";
		echo '  </md:Organization>' . "\n";
		echo '  <md:ContactPerson contactType="other">' . "\n";
		echo '    <md:Extensions>' . "\n";
		if ($ipa_code !== '') {
			echo '      <spid:IPACode>' . esc_html($ipa_code) . '</spid:IPACode>' . "\n";
		}
		echo '      <spid:Public />' . "\n";
		if ($fiscal_number !== '') {
			echo '      <spid:FiscalCode>' . esc_html($fiscal_number) . '</spid:FiscalCode>' . "\n";
		}
		echo '    </md:Extensions>' . "\n";
		if (is_email($contacts_email)) {
			echo '    <md:EmailAddress>' . esc_html($contacts_email) . '</md:EmailAddress>' . "\n";
		}
		echo '  </md:ContactPerson>' . "\n";
		echo '</md:EntityDescriptor>';
		exit;
	}

	private function get_saml_signing_cert(): string {
		$upload_dir = wp_upload_dir();
		$cert_file = trailingslashit($upload_dir['basedir']) . 'spid-cie-oidc-keys/public.crt';
		if (!file_exists($cert_file) || !is_readable($cert_file)) {
			return '';
		}

		$cert = (string) file_get_contents($cert_file);
		if ($cert === '') {
			return '';
		}

		$cert = str_replace(["\r", "\n", '-----BEGIN CERTIFICATE-----', '-----END CERTIFICATE-----'], '', $cert);
		return trim($cert);
	}

    private function extract_jwt_payload($jwt) {
        $parts = explode('.', trim((string) $jwt));
        if (count($parts) < 2) {
            return null;
        }

        $payload_b64 = strtr($parts[1], '-_', '+/');
        $payload_b64 .= str_repeat('=', (4 - strlen($payload_b64) % 4) % 4);
        $json = base64_decode($payload_b64, true);
        if ($json === false) {
            return null;
        }

        $payload = json_decode($json, true);
        return is_array($payload) ? $payload : null;
    }

    public function handle_login_flow() {
        $action = isset($_GET['oidc_action']) ? sanitize_key(wp_unslash($_GET['oidc_action'])) : get_query_var('oidc_action');
        $provider = isset($_GET['provider']) ? sanitize_key(wp_unslash($_GET['provider'])) : get_query_var('provider');
        $idp = isset($_GET['idp']) ? sanitize_key(wp_unslash($_GET['idp'])) : get_query_var('idp');

        if (!in_array($action, ['login', 'callback'], true) || !in_array($provider, ['spid', 'cie'], true)) {
            return;
        }

        if (!class_exists('WP_SPID_CIE_OIDC_Factory')) {
            require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-wp-spid-cie-oidc-factory.php';
        }

        $runtime = WP_SPID_CIE_OIDC_Factory::get_runtime_services();
        /** @var WP_SPID_CIE_OIDC_Logger $logger */
        $logger = $runtime['logger'];
        /** @var WP_SPID_CIE_OIDC_OidcClient $oidc */
        $oidc = $runtime['oidc_client'];
        /** @var WP_SPID_CIE_OIDC_WpUserMapper $userMapper */
        $userMapper = $runtime['user_mapper'];
        /** @var WP_SPID_CIE_OIDC_WpAuthService $authService */
        $authService = $runtime['auth_service'];
        $correlation_id = $logger->generateCorrelationId();

        $registry = WP_SPID_CIE_OIDC_Factory::get_provider_registry();
        $provider_config = $registry->resolveConfig($provider, $idp);
        if (is_wp_error($provider_config)) {
            $logger->error('OIDC provider config resolution failed', [
                'correlation_id' => $correlation_id,
                'provider' => $provider,
                'error_code' => $provider_config->get_error_code(),
            ]);
            $this->redirect_to_login_error($provider_config->get_error_code());
        }

        if ($action === 'login') {
            $target_url = $this->resolve_redirect_target();
            $auth_url = $oidc->buildAuthorizationUrl($provider_config, $target_url, $correlation_id);
            if (is_wp_error($auth_url)) {
                $logger->error('OIDC start login failed', [
                    'correlation_id' => $correlation_id,
                    'provider' => $provider,
                    'error_code' => $auth_url->get_error_code(),
                ]);
                $this->redirect_to_login_error('oidc_start_failed');
            }

            $logger->info('OIDC start login redirect', [
                'correlation_id' => $correlation_id,
                'provider' => $provider,
                'idp' => $idp,
            ]);

            wp_safe_redirect($auth_url);
            exit;
        }

        $request = [
            'state' => $_REQUEST['state'] ?? '',
            'code' => $_REQUEST['code'] ?? '',
            'error' => $_REQUEST['error'] ?? '',
            'correlation_id' => $correlation_id,
        ];

        $result = $oidc->handleCallback($request, $provider_config);
        if (is_wp_error($result)) {
            $logger->error('OIDC callback failed', [
                'correlation_id' => $correlation_id,
                'provider' => $provider,
                'error_code' => $result->get_error_code(),
            ]);
            $this->redirect_to_login_error($result->get_error_code());
        }

        $claims = $result['claims'];
        $state_context = $result['state_context'];

        $normalized = $userMapper->normalizeClaims($claims, $provider);
        $valid = $userMapper->validateMandatoryClaims($normalized, $correlation_id);
        if (is_wp_error($valid)) {
            $this->redirect_to_login_error($valid->get_error_code());
        }

        $provider_config['last_id_token_acr'] = isset($claims['acr']) ? (string) $claims['acr'] : '';
        $pluginOptions = get_option('wp-spid-cie-oidc_options', []);
        $user = $authService->resolveOrProvisionUser($normalized, $provider_config, $pluginOptions, $correlation_id);

        if (is_wp_error($user)) {
            $logger->error('OIDC WP user resolve failed', [
                'correlation_id' => $correlation_id,
                'error_code' => $user->get_error_code(),
            ]);
            $this->redirect_to_login_error($user->get_error_code());
        }

        wp_set_current_user($user->ID);
        wp_set_auth_cookie($user->ID, true);
        do_action('wp_login', $user->user_login, $user);

        $target = isset($state_context['target_url']) ? $state_context['target_url'] : home_url('/');
        $target = $this->sanitize_internal_redirect($target);

        $logger->info('OIDC login completed', [
            'correlation_id' => $correlation_id,
            'provider' => $provider,
            'user_id' => $user->ID,
        ]);

        wp_safe_redirect($target);
        exit;
    }

    private function resolve_redirect_target(): string {
        $raw = isset($_GET['redirect_to']) ? wp_unslash($_GET['redirect_to']) : '';
        if (empty($raw)) {
            return home_url('/');
        }

        return $this->sanitize_internal_redirect($raw);
    }

    private function sanitize_internal_redirect(string $url): string {
        $default = home_url('/');
        $safe = wp_validate_redirect($url, $default);
        $homeHost = wp_parse_url(home_url(), PHP_URL_HOST);
        $targetHost = wp_parse_url($safe, PHP_URL_HOST);

        if ($targetHost && $homeHost && strtolower($targetHost) !== strtolower($homeHost)) {
            return $default;
        }

        return $safe;
    }

    private function redirect_to_login_error(string $code): void {
        $url = add_query_arg([
            'login' => 'failed',
            'spid_cie_error' => sanitize_key($code),
        ], wp_login_url());

        wp_safe_redirect($url);
        exit;
    }


    private function resolve_login_entry_url(): string {
        if (function_exists('wp_login_url') && did_action('login_init')) {
            return wp_login_url();
        }

        $request_uri = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
        if ($request_uri !== '') {
            $candidate = home_url($request_uri);
            return $this->sanitize_internal_redirect($candidate);
        }

        return home_url('/');
    }

    private static $buttons_printed = false;

    public function print_login_buttons_on_login_page($arg = null) {
        if (self::$buttons_printed) return $arg;
        if (is_string($arg) && !empty($arg)) echo $arg;

        if (!empty($_GET['spid_cie_error'])) {
            $code = sanitize_key(wp_unslash($_GET['spid_cie_error']));
            echo '<p class="message" style="border-left-color:#d63638;">' . esc_html__('Autenticazione SPID/CIE non completata. Riprova.', 'wp-spid-cie-oidc') . ' (' . esc_html($code) . ')</p>';
        }

        echo $this->render_login_buttons();
        self::$buttons_printed = true;
        return null;
    }

    public function render_login_buttons() {
        $options = get_option( $this->plugin_name . '_options' ); 
        
        $spid_enabled = isset($options['spid_enabled']) && $options['spid_enabled'] === '1';
        $cie_enabled = isset($options['cie_enabled']) && $options['cie_enabled'] === '1';
        $provider_mode = $options['provider_mode'] ?? 'both';
        if ($provider_mode === 'spid_only') {
            $cie_enabled = false;
        } elseif ($provider_mode === 'cie_only') {
            $spid_enabled = false;
        }
        
        // Gestione Disclaimer
        $disclaimer_enabled = isset($options['disclaimer_enabled']) && $options['disclaimer_enabled'] === '1';
        $disclaimer_text = !empty($options['disclaimer_text']) ? $options['disclaimer_text'] : '';

        if ( ! $spid_enabled && ! $cie_enabled ) return '';

        $base_url = $this->resolve_login_entry_url();
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
