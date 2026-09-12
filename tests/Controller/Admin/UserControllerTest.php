<?php

namespace App\Tests\Controller\Admin;

use App\Entity\User;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class UserControllerTest extends AdminWebTestCase
{
    public function testCreateSuperManager(): void
    {
        $crawler = $this->client->request('GET', '/admin/users/new');
        self::assertResponseIsSuccessful();

        [$values, $uri] = $this->formValues($crawler, 'Создать');
        $values['user_form']['name'] = 'Мария';
        $values['user_form']['email'] = 'maria@redbox.local';
        $values['user_form']['role'] = User::ROLE_SUPER_MANAGER;
        $values['user_form']['plainPassword'] = ['first' => 'new-password-1', 'second' => 'new-password-1'];
        $this->submit($uri, $values);

        self::assertResponseRedirects('/admin/users');
        $this->client->followRedirect();
        self::assertSelectorTextContains('tbody', 'maria@redbox.local');
        self::assertSelectorTextContains('tbody', 'Супер менеджер');

        $user = $this->findUser('maria@redbox.local');
        self::assertSame([User::ROLE_SUPER_MANAGER, 'ROLE_USER'], $user->getRoles());
        self::assertTrue($this->hasher()->isPasswordValid($user, 'new-password-1'));
    }

    public function testPasswordIsRequiredAndMustMatch(): void
    {
        $crawler = $this->client->request('GET', '/admin/users/new');
        [$values, $uri] = $this->formValues($crawler, 'Создать');
        $values['user_form']['name'] = 'Мария';
        $values['user_form']['email'] = 'maria@redbox.local';

        $values['user_form']['plainPassword'] = ['first' => '', 'second' => ''];
        $this->submit($uri, $values);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form', 'Задайте пароль');

        $values['user_form']['plainPassword'] = ['first' => 'new-password-1', 'second' => 'other-password'];
        $this->submit($uri, $values);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form', 'Пароли не совпадают');

        self::assertNull($this->findUser('maria@redbox.local'));
    }

    public function testEmailMustBeUnique(): void
    {
        $this->createUser('maria@redbox.local', User::ROLE_SUPER_MANAGER);

        $crawler = $this->client->request('GET', '/admin/users/new');
        [$values, $uri] = $this->formValues($crawler, 'Создать');
        $values['user_form']['name'] = 'Другая Мария';
        $values['user_form']['email'] = 'maria@redbox.local';
        $values['user_form']['plainPassword'] = ['first' => 'new-password-1', 'second' => 'new-password-1'];
        $this->submit($uri, $values);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form', 'Пользователь с таким email уже есть');
    }

    public function testEditKeepsPasswordWhenEmptyAndChangesRole(): void
    {
        $user = $this->createUser('maria@redbox.local', User::ROLE_SUPER_MANAGER);
        $oldHash = $user->getPassword();

        $crawler = $this->client->request('GET', '/admin/users/'.$user->getId().'/edit');
        [$values, $uri] = $this->formValues($crawler, 'Сохранить');
        $values['user_form']['name'] = 'Мария Иванова';
        $values['user_form']['role'] = User::ROLE_ADMIN;
        $this->submit($uri, $values);

        self::assertResponseRedirects('/admin/users');
        $user = $this->findUser('maria@redbox.local');
        self::assertSame('Мария Иванова', $user->getName());
        self::assertSame(User::ROLE_ADMIN, $user->getRole());
        self::assertSame($oldHash, $user->getPassword());
    }

    public function testEditChangesPassword(): void
    {
        $user = $this->createUser('maria@redbox.local', User::ROLE_SUPER_MANAGER);

        $crawler = $this->client->request('GET', '/admin/users/'.$user->getId().'/edit');
        [$values, $uri] = $this->formValues($crawler, 'Сохранить');
        $values['user_form']['plainPassword'] = ['first' => 'changed-password', 'second' => 'changed-password'];
        $this->submit($uri, $values);

        self::assertResponseRedirects('/admin/users');
        self::assertTrue($this->hasher()->isPasswordValid($this->findUser('maria@redbox.local'), 'changed-password'));
    }

    public function testAdminCannotChangeOwnRole(): void
    {
        $crawler = $this->client->request('GET', '/admin/users/'.$this->currentUser->getId().'/edit');
        self::assertSelectorExists('input[name="user_form[role]"][disabled]');

        [$values, $uri] = $this->formValues($crawler, 'Сохранить');
        $values['user_form']['role'] = User::ROLE_SUPER_MANAGER; // tampered request
        $this->submit($uri, $values);

        self::assertResponseRedirects('/admin/users');
        self::assertSame(User::ROLE_ADMIN, $this->findUser('me@redbox.local')->getRole());
    }

    public function testDeleteOtherUser(): void
    {
        $user = $this->createUser('maria@redbox.local', User::ROLE_SUPER_MANAGER);

        $crawler = $this->client->request('GET', '/admin/users');
        $this->submitPostForm($crawler, 'form[action="/admin/users/'.$user->getId().'/delete"]');

        self::assertResponseRedirects('/admin/users');
        self::assertNull($this->findUser('maria@redbox.local'));
    }

    public function testCannotDeleteSelf(): void
    {
        $crawler = $this->client->request('GET', '/admin/users');
        self::assertCount(1, $crawler->filter('tbody tr'));
        self::assertCount(0, $crawler->filter('form[action="/admin/users/'.$this->currentUser->getId().'/delete"]'));
        self::assertSelectorTextContains('tbody', '(это вы)');
    }

    private function findUser(string $email): ?User
    {
        $this->em->clear();

        return $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
    }

    private function hasher(): UserPasswordHasherInterface
    {
        return static::getContainer()->get(UserPasswordHasherInterface::class);
    }
}
