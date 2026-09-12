<?php

namespace App\Tests\Command;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class CreateUserCommandTest extends KernelTestCase
{
    private CommandTester $tester;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $application = new Application(self::bootKernel());
        $this->tester = new CommandTester($application->find('app:user:create'));
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->createQuery('DELETE FROM '.User::class.' u')->execute();
    }

    public function testCreatesAdmin(): void
    {
        $this->tester->execute(['email' => 'Boss@Redbox.local', 'name' => 'Босс', '--password' => 'boss-password']);

        $this->tester->assertCommandIsSuccessful();
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => 'boss@redbox.local']);
        self::assertNotNull($user);
        self::assertSame(User::ROLE_ADMIN, $user->getRole());
        self::assertTrue(static::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($user, 'boss-password'));
    }

    public function testAsksForPasswordAndCreatesSuperManager(): void
    {
        $this->tester->setInputs(['manager-password']);
        $this->tester->execute(['email' => 'manager@redbox.local', 'name' => 'Менеджер', '--role' => 'super-manager']);

        $this->tester->assertCommandIsSuccessful();
        self::assertSame(User::ROLE_SUPER_MANAGER, $this->em->getRepository(User::class)->findOneBy(['email' => 'manager@redbox.local'])->getRole());
    }

    public function testRejectsUnknownRoleShortPasswordAndDuplicateEmail(): void
    {
        self::assertSame(Command::INVALID, $this->tester->execute(['email' => 'a@redbox.local', 'name' => 'A', '--role' => 'root', '--password' => 'long-enough']));
        self::assertSame(Command::INVALID, $this->tester->execute(['email' => 'a@redbox.local', 'name' => 'A', '--password' => 'short']));

        $this->tester->execute(['email' => 'a@redbox.local', 'name' => 'A', '--password' => 'long-enough']);
        self::assertSame(Command::FAILURE, $this->tester->execute(['email' => 'a@redbox.local', 'name' => 'B', '--password' => 'long-enough']));
        self::assertStringContainsString('Пользователь с таким email уже есть', $this->tester->getDisplay());
    }
}
