<?php

class WP_SPID_CIE_OIDC_TokenValidator {
    private $logger;

    public function __construct(WP_SPID_CIE_OIDC_Logger $logger) {
        $this->logger = $logger;
    }

    public function validateIdToken(string $idToken, array $providerConfig, string $expectedNonce, string $correlationId) {
        $parts = explode('.', $idToken);
        if (count($parts) !== 3) {
            return new WP_Error('oidc_invalid_token', __('Token di identità non valido.', 'wp-spid-cie-oidc'));
        }

        $header = $this->decodePart($parts[0]);
        $payload = $this->decodePart($parts[1]);
        $signature = $this->base64urlDecode($parts[2]);

        if (!is_array($header) || !is_array($payload) || $signature === false) {
            return new WP_Error('oidc_invalid_token_format', __('Token di identità malformato.', 'wp-spid-cie-oidc'));
        }

        $allowedAlgs = ['RS256', 'PS256'];
        $alg = $header['alg'] ?? '';
        if (!in_array($alg, $allowedAlgs, true)) {
            return new WP_Error('oidc_alg_not_allowed', __('Algoritmo token non consentito.', 'wp-spid-cie-oidc'));
        }

        $now = time();
        $skew = 60;

        if (($payload['iss'] ?? '') !== ($providerConfig['issuer'] ?? '')) {
            return new WP_Error('oidc_iss_mismatch', __('Issuer non valido.', 'wp-spid-cie-oidc'));
        }

        $aud = $payload['aud'] ?? null;
        $clientId = (string) ($providerConfig['client_id'] ?? '');
        if (is_array($aud)) {
            if (!in_array($clientId, $aud, true)) {
                return new WP_Error('oidc_aud_mismatch', __('Audience non valida.', 'wp-spid-cie-oidc'));
            }
        } elseif ((string) $aud !== $clientId) {
            return new WP_Error('oidc_aud_mismatch', __('Audience non valida.', 'wp-spid-cie-oidc'));
        }

        if (empty($payload['exp']) || ($now - $skew) >= intval($payload['exp'])) {
            return new WP_Error('oidc_expired', __('Token scaduto.', 'wp-spid-cie-oidc'));
        }

        if (empty($payload['iat']) || intval($payload['iat']) > ($now + $skew)) {
            return new WP_Error('oidc_iat_invalid', __('Token con timestamp non valido.', 'wp-spid-cie-oidc'));
        }

        if (($payload['nonce'] ?? '') !== $expectedNonce) {
            return new WP_Error('oidc_nonce_mismatch', __('Nonce non valido.', 'wp-spid-cie-oidc'));
        }

        $acrCheck = $this->validateAcrPolicy($payload, $providerConfig);
        if (is_wp_error($acrCheck)) {
            return $acrCheck;
        }

        $jwksUri = $providerConfig['jwks_uri'] ?? '';
        if (empty($jwksUri)) {
            // TODO Milestone 2/4: supportare trust chain e JWKS caching avanzato.
            return new WP_Error('oidc_no_jwks', __('Impossibile verificare la firma del token.', 'wp-spid-cie-oidc'));
        }

        $jwks = $this->fetchJwks($jwksUri, $correlationId);
        if (is_wp_error($jwks)) {
            return $jwks;
        }

        $kid = $header['kid'] ?? '';
        $jwk = $this->findJwk($jwks, $kid);
        if (!$jwk) {
            return new WP_Error('oidc_kid_not_found', __('Chiave di firma non trovata.', 'wp-spid-cie-oidc'));
        }

        $verify = $this->verifySignature($parts[0] . '.' . $parts[1], $signature, $jwk, $alg);
        if (!$verify) {
            return new WP_Error('oidc_bad_signature', __('Firma token non valida.', 'wp-spid-cie-oidc'));
        }

        return $payload;
    }

    private function fetchJwks(string $jwksUri, string $correlationId) {
        $response = wp_remote_get($jwksUri, ['timeout' => 10, 'redirection' => 2]);
        if (is_wp_error($response)) {
            $this->logger->error('JWKS fetch failed', ['correlation_id' => $correlationId, 'error' => $response->get_error_message()]);
            return new WP_Error('oidc_jwks_fetch_fail', __('Errore rete durante verifica token.', 'wp-spid-cie-oidc'));
        }

        $status = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        if ($status !== 200 || empty($body)) {
            return new WP_Error('oidc_jwks_http_fail', __('JWKS non disponibile.', 'wp-spid-cie-oidc'));
        }

        $json = json_decode($body, true);
        if (!is_array($json) || empty($json['keys']) || !is_array($json['keys'])) {
            return new WP_Error('oidc_jwks_invalid', __('JWKS non valido.', 'wp-spid-cie-oidc'));
        }

        return $json;
    }

    private function findJwk(array $jwks, string $kid): ?array {
        foreach ($jwks['keys'] as $key) {
            if (!is_array($key)) {
                continue;
            }
            if (!empty($kid) && ($key['kid'] ?? '') !== $kid) {
                continue;
            }
            if (($key['kty'] ?? '') === 'RSA' && !empty($key['n']) && !empty($key['e'])) {
                return $key;
            }
        }

        return null;
    }

    private function verifySignature(string $signedData, string $signature, array $jwk, string $alg): bool {
        try {
            $key = \phpseclib3\Crypt\PublicKeyLoader::loadFormat('JWK', wp_json_encode($jwk));
            $rsa = $key->withHash('sha256');

            if ($alg === 'PS256') {
                $rsa = $rsa->withPadding(\phpseclib3\Crypt\RSA::SIGNATURE_PSS);
            } else {
                $rsa = $rsa->withPadding(\phpseclib3\Crypt\RSA::SIGNATURE_PKCS1);
            }

            return $rsa->verify($signedData, $signature);
        } catch (\Throwable $e) {
            $this->logger->error('ID token signature verification error', ['error' => $e->getMessage()]);
            return false;
        }
    }

    private function decodePart(string $part): ?array {
        $decoded = $this->base64urlDecode($part);
        if ($decoded === false) {
            return null;
        }

        $json = json_decode($decoded, true);
        return is_array($json) ? $json : null;
    }

    private function base64urlDecode(string $data) {
        $data = strtr($data, '-_', '+/');
        $padding = strlen($data) % 4;
        if ($padding > 0) {
            $data .= str_repeat('=', 4 - $padding);
        }
        return base64_decode($data);
    }

    private function validateAcrPolicy(array $payload, array $providerConfig) {
        $minAcr = isset($providerConfig['min_acr']) ? trim((string) $providerConfig['min_acr']) : '';
        if ($minAcr === '') {
            return true;
        }

        $allowMissing = !empty($providerConfig['allow_missing_acr']);
        $actualAcr = isset($payload['acr']) ? (string) $payload['acr'] : '';

        if ($actualAcr === '') {
            if ($allowMissing) {
                return true;
            }
            return new WP_Error('oidc_missing_acr', __('Livello di autenticazione non presente.', 'wp-spid-cie-oidc'));
        }

        if (!$this->isAcrAtLeast($actualAcr, $minAcr)) {
            return new WP_Error('oidc_acr_too_low', __('Livello di autenticazione insufficiente.', 'wp-spid-cie-oidc'));
        }

        return true;
    }

    private function isAcrAtLeast(string $actual, string $min): bool {
        $actualLevel = $this->extractLoaLevel($actual);
        $minLevel = $this->extractLoaLevel($min);

        if ($actualLevel !== null && $minLevel !== null) {
            return $actualLevel >= $minLevel;
        }

        return strtolower($actual) === strtolower($min);
    }

    private function extractLoaLevel(string $acr): ?int {
        if (preg_match('/(?:spidl?|loa)\s*([1-3])$/i', $acr, $m)) {
            return intval($m[1]);
        }
        if (preg_match('/\/SpidL([1-3])$/i', $acr, $m)) {
            return intval($m[1]);
        }
        return null;
    }

}
