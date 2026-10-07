<?php

namespace App\Service;

use App\Entity\Lead;
use App\Entity\Notification;
use App\Entity\PhotoReport;
use App\Entity\User;
use App\Enum\ClientDocumentType;
use App\Enum\LeadStatus;
use App\Enum\NotificationType;
use App\Repository\UserRepository;
use App\Twig\AdminExtension;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Who hears about what.
 *
 * Staff (every CRM user): a line in the bell for a new client, a confirmed e-mail and a request from the website;
 * an e-mail for the first two. The client: a line in the personal account for their requests and documents,
 * a welcome e-mail once the address is confirmed and an e-mail that a request came in.
 *
 * Each method flushes the notifications it adds; e-mails go out after that, and a failed one is only logged.
 */
class Notifications
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $users,
        private readonly MailSender $mail,
        private readonly UrlGeneratorInterface $urls,
        private readonly ClockInterface $clock,
        #[Autowire('%env(WEBSITE_URL)%')] private readonly string $websiteUrl,
    ) {
    }

    /** Signed up on the website: the e-mail is not confirmed yet */
    public function clientRegistered(User $client): void
    {
        $title = \sprintf('Новый клиент на сайте: %s', $client->getClientTitle());
        $this->toStaff(NotificationType::ClientRegistered, $title, $this->contacts($client), 'admin_client_show', ['id' => $client->getId()],
            'emails/staff_client_registered', ['client' => $client]);
    }

    /** Followed the link from the e-mail (or reset the password by one): documents and plans can be given to them now */
    public function clientEmailVerified(User $client): void
    {
        $title = \sprintf('Клиент подтвердил почту: %s', $client->getClientTitle());
        $this->toStaff(NotificationType::ClientEmailVerified, $title, $this->contacts($client), 'admin_client_show', ['id' => $client->getId()],
            'emails/staff_client_verified', ['client' => $client]);

        $this->toClient($client, NotificationType::Welcome, 'Добро пожаловать в личный кабинет',
            'Почта подтверждена. Здесь появятся медиапланы, счета, документы и фотоотчёты по вашим кампаниям.', '/account');
        $this->mail->send((new TemplatedEmail())
            ->to(new Address((string) $client->getEmail(), (string) $client->getName()))
            ->subject('Добро пожаловать в REDBOX')
            ->htmlTemplate('emails/welcome.html.twig')
            ->textTemplate('emails/welcome.txt.twig')
            ->context(['name' => $client->getName(), 'url' => $this->website('/account')]));
    }

    /** A request from the website's cart */
    public function leadReceived(Lead $lead): void
    {
        $title = \sprintf('Заявка №%d с сайта: %s', (int) $lead->getId(), $lead->getClientTitle());
        $body = \sprintf('%s · %s', $this->positions($lead->getItems()->count()), $lead->getPhone() ?? $lead->getEmail() ?? 'без контактов');
        $this->toStaff(NotificationType::LeadReceived, $title, $body, 'admin_lead_show', ['id' => $lead->getId()]);

        $client = $lead->getClient();
        if (null !== $client) {
            $this->toClient($client, NotificationType::LeadAccepted, \sprintf('Заявка №%d принята', (int) $lead->getId()),
                \sprintf('%s. Менеджер проверит даты и свяжется с вами.', $this->positions($lead->getItems()->count())), '/account/requests');
        }

        $email = $lead->getEmail() ?? $client?->getEmail();
        if (null === $email || '' === $email) {
            return;
        }
        $this->mail->send((new TemplatedEmail())
            ->to(new Address($email, (string) $lead->getContactName()))
            ->subject(\sprintf('Заявка №%d принята — REDBOX', (int) $lead->getId()))
            ->htmlTemplate('emails/lead_received.html.twig')
            ->textTemplate('emails/lead_received.txt.twig')
            ->context([
                'name' => $lead->getContactName(),
                'lead' => $lead,
                'url' => $this->website(null !== $client ? '/account/requests' : '/catalog'),
                'button' => null !== $client ? 'Мои заявки' : 'Каталог конструкций',
            ]));
    }

    /** A manager moved the request along: the client sees it in the account (only requests sent while signed in) */
    public function leadStatusChanged(Lead $lead, LeadStatus $before): void
    {
        $client = $lead->getClient();
        if (null === $client || $before === $lead->getStatus()) {
            return;
        }

        [$body, $path] = match ($lead->getStatus()) {
            LeadStatus::New => ['Заявка снова ждёт менеджера.', '/account/requests'],
            LeadStatus::InProgress => ['Менеджер взял заявку в работу и проверяет даты.', '/account/requests'],
            LeadStatus::Quoted => ['Медиаплан готов — он в разделе «Кампании».', '/account'],
            LeadStatus::Booked => ['Конструкции забронированы за вами.', '/account'],
            LeadStatus::Paid => ['Оплата получена. Спасибо!', '/account/payments'],
            LeadStatus::Rejected => ['Заявка закрыта. Если это ошибка, позвоните нам.', '/account/requests'],
        };
        $this->toClient($client, NotificationType::LeadStatusChanged,
            \sprintf('Заявка №%d: %s', (int) $lead->getId(), mb_strtolower($lead->getStatus()->label())), $body, $path);
    }

    public function documentsAdded(User $client, ClientDocumentType $type, int $count): void
    {
        $title = 1 === $count ? \sprintf('Новый документ: %s', mb_strtolower($type->label())) : \sprintf('Новые документы: %d', $count);
        $this->toClient($client, NotificationType::DocumentsAdded, $title, 'Документы в разделе «Счета и документы».', '/account/documents');
    }

    public function photoReportAdded(PhotoReport $report): void
    {
        $client = $report->getClient();
        if (null === $client) {
            return;
        }
        $this->toClient($client, NotificationType::PhotoReportAdded, \sprintf('Новый фотоотчёт: %s', $report->getTitle()),
            \sprintf('%d фото в разделе «Фотоотчёты».', $report->getPhotos()->count()), '/account/reports');
    }

    /**
     * @param array<string, mixed> $context
     */
    private function toStaff(NotificationType $type, string $title, ?string $body, string $route, array $parameters, ?string $template = null, array $context = []): void
    {
        $staff = $this->users->findStaff();
        if ([] === $staff) {
            return;
        }

        $now = $this->clock->now();
        foreach ($staff as $user) {
            $this->entityManager->persist(new Notification($user, $type, $title, $body, $this->urls->generate($route, $parameters), $now));
        }
        $this->entityManager->flush();

        if (null === $template) {
            return;
        }
        $this->mail->send((new TemplatedEmail())
            ->to(...array_map(static fn (User $user) => new Address((string) $user->getEmail(), (string) $user->getName()), $staff))
            ->subject($title)
            ->htmlTemplate($template.'.html.twig')
            ->textTemplate($template.'.txt.twig')
            ->context($context + ['name' => null, 'url' => $this->urls->generate($route, $parameters, UrlGeneratorInterface::ABSOLUTE_URL)]));
    }

    private function toClient(User $client, NotificationType $type, string $title, ?string $body, string $path): void
    {
        $this->entityManager->persist(new Notification($client, $type, $title, $body, $path, $this->clock->now()));
        $this->entityManager->flush();
    }

    private function contacts(User $client): string
    {
        return implode(' · ', array_filter([$client->getEmail(), $client->getPhone(), $client->getClientType()?->label()]));
    }

    private function positions(int $count): string
    {
        return AdminExtension::plural($count, 'позиция', 'позиции', 'позиций');
    }

    private function website(string $path): string
    {
        return rtrim($this->websiteUrl, '/').$path;
    }
}
