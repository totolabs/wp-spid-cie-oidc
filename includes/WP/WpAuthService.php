<?php

class WP_SPID_CIE_OIDC_WpAuthService {
    private $logger;

    public function __construct(WP_SPID_CIE_OIDC_Logger $logger) {
        $this->logger = $logger;
    }

    public function resolveOrProvisionUser(array $identity, array $providerConfig, array $options, string $correlationId) {
        $provider = $identity['provider'];
        $providerSubMeta = $this->getProviderSubMetaKey($provider);

        $bySub = $this->findByMetaValue($providerSubMeta, $identity['sub']);
        $byFiscal = $this->findByMetaValue('_spidcie_fiscal_code', $identity['fiscal_code']);

        if (count($bySub) > 1) {
            $this->logger->error('OIDC identity conflict on provider sub', [
                'correlation_id' => $correlationId,
                'provider' => $provider,
            ]);
            return new WP_Error('oidc_identity_conflict', __('Autenticazione SPID/CIE non completata.', 'wp-spid-cie-oidc'));
        }

        if (count($byFiscal) > 1) {
            $this->logger->error('OIDC identity conflict on fiscal code', [
                'correlation_id' => $correlationId,
                'provider' => $provider,
            ]);
            return new WP_Error('oidc_identity_conflict', __('Autenticazione SPID/CIE non completata.', 'wp-spid-cie-oidc'));
        }

        $userFromSub = !empty($bySub) ? $bySub[0] : null;
        $userFromFiscal = !empty($byFiscal) ? $byFiscal[0] : null;

        if ($userFromSub && $userFromFiscal && intval($userFromSub->ID) !== intval($userFromFiscal->ID)) {
            $this->logger->error('OIDC identity conflict sub vs fiscal', [
                'correlation_id' => $correlationId,
                'provider' => $provider,
                'sub_user_id' => $userFromSub->ID,
                'fiscal_user_id' => $userFromFiscal->ID,
            ]);
            return new WP_Error('oidc_identity_conflict', __('Autenticazione SPID/CIE non completata.', 'wp-spid-cie-oidc'));
        }

        $user = $userFromSub ?: $userFromFiscal;

        if (!$user) {
            $autoProvision = !empty($options['auto_provisioning']) && $options['auto_provisioning'] === '1';
            if (!$autoProvision) {
                $this->logger->error('OIDC user not found and auto provisioning disabled', [
                    'correlation_id' => $correlationId,
                    'provider' => $provider,
                ]);
                return new WP_Error('oidc_user_not_found', __('Autenticazione SPID/CIE non completata.', 'wp-spid-cie-oidc'));
            }

            $user = $this->provisionUser($identity, $options, $correlationId);
            if (is_wp_error($user)) {
                return $user;
            }
        }

        $this->updateIdentityMeta($user->ID, $identity, $providerConfig);
        return $user;
    }

    private function provisionUser(array $identity, array $options, string $correlationId) {
        $provider = sanitize_key((string) $identity['provider']);
        $hash = substr(hash('sha256', $identity['sub']), 0, 12);
        $usernameBase = sanitize_user($provider . '_' . $hash, true);
        $username = $usernameBase;
        $i = 1;
        while (username_exists($username)) {
            $username = $usernameBase . '_' . $i;
            $i++;
        }

        $email = $identity['email'];
        if (!is_email($email)) {
            return new WP_Error('oidc_invalid_email', __('Autenticazione SPID/CIE non completata.', 'wp-spid-cie-oidc'));
        }

        $displayName = trim($identity['given_name'] . ' ' . $identity['family_name']);

        $userId = wp_insert_user([
            'user_login' => $username,
            'user_pass' => wp_generate_password(64, true, true),
            'user_email' => $email,
            'first_name' => $identity['given_name'],
            'last_name' => $identity['family_name'],
            'display_name' => $displayName,
            'role' => $this->resolveDefaultRole($options),
        ]);

        if (is_wp_error($userId)) {
            $this->logger->error('OIDC user provisioning failed', [
                'correlation_id' => $correlationId,
                'provider' => $provider,
                'error' => $userId->get_error_code(),
            ]);
            return new WP_Error('oidc_user_provisioning_failed', __('Autenticazione SPID/CIE non completata.', 'wp-spid-cie-oidc'));
        }

        $this->logger->info('OIDC user auto-provisioned', [
            'correlation_id' => $correlationId,
            'provider' => $provider,
            'user_id' => $userId,
        ]);

        return get_user_by('id', $userId);
    }

    private function updateIdentityMeta(int $userId, array $identity, array $providerConfig): void {
        $provider = sanitize_key((string) $identity['provider']);
        update_user_meta($userId, '_spidcie_provider', $provider);

        if ($provider === 'spid') {
            update_user_meta($userId, '_spidcie_sub_spid', $identity['sub']);
        } else {
            update_user_meta($userId, '_spidcie_sub_cie', $identity['sub']);
        }

        update_user_meta($userId, '_spidcie_fiscal_code', strtoupper($identity['fiscal_code']));
        update_user_meta($userId, '_spidcie_mobile', $identity['mobile']);
        update_user_meta($userId, '_spidcie_last_login_ts', time());

        $acr = isset($providerConfig['last_id_token_acr']) ? (string) $providerConfig['last_id_token_acr'] : '';
        update_user_meta($userId, '_spidcie_last_acr', sanitize_text_field($acr));
    }

    private function getProviderSubMetaKey(string $provider): string {
        return $provider === 'spid' ? '_spidcie_sub_spid' : '_spidcie_sub_cie';
    }

    private function findByMetaValue(string $metaKey, string $metaValue): array {
        if ($metaValue === '') {
            return [];
        }

        $query = new WP_User_Query([
            'meta_key' => $metaKey,
            'meta_value' => $metaValue,
            'number' => 3,
            'count_total' => false,
            'fields' => 'all_with_meta',
        ]);

        $results = $query->get_results();
        return is_array($results) ? $results : [];
    }

    private function resolveDefaultRole(array $options): string {
        $role = isset($options['default_role']) ? sanitize_key($options['default_role']) : '';
        if ($role && get_role($role)) {
            return $role;
        }

        return get_option('default_role', 'subscriber');
    }
}
