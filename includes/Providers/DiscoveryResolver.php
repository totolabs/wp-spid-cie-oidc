<?php

class WP_SPID_CIE_OIDC_DiscoveryResolver {
    private $logger;

    public function __construct(WP_SPID_CIE_OIDC_Logger $logger) {
        $this->logger = $logger;
    }

    public function resolveFromIssuer(string $issuer, string $correlationId) {
        $issuer = untrailingslashit(trim($issuer));
        if (empty($issuer)) {
            return new WP_Error('oidc_discovery_empty_issuer', __('Issuer non configurato.', 'wp-spid-cie-oidc'));
        }

        if (!$this->isSafeIssuer($issuer)) {
            return new WP_Error('oidc_discovery_unsafe_issuer', __('Issuer non valido per discovery.', 'wp-spid-cie-oidc'));
        }

        $wellKnown = $issuer . '/.well-known/openid-configuration';

        $response = wp_remote_get($wellKnown, [
            'timeout' => 10,
            'redirection' => 2,
            'headers' => ['Accept' => 'application/json'],
        ]);

        if (is_wp_error($response)) {
            $this->logger->error('OIDC discovery request failed', [
                'correlation_id' => $correlationId,
                'issuer' => $issuer,
                'error' => $response->get_error_message(),
            ]);
            return new WP_Error('oidc_discovery_http_error', __('Impossibile recuperare la configurazione del provider.', 'wp-spid-cie-oidc'));
        }

        $status = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $json = json_decode($body, true);

        if ($status !== 200 || !is_array($json)) {
            $this->logger->error('OIDC discovery invalid response', [
                'correlation_id' => $correlationId,
                'issuer' => $issuer,
                'http_status' => $status,
            ]);
            return new WP_Error('oidc_discovery_invalid_response', __('Configurazione provider non valida.', 'wp-spid-cie-oidc'));
        }

        $required = ['issuer', 'authorization_endpoint', 'token_endpoint', 'jwks_uri'];
        foreach ($required as $field) {
            if (empty($json[$field])) {
                return new WP_Error('oidc_discovery_missing_fields', __('Configurazione provider incompleta.', 'wp-spid-cie-oidc'));
            }
        }

        return [
            'issuer' => untrailingslashit((string) $json['issuer']),
            'authorization_endpoint' => (string) $json['authorization_endpoint'],
            'token_endpoint' => (string) $json['token_endpoint'],
            'jwks_uri' => (string) $json['jwks_uri'],
            'userinfo_endpoint' => isset($json['userinfo_endpoint']) ? (string) $json['userinfo_endpoint'] : '',
            'end_session_endpoint' => isset($json['end_session_endpoint']) ? (string) $json['end_session_endpoint'] : '',
        ];
    }

    private function isSafeIssuer(string $issuer): bool {
        $parts = wp_parse_url($issuer);
        if (!is_array($parts)) {
            return false;
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        if ($scheme !== 'https') {
            return false;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        if (empty($host) || $host === 'localhost') {
            return false;
        }

        return true;
    }
}
