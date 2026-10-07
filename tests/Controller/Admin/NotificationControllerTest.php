<?php

namespace App\Tests\Controller\Admin;

use App\Entity\Lead;
use App\Entity\Notification;
use App\Entity\User;
use App\Enum\NotificationType;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * The bell of the CRM's top bar, and what the client hears when a manager works their request or card.
 */
final class NotificationControllerTest extends AdminWebTestCase
{
    public function testBellShowsUnreadOpensAndMarksRead(): void
    {
        $lead = (new Lead())->setContactName('Иван')->setPhone('+7 900');
        $this->em->persist($lead);
        $this->em->flush();
        $mine = $this->notify($this->currentUser, 'Заявка №'.$lead->getId().' с сайта: Иван', '/admin/leads/'.$lead->getId());
        $this->notify($this->currentUser, 'Новый клиент на сайте: ООО Ромашка', '/admin/clients');
        $someoneElses = $this->notify($this->createUser('other@redbox.local', User::ROLE_AGENT), 'Чужое', '/admin');

        $crawler = $this->client->request('GET', '/admin');
        self::assertSelectorTextSame('[data-notifications-badge]', '2');
        self::assertCount(2, $crawler->filter('#notifications-menu li a'));
        self::assertSelectorTextContains('#notifications-menu', 'Новый клиент на сайте: ООО Ромашка');
        self::assertSelectorTextNotContains('#notifications-menu', 'Чужое');

        $this->client->request('GET', '/admin/notifications/unread');
        self::assertSame(['unread' => 2], json_decode((string) $this->client->getResponse()->getContent(), true));

        $this->client->request('GET', '/admin/notifications/'.$mine->getId());
        self::assertResponseRedirects('/admin/leads/'.$lead->getId());
        self::assertNotNull($this->em->find(Notification::class, $mine->getId())->getReadAt());

        $this->client->request('GET', '/admin/notifications/'.$someoneElses->getId());
        self::assertResponseStatusCodeSame(404);

        $crawler = $this->client->request('GET', '/admin/notifications');
        self::assertSelectorTextSame('[data-notifications-badge]', '1');
        $this->client->submit($crawler->filter('main')->selectButton('Прочитать все')->form());
        self::assertResponseRedirects('/admin/notifications');
        $this->client->followRedirect();
        self::assertSelectorExists('[data-notifications-badge].hidden');
        self::assertSelectorNotExists('main form[action="/admin/notifications/read"]');
    }

    public function testClientHearsAboutTheirRequestDocumentsAndReports(): void
    {
        $client = $this->createClientCard();
        $lead = (new Lead())->setContactName('Иван')->setPhone('+7 900')->setClient($client);
        $this->em->persist($lead);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/admin/leads/'.$lead->getId());
        $this->client->submit($crawler->selectButton('Сохранить')->form(['status' => 'in_progress']));
        // saving the card again without a new status is not news
        $crawler = $this->client->request('GET', '/admin/leads/'.$lead->getId());
        $this->client->submit($crawler->selectButton('Сохранить')->form(['note' => 'Созвонились']));

        $crawler = $this->client->request('GET', '/admin/clients/'.$client->getId());
        [$values, $uri] = $this->formValues($crawler, 'Загрузить');
        $values['client_document_form']['type'] = 'invoice';
        $this->submit($uri, $values, ['client_document_form' => ['files' => [$this->pdf('schet.pdf')]]]);

        $feed = $this->em->getRepository(Notification::class)->findBy(['recipient' => $client], ['id' => 'ASC']);
        self::assertSame([NotificationType::LeadStatusChanged, NotificationType::DocumentsAdded], array_map(static fn (Notification $n) => $n->getType(), $feed));
        self::assertSame(\sprintf('Заявка №%d: в работе', $lead->getId()), $feed[0]->getTitle());
        self::assertSame('/account/requests', $feed[0]->getLink());
        self::assertSame('Новый документ: счёт', $feed[1]->getTitle());
        self::assertSame('/account/documents', $feed[1]->getLink());
        // the staff's own bell stays quiet: this is the client's news
        self::assertSame([], $this->em->getRepository(Notification::class)->findBy(['recipient' => $this->currentUser]));
    }

    public function testManagerConfirmingTheEmailWelcomesTheClient(): void
    {
        $client = $this->createClientCard()->setEmailVerifiedAt(null);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/admin/clients/'.$client->getId());
        $this->client->submit($crawler->filter('form[action$="/verify-email"]')->form());

        self::assertEmailCount(2);
        [$staffMail, $welcome] = self::getMailerMessages();
        self::assertEmailSubjectContains($staffMail, 'Клиент подтвердил почту');
        self::assertEmailAddressContains($welcome, 'to', 'client@romashka.ru');
        self::assertSame(NotificationType::Welcome, $this->em->getRepository(Notification::class)->findOneBy(['recipient' => $client])?->getType());
    }

    private function notify(User $recipient, string $title, string $link): Notification
    {
        $notification = new Notification($recipient, NotificationType::LeadReceived, $title, null, $link, new \DateTimeImmutable());
        $this->em->persist($notification);
        $this->em->flush();

        return $notification;
    }

    private function pdf(string $name): UploadedFile
    {
        $path = sys_get_temp_dir().'/'.uniqid('doc', true).'.pdf';
        file_put_contents($path, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");

        return new UploadedFile($path, $name, 'application/pdf', null, true);
    }
}
