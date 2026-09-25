<?php

namespace App\Tests\Controller\Admin;

use App\Entity\ClientDocument;
use App\Entity\PhotoReport;
use App\Entity\User;
use App\Enum\ClientDocumentType;
use App\Enum\ClientType;
use App\Security\ClientFileVoter;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;

final class ClientControllerTest extends AdminWebTestCase
{
    private string $storageDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storageDir = static::getContainer()->getParameter('app.private_storage_dir');
    }

    public function testCreateClientAccount(): void
    {
        $crawler = $this->client->request('GET', '/admin/clients/new');
        self::assertResponseIsSuccessful();

        self::assertSame('individual', $crawler->filter('input[name="client_form[clientType]"]:checked')->attr('value')); // a private person by default

        [$values, $uri] = $this->formValues($crawler, 'Добавить клиента');
        $values['client_form']['clientType'] = 'legal';
        $values['client_form']['company'] = 'ООО «Ромашка»';
        $values['client_form']['inn'] = '7701 234 567';
        $values['client_form']['kpp'] = '770101001';
        $values['client_form']['ogrn'] = '1027700000001';
        $values['client_form']['legalAddress'] = 'г. Улан-Удэ, ул. Ленина, 1';
        $values['client_form']['name'] = 'Иван Петров';
        $values['client_form']['email'] = 'Ivan@Romashka.ru';
        $values['client_form']['phone'] = '+7 900 111-22-33';
        $values['client_form']['plainPassword'] = ['first' => 'client-password', 'second' => 'client-password'];
        $this->submit($uri, $values);

        $client = $this->em->getRepository(User::class)->findOneBy(['email' => 'ivan@romashka.ru']);
        self::assertResponseRedirects('/admin/clients/'.$client->getId());
        self::assertTrue($client->isClient());
        self::assertSame(['ROLE_CLIENT', 'ROLE_USER'], $client->getRoles());
        self::assertSame('ООО «Ромашка» (Иван Петров)', $client->getDisplayName());
        self::assertSame(ClientType::Legal, $client->getClientType());
        self::assertSame(['7701234567', '770101001', '1027700000001', 'г. Улан-Удэ, ул. Ленина, 1'], [$client->getInn(), $client->getKpp(), $client->getOgrn(), $client->getLegalAddress()]);
        self::assertTrue(static::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($client, 'client-password'));

        // listed with clients, not with staff
        $this->client->request('GET', '/admin/clients?q=ромашка', server: ['HTTP_X_LIVE_FILTER' => '1']);
        self::assertStringContainsString('<mark class="hl">Ромашка</mark>', $this->client->getResponse()->getContent());
        $this->client->request('GET', '/admin/users');
        self::assertSelectorTextNotContains('main', 'ivan@romashka.ru');
        $this->client->request('GET', '/admin/users/'.$client->getId().'/edit');
        self::assertResponseStatusCodeSame(404);
    }

    public function testClientCardNeedsNeitherEmailNorPhone(): void
    {
        $crawler = $this->client->request('GET', '/admin/clients/new');
        [$values, $uri] = $this->formValues($crawler, 'Добавить клиента');
        $values['client_form']['clientType'] = 'legal';
        $values['client_form']['company'] = 'ТЦ ВОСТОК ООО';
        $values['client_form']['inn'] = '0323340212';
        $values['client_form']['name'] = '';
        $values['client_form']['email'] = '';
        $values['client_form']['phone'] = '';
        $this->submit($uri, $values);

        $client = $this->em->getRepository(User::class)->findOneBy(['inn' => '0323340212']);
        self::assertResponseRedirects('/admin/clients/'.$client->getId());
        self::assertNull($client->getEmail());
        self::assertNull($client->getName());
        self::assertSame('ТЦ ВОСТОК ООО', $client->getDisplayName());
        self::assertTrue($client->isEmailVerified()); // made by a manager: can be picked for plans and bookings right away

        $this->client->request('GET', '/admin/clients?q=восток', server: ['HTTP_X_LIVE_FILTER' => '1']);
        self::assertSelectorTextContains('tbody', 'без почты');

        // a private person still needs a full name
        $crawler = $this->client->request('GET', '/admin/clients/new');
        [$values, $uri] = $this->formValues($crawler, 'Добавить клиента');
        $values['client_form']['name'] = '';
        $values['client_form']['email'] = '';
        $this->submit($uri, $values);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'Укажите ФИО');
    }

    public function testRequisitesDependOnTheClientType(): void
    {
        $crawler = $this->client->request('GET', '/admin/clients/new');
        // the requisites and the КПП show only for their types (assets/admin/dependent-fields.js)
        self::assertSame('entrepreneur legal', $crawler->filter('fieldset[data-visible-when="client_form_clientType"]')->attr('data-visible-values'));
        self::assertCount(1, $crawler->filter('[data-visible-values="legal"] input[name="client_form[kpp]"]'));

        [$values, $uri] = $this->formValues($crawler, 'Добавить клиента');
        $values['client_form']['name'] = 'Пётр Иванов';
        $values['client_form']['email'] = 'ip@example.com';

        // a company needs its name and a 10-digit ИНН
        $values['client_form']['clientType'] = 'legal';
        $this->submit($uri, $values);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'Укажите название организации');
        self::assertSelectorTextContains('main', 'Укажите ИНН');

        // an entrepreneur: 12 digits of ИНН, 15 of ОГРНИП
        $values['client_form']['clientType'] = 'entrepreneur';
        $values['client_form']['company'] = 'ИП Иванов П. С.';
        $values['client_form']['inn'] = '7701234567';
        $values['client_form']['ogrn'] = '1027700000001';
        $values['client_form']['kpp'] = '770101001';
        $this->submit($uri, $values);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'ИНН ИП — 12 цифр');
        self::assertSelectorTextContains('main', 'ОГРНИП — 15 цифр');

        $values['client_form']['inn'] = '380102345678';
        $values['client_form']['ogrn'] = '304380100000012';
        $this->submit($uri, $values);
        self::assertResponseRedirects();
        $client = $this->em->getRepository(User::class)->findOneBy(['email' => 'ip@example.com']);
        self::assertSame(ClientType::Entrepreneur, $client->getClientType());
        self::assertSame('ИП Иванов П. С.', $client->getClientTitle());
        self::assertNull($client->getKpp()); // an entrepreneur has no КПП: the hidden field is dropped

        // switched to a private person: the requisites go
        $crawler = $this->client->request('GET', '/admin/clients/'.$client->getId());
        [$values, $uri] = $this->formValues($crawler, 'Сохранить');
        $values['client_form']['clientType'] = 'individual';
        $this->submit($uri, $values);
        self::assertResponseRedirects();
        $this->em->clear();
        $client = $this->em->find(User::class, $client->getId());
        self::assertSame([ClientType::Individual, null, null, null], [$client->getClientType(), $client->getCompany(), $client->getInn(), $client->getOgrn()]);
        self::assertSame('Пётр Иванов', $client->getClientTitle());

        // the list filters by type and finds by ИНН
        $this->createUser('company@example.com', User::ROLE_CLIENT)->setClientType(ClientType::Legal)->setCompany('ООО «Вектор»')->setInn('0323123456');
        $this->em->flush();
        $crawler = $this->client->request('GET', '/admin/clients?type=legal');
        self::assertCount(1, $crawler->filter('tbody tr'));
        self::assertSelectorTextContains('tbody', 'ООО «Вектор»');
        self::assertSelectorTextContains('tbody', 'Юр. лицо · ИНН 0323123456');
        self::assertSelectorTextContains('main', 'Физ. лицо 1');
        $this->client->request('GET', '/admin/clients?q=0323123');
        self::assertSelectorTextContains('tbody', 'ООО «Вектор»');
    }

    public function testClientWithoutPasswordGetsARandomOne(): void
    {
        $crawler = $this->client->request('GET', '/admin/clients/new');
        [$values, $uri] = $this->formValues($crawler, 'Добавить клиента');
        $values['client_form']['name'] = 'Мария';
        $values['client_form']['email'] = 'maria@example.com';
        $this->submit($uri, $values);

        $client = $this->em->getRepository(User::class)->findOneBy(['email' => 'maria@example.com']);
        self::assertNotEmpty($client->getPassword());
        self::assertFalse(static::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($client, ''));
    }

    public function testClientCannotSignInToTheCrm(): void
    {
        $this->client->request('GET', '/logout');
        $this->createClientAccount('client@example.com', 'client-password');

        $crawler = $this->client->request('GET', '/login');
        $form = $crawler->selectButton('Войти')->form(['_username' => 'client@example.com', '_password' => 'client-password']);
        $this->submit($form->getUri(), $form->getPhpValues());
        $this->client->followRedirect();

        self::assertSelectorTextContains('main, body', 'Вход в CRM только для сотрудников');
        $this->client->request('GET', '/admin');
        self::assertResponseRedirects('/login');
    }

    public function testUploadDownloadAndDeleteDocuments(): void
    {
        $client = $this->createClientAccount('client@example.com');

        $crawler = $this->client->request('GET', '/admin/clients/'.$client->getId());
        [$values, $uri] = $this->formValues($crawler, 'Загрузить');
        $values['client_document_form']['type'] = 'contract';
        $values['client_document_form']['title'] = 'Договор № 15';
        $this->submit($uri, $values, ['client_document_form' => ['files' => [$this->pdf('dogovor.pdf')]]]);

        self::assertResponseRedirects('/admin/clients/'.$client->getId().'#documents');
        $document = $this->em->getRepository(ClientDocument::class)->findOneBy([]);
        self::assertSame('Договор № 15', $document->getTitle());
        self::assertSame(ClientDocumentType::Contract, $document->getType());
        self::assertSame('dogovor.pdf', $document->getOriginalName());
        self::assertSame('application/pdf', $document->getMimeType());
        self::assertSame('me@redbox.local', $document->getUploadedBy()?->getEmail());

        // kept outside public/, served by the controller
        $path = $this->storageDir.'/clients/'.$client->getId().'/documents/'.$document->getFileName();
        self::assertFileExists($path);
        self::assertStringNotContainsString('public', str_replace('\\', '/', $path));

        $this->client->request('GET', '/admin/clients/documents/'.$document->getId());
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('attachment; filename=dogovor.pdf', $this->client->getResponse()->headers->get('Content-Disposition'));
        $this->client->request('GET', '/admin/clients/documents/'.$document->getId().'?inline=1');
        self::assertStringStartsWith('inline', $this->client->getResponse()->headers->get('Content-Disposition'));

        $crawler = $this->client->request('GET', '/admin/clients/'.$client->getId());
        self::assertSelectorTextContains('[data-tab="documents"]', '1');
        self::assertSelectorTextContains('#tab-documents', 'Договор № 15');
        $this->submitPostForm($crawler, 'form[action="/admin/clients/documents/'.$document->getId().'/delete"]');
        self::assertResponseRedirects();
        self::assertFileDoesNotExist($path);
        self::assertSame(0, $this->em->getRepository(ClientDocument::class)->count([]));
    }

    public function testSeveralFilesAreNamedAfterThemselves(): void
    {
        $client = $this->createClientAccount('client@example.com');

        $crawler = $this->client->request('GET', '/admin/clients/'.$client->getId());
        [$values, $uri] = $this->formValues($crawler, 'Загрузить');
        $values['client_document_form']['type'] = 'invoice';
        $this->submit($uri, $values, ['client_document_form' => ['files' => [$this->pdf('schet-1.pdf'), $this->pdf('schet-2.pdf')]]]);

        $titles = array_map(static fn (ClientDocument $d) => $d->getTitle(), $this->em->getRepository(ClientDocument::class)->findBy([], ['id' => 'ASC']));
        self::assertSame(['schet-1', 'schet-2'], $titles);
    }

    public function testDocumentTypeIsChecked(): void
    {
        $client = $this->createClientAccount('client@example.com');
        $file = sys_get_temp_dir().'/script.php';
        file_put_contents($file, '<?php echo 1;');

        $crawler = $this->client->request('GET', '/admin/clients/'.$client->getId());
        [$values, $uri] = $this->formValues($crawler, 'Загрузить');
        $this->submit($uri, $values, ['client_document_form' => ['files' => [new UploadedFile($file, 'script.php', 'text/x-php', null, true)]]]);

        $this->client->followRedirect();
        self::assertSelectorTextContains('[role=alert]', 'PDF, Word, Excel, ZIP или изображение');
        self::assertSame(0, $this->em->getRepository(ClientDocument::class)->count([]));
    }

    public function testPhotoReport(): void
    {
        $client = $this->createClientAccount('client@example.com');

        $crawler = $this->client->request('GET', '/admin/clients/'.$client->getId());
        [$values, $uri] = $this->formValues($crawler, 'Добавить фотоотчёт');
        $values['photo_report_form']['title'] = 'Монтаж баннера';
        $values['photo_report_form']['shotAt'] = '2026-09-05';
        $this->submit($uri, $values, ['photo_report_form' => ['photos' => [$this->makeImage('a.png'), $this->makeImage('b.png')]]]);

        self::assertResponseRedirects('/admin/clients/'.$client->getId().'#reports');
        $report = $this->em->getRepository(PhotoReport::class)->findOneBy([]);
        self::assertSame('Монтаж баннера', $report->getTitle());
        self::assertCount(2, $report->getPhotos());
        $photo = $report->getPhotos()->first();

        $crawler = $this->client->request('GET', '/admin/clients/'.$client->getId());
        self::assertCount(2, $crawler->filter('#tab-reports img'));
        $this->client->request('GET', '/admin/clients/photos/'.$photo->getId());
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'image/png');

        $this->submitPostForm($crawler, 'form[action="/admin/clients/reports/'.$report->getId().'/delete"]');
        self::assertSame(0, $this->em->getRepository(PhotoReport::class)->count([]));
        self::assertFileDoesNotExist($this->storageDir.'/clients/'.$client->getId().'/photos/'.$photo->getFileName());
    }

    public function testPhotoReportNeedsPhotos(): void
    {
        $client = $this->createClientAccount('client@example.com');

        $crawler = $this->client->request('GET', '/admin/clients/'.$client->getId());
        [$values, $uri] = $this->formValues($crawler, 'Добавить фотоотчёт');
        $this->submit($uri, $values);

        $this->client->followRedirect();
        self::assertSelectorTextContains('[role=alert]', 'Добавьте хотя бы одно фото');
    }

    public function testDeletingAClientRemovesTheirFiles(): void
    {
        $client = $this->createClientAccount('client@example.com');
        $crawler = $this->client->request('GET', '/admin/clients/'.$client->getId());
        [$values, $uri] = $this->formValues($crawler, 'Загрузить');
        $this->submit($uri, $values, ['client_document_form' => ['files' => [$this->pdf('dogovor.pdf')]]]);
        self::assertDirectoryExists($this->storageDir.'/clients/'.$client->getId());

        $crawler = $this->client->request('GET', '/admin/clients/'.$client->getId());
        $this->submitPostForm($crawler, 'form[action="/admin/clients/'.$client->getId().'/delete"]');

        self::assertResponseRedirects('/admin/clients');
        self::assertDirectoryDoesNotExist($this->storageDir.'/clients/'.$client->getId());
        self::assertSame(0, $this->em->getRepository(ClientDocument::class)->count([]));
    }

    public function testAClientSeesOnlyTheirOwnFiles(): void
    {
        $owner = $this->createClientAccount('owner@example.com');
        $other = $this->createClientAccount('other@example.com');
        $manager = $this->createUser('manager@redbox.local', User::ROLE_SUPER_MANAGER);
        $document = new ClientDocument($owner, ClientDocumentType::Act, 'Акт', new \App\Dto\StoredFile('akt.pdf', 'akt.pdf', 'application/pdf', 10));
        $this->em->persist($document);
        $this->em->flush();

        $decide = fn (User $user) => static::getContainer()->get(AccessDecisionManagerInterface::class)
            ->decide(new UsernamePasswordToken($user, 'main', $user->getRoles()), [ClientFileVoter::VIEW], $document);

        self::assertTrue($decide($owner));
        self::assertFalse($decide($other));
        self::assertTrue($decide($manager));
    }

    private function createClientAccount(string $email, string $password = 'client-password'): User
    {
        $client = (new User())->setEmail($email)->setName('Клиент')->setRole(User::ROLE_CLIENT)->setEmailVerifiedAt(new \DateTimeImmutable());
        $client->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($client, $password));
        $this->em->persist($client);
        $this->em->flush();

        return $client;
    }

    private function pdf(string $name): UploadedFile
    {
        $path = sys_get_temp_dir().'/'.uniqid('doc', true).'.pdf';
        file_put_contents($path, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");

        return new UploadedFile($path, $name, 'application/pdf', null, true);
    }
}
