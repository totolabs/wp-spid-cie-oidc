<?php

/**
 * Factory per la creazione e configurazione dell'istanza client OIDC.
 * Wrapper per la libreria SPID_CIE_OIDC_PHP.
 *
 * @package    WP_SPID_CIE_OIDC
 * @subpackage WP_SPID_CIE_OIDC/includes
 */

// Autoloading
if ( file_exists( plugin_dir_path( dirname( __FILE__ ) ) . 'vendor/autoload.php' ) ) {
    require_once plugin_dir_path( dirname( __FILE__ ) ) . 'vendor/autoload.php';
}

use SPID_CIE_OIDC_PHP\Core\Util;
use SPID_CIE_OIDC_PHP\Core\JWT;

class WP_SPID_CIE_OIDC_Factory {

    /**
     * Restituisce un'istanza del Wrapper configurato.
     * @return WP_SPID_CIE_OIDC_Wrapper
     */
    public static function get_client() {
        // Recuperiamo le opzioni
        $options = get_option('wp_spid_cie_oidc_options');
        
        // Configurazione base
        $config = [
            'organization_name' => $options['organization_name'] ?? get_bloginfo('name'),
            'ipa_code'          => $options['ipa_code'] ?? '',
            'contacts_email'    => $options['contacts_email'] ?? get_option('admin_email'),
            'base_url'          => home_url('/'),
        ];

        // Directory chiavi
        $upload_dir = wp_upload_dir();
        $keys_dir = trailingslashit($upload_dir['basedir']) . 'spid-cie-oidc-keys';
        
        // Assicuriamoci che la directory esista
        if (!file_exists($keys_dir)) {
            wp_mkdir_p($keys_dir);
            file_put_contents($keys_dir . '/.htaccess', 'deny from all');
        }

        $config['key_dir'] = $keys_dir;

        // Restituiamo il nostro Wrapper personalizzato
        return new WP_SPID_CIE_OIDC_Wrapper($config);
    }
}

/**
 * Classe Wrapper che adatta la nuova libreria alle necessità del plugin.
 * Espone i metodi semplici usati da Admin e Public.
 */
class WP_SPID_CIE_OIDC_Wrapper {
    
    private $config;
    private $db_file;

    public function __construct($config) {
        $this->config = $config;
        // Il Database JSON serve alla libreria per salvare lo stato delle Trust Chain
        $this->db_file = $config['key_dir'] . '/oidc_rp_database.json';
        
        // Inizializza il file DB se non esiste
        if (!file_exists($this->db_file)) {
            file_put_contents($this->db_file, json_encode([]));
        }
    }

    /**
     * Genera le chiavi crittografiche (RSA 2048).
     */
    public function generateKeys() {
        // Usiamo phpseclib3
        $private = \phpseclib3\Crypt\RSA::createKey(2048);
        $public = $private->getPublicKey();
        
        file_put_contents($this->config['key_dir'] . '/private.key', (string)$private);
        file_put_contents($this->config['key_dir'] . '/public.crt', (string)$public);
        
        return true;
    }

    /**
     * Restituisce il JWKS (JSON Web Key Set) pubblico.
     */
    public function getJwks() {
        $crt_content = file_get_contents($this->config['key_dir'] . '/public.crt');
        if (!$crt_content) throw new Exception("Chiave pubblica non trovata. Rigenerare le chiavi.");

        // FIX: Usiamo il metodo nativo di phpseclib3 per esportare in JWK
        $key = \phpseclib3\Crypt\PublicKeyLoader::load($crt_content);
        
        // toString('JWK') restituisce già il JSON con 'n' ed 'e' codificati correttamente
        $jwk_array = json_decode($key->toString('JWK'), true);

        // Aggiungiamo i parametri obbligatori per OIDC Federation
        $jwk_array['use'] = 'sig';
        $jwk_array['alg'] = 'RS256';
        $jwk_array['kid'] = $this->getKid(); // Il nostro KID calcolato

        return json_encode(['keys' => [$jwk_array]]);
    }

    /**
     * Restituisce l'Entity Statement (JWT firmato) per la registrazione su AGID/CIE.
     */
    public function getEntityStatement() {
        // Costruzione del payload secondo specifiche OIDC Federation
        $now = time();
        $exp = $now + (86400 * 365); // Valido 1 anno
        
        $sub = $this->config['base_url']; // Subject (noi)
        
        // Recuperiamo il JWKS come array per inserirlo nel payload
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

        // Firma del JWT usando la chiave privata
        return $this->signJwt($payload);
    }

    /**
     * Genera l'URL di autorizzazione per iniziare il login.
     */
    public function getAuthorizationUrl($trust_anchor) {
        return home_url('/?oidc_debug=discovery_not_implemented_yet');
    }

    /**
     * Recupera le info utente dalla callback.
     */
    public function getUserInfo($get_params) {
        return [];
    }

    // --- Helpers Privati ---

    private function signJwt($payload) {
        $privateKeyContent = file_get_contents($this->config['key_dir'] . '/private.key');
        $rsa = \phpseclib3\Crypt\RSA::load($privateKeyContent);

        $header = ['typ' => 'entity-statement+jwt', 'alg' => 'RS256', 'kid' => $this->getKid()];
        
        $base64UrlHeader = $this->base64url_encode(json_encode($header));
        $base64UrlPayload = $this->base64url_encode(json_encode($payload));
        
        $signature = $rsa->sign($base64UrlHeader . "." . $base64UrlPayload);
        $base64UrlSignature = $this->base64url_encode($signature);
        
        return $base64UrlHeader . "." . $base64UrlPayload . "." . $base64UrlSignature;
    }

    private function getKid() {
        // Calcola un KID deterministico dalla chiave pubblica
        $crt = file_get_contents($this->config['key_dir'] . '/public.crt');
        return substr(hash('sha256', $crt), 0, 16);
    }

    private function base64url_encode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}