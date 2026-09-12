<?php

namespace App\Entity;

use App\Repository\PhotoReportRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Photos proving a client's placement (the banner is up), for the client's personal account.
 * Photos are stored outside public/ and served only after ClientFileVoter allows it.
 */
#[ORM\Entity(repositoryClass: PhotoReportRepository::class)]
#[ORM\HasLifecycleCallbacks]
class PhotoReport
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $client = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Укажите название', normalizer: 'trim')]
    #[Assert\Length(max: 255)]
    private ?string $title = null;

    /** Day the photos were taken */
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    #[Assert\NotNull(message: 'Укажите дату съёмки')]
    private ?\DateTimeImmutable $shotAt = null;

    /** Structure on the photos, if one */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Product $product = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $comment = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $uploadedBy = null;

    /**
     * @var Collection<int, PhotoReportPhoto>
     */
    #[ORM\OneToMany(targetEntity: PhotoReportPhoto::class, mappedBy: 'report', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $photos;

    public function __construct()
    {
        $this->photos = new ArrayCollection();
    }

    /** Storage folder of a client's report photos */
    public static function folder(User $client): string
    {
        return 'clients/'.$client->getId().'/photos';
    }

    public function getFolder(): string
    {
        return self::folder($this->client);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getClient(): ?User
    {
        return $this->client;
    }

    public function setClient(User $client): static
    {
        $this->client = $client;

        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getShotAt(): ?\DateTimeImmutable
    {
        return $this->shotAt;
    }

    public function setShotAt(?\DateTimeImmutable $shotAt): static
    {
        $this->shotAt = $shotAt;

        return $this;
    }

    public function getProduct(): ?Product
    {
        return $this->product;
    }

    public function setProduct(?Product $product): static
    {
        $this->product = $product;

        return $this;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

    public function setComment(?string $comment): static
    {
        $this->comment = $comment;

        return $this;
    }

    public function getUploadedBy(): ?User
    {
        return $this->uploadedBy;
    }

    public function setUploadedBy(?User $uploadedBy): static
    {
        $this->uploadedBy = $uploadedBy;

        return $this;
    }

    /**
     * @return Collection<int, PhotoReportPhoto>
     */
    public function getPhotos(): Collection
    {
        return $this->photos;
    }

    public function addPhoto(PhotoReportPhoto $photo): static
    {
        if (!$this->photos->contains($photo)) {
            $photo->setPosition(\count($this->photos));
            $this->photos->add($photo);
            $photo->setReport($this);
        }

        return $this;
    }

    public function removePhoto(PhotoReportPhoto $photo): static
    {
        $this->photos->removeElement($photo);

        return $this;
    }
}
