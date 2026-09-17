<?php

namespace App\Tests\Controller\Api;

use App\Dto\StoredFile;
use App\Entity\Category;
use App\Entity\ClientDocument;
use App\Entity\District;
use App\Entity\Lead;
use App\Entity\LeadItem;
use App\Entity\MediaPlan;
use App\Entity\MediaPlanItem;
use App\Entity\Payment;
use App\Entity\PhotoReport;
use App\Entity\PhotoReportPhoto;
use App\Entity\Product;
use App\Entity\ProductSide;
use App\Entity\ProductType;
use App\Entity\RefreshToken;
use App\Entity\User;
use App\Enum\ClientDocumentType;
use App\Enum\ClientType;
use App\Tests\Controller\Admin\AdminWebTestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * The website's personal account: JWT sign-in, refresh, sign-up and the client's own data.
 */
final class ApiAccountTest extends AdminWebTestCase
{
    protected ?string $loginAs = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->em->createQuery(\sprintf('DELETE FROM %s t', RefreshToken::class))->execute();
    }

    public function testClientSignsInRefreshesAndSignsOut(): void
    {
        $this->client('anna@citypark.ru');

        $login = $this->call('POST', '/api/v1/auth/login', ['email' => 'anna@citypark.ru', 'password' => self::PASSWORD]);
        self::assertSame(200, $login['status']);
        self::assertNotEmpty($login['body']['token']);
        self::assertNotEmpty($login['body']['refresh_token']);

        $me = $this->call('GET', '/api/v1/me', token: $login['body']['token']);
        self::assertSame(200, $me['status']);
        self::assertSame('anna@citypark.ru', $me['body']['email']);
        self::assertSame('ООО «Ситипарк»', $me['body']['title']);

        $refreshed = $this->call('POST', '/api/v1/auth/refresh', ['refresh_token' => $login['body']['refresh_token']]);
        self::assertSame(200, $refreshed['status']);
        self::assertSame(200, $this->call('GET', '/api/v1/me', token: $refreshed['body']['token'])['status']);
        // stored hashed: a copy of the table is no use for signing in
        self::assertNull($this->em->getRepository(RefreshToken::class)->findOneBy(['refreshToken' => $login['body']['refresh_token']]));

        $logout = $this->call('POST', '/api/v1/auth/logout', ['refresh_token' => $refreshed['body']['refresh_token']], $refreshed['body']['token']);
        self::assertSame(200, $logout['status']);
        self::assertSame(401, $this->call('POST', '/api/v1/auth/refresh', ['refresh_token' => $refreshed['body']['refresh_token']])['status']);
    }

    public function testSignInIsRefusedToStrangersAndStaff(): void
    {
        $this->client('anna@citypark.ru');
        $this->createUser('manager@redbox.local', User::ROLE_SUPER_MANAGER);

        $wrong = $this->call('POST', '/api/v1/auth/login', ['email' => 'anna@citypark.ru', 'password' => 'not-the-password']);
        self::assertSame(401, $wrong['status']);
        self::assertSame(['error' => 'invalid_credentials', 'message' => 'Неверная почта или пароль'], $wrong['body']);

        $staff = $this->call('POST', '/api/v1/auth/login', ['email' => 'manager@redbox.local', 'password' => self::PASSWORD]);
        self::assertSame(403, $staff['status']);
        self::assertSame('not_a_client', $staff['body']['error']);

        self::assertSame('unauthorized', $this->call('GET', '/api/v1/me')['body']['error']);
        self::assertSame('token_invalid', $this->call('GET', '/api/v1/me', token: 'not.a.jwt')['body']['error']);
        // the public catalogue still needs nothing
        self::assertSame(200, $this->call('GET', '/api/v1/filters')['status']);
    }

    public function testLoginIsThrottled(): void
    {
        $this->client('anna@citypark.ru');
        for ($i = 0; $i < 5; ++$i) {
            $this->call('POST', '/api/v1/auth/login', ['email' => 'anna@citypark.ru', 'password' => 'guess-'.$i]);
        }

        $blocked = $this->call('POST', '/api/v1/auth/login', ['email' => 'anna@citypark.ru', 'password' => self::PASSWORD]);
        self::assertSame(429, $blocked['status']);
        self::assertStringContainsString('Слишком много попыток', $blocked['body']['message']);
    }

    public function testRegistrationCreatesAClientAndSignsItIn(): void
    {
        $response = $this->call('POST', '/api/v1/auth/register', [
            'name' => 'Анна Семёнова',
            'email' => 'A.Semenova@CityPark.ru',
            'phone' => '+7 983 000-00-00',
            'password' => 'long-enough-password',
            'company' => 'ООО «Ситипарк»',
            'inn' => '0326 000 000',
            'agree' => true,
        ]);

        self::assertSame(201, $response['status'], json_encode($response['body'], \JSON_UNESCAPED_UNICODE));
        self::assertNotEmpty($response['body']['refresh_token']);
        self::assertSame('legal', $response['body']['user']['clientType']);
        self::assertSame(200, $this->call('GET', '/api/v1/me', token: $response['body']['token'])['status']);

        $user = $this->em->getRepository(User::class)->findOneBy(['email' => 'a.semenova@citypark.ru']);
        self::assertTrue($user->isClient());
        self::assertSame(ClientType::Legal, $user->getClientType());
        self::assertSame('0326000000', $user->getInn());

        $again = $this->call('POST', '/api/v1/auth/register', ['name' => 'Кто-то', 'email' => 'a.semenova@citypark.ru', 'phone' => '1', 'password' => 'long-enough-password', 'agree' => true]);
        self::assertSame(422, $again['status']);
        self::assertSame(['email'], array_column($again['body']['violations'], 'field'));

        $invalid = $this->call('POST', '/api/v1/auth/register', ['name' => 'ИП', 'email' => 'ip@example.ru', 'phone' => '1', 'password' => 'short', 'inn' => '123456789012']);
        self::assertSame(422, $invalid['status']);
        self::assertEqualsCanonicalizing(['password', 'agree'], array_column($invalid['body']['violations'], 'field'));

        // ИНН of an entrepreneur without the name of the ИП
        $noCompany = $this->call('POST', '/api/v1/auth/register', ['name' => 'Иван', 'email' => 'ip@example.ru', 'phone' => '1', 'password' => 'long-enough-password', 'inn' => '123456789012', 'agree' => true]);
        self::assertSame(['company'], array_column($noCompany['body']['violations'], 'field'));
    }

    public function testAccountShowsOnlyTheClientsOwnData(): void
    {
        $me = $this->client('anna@citypark.ru');
        $other = $this->client('boris@vector.ru');
        [$side, $myPlan] = $this->plan($me, 'Осень');
        [, $otherPlan] = $this->plan($other, 'Чужая');

        $this->em->persist((new Payment())->setClient($me)->setMediaPlan($myPlan)->setTitle('Октябрь')->setAmount('20000')->setDueDate(new \DateTimeImmutable('2026-10-01')));
        $this->em->persist((new Payment())->setClient($other)->setTitle('Чужой платёж')->setAmount('1')->setDueDate(new \DateTimeImmutable('2026-10-01')));
        $myDocument = $this->document($me, 'Счёт №1');
        $otherDocument = $this->document($other, 'Чужой счёт');
        $myReport = $this->report($me, 'Монтаж');
        $otherReport = $this->report($other, 'Чужой монтаж');
        $lead = (new Lead())->setContactName('Анна')->setPhone('+7')->setClient($me)
            ->addItem(new LeadItem($side, 'Щит на Ленина', 'A', new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable('2026-11-30'), null, '20000'));
        $this->em->persist($lead);
        $this->em->persist((new Lead())->setContactName('Борис')->setPhone('+7')->setClient($other));
        $this->em->flush();

        $token = $this->signIn('anna@citypark.ru');

        $campaigns = $this->call('GET', '/api/v1/me/campaigns', token: $token)['body'];
        self::assertSame(['Осень'], array_column($campaigns, 'title'));
        self::assertSame('proposal', $campaigns[0]['status']);
        self::assertSame(['Щит на Ленина'], array_column($campaigns[0]['items'], 'name'));
        self::assertArrayNotHasKey('margin', $campaigns[0]);

        self::assertSame(['Октябрь'], array_column($this->call('GET', '/api/v1/me/payments', token: $token)['body'], 'title'));
        self::assertSame(['Счёт №1'], array_column($this->call('GET', '/api/v1/me/documents', token: $token)['body'], 'title'));
        self::assertSame(['Монтаж'], array_column($this->call('GET', '/api/v1/me/reports', token: $token)['body'], 'title'));
        $requests = $this->call('GET', '/api/v1/me/requests', token: $token)['body'];
        self::assertCount(1, $requests);
        self::assertEquals(20000, $requests[0]['estimate']);

        $this->call('GET', '/api/v1/me/documents/'.$myDocument->getId().'/file', token: $token);
        self::assertResponseIsSuccessful();
        self::assertSame('%PDF-test', $this->client->getInternalResponse()->getContent());
        self::assertSame(404, $this->call('GET', '/api/v1/me/documents/'.$otherDocument->getId().'/file', token: $token)['status']);

        $this->call('GET', '/api/v1/me/photos/'.$myReport->getPhotos()->first()->getId(), token: $token);
        self::assertResponseIsSuccessful();
        self::assertSame(404, $this->call('GET', '/api/v1/me/photos/'.$otherReport->getPhotos()->first()->getId(), token: $token)['status']);

        self::assertSame(404, $this->call('GET', '/api/v1/me/campaigns/'.$otherPlan->getId().'/pdf', token: $token)['status']);
        $this->call('GET', '/api/v1/me/campaigns/'.$myPlan->getId().'/pdf', token: $token);
        self::assertResponseHeaderSame('Content-Type', 'application/pdf');
    }

    public function testOrderOfASignedInClientIsLinkedToTheAccount(): void
    {
        $me = $this->client('anna@citypark.ru');
        [$side] = $this->plan($me, 'Осень');
        $token = $this->signIn('anna@citypark.ru');

        $response = $this->call('POST', '/api/v1/orders', [
            'contactName' => 'Анна', 'phone' => '+7 983 000-00-00',
            'items' => [['sideId' => $side->getId(), 'from' => '2026-12-01', 'to' => '2026-12-31']],
        ], $token);

        self::assertSame(201, $response['status']);
        self::assertSame($me->getId(), $this->em->getRepository(Lead::class)->find($response['body']['id'])->getClient()?->getId());
    }

    public function testClientEditsProfileAndChangesPassword(): void
    {
        $this->client('anna@citypark.ru');
        $login = $this->call('POST', '/api/v1/auth/login', ['email' => 'anna@citypark.ru', 'password' => self::PASSWORD])['body'];

        $missingInn = $this->call('PATCH', '/api/v1/me', ['name' => 'Анна', 'phone' => '+7', 'clientType' => 'legal', 'company' => 'ООО «Ситипарк»'], $login['token']);
        self::assertSame(422, $missingInn['status']);
        self::assertSame(['inn'], array_column($missingInn['body']['violations'], 'field'));

        $saved = $this->call('PATCH', '/api/v1/me', ['name' => 'Анна Семёнова', 'phone' => '+7 983', 'clientType' => 'legal', 'company' => 'ООО «Ситипарк»', 'inn' => '0326000000', 'kpp' => '032601001'], $login['token']);
        self::assertSame(200, $saved['status']);
        self::assertSame('032601001', $saved['body']['kpp']);

        $wrongCurrent = $this->call('POST', '/api/v1/me/password', ['current' => 'nope', 'new' => 'brand-new-password'], $login['token']);
        self::assertSame(['current'], array_column($wrongCurrent['body']['violations'], 'field'));

        $changed = $this->call('POST', '/api/v1/me/password', ['current' => self::PASSWORD, 'new' => 'brand-new-password'], $login['token']);
        self::assertSame(200, $changed['status']);
        self::assertNotEmpty($changed['body']['refresh_token']);
        // other devices are signed out, this one keeps working with the new pair
        self::assertSame(401, $this->call('POST', '/api/v1/auth/refresh', ['refresh_token' => $login['refresh_token']])['status']);
        self::assertSame(200, $this->call('POST', '/api/v1/auth/refresh', ['refresh_token' => $changed['body']['refresh_token']])['status']);
        self::assertSame(200, $this->call('POST', '/api/v1/auth/login', ['email' => 'anna@citypark.ru', 'password' => 'brand-new-password'])['status']);
    }

    public function testForgottenPasswordIsResetByALinkFromTheEmail(): void
    {
        $this->client('anna@citypark.ru');
        $old = $this->call('POST', '/api/v1/auth/login', ['email' => 'anna@citypark.ru', 'password' => self::PASSWORD])['body'];

        $asked = $this->call('POST', '/api/v1/auth/forgot-password', ['email' => ' Anna@CityPark.ru ']);
        self::assertSame(202, $asked['status']);
        self::assertQueuedEmailCount(1);
        $email = self::getMailerMessage();
        self::assertEmailAddressContains($email, 'to', 'anna@citypark.ru');
        self::assertEmailHtmlBodyContains($email, 'Сменить пароль');
        self::assertMatchesRegularExpression('~http://localhost:3000/reset-password\?token=([\w%]+)~', (string) $email->getTextBody(), 'the link leads to the website');
        preg_match('~reset-password\?token=([\w%]+)~', (string) $email->getTextBody(), $match);
        $token = urldecode($match[1]);

        // asking again right away sends nothing new, and the answer doesn't change
        self::assertSame($asked, $this->call('POST', '/api/v1/auth/forgot-password', ['email' => 'anna@citypark.ru']));
        self::assertQueuedEmailCount(0);

        $short = $this->call('POST', '/api/v1/auth/reset-password', ['token' => $token, 'password' => 'short']);
        self::assertSame(['password'], array_column($short['body']['violations'], 'field'));

        $reset = $this->call('POST', '/api/v1/auth/reset-password', ['token' => $token, 'password' => 'brand-new-password']);
        self::assertSame(200, $reset['status']);
        self::assertNotEmpty($reset['body']['refresh_token']);
        self::assertSame(200, $this->call('GET', '/api/v1/me', token: $reset['body']['token'])['status']);

        // the old password and the sessions made with it are gone, the link works once
        self::assertSame(401, $this->call('POST', '/api/v1/auth/login', ['email' => 'anna@citypark.ru', 'password' => self::PASSWORD])['status']);
        self::assertSame(401, $this->call('POST', '/api/v1/auth/refresh', ['refresh_token' => $old['refresh_token']])['status']);
        $again = $this->call('POST', '/api/v1/auth/reset-password', ['token' => $token, 'password' => 'another-password']);
        self::assertSame(422, $again['status']);
        self::assertSame('invalid_reset_token', $again['body']['error']);
    }

    public function testForgotPasswordTellsNothingAboutAccounts(): void
    {
        $this->createUser('manager@redbox.local', User::ROLE_SUPER_MANAGER);

        $nobody = $this->call('POST', '/api/v1/auth/forgot-password', ['email' => 'nobody@example.ru']);
        $staff = $this->call('POST', '/api/v1/auth/forgot-password', ['email' => 'manager@redbox.local']);

        self::assertSame(202, $nobody['status']);
        self::assertSame($nobody, $staff);
        self::assertQueuedEmailCount(0);
        self::assertSame('invalid_reset_token', $this->call('POST', '/api/v1/auth/reset-password', ['token' => str_repeat('x', 40), 'password' => 'brand-new-password'])['body']['error']);
    }

    public function testManagerSettingANewPasswordSignsTheClientOut(): void
    {
        $client = $this->client('anna@citypark.ru');
        $session = $this->call('POST', '/api/v1/auth/login', ['email' => 'anna@citypark.ru', 'password' => self::PASSWORD])['body'];

        $this->client->loginUser($this->createUser('admin@redbox.local', User::ROLE_ADMIN));
        $crawler = $this->client->request('GET', '/admin/clients/'.$client->getId());
        [$values, $uri] = $this->formValues($crawler, 'Сохранить');
        $values['client_form']['plainPassword'] = ['first' => 'set-by-manager', 'second' => 'set-by-manager'];
        $this->submit($uri, $values);
        self::assertResponseRedirects();

        self::assertSame(401, $this->call('POST', '/api/v1/auth/refresh', ['refresh_token' => $session['refresh_token']])['status']);
        self::assertSame(200, $this->call('POST', '/api/v1/auth/login', ['email' => 'anna@citypark.ru', 'password' => 'set-by-manager'])['status']);
    }

    public function testSignUpAsksToConfirmTheEmail(): void
    {
        $signUp = $this->call('POST', '/api/v1/auth/register', ['name' => 'Анна', 'email' => 'anna@citypark.ru', 'phone' => '+7 983', 'password' => 'long-enough-password', 'agree' => true]);
        self::assertSame(201, $signUp['status']);
        self::assertFalse($signUp['body']['user']['emailVerified']);

        self::assertQueuedEmailCount(1);
        $email = self::getMailerMessage();
        self::assertEmailHtmlBodyContains($email, 'Подтвердить почту');
        self::assertSame(1, preg_match('~http://localhost:3000/verify-email\?(\S+)~', (string) $email->getTextBody(), $match), 'the link leads to the website');
        parse_str($match[1], $query);
        self::assertEqualsCanonicalizing(['expires', 'id', 'signature', 'token'], array_keys($query));

        $forged = $this->call('POST', '/api/v1/auth/verify-email', ['signature' => 'x'.substr($query['signature'], 1)] + $query);
        self::assertSame(422, $forged['status']);
        self::assertSame('invalid_verification_link', $forged['body']['error']);
        $otherAccount = $this->call('POST', '/api/v1/auth/verify-email', ['id' => $this->client('boris@vector.ru')->getId()] + $query);
        self::assertSame(422, $otherAccount['status']);

        $confirmed = $this->call('POST', '/api/v1/auth/verify-email', $query);
        self::assertSame(200, $confirmed['status']);
        self::assertStringContainsString('подтверждена', $confirmed['body']['message']);
        self::assertTrue($this->call('GET', '/api/v1/me', token: $signUp['body']['token'])['body']['emailVerified']);
        self::assertSame('Почта уже подтверждена', $this->call('POST', '/api/v1/me/verify-email', token: $signUp['body']['token'])['body']['message']);
    }

    public function testConfirmationEmailCanBeSentAgainAFewTimes(): void
    {
        $token = $this->call('POST', '/api/v1/auth/register', ['name' => 'Анна', 'email' => 'anna@citypark.ru', 'phone' => '+7 983', 'password' => 'long-enough-password', 'agree' => true])['body']['token'];

        for ($i = 0; $i < 3; ++$i) {
            self::assertSame(202, $this->call('POST', '/api/v1/me/verify-email', token: $token)['status']);
        }
        $tooMany = $this->call('POST', '/api/v1/me/verify-email', token: $token);
        self::assertSame(429, $tooMany['status']);
        self::assertStringContainsString('Повторить можно через', $tooMany['body']['message']);
    }

    public function testResettingThePasswordByEmailConfirmsTheEmail(): void
    {
        $this->client('anna@citypark.ru')->setEmailVerifiedAt(null);
        $this->em->flush();

        $this->call('POST', '/api/v1/auth/forgot-password', ['email' => 'anna@citypark.ru']);
        preg_match('~reset-password\?token=([\w%]+)~', (string) self::getMailerMessage()->getTextBody(), $match);
        $reset = $this->call('POST', '/api/v1/auth/reset-password', ['token' => urldecode($match[1]), 'password' => 'brand-new-password']);

        self::assertTrue($this->call('GET', '/api/v1/me', token: $reset['body']['token'])['body']['emailVerified']);
    }

    private function client(string $email): User
    {
        $user = $this->createUser($email, User::ROLE_CLIENT)->setClientType(ClientType::Legal)->setCompany('ООО «Ситипарк»')->setInn('0326000000');
        $this->em->flush();

        return $user;
    }

    private function signIn(string $email): string
    {
        return $this->call('POST', '/api/v1/auth/login', ['email' => $email, 'password' => self::PASSWORD])['body']['token'];
    }

    /**
     * @return array{ProductSide, MediaPlan}
     */
    private function plan(User $client, string $title): array
    {
        $type = (new ProductType())->setName('Статика '.$title);
        $category = (new Category())->setName('Билборд '.$title);
        $district = (new District())->setName('Центр '.$title);
        $side = (new ProductSide())->setName('A');
        $product = (new Product())->setName('Щит на Ленина')->setCategory($category)->setProductType($type)->setDistrict($district)
            ->setPrice('20000')->setPurchasePrice('12000')->setLatitude('51.8')->setLongitude('107.6')->addSide($side);
        $plan = (new MediaPlan())->setTitle($title)->setClientName($client->getClientTitle())->setClient($client)
            ->setStartMonth(new \DateTimeImmutable('2026-10-01'))->setMonths(2);
        $plan->addItem(new MediaPlanItem($side, '20000'));
        foreach ([$type, $category, $district, $product, $plan] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();

        return [$side, $plan];
    }

    private function document(User $client, string $title): ClientDocument
    {
        $document = new ClientDocument($client, ClientDocumentType::Invoice, $title, new StoredFile('invoice.pdf', 'invoice.pdf', 'application/pdf', 9));
        (new Filesystem())->dumpFile($this->storagePath($document->getFolder(), 'invoice.pdf'), '%PDF-test');
        $this->em->persist($document);
        $this->em->flush();

        return $document;
    }

    private function report(User $client, string $title): PhotoReport
    {
        $report = (new PhotoReport())->setClient($client)->setTitle($title)->setShotAt(new \DateTimeImmutable('2026-10-02'));
        $report->addPhoto(new PhotoReportPhoto(new StoredFile('shot.png', 'shot.png', 'image/png', 4)));
        (new Filesystem())->dumpFile($this->storagePath(PhotoReport::folder($client), 'shot.png'), 'png!');
        $this->em->persist($report);
        $this->em->flush();

        return $report;
    }

    private function storagePath(string $folder, string $file): string
    {
        return static::getContainer()->getParameter('app.private_storage_dir').'/'.$folder.'/'.$file;
    }

    /**
     * @param array<string, mixed>|null $payload
     *
     * @return array{status: int, body: array<mixed>}
     */
    private function call(string $method, string $url, ?array $payload = null, ?string $token = null): array
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        if (null !== $token) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        }
        $this->client->request($method, $url, server: $server, content: null !== $payload ? json_encode($payload, \JSON_THROW_ON_ERROR) : null);
        $response = $this->client->getInternalResponse();

        return ['status' => $response->getStatusCode(), 'body' => json_decode((string) $response->getContent(), true) ?? []];
    }
}
