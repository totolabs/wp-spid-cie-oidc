<?php

/**
 * Minimal logger for plugin runtime.
 * Avoids logging sensitive tokens/secrets.
 */
class WP_SPID_CIE_OIDC_Logger {
    const LEVEL_ERROR = 'ERROR';
    const LEVEL_WARN  = 'WARN';
    const LEVEL_INFO  = 'INFO';
    const LEVEL_DEBUG = 'DEBUG';

    private $component;

    public function __construct(string $component = 'OIDC') {
        $this->component = $component;
    }

    public function error(string $message, array $context = []): void {
        $this->log(self::LEVEL_ERROR, $message, $context);
    }

    public function warn(string $message, array $context = []): void {
        $this->log(self::LEVEL_WARN, $message, $context);
    }

    public function info(string $message, array $context = []): void {
        $this->log(self::LEVEL_INFO, $message, $context);
    }

    public function debug(string $message, array $context = []): void {
        $this->log(self::LEVEL_DEBUG, $message, $context);
    }

    public function generateCorrelationId(): string {
        return bin2hex(random_bytes(8));
    }

    private function log(string $level, string $message, array $context = []): void {
        $safeContext = $this->sanitizeContext($context);
        $line = sprintf(
            '[%s] [%s] [%s] %s %s',
            gmdate('c'),
            $level,
            $this->component,
            $message,
            wp_json_encode($safeContext)
        );
        error_log($line);
    }

    private function sanitizeContext(array $context): array {
        $forbidden = ['client_secret', 'access_token', 'refresh_token', 'id_token', 'token'];
        foreach ($context as $key => $value) {
            $lowerKey = strtolower((string) $key);
            foreach ($forbidden as $needle) {
                if (strpos($lowerKey, $needle) !== false) {
                    $context[$key] = '[REDACTED]';
                    continue 2;
                }
            }

            if (is_string($value) && strlen($value) > 500) {
                $context[$key] = substr($value, 0, 120) . '...[truncated]';
            }
        }
        return $context;
    }
}
