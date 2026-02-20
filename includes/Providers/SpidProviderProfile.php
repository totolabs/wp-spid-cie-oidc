<?php

class WP_SPID_CIE_OIDC_SpidProviderProfile implements WP_SPID_CIE_OIDC_ProviderProfileInterface {
    public function getProviderKey(): string {
        return 'spid';
    }

    public function buildBaseConfig(array $options, ?string $idp, WP_SPID_CIE_OIDC_Wrapper $wrapper): array {
        $providers = $wrapper->getSpidProviders();
        $selected = 'validator';

        if (!empty($idp) && isset($providers[$idp])) {
            $selected = $idp;
        } elseif (!isset($providers[$selected])) {
            $keys = array_keys($providers);
            $selected = !empty($keys) ? $keys[0] : 'validator';
        }

        $spidProvider = $providers[$selected] ?? [
            'issuer' => 'https://validator.spid.gov.it',
            'auth_endpoint' => 'https://validator.spid.gov.it/oidc/op/authorization',
        ];

        $issuer = untrailingslashit((string) ($options['spid_issuer'] ?? $spidProvider['issuer']));

        return [
            'provider' => 'spid',
            'provider_id' => $selected,
            'issuer' => $issuer,
            'authorization_endpoint' => (string) ($options['spid_authorization_endpoint'] ?? $spidProvider['auth_endpoint']),
            'token_endpoint' => (string) ($options['spid_token_endpoint'] ?? ($issuer . '/oidc/op/token')),
            'jwks_uri' => (string) ($options['spid_jwks_uri'] ?? ($issuer . '/oidc/op/jwks')),
            'userinfo_endpoint' => (string) ($options['spid_userinfo_endpoint'] ?? ''),
            'end_session_endpoint' => (string) ($options['spid_end_session_endpoint'] ?? ''),
        ];
    }

    public function resolveConfig(array $options, ?string $idp, WP_SPID_CIE_OIDC_Wrapper $wrapper, WP_SPID_CIE_OIDC_DiscoveryResolver $discoveryResolver) {
        $base = $this->buildBaseConfig($options, $idp, $wrapper);
        $mode = $options['discovery_mode'] ?? 'auto';

        if ($mode === 'auto') {
            $resolved = $discoveryResolver->resolveFromIssuer((string) $base['issuer'], 'spid-' . bin2hex(random_bytes(4)));
            if (is_wp_error($resolved)) {
                return $resolved;
            }
            $base = array_merge($base, $resolved);
        }

        $base['scope'] = $this->buildScope($options['spid_scope'] ?? 'openid profile');
        $base['acr_values'] = $this->resolveAcrValues($options, 'spid');
        $base['min_acr'] = $options['min_loa'] ?? 'SpidL2';
        $base['allow_missing_acr'] = false;

        return $base;
    }

    private function buildScope(string $scope): string {
        $scope = trim(preg_replace('/\s+/', ' ', $scope));
        return $scope === '' ? 'openid profile' : $scope;
    }

    private function resolveAcrValues(array $options, string $provider): string {
        if (!empty($options[$provider . '_acr_values'])) {
            return (string) $options[$provider . '_acr_values'];
        }

        $map = [
            'SpidL1' => 'https://www.spid.gov.it/SpidL1',
            'SpidL2' => 'https://www.spid.gov.it/SpidL2',
            'SpidL3' => 'https://www.spid.gov.it/SpidL3',
        ];

        $min = $options['min_loa'] ?? 'SpidL2';
        return $map[$min] ?? $map['SpidL2'];
    }
}
