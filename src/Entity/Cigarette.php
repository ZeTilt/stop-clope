<?php

namespace App\Entity;

use App\Repository\CigaretteRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CigaretteRepository::class)]
#[ORM\Index(columns: ['smoked_at'], name: 'idx_smoked_at')]
#[ORM\Index(columns: ['user_id'], name: 'idx_user_id')]
class Cigarette
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $smokedAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $isRetroactive = false;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'cigarettes')]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $user = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
        $this->smokedAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSmokedAt(): ?\DateTimeInterface
    {
        return $this->smokedAt;
    }

    public function setSmokedAt(\DateTimeInterface $smokedAt): static
    {
        $this->smokedAt = $smokedAt;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeInterface $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function isRetroactive(): bool
    {
        return $this->isRetroactive;
    }

    public function setIsRetroactive(bool $isRetroactive): static
    {
        $this->isRetroactive = $isRetroactive;
        return $this;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;
        return $this;
    }
}
