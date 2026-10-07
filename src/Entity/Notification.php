<?php

namespace App\Entity;

use App\Enum\NotificationType;
use App\Repository\NotificationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One line of a person's notifications: the bell of the CRM for staff, the feed of the personal account for clients.
 * Every recipient gets a copy of their own, so "read" is per person.
 */
#[ORM\Entity(repositoryClass: NotificationRepository::class)]
#[ORM\Index(name: 'IDX_NOTIFICATION_UNREAD', columns: ['recipient_id', 'read_at'])]
class Notification
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $readAt = null;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $recipient,

        #[ORM\Column(length: 40, enumType: NotificationType::class)]
        private NotificationType $type,

        #[ORM\Column(length: 255)]
        private string $title,

        #[ORM\Column(type: Types::TEXT, nullable: true)]
        private ?string $body,

        /** A path of the CRM for staff ("/admin/leads/12"), of the website for clients ("/account/requests") */
        #[ORM\Column(length: 255, nullable: true)]
        private ?string $link,

        #[ORM\Column]
        private \DateTimeImmutable $createdAt,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRecipient(): User
    {
        return $this->recipient;
    }

    public function getType(): NotificationType
    {
        return $this->type;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getBody(): ?string
    {
        return $this->body;
    }

    public function getLink(): ?string
    {
        return $this->link;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getReadAt(): ?\DateTimeImmutable
    {
        return $this->readAt;
    }

    public function isRead(): bool
    {
        return null !== $this->readAt;
    }

    public function markRead(\DateTimeImmutable $at): static
    {
        $this->readAt ??= $at;

        return $this;
    }
}
