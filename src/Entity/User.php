<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A user with exactly one role (see role_hierarchy in security.yaml):
 * staff (admin, super manager) work in the CRM; clients get documents and photo reports
 * for their personal account on the website and can't sign in to the CRM (StaffOnlyUserChecker).
 */
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_IDENTIFIER_EMAIL', fields: ['email'])]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity('email', message: 'Пользователь с таким email уже есть')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    use TimestampableTrait;

    /** Full access, including user management */
    public const ROLE_ADMIN = 'ROLE_ADMIN';
    /** Manages structures and dictionaries */
    public const ROLE_SUPER_MANAGER = 'ROLE_SUPER_MANAGER';

    /** A client of the website: its documents and photo reports; no CRM access */
    public const ROLE_CLIENT = 'ROLE_CLIENT';

    /** Staff roles, as offered in the staff user form */
    public const ROLE_LABELS = [
        self::ROLE_ADMIN => 'Администратор',
        self::ROLE_SUPER_MANAGER => 'Супер менеджер',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank]
    #[Assert\Email]
    #[Assert\Length(max: 180)]
    private ?string $email = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    private ?string $name = null;

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

    /** Clients: contact phone */
    #[ORM\Column(length: 50, nullable: true)]
    #[Assert\Length(max: 50)]
    private ?string $phone = null;

    /** Clients: company name */
    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $company = null;

    public function __toString(): string
    {
        return (string) $this->name;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = null !== $email ? mb_strtolower(trim($email)) : null;

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $this->name = $name;

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

        return array_values(array_unique($roles));
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
     * The single assigned role (ROLE_ADMIN, ROLE_SUPER_MANAGER or ROLE_CLIENT).
     */
    public function getRole(): ?string
    {
        return $this->roles[0] ?? null;
    }

    public function setRole(?string $role): static
    {
        $this->roles = null !== $role ? [$role] : [];

        return $this;
    }

    public function getRoleLabel(): string
    {
        return self::ROLE_LABELS[$this->getRole()] ?? ($this->isClient() ? 'Клиент' : '—');
    }

    public function isClient(): bool
    {
        return self::ROLE_CLIENT === $this->getRole();
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): static
    {
        $this->phone = null !== $phone && '' !== trim($phone) ? trim($phone) : null;

        return $this;
    }

    public function getCompany(): ?string
    {
        return $this->company;
    }

    public function setCompany(?string $company): static
    {
        $this->company = null !== $company && '' !== trim($company) ? trim($company) : null;

        return $this;
    }

    /** "ООО «Ромашка» (Иван Петров)" for clients, the name otherwise */
    public function getDisplayName(): string
    {
        return null !== $this->company ? \sprintf('%s (%s)', $this->company, $this->name) : (string) $this->name;
    }

    public function isAdmin(): bool
    {
        return self::ROLE_ADMIN === $this->getRole();
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
}
