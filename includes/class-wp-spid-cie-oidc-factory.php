<?php

/**
 * Factory per la creazione e configurazione dell'istanza client OIDC.
 * Wrapper per la libreria SPID_CIE_OIDC_PHP.
 *
 * @since      1.0.0
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
        
        // Rimuoviamo slash finale dalla base URL per conformità CIE
        $base_url = untrailingslashit(home_url());

        $config = [
            'organization_name' => $options['organization_name'] ?? get_bloginfo('name'),
            'ipa_code'          => $options['ipa_code'] ?? '',
            'fiscal_number'     => $options['fiscal_number'] ?? '',
            'contacts_email'    => $options['contacts_email'] ?? get_option('admin_email'),
            'base_url'          => $base_url,
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
}

/**
 * Wrapper per gestire la logica OIDC (Login, Metadata, PKCE).
 */
class WP_SPID_CIE_OIDC_Wrapper {
    
    private $config;

    public function __construct($config) {
        $this->config = $config;
    }

    public function generateKeys() {
        $private = \phpseclib3\Crypt\RSA::createKey(2048);
        $public = $private->getPublicKey();
        file_put_contents($this->config['key_dir'] . '/private.key', (string)$private);
        file_put_contents($this->config['key_dir'] . '/public.crt', (string)$public);
        return true;
    }

    public function getJwks() {
        $jwk_item = $this->buildJwkItem();
        $jwks = ['keys' => [$jwk_item]];
        return json_encode($jwks);
    }

    public function getEntityStatement() {
        $now = time();
        $exp = $now + (86400 * 365); 
        $sub = $this->config['base_url'];
        
        $jwk_item = $this->buildJwkItem();
        $jwks_structure = ['keys' => [$jwk_item]];

        // Endpoint (Aggiungiamo lo slash qui perché rimosso dalla base_url)
        $fed_api = $this->config['base_url'] . '/.well-known/openid-federation';
        $resolve = $this->config['base_url'] . '/resolve';
        $fetch   = $this->config['base_url'] . '/fetch';
        $list    = $this->config['base_url'] . '/list';
        $status  = $this->config['base_url'] . '/trust_mark_status';

        // Identifier: Preferenza al CF, fallback su IPA
        $org_id_val = $this->config['ipa_code'];
        if (!empty($this->config['fiscal_number'])) {
             $org_id_val = $this->config['fiscal_number'];
        }
        $org_identifier = "PA:IT-" . $org_id_val;

        $payload = [
            "iss" => $sub,
            "sub" => $sub,
            "iat" => $now,
            "exp" => $exp,
            "jwks" => $jwks_structure,
            "authority_hints" => [],
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
                        add_query_arg(['oidc_action' => 'callback', 'provider' => 'spid'], $this->config['base_url']),
                        add_query_arg(['oidc_action' => 'callback', 'provider' => 'cie'], $this->config['base_url'])
                    ],
                    "response_types" => ["code"],
                    "subject_type" => "pairwise"
                ],
                "federation_entity" => [
                    "organization_name" => $this->config['organization_name'],
                    "homepage_uri" => $this->config['base_url'],
                    "policy_uri" => $this->config['base_url'] . '/privacy-policy', 
                    "logo_uri" => $this->config['base_url'] . '/wp-admin/images/w-logo-blue.png',
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

        return $this->signJwt($payload);
    }

	 /**
     * Genera l'URL di autorizzazione per l'IdP.
     * IMPLEMENTAZIONE AGGIORNATA
     */
    public function getAuthorizationUrl($trust_anchor) {
		
		// 1. Generazione PKCE (Code Verifier & Challenge)
        $code_verifier = $this->generateCodeVerifier();
        $code_challenge = $this->generateCodeChallenge($code_verifier);
        $state = bin2hex(random_bytes(16));
        $nonce = bin2hex(random_bytes(16));
		
		// 2. Salviamo in sessione (WP non usa $_SESSION nativo di solito, ma qui serve)
        if (!session_id()) { session_start(); }
        $_SESSION['oidc_verifier'] = $code_verifier;
        $_SESSION['oidc_state'] = $state;
        $_SESSION['oidc_nonce'] = $nonce;

        // 3. Endpoint di Autorizzazione dell'IdP: Default CIE Production
        $auth_endpoint = 'https://id.cie.gov.it/oidc/authorization'; 
        $scope = 'openid profile email';
        $provider_param = isset($_GET['provider']) ? $_GET['provider'] : '';

        if (strpos($trust_anchor, 'spid') !== false || $provider_param === 'spid') {
             // SPID Demo Environment
             $auth_endpoint = 'https://demo.spid.gov.it/auth'; 
             $scope = 'openid profile';
        }
		
		// 4. Costruzione Parametri
        $params = [
            'client_id' => $this->config['base_url'],
            'response_type' => 'code',
            'scope' => $scope,
            'redirect_uri' => add_query_arg(['oidc_action' => 'callback', 'provider' => ($provider_param ?: 'cie')], $this->config['base_url']),
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => $code_challenge,
            'code_challenge_method' => 'S256',
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

        if (isset($jwk_native['keys'][0])) {
            $jwk_native = $jwk_native['keys'][0];
        }

        $jwk_item = [
            'kty' => 'RSA',
            'n'   => $jwk_native['n'], 
            'e'   => $jwk_native['e'], 
            'alg' => 'RS256',
            'use' => 'sig',
            'kid' => $this->getKid()
        ];

        return $jwk_item;
    }

    private function signJwt($payload) {
        $privateKeyContent = file_get_contents($this->config['key_dir'] . '/private.key');
        $rsa = \phpseclib3\Crypt\RSA::load($privateKeyContent);
        
        $rsa = $rsa->withHash('sha256')->withPadding(\phpseclib3\Crypt\RSA::SIGNATURE_PKCS1);

        $header = ['typ' => 'entity-statement+jwt', 'alg' => 'RS256', 'kid' => $this->getKid()];
        
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
        if (isset($jwk_native['keys'][0])) {
            $jwk_native = $jwk_native['keys'][0];
        }
        
        $jwk = [
            'e'   => $jwk_native['e'],
            'kty' => 'RSA',
            'n'   => $jwk_native['n'],
        ];
        
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
	
	// PKCE Helpers
    private function generateCodeChallenge($verifier) {
        return $this->base64url_encode(hash('sha256', $verifier, true));
    }
}