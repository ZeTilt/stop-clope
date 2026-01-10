<?php

namespace App\Entity;

use App\Repository\ActiveBonusRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ActiveBonusRepository::class)]
#[ORM\Table(name: 'active_bonuses')]
#[ORM\Index(columns: ['expires_at'], name: 'idx_active_bonus_expires')]
class ActiveBonus
{
    public const TYPE_SCORE_PERCENT = 'score_percent';
    public const TYPE_MULTIPLIER = 'multiplier';
    public const TYPE_SHIELD = 'shield';
    public const TYPE_MAINTENANCE_DAY = 'maintenance_day';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'activeBonuses')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(length: 50)]
    private string $bonusType;

    #[ORM\Column(type: Types::FLOAT)]
    private float $bonusValue;

    #[ORM\Column(length: 100)]
    private string $sourceBadge;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $expiresAt;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
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

    public function getBonusType(): string
    {
        return $this->bonusType;
    }

    public function setBonusType(string $bonusType): static
    {
        $this->bonusType = $bonusType;
        return $this;
    }

    public function getBonusValue(): float
    {
        return $this->bonusValue;
    }

    public function setBonusValue(float $bonusValue): static
    {
        $this->bonusValue = $bonusValue;
        return $this;
    }

    public function getSourceBadge(): string
    {
        return $this->sourceBadge;
    }

    public function setSourceBadge(string $sourceBadge): static
    {
        $this->sourceBadge = $sourceBadge;
        return $this;
    }

    public function getExpiresAt(): \DateTimeInterface
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(\DateTimeInterface $expiresAt): static
    {
        $this->expiresAt = $expiresAt;
        return $this;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeInterface $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function isExpired(): bool
    {
        return $this->expiresAt < new \DateTime();
    }

    public function isActive(): bool
    {
        return !$this->isExpired();
    }
}
