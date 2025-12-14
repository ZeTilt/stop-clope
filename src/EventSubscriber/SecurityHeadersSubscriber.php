<?php

namespace App\EventSubscriber;

use App\Service\CspNonceService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Ajoute les headers de sécurité HTTP à toutes les réponses
 * Équivalent de nelmio/security-bundle mais compatible Symfony 8
 */
class SecurityHeadersSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private CspNonceService $cspNonceService,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => 'onKernelResponse',
        ];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $response = $event->getResponse();

        // HSTS: force HTTPS pendant 1 an (incluant sous-domaines)
        $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');

        // X-Content-Type-Options: empêche le MIME sniffing
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        // X-Frame-Options: empêche le clickjacking
        $response->headers->set('X-Frame-Options', 'DENY');

        // X-XSS-Protection: protection XSS (legacy mais utile pour vieux navigateurs)
        $response->headers->set('X-XSS-Protection', '1; mode=block');

        // Referrer-Policy: limite les informations envoyées dans le referer
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // Permissions-Policy: désactive les fonctionnalités non utilisées
        $response->headers->set('Permissions-Policy', 'geolocation=(), microphone=(), camera=()');

        // Content-Security-Policy avec nonce pour scripts inline
        $nonce = $this->cspNonceService->getNonce();
        $csp = implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data:",
            "font-src 'self'",
            "connect-src 'self'",
            "frame-ancestors 'none'",
            "form-action 'self'",
            "base-uri 'self'",
        ]);
        $response->headers->set('Content-Security-Policy', $csp);
    }
}
