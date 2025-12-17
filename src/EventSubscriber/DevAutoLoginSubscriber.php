<?php

namespace App\EventSubscriber;

use App\Repository\UserRepository;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Auto-login du premier utilisateur en environnement dev
 * Permet de tester l'application sans passer par le formulaire de connexion
 */
class DevAutoLoginSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private string $appEnv,
        private UserRepository $userRepository,
        private TokenStorageInterface $tokenStorage
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 9],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        // Ne s'applique qu'en dev
        if ($this->appEnv !== 'dev') {
            return;
        }

        // Ne s'applique qu'à la requête principale
        if (!$event->isMainRequest()) {
            return;
        }

        // Si déjà connecté, ne rien faire
        if ($this->tokenStorage->getToken() !== null) {
            return;
        }

        // Récupérer le premier utilisateur
        $user = $this->userRepository->findOneBy([]);
        if (!$user) {
            return;
        }

        // Créer le token d'authentification
        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());
        $this->tokenStorage->setToken($token);
    }
}
