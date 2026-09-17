<?php

namespace App\Entity;

use App\Enum\ClientType;
use App\Repository\UserRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

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

    /** Why an account that signed up on the website gets nothing yet */
    public const UNVERIFIED_MESSAGE = 'Клиент не подтвердил почту: документы, медиапланы и платежи ему пока не привязываются. Подтвердите почту в карточке клиента, если сверили её с клиентом.';

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

    /** Clients: company name, or "ИП Иванов И. И." */
    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $company = null;

    /** Clients: a private person, an entrepreneur or a company; null for staff */
    #[ORM\Column(length: 20, nullable: true, enumType: ClientType::class)]
    private ?ClientType $clientType = null;

    /** Clients (ИП, company): ИНН, 12 or 10 digits */
    #[ORM\Column(length: 12, nullable: true)]
    private ?string $inn = null;

    /** Clients (company): КПП, 9 digits */
    #[ORM\Column(length: 9, nullable: true)]
    private ?string $kpp = null;

    /** Clients: ОГРН (company, 13 digits) or ОГРНИП (entrepreneur, 15 digits) */
    #[ORM\Column(length: 15, nullable: true)]
    private ?string $ogrn = null;

    /** Clients (ИП, company): legal address */
    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $legalAddress = null;

    /**
     * When the client proved the e-mail is theirs: the link from the e-mail, a password reset by e-mail,
     * or a manager who took the address from the client. Until then the account gets no documents, plans or payments.
     */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $emailVerifiedAt = null;

    public function __toString(): string
    {
        return (string) $this->name;
    }

    public function getEmailVerifiedAt(): ?\DateTimeImmutable
    {
        return $this->emailVerifiedAt;
    }

    public function isEmailVerified(): bool
    {
        return null !== $this->emailVerifiedAt;
    }

    /** Keeps the first confirmation date */
    public function markEmailVerified(\DateTimeImmutable $at): static
    {
        $this->emailVerifiedAt ??= $at;

        return $this;
    }

    public function setEmailVerifiedAt(?\DateTimeImmutable $emailVerifiedAt): static
    {
        $this->emailVerifiedAt = $emailVerifiedAt;

        return $this;
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

    /** The organisation for ИП and companies, the person's name for a private person */
    public function getClientTitle(): string
    {
        return $this->company ?? (string) $this->name;
    }

    public function getClientType(): ?ClientType
    {
        return $this->clientType;
    }

    public function setClientType(?ClientType $clientType): static
    {
        $this->clientType = $clientType;

        return $this;
    }

    public function getInn(): ?string
    {
        return $this->inn;
    }

    public function setInn(?string $inn): static
    {
        $this->inn = self::digits($inn);

        return $this;
    }

    public function getKpp(): ?string
    {
        return $this->kpp;
    }

    public function setKpp(?string $kpp): static
    {
        $this->kpp = self::digits($kpp);

        return $this;
    }

    public function getOgrn(): ?string
    {
        return $this->ogrn;
    }

    public function setOgrn(?string $ogrn): static
    {
        $this->ogrn = self::digits($ogrn);

        return $this;
    }

    public function getLegalAddress(): ?string
    {
        return $this->legalAddress;
    }

    public function setLegalAddress(?string $legalAddress): static
    {
        $this->legalAddress = null !== $legalAddress && '' !== trim($legalAddress) ? trim($legalAddress) : null;

        return $this;
    }

    /**
     * Drops the requisites the client's type doesn't have (the form keeps hidden fields filled when the type changes).
     */
    public function clearRequisitesOfOtherTypes(): static
    {
        if (!($this->clientType?->hasRequisites() ?? false)) {
            $this->company = $this->inn = $this->ogrn = $this->legalAddress = null;
        }
        if (ClientType::Legal !== $this->clientType) {
            $this->kpp = null;
        }

        return $this;
    }

    #[Assert\Callback]
    public function validateRequisites(ExecutionContextInterface $context): void
    {
        if (!$this->isClient()) {
            return;
        }
        if (null === $this->clientType) {
            $context->buildViolation('Выберите, кто клиент: физ. лицо, ИП или юр. лицо')->atPath('clientType')->addViolation();

            return;
        }
        if (!$this->clientType->hasRequisites()) {
            return;
        }

        if (null === $this->company) {
            $context->buildViolation(ClientType::Legal === $this->clientType ? 'Укажите название организации' : 'Укажите, как называется ИП')->atPath('company')->addViolation();
        }
        if (null === $this->inn) {
            $context->buildViolation('Укажите ИНН')->atPath('inn')->addViolation();
        } elseif (\strlen($this->inn) !== $this->clientType->innLength()) {
            $context->buildViolation(\sprintf('ИНН %s — %d цифр', ClientType::Legal === $this->clientType ? 'организации' : 'ИП', $this->clientType->innLength()))->atPath('inn')->addViolation();
        }
        if (null !== $this->ogrn && \strlen($this->ogrn) !== $this->clientType->ogrnLength()) {
            $context->buildViolation(\sprintf('%s — %d цифр', ClientType::Legal === $this->clientType ? 'ОГРН' : 'ОГРНИП', $this->clientType->ogrnLength()))->atPath('ogrn')->addViolation();
        }
        if (ClientType::Legal === $this->clientType && null !== $this->kpp && 9 !== \strlen($this->kpp)) {
            $context->buildViolation('КПП — 9 цифр')->atPath('kpp')->addViolation();
        }
    }

    /** "7701 234 567" → "7701234567"; null when nothing but spaces */
    private static function digits(?string $value): ?string
    {
        $value = null !== $value ? preg_replace('/\D+/', '', $value) : null;

        return null !== $value && '' !== $value ? $value : null;
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
