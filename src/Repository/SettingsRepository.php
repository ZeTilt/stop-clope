<?php

namespace App\Repository;

use App\Entity\Settings;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Settings>
 */
class SettingsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Settings::class);
    }

    public function get(string $name, ?string $default = null): ?string
    {
        $setting = $this->findOneBy(['name' => $name]);
        return $setting ? $setting->getValue() : $default;
    }

    public function set(string $name, string $value): void
    {
        $setting = $this->findOneBy(['name' => $name]);
        if (!$setting) {
            $setting = new Settings();
            $setting->setName($name);
        }
        $setting->setValue($value);

        $this->getEntityManager()->persist($setting);
        $this->getEntityManager()->flush();
    }
}
