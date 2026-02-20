<?php

class WP_SPID_CIE_OIDC_WpUserMapper {
    private $logger;

    public function __construct(WP_SPID_CIE_OIDC_Logger $logger) {
        $this->logger = $logger;
    }

    /**
     * Normalize provider claims into stable internal schema.
     */
    public function normalizeClaims(array $claims, string $provider): array {
        $email = $this->pickFirst($claims, ['email', 'mail']);
        $givenName = $this->pickFirst($claims, ['given_name', 'name']);
        $familyName = $this->pickFirst($claims, ['family_name', 'familyName', 'surname']);
        $fiscalCode = $this->pickFirst($claims, ['fiscal_code', 'fiscalCode', 'fiscalNumber', 'fiscal_number', 'cf', 'tax_id']);
        $mobile = $this->pickFirst($claims, ['mobile', 'mobile_phone', 'phone_number', 'phoneNumber', 'cellulare']);

        return [
            'provider' => $provider,
            'sub' => $this->sanitizeText($claims['sub'] ?? ''),
            'email' => sanitize_email((string) $email),
            'given_name' => $this->sanitizeText($givenName),
            'family_name' => $this->sanitizeText($familyName),
            'fiscal_code' => strtoupper($this->sanitizeText($fiscalCode)),
            'mobile' => $this->normalizeMobile($mobile),
        ];
    }

    /**
     * Mandatory PA-grade validation.
     */
    public function validateMandatoryClaims(array $normalized, string $correlationId) {
        $missing = [];

        if (empty($normalized['sub'])) {
            $missing[] = 'sub';
        }
        if (empty($normalized['email']) || !is_email($normalized['email'])) {
            $missing[] = 'email';
        }
        if (empty($normalized['given_name'])) {
            $missing[] = 'given_name';
        }
        if (empty($normalized['family_name'])) {
            $missing[] = 'family_name';
        }
        if (empty($normalized['fiscal_code'])) {
            $missing[] = 'fiscal_code';
        }
        if (empty($normalized['mobile'])) {
            $missing[] = 'mobile';
        }

        if (!empty($missing)) {
            $this->logger->error('OIDC mandatory claims missing', [
                'correlation_id' => $correlationId,
                'provider' => $normalized['provider'] ?? 'unknown',
                'missing' => implode(',', $missing),
            ]);
            return new WP_Error('oidc_missing_required_claims', __('Autenticazione SPID/CIE non completata.', 'wp-spid-cie-oidc'));
        }

        return true;
    }

    private function pickFirst(array $claims, array $keys): string {
        foreach ($keys as $key) {
            if (isset($claims[$key]) && $claims[$key] !== null && $claims[$key] !== '') {
                return (string) $claims[$key];
            }
        }
        return '';
    }

    private function sanitizeText(string $value): string {
        return sanitize_text_field(trim($value));
    }

    private function normalizeMobile(string $value): string {
        $v = preg_replace('/\s+/', '', (string) $value);
        $v = preg_replace('/[^0-9\+]/', '', $v);
        return sanitize_text_field($v ?? '');
    }
}
