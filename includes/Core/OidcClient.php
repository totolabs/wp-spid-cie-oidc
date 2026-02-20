<?php

class WP_SPID_CIE_OIDC_OidcClient {
    private $pkce;
    private $stateStore;
    private $tokenValidator;
    private $logger;

    public function __construct(
        WP_SPID_CIE_OIDC_PkceService $pkce,
        WP_SPID_CIE_OIDC_StateNonceStoreInterface $stateStore,
        WP_SPID_CIE_OIDC_TokenValidator $tokenValidator,
        WP_SPID_CIE_OIDC_Logger $logger
    ) {
        $this->pkce = $pkce;
        $this->stateStore = $stateStore;
        $this->tokenValidator = $tokenValidator;
        $this->logger = $logger;
    }

    public function buildAuthorizationUrl(array $providerConfig, string $targetUrl, string $correlationId) {
        $state = bin2hex(random_bytes(16));
        $nonce = bin2hex(random_bytes(16));
        $verifier = $this->pkce->generateVerifier();
        $challenge = $this->pkce->generateChallenge($verifier);

        $ctx = [
            'created_at' => time(),
            'nonce' => $nonce,
            'code_verifier' => $verifier,
            'provider' => $providerConfig['provider'] ?? 'spid',
            'target_url' => $targetUrl,
            'issuer' => $providerConfig['issuer'] ?? '',
            'correlation_id' => $correlationId,
        ];

        if (!$this->stateStore->store($state, $ctx, 600)) {
            return new WP_Error('oidc_state_store_fail', __('Errore temporaneo di sicurezza.', 'wp-spid-cie-oidc'));
        }

        $params = [
            'client_id' => $providerConfig['client_id'],
            'response_type' => 'code',
            'redirect_uri' => $providerConfig['redirect_uri'],
            'scope' => $providerConfig['scope'] ?? 'openid',
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => $challenge,
            'code_challenge_method' => $this->pkce->getChallengeMethod(),
        ];

        if (!empty($providerConfig['acr_values'])) {
            $params['acr_values'] = $providerConfig['acr_values'];
        }

        $authorizationEndpoint = $providerConfig['authorization_endpoint'] ?? '';
        if (!$authorizationEndpoint) {
            return new WP_Error('oidc_no_auth_endpoint', __('Endpoint di autorizzazione non configurato.', 'wp-spid-cie-oidc'));
        }

        return $authorizationEndpoint . '?' . http_build_query($params);
    }

    public function handleCallback(array $request, array $providerConfig) {
        $correlationId = $request['correlation_id'] ?? $this->logger->generateCorrelationId();

        if (!empty($request['error'])) {
            $code = sanitize_key($request['error']);
            $this->logger->warn('OIDC callback provider error', ['correlation_id' => $correlationId, 'code' => $code]);
            return new WP_Error('oidc_provider_error', __('Accesso annullato o non autorizzato.', 'wp-spid-cie-oidc'));
        }

        $state = isset($request['state']) ? sanitize_text_field(wp_unslash($request['state'])) : '';
        $code = isset($request['code']) ? sanitize_text_field(wp_unslash($request['code'])) : '';

        if (empty($state) || empty($code)) {
            return new WP_Error('oidc_missing_callback_params', __('Risposta di autenticazione non valida.', 'wp-spid-cie-oidc'));
        }

        $stateCtx = $this->stateStore->consume($state);
        if (!$stateCtx || !is_array($stateCtx)) {
            $this->logger->error('OIDC state mismatch/expired', ['correlation_id' => $correlationId]);
            return new WP_Error('oidc_state_mismatch', __('Sessione di autenticazione non valida o scaduta.', 'wp-spid-cie-oidc'));
        }

        $tokenResponse = $this->exchangeCodeForTokens($code, $stateCtx['code_verifier'], $providerConfig, $correlationId);
        if (is_wp_error($tokenResponse)) {
            return $tokenResponse;
        }

        $idToken = $tokenResponse['id_token'] ?? '';
        if (empty($idToken)) {
            return new WP_Error('oidc_no_id_token', __('Token di identità assente nella risposta.', 'wp-spid-cie-oidc'));
        }

        $payload = $this->tokenValidator->validateIdToken($idToken, $providerConfig, $stateCtx['nonce'], $correlationId);
        if (is_wp_error($payload)) {
            $this->logger->error('OIDC id_token validation failed', [
                'correlation_id' => $correlationId,
                'error_code' => $payload->get_error_code(),
            ]);
            return $payload;
        }

        return [
            'claims' => $payload,
            'state_context' => $stateCtx,
            'correlation_id' => $correlationId,
        ];
    }

    private function exchangeCodeForTokens(string $code, string $codeVerifier, array $providerConfig, string $correlationId) {
        $tokenEndpoint = $providerConfig['token_endpoint'] ?? '';
        if (empty($tokenEndpoint)) {
            return new WP_Error('oidc_no_token_endpoint', __('Endpoint token non configurato.', 'wp-spid-cie-oidc'));
        }

        $body = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $providerConfig['redirect_uri'],
            'client_id' => $providerConfig['client_id'],
            'code_verifier' => $codeVerifier,
        ];

        if (!empty($providerConfig['client_secret'])) {
            $body['client_secret'] = $providerConfig['client_secret'];
        }

        $response = wp_remote_post($tokenEndpoint, [
            'timeout' => 15,
            'redirection' => 2,
            'headers' => ['Accept' => 'application/json'],
            'body' => $body,
        ]);

        if (is_wp_error($response)) {
            $this->logger->error('OIDC token request failed', [
                'correlation_id' => $correlationId,
                'error' => $response->get_error_message(),
            ]);
            return new WP_Error('oidc_token_http_error', __('Errore di rete durante autenticazione.', 'wp-spid-cie-oidc'));
        }

        $status = wp_remote_retrieve_response_code($response);
        $rawBody = wp_remote_retrieve_body($response);
        $json = json_decode($rawBody, true);

        if ($status < 200 || $status >= 300 || !is_array($json)) {
            $this->logger->error('OIDC token endpoint invalid response', [
                'correlation_id' => $correlationId,
                'http_status' => $status,
            ]);
            return new WP_Error('oidc_token_bad_response', __('Risposta non valida dal servizio di autenticazione.', 'wp-spid-cie-oidc'));
        }

        return $json;
    }
}
