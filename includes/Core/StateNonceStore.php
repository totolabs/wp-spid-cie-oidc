<?php

interface WP_SPID_CIE_OIDC_StateNonceStoreInterface {
    public function store(string $state, array $context, int $ttl): bool;
    public function consume(string $state): ?array;
}

class WP_SPID_CIE_OIDC_TransientStateNonceStore implements WP_SPID_CIE_OIDC_StateNonceStoreInterface {
    const PREFIX = 'spidcie_oidc_state_';

    public function store(string $state, array $context, int $ttl): bool {
        $key = $this->buildKey($state);
        return set_transient($key, $context, $ttl);
    }

    public function consume(string $state): ?array {
        $key = $this->buildKey($state);
        $value = get_transient($key);
        delete_transient($key);

        if (!is_array($value)) {
            return null;
        }

        return $value;
    }

    private function buildKey(string $state): string {
        return self::PREFIX . md5($state);
    }
}
