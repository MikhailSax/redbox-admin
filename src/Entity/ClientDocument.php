<?php

namespace App\Entity;

use App\Dto\StoredFile;
use App\Enum\ClientDocumentType;
use App\Repository\ClientDocumentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A file (contract, invoice, act…) uploaded for a client's personal account.
 * Stored outside public/ (PrivateFileStorage) and served only after ClientFileVoter allows it.
 */
#[ORM\Entity(repositoryClass: ClientDocumentRepository::class)]
#[ORM\HasLifecycleCallbacks]
class ClientDocument
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $client;

    #[ORM\Column(length: 20, enumType: ClientDocumentType::class)]
    private ClientDocumentType $type;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(normalizer: 'trim')]
    #[Assert\Length(max: 255)]
    private string $title;

    /** Name in the storage folder */
    #[ORM\Column(length: 255)]
    private string $fileName;

    /** Name of the uploaded file, used for downloads */
    #[ORM\Column(length: 255)]
    private string $originalName;

    #[ORM\Column(length: 100)]
    private string $mimeType;

    /** Bytes */
    #[ORM\Column]
    private int $size;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $comment;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $uploadedBy;

    public function __construct(User $client, ClientDocumentType $type, string $title, StoredFile $file, ?string $comment = null, ?User $uploadedBy = null)
    {
        $this->client = $client;
        $this->type = $type;
        $this->title = $title;
        $this->fileName = $file->fileName;
        $this->originalName = $file->originalName;
        $this->mimeType = $file->mimeType;
        $this->size = $file->size;
        $this->comment = $comment;
        $this->uploadedBy = $uploadedBy;
    }

    /** Storage folder of a client's documents */
    public static function folder(User $client): string
    {
        return 'clients/'.$client->getId().'/documents';
    }

    public function getFolder(): string
    {
        return self::folder($this->client);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getClient(): User
    {
        return $this->client;
    }

    public function getType(): ClientDocumentType
    {
        return $this->type;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getFileName(): string
    {
        return $this->fileName;
    }

    public function getOriginalName(): string
    {
        return $this->originalName;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function getSize(): int
    {
        return $this->size;
    }

    /** Opens in the browser instead of downloading */
    public function isViewable(): bool
    {
        return 'application/pdf' === $this->mimeType || str_starts_with($this->mimeType, 'image/');
    }

    public function getExtension(): string
    {
        return mb_strtolower(pathinfo($this->originalName, \PATHINFO_EXTENSION));
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

    public function getUploadedBy(): ?User
    {
        return $this->uploadedBy;
    }
}
