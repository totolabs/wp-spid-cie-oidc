<?php

interface WP_SPID_CIE_OIDC_ProviderProfileInterface {
    public function getProviderKey(): string;

    /**
     * Build provider-specific baseline config (pre-discovery/manual override).
     */
    public function buildBaseConfig(array $options, ?string $idp, WP_SPID_CIE_OIDC_Wrapper $wrapper): array;

    /**
     * Returns normalized config by applying discovery/manual endpoint strategy.
     */
    public function resolveConfig(
        array $options,
        ?string $idp,
        WP_SPID_CIE_OIDC_Wrapper $wrapper,
        WP_SPID_CIE_OIDC_DiscoveryResolver $discoveryResolver
    );
}
