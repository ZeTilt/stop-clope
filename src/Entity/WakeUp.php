<?php

namespace App\Entity;

use App\Repository\WakeUpRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WakeUpRepository::class)]
#[ORM\Index(columns: ['date'], name: 'idx_wakeup_date')]
class WakeUp
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, unique: true)]
    private ?\DateTimeInterface $date = null;

    #[ORM\Column(type: Types::TIME_MUTABLE)]
    private ?\DateTimeInterface $wakeTime = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $createdAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
        $this->date = new \DateTime();
        $this->date->setTime(0, 0, 0);
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getWakeTime(): ?\DateTimeInterface
    {
        return $this->wakeTime;
    }

    public function setWakeTime(\DateTimeInterface $wakeTime): static
    {
        $this->wakeTime = $wakeTime;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getWakeDateTime(): \DateTimeInterface
    {
        $dateTime = clone $this->date;
        $dateTime->setTime(
            (int) $this->wakeTime->format('H'),
            (int) $this->wakeTime->format('i'),
            0
        );
        return $dateTime;
    }
}
