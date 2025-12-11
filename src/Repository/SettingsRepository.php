<?php

namespace App\Repository;

use App\Entity\Settings;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * @extends ServiceEntityRepository<Settings>
 */
class SettingsRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private Security $security
    ) {
        parent::__construct($registry, Settings::class);
    }

    private function getCurrentUser(): ?User
    {
        $user = $this->security->getUser();
        return $user instanceof User ? $user : null;
    }

    public function get(string $name, ?string $default = null): ?string
    {
        $user = $this->getCurrentUser();
        $criteria = ['name' => $name];

        if ($user) {
            $criteria['user'] = $user;
        }

        $setting = $this->findOneBy($criteria);
        return $setting ? $setting->getValue() : $default;
    }

    public function set(string $name, string $value): void
    {
        $user = $this->getCurrentUser();
        $criteria = ['name' => $name];

        if ($user) {
            $criteria['user'] = $user;
        }

        $setting = $this->findOneBy($criteria);
        if (!$setting) {
            $setting = new Settings();
            $setting->setName($name);
            if ($user) {
                $setting->setUser($user);
            }
        }
        $setting->setValue($value);

        $this->getEntityManager()->persist($setting);
        $this->getEntityManager()->flush();
    }
}
