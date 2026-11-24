<?php

namespace App\Tests\Controller\Api;

use App\Entity\Lead;
use App\Entity\Notification;
use App\Entity\User;
use App\Enum\LeadStatus;
use App\Tests\Controller\Admin\AdminWebTestCase;

/**
 * The website's feedback form: a name, a phone and a message become a request of the CRM marked as feedback,
 * shown apart from orders of structures, and the staff get a notification.
 */
final class ApiFeedbackTest extends AdminWebTestCase
{
    public function testFeedbackLandsInTheRequests(): void
    {
        $response = $this->post([
            'name' => '  Ольга  ',
            'phone' => '+7 900 555-44-33',
            'message' => "Сколько стоит щит на Ленина?\nПерезвоните после обеда.",
            'email' => 'olga@example.com',
            'agree' => true,
        ]);
        self::assertSame(201, $response['status']);
        self::assertStringContainsString('Сообщение отправлено', $response['body']['message']);

        $lead = $this->em->getRepository(Lead::class)->find($response['body']['id']);
        self::assertTrue($lead->isFeedback());
        self::assertFalse($lead->isFromSite()); // not an order of structures
        self::assertSame(LeadStatus::New, $lead->getStatus());
        self::assertSame('Ольга', $lead->getContactName());
        self::assertSame('+7 900 555-44-33', $lead->getPhone());
        self::assertStringContainsString('щит на Ленина', (string) $lead->getComment());
        self::assertCount(0, $lead->getItems());

        // the staff's bell
        $notifications = $this->em->getRepository(Notification::class)->findBy(['recipient' => $this->currentUser]);
        self::assertCount(1, $notifications);
        self::assertStringContainsString('Обратная связь №'.$lead->getId(), $notifications[0]->getTitle());

        // the list: marked, with the message, and on its own tab
        $crawler = $this->client->request('GET', '/admin/leads');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-lead-feedback]');
        self::assertSelectorTextContains('#lead-results', 'Сколько стоит щит на Ленина?');
        self::assertSelectorTextContains('#lead-results', 'обратная связь');
        self::assertSelectorTextContains('[data-lead-kind="feedback"]', '1');

        $this->em->persist((new Lead())->setSource(Lead::SOURCE_SITE)->setContactName('Иван')->setPhone('+7 900 111-22-33'));
        $this->em->flush();
        $crawler = $this->client->request('GET', '/admin/leads?kind=feedback');
        self::assertCount(1, $crawler->filter('#lead-results tbody tr [data-lead-feedback]'));
        self::assertStringNotContainsString('Иван', $crawler->filter('#lead-results tbody')->text());
        $crawler = $this->client->request('GET', '/admin/leads?kind=orders');
        self::assertSelectorNotExists('[data-lead-feedback]');
        self::assertStringContainsString('Иван', $crawler->filter('#lead-results tbody')->text());

        // the card shows the message instead of the structures
        $this->client->request('GET', '/admin/leads/'.$lead->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-lead-message]', 'Перезвоните после обеда.');
    }

    public function testFeedbackValidation(): void
    {
        $empty = $this->post(['agree' => true]);
        self::assertSame(422, $empty['status']);
        self::assertSame(['name', 'phone', 'message'], array_column($empty['body']['violations'], 'field'));

        $noConsent = $this->post(['name' => 'Ольга', 'phone' => '+7 900 555-44-33', 'message' => 'Здравствуйте']);
        self::assertSame(422, $noConsent['status']);
        self::assertStringContainsString('согласие', $noConsent['body']['violations'][0]['message']);

        // a bot fills the hidden field
        $bot = $this->post(['name' => 'Bot', 'phone' => '+7 900 555-44-33', 'message' => 'spam', 'agree' => true, 'website' => 'http://spam']);
        self::assertSame(422, $bot['status']);

        self::assertSame(0, $this->em->getRepository(Lead::class)->count([]));
    }

    public function testAClientSignedInSeesItInTheAccount(): void
    {
        $client = $this->createUser('olga@example.com', User::ROLE_CLIENT);
        $login = $this->post(['email' => 'olga@example.com', 'password' => self::PASSWORD], '/api/v1/auth/login');
        $token = $login['body']['token'];

        $this->post(['name' => 'Ольга', 'phone' => '+7 900 555-44-33', 'message' => 'Перезвоните', 'agree' => true], token: $token);
        $lead = $this->em->getRepository(Lead::class)->findOneBy([]);
        self::assertSame($client->getId(), $lead->getClient()?->getId());

        $this->client->request('GET', '/api/v1/me/requests', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        $requests = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('feedback', $requests[0]['kind']);
        self::assertSame([], $requests[0]['items']);
    }

    /** @return array{status: int, body: array<string, mixed>} */
    private function post(array $payload, string $url = '/api/v1/feedback', ?string $token = null): array
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        if (null !== $token) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        }
        $this->client->request('POST', $url, content: json_encode($payload, \JSON_THROW_ON_ERROR), server: $server);
        $response = $this->client->getResponse();

        return ['status' => $response->getStatusCode(), 'body' => json_decode((string) $response->getContent(), true) ?? []];
    }
}
