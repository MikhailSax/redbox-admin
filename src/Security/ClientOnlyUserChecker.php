<?php

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The website's personal account is for clients: staff sign in to the CRM instead (see StaffOnlyUserChecker).
 * Checked on login and on every token refresh, so a client turned into staff loses the website session.
 */
final class ClientOnlyUserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        if ($user instanceof User && !$user->isClient()) {
            throw new CustomUserMessageAccountStatusException('Личный кабинет на сайте — для клиентов. Сотрудники входят в CRM.');
        }
    }

    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
    }
}
