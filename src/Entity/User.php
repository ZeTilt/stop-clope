<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_IDENTIFIER_EMAIL', fields: ['email'])]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    private ?string $email = null;

    /**
     * @var list<string> The user roles
     */
    #[ORM\Column]
    private array $roles = [];

    /**
     * @var string The hashed password
     */
    #[ORM\Column]
    private ?string $password = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $createdAt = null;

    /** @var Collection<int, Cigarette> */
    #[ORM\OneToMany(targetEntity: Cigarette::class, mappedBy: 'user', orphanRemoval: true)]
    private Collection $cigarettes;

    /** @var Collection<int, Settings> */
    #[ORM\OneToMany(targetEntity: Settings::class, mappedBy: 'user', orphanRemoval: true)]
    private Collection $settings;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
        $this->cigarettes = new ArrayCollection();
        $this->settings = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    /**
     * A visual identifier that represents this user.
     *
     * @see UserInterface
     */
    public function getUserIdentifier(): string
    {
        return (string) $this->email;
    }

    /**
     * @see UserInterface
     */
    public function getRoles(): array
    {
        $roles = $this->roles;
        // guarantee every user at least has ROLE_USER
        $roles[] = 'ROLE_USER';

        return array_unique($roles);
    }

    /**
     * @param list<string> $roles
     */
    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    /**
     * @see PasswordAuthenticatedUserInterface
     */
    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    /**
     * Ensure the session doesn't contain actual password hashes by CRC32C-hashing them, as supported since Symfony 7.3.
     */
    public function __serialize(): array
    {
        $data = (array) $this;
        $data["\0".self::class."\0password"] = hash('crc32c', $this->password);

        return $data;
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

    /** @return Collection<int, Cigarette> */
    public function getCigarettes(): Collection
    {
        return $this->cigarettes;
    }

    public function addCigarette(Cigarette $cigarette): static
    {
        if (!$this->cigarettes->contains($cigarette)) {
            $this->cigarettes->add($cigarette);
            $cigarette->setUser($this);
        }
        return $this;
    }

    public function removeCigarette(Cigarette $cigarette): static
    {
        if ($this->cigarettes->removeElement($cigarette)) {
            if ($cigarette->getUser() === $this) {
                $cigarette->setUser(null);
            }
        }
        return $this;
    }

    /** @return Collection<int, Settings> */
    public function getSettings(): Collection
    {
        return $this->settings;
    }

    public function addSetting(Settings $setting): static
    {
        if (!$this->settings->contains($setting)) {
            $this->settings->add($setting);
            $setting->setUser($this);
        }
        return $this;
    }

    public function removeSetting(Settings $setting): static
    {
        if ($this->settings->removeElement($setting)) {
            if ($setting->getUser() === $this) {
                $setting->setUser(null);
            }
        }
        return $this;
    }
}
