<?php

namespace App\Command;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Creates an admin panel user, e.g. the very first administrator:
 *   bin/console app:user:create admin@example.com "Иван Петров" --role=admin
 */
#[AsCommand(name: 'app:user:create', description: 'Создать пользователя админки')]
final class CreateUserCommand
{
    private const ROLES = [
        'admin' => User::ROLE_ADMIN,
        'super-manager' => User::ROLE_SUPER_MANAGER,
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly ValidatorInterface $validator,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Email (логин)')] string $email,
        #[Argument('Имя для отображения')] string $name,
        #[Option('Роль: admin или super-manager', suggestedValues: ['admin', 'super-manager'])] string $role = 'admin',
        #[Option('Пароль; если не указан, будет запрошен')] ?string $password = null,
    ): int {
        if (!isset(self::ROLES[$role])) {
            $io->error(\sprintf('Неизвестная роль "%s". Допустимо: %s.', $role, implode(', ', array_keys(self::ROLES))));

            return Command::INVALID;
        }

        $password ??= $io->askHidden('Пароль (не меньше 8 символов)');
        if (null === $password || mb_strlen($password) < 8) {
            $io->error('Пароль должен быть не короче 8 символов.');

            return Command::INVALID;
        }

        $user = (new User())
            ->setEmail($email)
            ->setName($name)
            ->setRole(self::ROLES[$role]);

        $violations = $this->validator->validate($user);
        if (\count($violations) > 0) {
            foreach ($violations as $violation) {
                $io->error($violation->getPropertyPath().': '.$violation->getMessage());
            }

            return Command::FAILURE;
        }

        $user->setPassword($this->passwordHasher->hashPassword($user, $password));
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $io->success(\sprintf('Пользователь %s (%s) создан.', $user->getEmail(), $user->getRoleLabel()));

        return Command::SUCCESS;
    }
}
