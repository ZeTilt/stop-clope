<?php

namespace App\Entity;

use App\Repository\UserStateRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: UserStateRepository::class)]
#[ORM\Table(name: 'user_states')]
class UserState
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'userState', targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column]
    private int $shieldsCount = 0;

    #[ORM\Column(type: Types::FLOAT)]
    private float $permanentMultiplier = 0.0;

    #[ORM\Column(length: 50)]
    private string $currentRank = 'fumeur';

    #[ORM\Column]
    private int $totalScore = 0;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $currentTargetInterval = null;

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

    public function getShieldsCount(): int
    {
        return $this->shieldsCount;
    }

    public function setShieldsCount(int $shieldsCount): static
    {
        $this->shieldsCount = $shieldsCount;
        return $this;
    }

    public function addShield(): static
    {
        $this->shieldsCount++;
        return $this;
    }

    public function useShield(): bool
    {
        if ($this->shieldsCount > 0) {
            $this->shieldsCount--;
            return true;
        }
        return false;
    }

    public function getPermanentMultiplier(): float
    {
        return $this->permanentMultiplier;
    }

    public function setPermanentMultiplier(float $permanentMultiplier): static
    {
        $this->permanentMultiplier = $permanentMultiplier;
        return $this;
    }

    public function addPermanentMultiplier(float $bonus): static
    {
        $this->permanentMultiplier += $bonus;
        return $this;
    }

    public function getCurrentRank(): string
    {
        return $this->currentRank;
    }

    public function setCurrentRank(string $currentRank): static
    {
        $this->currentRank = $currentRank;
        return $this;
    }

    public function getTotalScore(): int
    {
        return $this->totalScore;
    }

    public function setTotalScore(int $totalScore): static
    {
        $this->totalScore = $totalScore;
        return $this;
    }

    public function addScore(int $score): static
    {
        $this->totalScore += $score;
        return $this;
    }

    public function getCurrentTargetInterval(): ?float
    {
        return $this->currentTargetInterval;
    }

    public function setCurrentTargetInterval(?float $currentTargetInterval): static
    {
        $this->currentTargetInterval = $currentTargetInterval;
        return $this;
    }
}
