<?php

namespace App\Dto\Api;

use Symfony\Component\Security\Core\Validator\Constraints\UserPassword;
use Symfony\Component\Validator\Constraints as Assert;

final class PasswordRequest
{
    #[Assert\NotBlank(message: 'Введите текущий пароль')]
    #[UserPassword(message: 'Текущий пароль указан неверно')]
    public ?string $current = null;

    #[Assert\NotBlank(message: 'Придумайте новый пароль')]
    #[Assert\Length(min: 8, max: 4096, minMessage: 'Пароль — не короче {{ limit }} символов')]
    #[Assert\NotEqualTo(propertyPath: 'current', message: 'Новый пароль совпадает с текущим')]
    public ?string $new = null;
}
