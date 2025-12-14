<?php

namespace App\Twig;

use App\Service\CspNonceService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class CspNonceExtension extends AbstractExtension
{
    public function __construct(
        private CspNonceService $cspNonceService,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('csp_nonce', [$this, 'getCspNonce']),
        ];
    }

    public function getCspNonce(): string
    {
        return $this->cspNonceService->getNonce();
    }
}
