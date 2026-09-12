<?php

namespace App\Tests\Controller\Admin;

use App\Entity\ClientDocument;
use App\Entity\PhotoReport;
use App\Entity\User;
use App\Enum\ClientDocumentType;
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

        [$values, $uri] = $this->formValues($crawler, 'Добавить клиента');
        $values['client_form']['company'] = 'ООО «Ромашка»';
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
        self::assertTrue(static::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($client, 'client-password'));

        // listed with clients, not with staff
        $this->client->request('GET', '/admin/clients?q=ромашка', server: ['HTTP_X_LIVE_FILTER' => '1']);
        self::assertStringContainsString('<mark class="hl">Ромашка</mark>', $this->client->getResponse()->getContent());
        $this->client->request('GET', '/admin/users');
        self::assertSelectorTextNotContains('main', 'ivan@romashka.ru');
        $this->client->request('GET', '/admin/users/'.$client->getId().'/edit');
        self::assertResponseStatusCodeSame(404);
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
        $client = (new User())->setEmail($email)->setName('Клиент')->setRole(User::ROLE_CLIENT);
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
