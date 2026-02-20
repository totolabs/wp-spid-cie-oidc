<?php

/**
 * Factory per la creazione e configurazione dell'istanza client OIDC.
 * Wrapper per la libreria SPID_CIE_OIDC_PHP.
 *
 * @package    WP_SPID_CIE_OIDC
 * @subpackage WP_SPID_CIE_OIDC/includes
 */

if ( file_exists( plugin_dir_path( dirname( __FILE__ ) ) . 'vendor/autoload.php' ) ) {
    require_once plugin_dir_path( dirname( __FILE__ ) ) . 'vendor/autoload.php';
}

use SPID_CIE_OIDC_PHP\Core\Util;

class WP_SPID_CIE_OIDC_Factory {

    public static function get_client() {
        $options = get_option('wp-spid-cie-oidc_options');
        
        $issuer_override = isset($options['issuer_override']) ? trim((string) $options['issuer_override']) : '';
        $base_source = $issuer_override !== '' ? $issuer_override : home_url();
        $base_url = untrailingslashit(set_url_scheme((string) $base_source, 'https'));

        $entity_id_override = isset($options['entity_id']) ? trim((string) $options['entity_id']) : '';
        $entity_id_source = $entity_id_override !== '' ? $entity_id_override : ($issuer_override !== '' ? $issuer_override : home_url('/'));
        $entity_id = set_url_scheme((string) $entity_id_source, 'https');

        $config = [
            'organization_name' => $options['organization_name'] ?? get_bloginfo('name'),
            'ipa_code'          => $options['ipa_code'] ?? '',
            'fiscal_number'     => $options['fiscal_number'] ?? '',
            'contacts_email'    => $options['contacts_email'] ?? get_option('admin_email'),
            'base_url'          => $base_url,
            'entity_id'         => $entity_id,
            'test_env'          => isset($options['spid_test_env']) && $options['spid_test_env'] === '1',
			'cie_trust_anchor_preprod' => $options['cie_trust_anchor_preprod'] ?? '',
			'cie_trust_anchor_prod'    => $options['cie_trust_anchor_prod'] ?? '',
			'spid_trust_anchor'        => $options['spid_trust_anchor'] ?? '',
			'cie_trust_mark_preprod' => $options['cie_trust_mark_preprod'] ?? '',
			'cie_trust_mark_prod'    => $options['cie_trust_mark_prod'] ?? '',
			'spid_enabled' => !empty($options['spid_enabled']) && $options['spid_enabled'] === '1',
			'cie_enabled'  => !empty($options['cie_enabled']) && $options['cie_enabled'] === '1'
        ];

        $upload_dir = wp_upload_dir();
        $keys_dir = trailingslashit($upload_dir['basedir']) . 'spid-cie-oidc-keys';
        
        if (!file_exists($keys_dir)) {
            wp_mkdir_p($keys_dir);
            file_put_contents($keys_dir . '/.htaccess', 'deny from all');
        }

        $config['key_dir'] = $keys_dir;

        return new WP_SPID_CIE_OIDC_Wrapper($config);
    }

    /**
     * Runtime services for OIDC login callback flow (Milestone 1).
     */
    public static function get_runtime_services() {
        $logger = new WP_SPID_CIE_OIDC_Logger('OIDC');
        $pkce = new WP_SPID_CIE_OIDC_PkceService();
        $store = new WP_SPID_CIE_OIDC_TransientStateNonceStore();
        $validator = new WP_SPID_CIE_OIDC_TokenValidator($logger);
        $client = new WP_SPID_CIE_OIDC_OidcClient($pkce, $store, $validator, $logger);
        $userMapper = new WP_SPID_CIE_OIDC_WpUserMapper($logger);
        $authService = new WP_SPID_CIE_OIDC_WpAuthService($logger);

        return [
            'logger' => $logger,
            'oidc_client' => $client,
            'user_mapper' => $userMapper,
            'auth_service' => $authService,
        ];
    }

    /**
     * Provider registry with SPID/CIE profiles + discovery resolver.
     */
    public static function get_provider_registry() {
        $runtime = self::get_runtime_services();
        $logger = $runtime['logger'];
        $wrapper = self::get_client();
        $resolver = new WP_SPID_CIE_OIDC_DiscoveryResolver($logger);
        return new WP_SPID_CIE_OIDC_ProviderRegistry($resolver, $wrapper);
    }
}

class WP_SPID_CIE_OIDC_Wrapper {
    
    private $config;

    private $spid_providers = [
        'validator' => [
            'name' => 'SPID Validator (Test)',
            'issuer' => 'https://validator.spid.gov.it',
            'auth_endpoint' => 'https://validator.spid.gov.it/oidc/op/authorization',
            'logo' => 'spid-idp-spiditalia.svg'
        ],
        'poste' => [
            'name' => 'Poste ID',
            'issuer' => 'https://posteid.poste.it',
            'auth_endpoint' => 'https://posteid.poste.it/j/oidc/authorization', 
            'logo' => 'spid-idp-posteid.svg'
        ],
        'aruba' => [
            'name' => 'Aruba ID',
            'issuer' => 'https://loginspid.aruba.it',
            'auth_endpoint' => 'https://loginspid.aruba.it/authorization',
            'logo' => 'spid-idp-arubaid.svg'
        ],
        'sielte' => [
            'name' => 'Sielte ID',
            'issuer' => 'https://identity.sieltecloud.it',
            'auth_endpoint' => 'https://identity.sieltecloud.it/simplesaml/module.php/oidc/authorize',
            'logo' => 'spid-idp-sielteid.svg'
        ],
        'namirial' => [
            'name' => 'Namirial ID',
            'issuer' => 'https://idp.namirialtsp.com', 
            'auth_endpoint' => 'https://idp.namirialtsp.com/idp/profile/oidc/authorize', 
            'logo' => 'spid-idp-namirialid.svg'
        ],
    ];

    public function __construct($config) {
        $this->config = $config;
    }

    public function getSpidProviders() {
        $providers = $this->spid_providers;
        if (empty($this->config['test_env'])) {
            unset($providers['validator']);
        }
        return $providers;
    }

	public function generateKeys() {
		$keyDir = $this->config['key_dir'];
		$logFile = $keyDir . '/debug.txt';
		$log = function(string $msg) use ($logFile) {
			@file_put_contents($logFile, '[' . gmdate('c') . '] ' . $msg . PHP_EOL, FILE_APPEND);
		};

		// Assicura cartella
		if (!is_dir($keyDir)) {
			wp_mkdir_p($keyDir);
		}

		// 1) Genera coppia RSA
		$private = \phpseclib3\Crypt\RSA::createKey(2048);
		$public  = $private->getPublicKey();

		// Esporta in PEM (compatibile OpenSSL)
		// PKCS8 è in genere il formato più interoperabile
		$privatePem = $private->toString('PKCS1');
		$publicPem  = $public->toString('PKCS8');

		// 2) Salva private key
		file_put_contents($keyDir . '/private.key', $privatePem);

		// 3) Salva public key RAW (quella che oggi chiamavate public.crt)
		file_put_contents($keyDir . '/public.key', $publicPem);

		// 4) Genera CERTIFICATO X.509 autosigned e salvalo come public.crt (quello richiesto dal portale)
		$cnHost = parse_url($this->config['base_url'] ?? home_url(), PHP_URL_HOST);
		if (!$cnHost) {
			$cnHost = 'localhost';
		}

		$cnHost = parse_url($this->config['base_url'] ?? home_url(), PHP_URL_HOST);
		if (!$cnHost) { $cnHost = 'localhost'; }

		//questa funzione accorcia il nome entro i 60 caratteri
		$org = $this->config['organization_name'] ?? 'Service Provider';
		$org = trim(preg_replace('/\s+/', ' ', $org));
		if (strlen($org) > 60) { $org = substr($org, 0, 60); }

		$cn = trim($cnHost);
		if (strlen($cn) > 60) { $cn = substr($cn, 0, 60); }

		$dn = [
		  "countryName"      => "IT",
		  "organizationName" => $org,
		  "commonName"       => $cn,
		];
		
		$certPem = null;

		$log('generateKeys: OpenSSL loaded: ' . (extension_loaded('openssl') ? 'YES' : 'NO'));

		$opensslPriv = openssl_pkey_get_private($privatePem);
		if ($opensslPriv === false) {
			$log('OpenSSL: pkey_get_private FAILED');
			while ($msg = openssl_error_string()) {
				$log('OpenSSL error (pkey_get_private): ' . $msg);
			}
		} else {
			$log('OpenSSL: pkey_get_private OK');

			$csr = openssl_csr_new($dn, $opensslPriv, ['digest_alg' => 'sha256']);
			if ($csr === false) {
				$log('OpenSSL: csr_new FAILED');
				while ($msg = openssl_error_string()) {
					$log('OpenSSL error (csr_new): ' . $msg);
				}
			} else {
				$log('OpenSSL: csr_new OK');

				$x509 = openssl_csr_sign($csr, null, $opensslPriv, 365, ['digest_alg' => 'sha256']);
				if ($x509 === false) {
					$log('OpenSSL: csr_sign FAILED');
					while ($msg = openssl_error_string()) {
						$log('OpenSSL error (csr_sign): ' . $msg);
					}
				} else {
					$log('OpenSSL: csr_sign OK');

					$ok = openssl_x509_export($x509, $certPem);
					$log('OpenSSL: x509_export ' . ($ok ? 'OK' : 'FAILED'));
				}
			}
		}

		if (empty($certPem)) {
			$log('generateKeys: certPem EMPTY -> returning false');
			return false;
		}

		file_put_contents($keyDir . '/public.crt', $certPem);
		$log('generateKeys: wrote public.crt (CERTIFICATE)');
		return true;
	}

    public function getJwks() {
        $jwk_item = $this->buildJwkItem();
        $jwks = ['keys' => [$jwk_item]];
        return json_encode($jwks);
    }

    public function getEntityStatement() {
        $now = time();
        $exp = $now + 21600; // 6 ore 
        $sub = trim((string) ($this->config['entity_id'] ?? $this->config['base_url'] ?? ''));
        if ($sub === '') {
            throw new Exception('Issuer base_url non configurato');
        }

        $jwk_item = $this->buildJwkItem();
        $jwks_structure = ['keys' => [$jwk_item]];

        $endpoint_base = untrailingslashit((string) ($this->config['base_url'] ?? $sub));
        $fed_api = $endpoint_base . '/.well-known/openid-federation';
        $resolve = $endpoint_base . '/resolve';
        $fetch   = $endpoint_base . '/fetch';
        $list    = $endpoint_base . '/list';
        $status  = $endpoint_base . '/trust_mark_status';

        $org_id_val = $this->config['ipa_code'];
        if (!empty($this->config['fiscal_number'])) {
             $org_id_val = $this->config['fiscal_number'];
        }
        $org_identifier = "PA:IT-" . $org_id_val;
		
		$authority_hints = [];

		// Includi TA CIE solo se CIE è abilitato
		if (!empty($this->config['cie_enabled'])) {
			if (!empty($this->config['cie_trust_anchor_preprod'])) {
				$authority_hints[] = untrailingslashit($this->config['cie_trust_anchor_preprod']);
			}
			if (!empty($this->config['cie_trust_anchor_prod'])) {
				$authority_hints[] = untrailingslashit($this->config['cie_trust_anchor_prod']);
			}
		}

		// Includi TA SPID solo se SPID è abilitato
		if (!empty($this->config['spid_enabled']) && !empty($this->config['spid_trust_anchor'])) {
			$authority_hints[] = untrailingslashit($this->config['spid_trust_anchor']);
		}

		// Rimuovi duplicati e reindicizza
		$authority_hints = array_values(array_unique($authority_hints));
		
		$trust_marks = [];

		$tm_pre = trim($this->config['cie_trust_mark_preprod'] ?? '');
		$tm_prod = trim($this->config['cie_trust_mark_prod'] ?? '');

		foreach ([$tm_pre, $tm_prod] as $tm) {
			if (!$tm) continue;

			$id = $this->extract_trust_mark_id($tm);
			if ($id) {
				$trust_marks[] = [
					'id' => $id,
					'trust_mark' => $tm,
				];
			}
		}	
        $payload = [
            "iss" => $sub,
            "sub" => $sub,
            "iat" => $now,
            "exp" => $exp,
            "jwks" => $jwks_structure,
            "authority_hints" => $authority_hints, 
            "metadata" => [
                "openid_relying_party" => [
                    "application_type" => "web",
                    "client_id" => $sub,
                    "client_registration_types" => ["automatic"],
                    "jwks" => $jwks_structure,
                    "client_name" => $this->config['organization_name'],
                    "contacts" => [$this->config['contacts_email']],
                    "grant_types" => ["authorization_code", "refresh_token"],
                    "redirect_uris" => [
                        add_query_arg(['oidc_action' => 'callback', 'provider' => 'spid'], $endpoint_base),
                        add_query_arg(['oidc_action' => 'callback', 'provider' => 'cie'], $endpoint_base)
                    ],
                    "response_types" => ["code"],
                    "subject_type" => "public"
                ],
                "federation_entity" => [
                    "organization_name" => $this->config['organization_name'],
                    "homepage_uri" => $endpoint_base,
                    "policy_uri" => $endpoint_base . '/privacy-policy', 
                    "logo_uri" => $endpoint_base . '/wp-admin/images/w-logo-blue.png',
                    "contacts" => [$this->config['contacts_email']],
                    "federation_api_endpoint" => $fed_api,
                    "federation_resolve_endpoint" => $resolve,
                    "federation_fetch_endpoint" => $fetch,
                    "federation_list_endpoint" => $list,
                    "federation_trust_mark_status_endpoint" => $status,
                    "ipa_code" => $this->config['ipa_code'],
                    "organization_identifier" => $org_identifier
                ]
            ]
        ];
		
		// --- Trust Marks (se presenti) ---
		$trust_marks = [];

		$tm_pre  = trim($this->config['cie_trust_mark_preprod'] ?? '');
		$tm_prod = trim($this->config['cie_trust_mark_prod'] ?? '');

		foreach ([$tm_pre, $tm_prod] as $tm) {
			if (!$tm) continue;

			$id = $this->extract_trust_mark_id($tm);
			if ($id) {
				$trust_marks[] = [
					'id' => $id,
					'trust_mark' => $tm,
				];
			}
		}

		if (!empty($trust_marks)) {
			$payload['trust_marks'] = $trust_marks;
		}
		
        return $this->signJwt($payload);
    }

    /**
     * Endpoint /resolve OpenID Federation.
     * Ritorna un resolve-response+jwt firmato con la stessa chiave federativa.
     */
    public function getResolveResponse($sub = '', $trust_anchor = '') {
        $base_sub = trim((string) ($this->config['entity_id'] ?? $this->config['base_url'] ?? ''));
        if ($base_sub === '') {
            throw new Exception('Issuer base_url non configurato');
        }

        $resolved_sub = trim((string) $sub);
        if ($resolved_sub === '') {
            $resolved_sub = $base_sub;
        }

        $now = time();
        $exp = $now + 21600;
        $jwk_item = $this->buildJwkItem();
        $jwks_structure = ['keys' => [$jwk_item]];

        $endpoint_base = untrailingslashit((string) ($this->config['base_url'] ?? $base_sub));
        $fed_api = $endpoint_base . '/.well-known/openid-federation';
        $resolve = $endpoint_base . '/resolve';
        $fetch   = $endpoint_base . '/fetch';
        $list    = $endpoint_base . '/list';
        $status  = $endpoint_base . '/trust_mark_status';

        $org_id_val = $this->config['ipa_code'];
        if (!empty($this->config['fiscal_number'])) {
            $org_id_val = $this->config['fiscal_number'];
        }
        $org_identifier = 'PA:IT-' . $org_id_val;

        $payload = [
            'iss' => $base_sub,
            'sub' => $resolved_sub,
            'iat' => $now,
            'exp' => $exp,
            'jwks' => $jwks_structure,
            'metadata' => [
                'openid_relying_party' => [
                    'application_type' => 'web',
                    'client_id' => $base_sub,
                    'client_registration_types' => ['automatic'],
                    'jwks' => $jwks_structure,
                    'client_name' => $this->config['organization_name'],
                    'contacts' => [$this->config['contacts_email']],
                    'grant_types' => ['authorization_code', 'refresh_token'],
                    'redirect_uris' => [
                        add_query_arg(['oidc_action' => 'callback', 'provider' => 'spid'], $endpoint_base),
                        add_query_arg(['oidc_action' => 'callback', 'provider' => 'cie'], $endpoint_base)
                    ],
                    'response_types' => ['code'],
                    'subject_type' => 'public'
                ],
                'federation_entity' => [
                    'organization_name' => $this->config['organization_name'],
                    'homepage_uri' => $endpoint_base,
                    'policy_uri' => $endpoint_base . '/privacy-policy',
                    'logo_uri' => $endpoint_base . '/wp-admin/images/w-logo-blue.png',
                    'contacts' => [$this->config['contacts_email']],
                    'federation_api_endpoint' => $fed_api,
                    'federation_resolve_endpoint' => $resolve,
                    'federation_fetch_endpoint' => $fetch,
                    'federation_list_endpoint' => $list,
                    'federation_trust_mark_status_endpoint' => $status,
                    'ipa_code' => $this->config['ipa_code'],
                    'organization_identifier' => $org_identifier
                ]
            ]
        ];

        $ta = trim((string) $trust_anchor);
        if ($ta !== '') {
            $payload['trust_anchor'] = untrailingslashit($ta);
        }

        return $this->signGenericJwt($payload, 'resolve-response+jwt');
    }


    public function getEntityId() {
        $entity_id = trim((string) ($this->config['entity_id'] ?? ''));
        if ($entity_id !== '') {
            return $entity_id;
        }
        return trim((string) ($this->config['base_url'] ?? ''));
    }

    /**
     * Genera l'URL di autorizzazione.
     */
    public function getAuthorizationUrl($trust_anchor, $idp_id = null) {
        
        $code_verifier = $this->generateCodeVerifier();
        $code_challenge = $this->generateCodeChallenge($code_verifier);
        $state = bin2hex(random_bytes(16));
        $nonce = bin2hex(random_bytes(16));

        if (!session_id()) { session_start(); }
        $_SESSION['oidc_verifier'] = $code_verifier;
        $_SESSION['oidc_state'] = $state;
        $_SESSION['oidc_nonce'] = $nonce;

        $auth_endpoint = '';
        $issuer = ''; // Per il campo 'aud' del Request Object
        $scope = 'openid profile email';
        $provider_param = isset($_GET['provider']) ? $_GET['provider'] : '';
        $acr_values = 'https://www.spid.gov.it/SpidL2';

        // Selezione Endpoint
        if (strpos($trust_anchor, 'cie') !== false || $provider_param === 'cie') {
             // CIE
             $auth_endpoint = 'https://id.cie.gov.it/oidc/authorization';
             $issuer = 'https://id.cie.gov.it/oidc/op/'; // Issuer CIE standard
             $scope = 'openid profile email';
             $provider_param = 'cie';
             $acr_values = 'https://www.spid.gov.it/SpidL2'; 
        } else {
             // SPID
             $provider_param = 'spid';
             $scope = 'openid profile'; 
             
             $selected_idp = 'validator'; // Default
             if ($idp_id && isset($this->spid_providers[$idp_id])) {
                 $selected_idp = $idp_id;
             }
             
             $auth_endpoint = $this->spid_providers[$selected_idp]['auth_endpoint'];
             $issuer = $this->spid_providers[$selected_idp]['issuer'];
        }

        // Costruzione Request Object (JWT)
        $ro_payload = [
            'iss' => $this->config['base_url'],
            'sub' => $this->config['base_url'],
            'aud' => [$issuer], // Audience fondamentale
            'iat' => time(),
            'exp' => time() + 300,
            'client_id' => $this->config['base_url'],
            'response_type' => 'code',
            'scope' => $scope,
            'redirect_uri' => add_query_arg(['oidc_action' => 'callback', 'provider' => $provider_param], $this->config['base_url']),
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => $code_challenge,
            'code_challenge_method' => 'S256',
            'acr_values' => $acr_values,
            'prompt' => 'login'
        ];

        // Firma con header 'typ' => 'oauth-authz-req+jwt'
        $request_token = $this->signRequestObject($ro_payload);

        $params = [
            'client_id' => $this->config['base_url'],
            'response_type' => 'code',
            'scope' => $scope,
            'request' => $request_token // Parametro obbligatorio
        ];

        return $auth_endpoint . '?' . http_build_query($params);
    }

    public function getUserInfo($get_params) {
        return []; 
    }

    // --- Helpers Privati ---

    private function buildJwkItem() {
        $crt_content = file_get_contents($this->config['key_dir'] . '/public.crt');
        if (!$crt_content) throw new Exception("Chiave pubblica non trovata.");
        $key = \phpseclib3\Crypt\PublicKeyLoader::load($crt_content);
        $jwk_native = json_decode($key->toString('JWK'), true);
        if (isset($jwk_native['keys'][0])) $jwk_native = $jwk_native['keys'][0];
        return [
            'kty' => 'RSA', 'n' => $jwk_native['n'], 'e' => $jwk_native['e'], 
            'alg' => 'RS256', 'use' => 'sig', 'kid' => $this->getKid()
        ];
    }

    // Firma Metadata (entity-statement+jwt)
    private function signJwt($payload) {
        return $this->signGenericJwt($payload, 'entity-statement+jwt');
    }

    // Firma Request Object (oauth-authz-req+jwt)
    private function signRequestObject($payload) {
        return $this->signGenericJwt($payload, 'oauth-authz-req+jwt');
    }

    private function signGenericJwt($payload, $typ) {
        $privateKeyContent = file_get_contents($this->config['key_dir'] . '/private.key');
        $rsa = \phpseclib3\Crypt\RSA::load($privateKeyContent);
        $rsa = $rsa->withHash('sha256')->withPadding(\phpseclib3\Crypt\RSA::SIGNATURE_PKCS1);

        $header = ['typ' => $typ, 'alg' => 'RS256', 'kid' => $this->getKid()];
        
        $jsonHeader = json_encode($header, JSON_UNESCAPED_SLASHES);
        $jsonPayload = json_encode($payload, JSON_UNESCAPED_SLASHES);

        $base64UrlHeader = $this->base64url_encode($jsonHeader);
        $base64UrlPayload = $this->base64url_encode($jsonPayload);
        
        $signature = $rsa->sign($base64UrlHeader . "." . $base64UrlPayload);
        
        return $base64UrlHeader . "." . $base64UrlPayload . "." . $this->base64url_encode($signature);
    }

    private function getKid() {
        $crt = file_get_contents($this->config['key_dir'] . '/public.crt');
        $key = \phpseclib3\Crypt\PublicKeyLoader::load($crt);
        $jwk_native = json_decode($key->toString('JWK'), true);
        if (isset($jwk_native['keys'][0])) $jwk_native = $jwk_native['keys'][0];
        $jwk = ['e' => $jwk_native['e'], 'kty' => 'RSA', 'n' => $jwk_native['n']];
        ksort($jwk);
        $json = json_encode($jwk, JSON_UNESCAPED_SLASHES);
        return $this->base64url_encode(hash('sha256', $json, true));
    }

    private function base64url_encode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function generateCodeVerifier() {
        return $this->base64url_encode(random_bytes(64));
    }
    private function generateCodeChallenge($verifier) {
        return $this->base64url_encode(hash('sha256', $verifier, true));
    }
	private function extract_trust_mark_id(string $jwt): ?string {
    $parts = explode('.', $jwt);
    if (count($parts) < 2) return null;

    $payload_b64 = strtr($parts[1], '-_', '+/');
    $payload_b64 .= str_repeat('=', (4 - strlen($payload_b64) % 4) % 4);

    $json = base64_decode($payload_b64);
    if (!$json) return null;

    $data = json_decode($json, true);
    if (!is_array($data)) return null;

    return isset($data['id']) && is_string($data['id']) ? $data['id'] : null;
	}
}
