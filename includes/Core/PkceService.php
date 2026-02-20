<?php

class WP_SPID_CIE_OIDC_PkceService {

    public function generateVerifier(): string {
        return $this->base64urlEncode(random_bytes(64));
    }

    public function generateChallenge(string $verifier): string {
        return $this->base64urlEncode(hash('sha256', $verifier, true));
    }

    public function getChallengeMethod(): string {
        return 'S256';
    }

    private function base64urlEncode(string $data): string {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
