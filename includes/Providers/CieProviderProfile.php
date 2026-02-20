<?php

class WP_SPID_CIE_OIDC_CieProviderProfile implements WP_SPID_CIE_OIDC_ProviderProfileInterface {
    public function getProviderKey(): string {
        return 'cie';
    }

    public function buildBaseConfig(array $options, ?string $idp, WP_SPID_CIE_OIDC_Wrapper $wrapper): array {
        return [
            'provider' => 'cie',
            'provider_id' => 'cie',
            'issuer' => untrailingslashit((string) ($options['cie_issuer'] ?? 'https://id.cie.gov.it/oidc/op')),
            'authorization_endpoint' => (string) ($options['cie_authorization_endpoint'] ?? 'https://id.cie.gov.it/oidc/authorization'),
            'token_endpoint' => (string) ($options['cie_token_endpoint'] ?? 'https://id.cie.gov.it/oidc/token'),
            'jwks_uri' => (string) ($options['cie_jwks_uri'] ?? 'https://id.cie.gov.it/oidc/jwks'),
            'userinfo_endpoint' => (string) ($options['cie_userinfo_endpoint'] ?? ''),
            'end_session_endpoint' => (string) ($options['cie_end_session_endpoint'] ?? ''),
        ];
    }

    public function resolveConfig(array $options, ?string $idp, WP_SPID_CIE_OIDC_Wrapper $wrapper, WP_SPID_CIE_OIDC_DiscoveryResolver $discoveryResolver) {
        $base = $this->buildBaseConfig($options, $idp, $wrapper);
        $mode = $options['discovery_mode'] ?? 'auto';

        if ($mode === 'auto') {
            $resolved = $discoveryResolver->resolveFromIssuer((string) $base['issuer'], 'cie-' . bin2hex(random_bytes(4)));
            if (is_wp_error($resolved)) {
                return $resolved;
            }
            $base = array_merge($base, $resolved);
        }

        $base['scope'] = $this->buildScope($options['cie_scope'] ?? 'openid profile email');
        $base['acr_values'] = $this->resolveAcrValues($options, 'cie');
        $base['min_acr'] = $options['min_loa'] ?? 'SpidL2';
        $base['allow_missing_acr'] = false;

        return $base;
    }

    private function buildScope(string $scope): string {
        $scope = trim(preg_replace('/\s+/', ' ', $scope));
        return $scope === '' ? 'openid profile email' : $scope;
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
