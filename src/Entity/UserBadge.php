<?php

namespace App\Entity;

use App\Repository\UserBadgeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: UserBadgeRepository::class)]
#[ORM\UniqueConstraint(name: 'unique_user_badge', columns: ['user_id', 'badge_code'])]
#[ORM\Index(columns: ['badge_code'], name: 'idx_badge_code')]
class UserBadge
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    #[ORM\Column(length: 50)]
    private ?string $badgeCode = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $unlockedAt = null;

    public function __construct()
    {
        $this->unlockedAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getBadgeCode(): ?string
    {
        return $this->badgeCode;
    }

    public function setBadgeCode(string $badgeCode): static
    {
        $this->badgeCode = $badgeCode;
        return $this;
    }

    public function getUnlockedAt(): ?\DateTimeInterface
    {
        return $this->unlockedAt;
    }

    public function setUnlockedAt(\DateTimeInterface $unlockedAt): static
    {
        $this->unlockedAt = $unlockedAt;
        return $this;
    }
}
