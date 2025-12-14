<?php

namespace App\Service;

/**
 * Service pour générer et stocker le nonce CSP par requête
 */
class CspNonceService
{
    private ?string $nonce = null;

    public function getNonce(): string
    {
        if ($this->nonce === null) {
            $this->nonce = base64_encode(random_bytes(16));
        }

        return $this->nonce;
    }
}
