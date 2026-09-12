<?php

namespace App\Entity;

use App\Dto\StoredFile;
use Doctrine\ORM\Mapping as ORM;

/**
 * One photo of a photo report; the file lives in PhotoReport::folder() of the report's client.
 */
#[ORM\Entity]
class PhotoReportPhoto
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'photos')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?PhotoReport $report = null;

    #[ORM\Column(length: 255)]
    private string $fileName;

    #[ORM\Column(length: 255)]
    private string $originalName;

    #[ORM\Column(length: 100)]
    private string $mimeType;

    #[ORM\Column]
    private int $position = 0;

    public function __construct(StoredFile $file)
    {
        $this->fileName = $file->fileName;
        $this->originalName = $file->originalName;
        $this->mimeType = $file->mimeType;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getReport(): ?PhotoReport
    {
        return $this->report;
    }

    public function setReport(?PhotoReport $report): static
    {
        $this->report = $report;

        return $this;
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
