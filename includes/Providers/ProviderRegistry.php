<?php

class WP_SPID_CIE_OIDC_ProviderRegistry {
    private $profiles = [];
    private $discoveryResolver;
    private $wrapper;

    public function __construct(WP_SPID_CIE_OIDC_DiscoveryResolver $discoveryResolver, WP_SPID_CIE_OIDC_Wrapper $wrapper) {
        $this->discoveryResolver = $discoveryResolver;
        $this->wrapper = $wrapper;

        $this->profiles['spid'] = new WP_SPID_CIE_OIDC_SpidProviderProfile();
        $this->profiles['cie'] = new WP_SPID_CIE_OIDC_CieProviderProfile();
    }

    public function resolveConfig(string $provider, ?string $idp = null) {
        if (!isset($this->profiles[$provider])) {
            return new WP_Error('oidc_provider_not_supported', __('Provider non supportato.', 'wp-spid-cie-oidc'));
        }

        $options = get_option('wp-spid-cie-oidc_options', []);
        if (!$this->isProviderEnabledByMode($provider, $options)) {
            return new WP_Error('oidc_provider_disabled', __('Provider non abilitato dalla configurazione.', 'wp-spid-cie-oidc'));
        }

        $baseUrl = untrailingslashit($options['issuer_override'] ?? home_url());

        $config = $this->profiles[$provider]->resolveConfig($options, $idp, $this->wrapper, $this->discoveryResolver);
        if (is_wp_error($config)) {
            return $config;
        }

        $config['client_id'] = $baseUrl;
        $config['client_secret'] = '';
        $config['redirect_uri'] = add_query_arg(['oidc_action' => 'callback', 'provider' => $provider], $baseUrl);

        return $config;
    }

    private function isProviderEnabledByMode(string $provider, array $options): bool {
        $mode = $options['provider_mode'] ?? 'both';
        if ($mode === 'spid_only' && $provider !== 'spid') {
            return false;
        }
        if ($mode === 'cie_only' && $provider !== 'cie') {
            return false;
        }

        // Backward compatibility with existing provider toggles.
        if ($provider === 'spid' && isset($options['spid_enabled']) && $options['spid_enabled'] !== '1') {
            return false;
        }
        if ($provider === 'cie' && isset($options['cie_enabled']) && $options['cie_enabled'] !== '1') {
            return false;
        }

        return true;
    }
}
