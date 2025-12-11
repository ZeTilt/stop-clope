<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\RegistrationFormType;
use App\Repository\CigaretteRepository;
use App\Repository\SettingsRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class SecurityController extends AbstractController
{
    #[Route(path: '/login', name: 'app_login')]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_home');
        }

        $error = $authenticationUtils->getLastAuthenticationError();
        $lastUsername = $authenticationUtils->getLastUsername();

        return $this->render('security/login.html.twig', [
            'last_username' => $lastUsername,
            'error' => $error,
        ]);
    }

    #[Route(path: '/register', name: 'app_register')]
    public function register(
        Request $request,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $entityManager,
        UserRepository $userRepository,
        CigaretteRepository $cigaretteRepository,
        SettingsRepository $settingsRepository
    ): Response {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_home');
        }

        $user = new User();
        $form = $this->createForm(RegistrationFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $user->setPassword(
                $passwordHasher->hashPassword(
                    $user,
                    $form->get('plainPassword')->getData()
                )
            );

            $entityManager->persist($user);
            $entityManager->flush();

            // Migration des données orphelines pour le premier utilisateur
            if ($userRepository->count([]) === 1) {
                $this->migrateOrphanData($user, $cigaretteRepository, $settingsRepository, $entityManager);
            }

            $this->addFlash('success', 'Compte créé ! Tu peux te connecter.');

            return $this->redirectToRoute('app_login');
        }

        return $this->render('security/register.html.twig', [
            'registrationForm' => $form,
        ]);
    }

    private function migrateOrphanData(
        User $user,
        CigaretteRepository $cigaretteRepository,
        SettingsRepository $settingsRepository,
        EntityManagerInterface $entityManager
    ): void {
        // Migrer les cigarettes sans user
        $orphanCigarettes = $cigaretteRepository->findBy(['user' => null]);
        foreach ($orphanCigarettes as $cigarette) {
            $cigarette->setUser($user);
        }

        // Migrer les settings sans user
        $orphanSettings = $settingsRepository->findBy(['user' => null]);
        foreach ($orphanSettings as $setting) {
            $setting->setUser($user);
        }

        $entityManager->flush();
    }

    #[Route(path: '/logout', name: 'app_logout')]
    public function logout(): void
    {
        throw new \LogicException('This method can be blank - it will be intercepted by the logout key on your firewall.');
    }
}
