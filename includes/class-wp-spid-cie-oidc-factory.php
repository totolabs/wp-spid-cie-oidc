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
        $options = get_option('wp_spid_cie_oidc_options');
        
        $config = [
            'organization_name' => $options['organization_name'] ?? get_bloginfo('name'),
            'ipa_code'          => $options['ipa_code'] ?? '',
            'contacts_email'    => $options['contacts_email'] ?? get_option('admin_email'),
            'base_url'          => home_url('/'),
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
        $crt_content = file_get_contents($this->config['key_dir'] . '/public.crt');
        if (!$crt_content) throw new Exception("Chiave pubblica non trovata.");

        $key = \phpseclib3\Crypt\PublicKeyLoader::load($crt_content);
        $jwk_array = json_decode($key->toString('JWK'), true);
        
        $jwk_array['use'] = 'sig';
        $jwk_array['alg'] = 'RS256';
        $jwk_array['kid'] = $this->getKid();

        return json_encode(['keys' => [$jwk_array]]);
    }

    public function getEntityStatement() {
        $now = time();
        $exp = $now + (86400 * 365);
        $sub = $this->config['base_url'];
        $jwks_array = json_decode($this->getJwks(), true);

        $payload = [
            "iss" => $sub,
            "sub" => $sub,
            "iat" => $now,
            "exp" => $exp,
            "jwks" => $jwks_array,
            "metadata" => [
                "openid_relying_party" => [
                    "application_type" => "web",
                    "client_id" => $sub,
                    "client_registration_types" => ["automatic"],
                    "jwks" => $jwks_array,
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
                    "policy_uri" => $this->config['base_url'] . 'privacy-policy', 
                    "logo_uri" => $this->config['base_url'] . 'wp-admin/images/w-logo-blue.png',
                    "contacts" => [$this->config['contacts_email']],
                    "federation_resolve_endpoint" => $this->config['base_url'] . 'resolve',
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

        // 3. Endpoint di Autorizzazione dell'IdP
        // NOTA: In produzione, questo URL deve essere scoperto dinamicamente tramite "Entity Configuration".
        // Per ora, forziamo gli endpoint noti per permetterti di testare il redirect.
        
        $auth_endpoint = '';
        $scope = 'openid profile';
        $provider_param = isset($_GET['provider']) ? $_GET['provider'] : '';

        if (strpos($trust_anchor, 'cie.gov.it') !== false || $provider_param === 'cie') {
            // CIE Produzione (Questo server risponde sempre)
            // Nota: Ci darà errore "Client sconosciuto" perché non siamo registrati,
            // ma almeno vedremo la pagina del Ministero!
            $auth_endpoint = 'https://id.cie.gov.it/oidc/authorization'; 
            $scope = 'openid profile email';
        } else {
            // SPID Demo (Ambiente ufficiale di test AgID)
            // Questo è ottimo per i test preliminari
            $auth_endpoint = 'https://demo.spid.gov.it/auth'; 
        }

        // 4. Costruzione Parametri
        $params = [
            'client_id' => $this->config['base_url'],
            'response_type' => 'code',
            'scope' => $scope,
            'redirect_uri' => add_query_arg(['oidc_action' => 'callback', 'provider' => ($provider_param ?: 'spid')], $this->config['base_url']),
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => $code_challenge,
            'code_challenge_method' => 'S256',
            // 'prompt' => 'login'
        ];

        return $auth_endpoint . '?' . http_build_query($params);
    }

    public function getUserInfo($get_params) {
        return []; // Implementeremo al prossimo step
    }

    // --- Helpers Privati ---

    private function signJwt($payload) {
        $privateKeyContent = file_get_contents($this->config['key_dir'] . '/private.key');
        $rsa = \phpseclib3\Crypt\RSA::load($privateKeyContent);
        $header = ['typ' => 'entity-statement+jwt', 'alg' => 'RS256', 'kid' => $this->getKid()];
        
        $base64UrlHeader = $this->base64url_encode(json_encode($header));
        $base64UrlPayload = $this->base64url_encode(json_encode($payload));
        $signature = $rsa->sign($base64UrlHeader . "." . $base64UrlPayload);
        
        return $base64UrlHeader . "." . $base64UrlPayload . "." . $this->base64url_encode($signature);
    }

    private function getKid() {
        $crt = file_get_contents($this->config['key_dir'] . '/public.crt');
        return substr(hash('sha256', $crt), 0, 16);
    }

    private function base64url_encode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    // PKCE Helpers
    private function generateCodeVerifier() {
        return $this->base64url_encode(random_bytes(64));
    }
    private function generateCodeChallenge($verifier) {
        return $this->base64url_encode(hash('sha256', $verifier, true));
    }
}