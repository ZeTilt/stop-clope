<?php

namespace App\Entity;

use App\Repository\DailyScoreRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DailyScoreRepository::class)]
#[ORM\UniqueConstraint(name: 'unique_user_date', columns: ['user_id', 'date'])]
#[ORM\Index(columns: ['date'], name: 'idx_daily_score_date')]
class DailyScore
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    private ?\DateTimeInterface $date = null;

    #[ORM\Column]
    private int $score = 0;

    #[ORM\Column]
    private int $cigaretteCount = 0;

    #[ORM\Column]
    private int $streak = 0;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $averageInterval = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $calculatedAt = null;

    public function __construct()
    {
        $this->calculatedAt = new \DateTime();
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

    public function getDate(): ?\DateTimeInterface
    {
        return $this->date;
    }

    public function setDate(\DateTimeInterface $date): static
    {
        $this->date = $date;
        return $this;
    }

    public function getScore(): int
    {
        return $this->score;
    }

    public function setScore(int $score): static
    {
        $this->score = $score;
        return $this;
    }

    public function getCigaretteCount(): int
    {
        return $this->cigaretteCount;
    }

    public function setCigaretteCount(int $cigaretteCount): static
    {
        $this->cigaretteCount = $cigaretteCount;
        return $this;
    }

    public function getStreak(): int
    {
        return $this->streak;
    }

    public function setStreak(int $streak): static
    {
        $this->streak = $streak;
        return $this;
    }

    public function getAverageInterval(): ?float
    {
        return $this->averageInterval;
    }

    public function setAverageInterval(?float $averageInterval): static
    {
        $this->averageInterval = $averageInterval;
        return $this;
    }

    public function getCalculatedAt(): ?\DateTimeInterface
    {
        return $this->calculatedAt;
    }

    public function setCalculatedAt(\DateTimeInterface $calculatedAt): static
    {
        $this->calculatedAt = $calculatedAt;
        return $this;
    }
}
