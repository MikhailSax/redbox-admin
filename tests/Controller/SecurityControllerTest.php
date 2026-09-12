<?php

namespace App\Tests\Controller;

use App\Entity\User;
use App\Tests\Controller\Admin\AdminWebTestCase;

final class SecurityControllerTest extends AdminWebTestCase
{
    protected ?string $loginAs = null;

    public function testAnonymousIsRedirectedToLogin(): void
    {
        foreach (['/admin/products', '/admin/categories', '/admin/users'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseRedirects('/login', message: $url);
        }
    }

    public function testLoginPageHasTheLogoAndNoMarketingText(): void
    {
        $crawler = $this->client->request('GET', '/login');

        self::assertCount(1, $crawler->filter('img[src="/images/brand/logo-250.png"]'));
        self::assertSelectorTextNotContains('body', 'Все рекламные конструкции в одном месте');
        // the decorative panel is background only: no text, no billboard picture
        $panel = $crawler->filter('section > div[aria-hidden="true"]');
        self::assertCount(1, $panel);
        self::assertSame('', trim($panel->text()));
        self::assertCount(0, $panel->filter('svg[viewBox="0 0 320 200"]'));
    }

    public function testSiteRootLeadsToLoginOrDashboard(): void
    {
        $this->client->request('GET', '/');
        self::assertResponseRedirects('/login');

        $this->client->loginUser($this->createUser('manager@redbox.local', User::ROLE_SUPER_MANAGER));
        $this->client->request('GET', '/');
        self::assertResponseRedirects('/admin');
    }

    public function testLoginWithValidCredentials(): void
    {
        $this->createUser('manager@redbox.local', User::ROLE_SUPER_MANAGER);

        $this->logIn('Manager@Redbox.local ', self::PASSWORD);

        self::assertResponseRedirects('/admin'); // dashboard is the landing page
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#default-sidebar', 'Супер менеджер');
    }

    public function testLoginWithWrongPasswordShowsError(): void
    {
        $this->createUser('manager@redbox.local', User::ROLE_SUPER_MANAGER);

        $this->logIn('manager@redbox.local', 'wrong-password');

        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        self::assertSelectorTextContains('[role=alert]', 'Недействительные аутентификационные данные');
        self::assertInputValueSame('_username', 'manager@redbox.local');
    }

    public function testLoginIsBlockedAfterFiveFailedAttempts(): void
    {
        $this->createUser('manager@redbox.local', User::ROLE_SUPER_MANAGER);

        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            $this->logIn('manager@redbox.local', 'wrong-password-'.$attempt);
            $this->client->followRedirect();
            self::assertSelectorTextContains('[role=alert]', 'Недействительные аутентификационные данные', 'attempt '.$attempt);
        }

        // 6th attempt is refused even with the correct password
        $this->logIn('manager@redbox.local', self::PASSWORD);
        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        self::assertSelectorTextContains('[role=alert]', 'Слишком много неудачных попыток входа');

        $this->client->request('GET', '/admin/products');
        self::assertResponseRedirects('/login');
    }

    public function testSuperManagerCannotManageUsers(): void
    {
        $this->client->loginUser($this->createUser('manager@redbox.local', User::ROLE_SUPER_MANAGER));

        $this->client->request('GET', '/admin/products');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('#default-sidebar a[href="/admin/users"]');

        $this->client->request('GET', '/admin/categories');
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/admin/users');
        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminSeesUserManagement(): void
    {
        $this->client->loginUser($this->createUser('admin@redbox.local', User::ROLE_ADMIN));

        $this->client->request('GET', '/admin/users');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#default-sidebar a[href="/admin/users"]');
    }

    public function testLogout(): void
    {
        $this->client->loginUser($this->createUser('admin@redbox.local', User::ROLE_ADMIN));

        $this->client->request('GET', '/logout');
        self::assertResponseRedirects('/login');

        $this->client->request('GET', '/admin/products');
        self::assertResponseRedirects('/login');
    }

    private function logIn(string $email, string $password): void
    {
        $crawler = $this->client->request('GET', '/login');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Войти')->form(['_username' => $email, '_password' => $password]);
        $this->submit($form->getUri(), $form->getPhpValues());
    }
}
