<?php

namespace App\Entity;

use App\Repository\ProductSidePhotoRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProductSidePhotoRepository::class)]
#[ORM\HasLifecycleCallbacks]
class ProductSidePhoto
{
    use TimestampableTrait;

    /** Folder inside public/uploads */
    public const UPLOAD_FOLDER = 'sides';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'photos')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?ProductSide $side = null;

    /**
     * Stored file name inside the side photos directory.
     */
    #[ORM\Column(length: 255)]
    private string $filename;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $originalName;

    #[ORM\Column]
    private int $position = 0;

    public function __construct(string $filename, ?string $originalName = null)
    {
        $this->filename = $filename;
        $this->originalName = $originalName;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSide(): ?ProductSide
    {
        return $this->side;
    }

    public function setSide(?ProductSide $side): static
    {
        $this->side = $side;

        return $this;
    }

    public function getFilename(): string
    {
        return $this->filename;
    }

    /**
     * Public path for asset(), e.g. "uploads/sides/front-1a2b3c.png".
     */
    public function getWebPath(): string
    {
        return 'uploads/'.self::UPLOAD_FOLDER.'/'.$this->filename;
    }

    public function getOriginalName(): ?string
    {
        return $this->originalName;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }
}
