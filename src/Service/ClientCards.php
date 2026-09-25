<?php

namespace App\Service;

use App\Entity\User;
use App\Enum\ClientType;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Client cards a manager makes without the client signing up: from a price list, on the spot in a media plan
 * or a booking, from a request of the website. A card needs only a name; phone, email and requisites are optional.
 *
 * An existing card is found first (by ИНН, then by email, then by the exact name), so the same client isn't
 * added twice. A new card is trusted like one made in the client form (the manager got the data from the client)
 * and has a random password: the client signs in only after setting one by "forgot password".
 */
class ClientCards
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $users,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly ValidatorInterface $validator,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * The client with this ИНН, email or name; null when there is none.
     */
    public function find(string $title, ?string $inn = null, ?string $email = null): ?User
    {
        $inn = self::digits($inn);
        $email = null !== $email && '' !== trim($email) ? mb_strtolower(trim($email)) : null;
        $title = trim($title);

        $found = null !== $inn ? $this->findClient(['inn' => $inn]) : null;
        if (null === $found && null !== $email) {
            // one email may serve two companies of one owner: a different ИНН is a different client
            $byEmail = $this->findClient(['email' => $email]);
            $found = null === $inn || null === $byEmail?->getInn() ? $byEmail : null;
        }
        if (null !== $found || '' === $title) {
            return $found;
        }

        return $this->findClient(['company' => $title]) ?? $this->findClient(['name' => $title, 'company' => null]);
    }

    /**
     * A new card, persisted (not flushed). The type follows from the ИНН; without one it is a private person
     * called $title. An email another account already has is left out and reported in $notes.
     *
     * @param list<string> $notes
     *
     * @throws ClientCardException when the data don't make a valid card (e.g. an ИНН of 11 digits)
     */
    public function create(string $title, ?string $phone = null, ?string $email = null, ?string $inn = null, ?string $kpp = null, ?string $contactName = null, array &$notes = []): User
    {
        $title = trim($title);
        if ('' === $title) {
            throw new ClientCardException('Укажите название или ФИО клиента');
        }

        if (!\in_array(\strlen((string) self::digits($inn)), [0, 10, 12], true)) {
            throw new ClientCardException(\sprintf('%s: ИНН — 10 цифр у организации или 12 у ИП', $title));
        }
        $type = ClientType::fromInn($inn);
        $client = (new User())
            ->setRole(User::ROLE_CLIENT)
            ->setClientType($type)
            ->setPhone(null !== $phone ? mb_substr(trim($phone), 0, 50) : null)
            ->setEmail($email);
        if ($type->hasRequisites()) {
            $client->setCompany($title)->setInn($inn)->setKpp(ClientType::Legal === $type ? $kpp : null)->setName($contactName);
        } else {
            $client->setName(mb_substr($title, 0, 100));
        }

        if (null !== $client->getEmail() && null !== $this->users->findOneBy(['email' => $client->getEmail()])) {
            $notes[] = \sprintf('%s: почта %s уже есть у другого пользователя — карточка заведена без почты', $title, $client->getEmail());
            $client->setEmail(null);
        }

        $violations = $this->validator->validate($client);
        if (\count($violations) > 0) {
            throw new ClientCardException(\sprintf('%s: %s', $title, $violations->get(0)->getMessage()));
        }

        $client->setEmailVerifiedAt($this->clock->now())
            ->setPassword($this->passwordHasher->hashPassword($client, bin2hex(random_bytes(24))));
        $this->entityManager->persist($client);

        return $client;
    }

    /**
     * The existing client, otherwise a new card.
     *
     * @return array{0: User, 1: bool} [client, created]
     *
     * @throws ClientCardException
     */
    public function findOrCreate(string $title, ?string $phone = null, ?string $email = null, ?string $inn = null, ?string $kpp = null, ?string $contactName = null): array
    {
        $found = $this->find($title, $inn, $email);

        return null !== $found ? [$found, false] : [$this->create($title, $phone, $email, $inn, $kpp, $contactName), true];
    }

    /**
     * @param array<string, string|null> $criteria
     */
    private function findClient(array $criteria): ?User
    {
        foreach ($this->users->findBy($criteria, ['id' => 'ASC']) as $user) {
            if ($user->isClient()) {
                return $user;
            }
        }

        return null;
    }

    private static function digits(?string $value): ?string
    {
        $value = preg_replace('/\D+/', '', (string) $value);

        return '' !== $value ? $value : null;
    }
}
