<?php

namespace App\Dto\Api;

use Symfony\Component\Validator\Constraints as Assert;

/** The token from the "Забыли пароль?" e-mail and the password the client chose */
final class NewPasswordRequest
{
    #[Assert\NotBlank(message: 'Ссылка неполная — откройте её из письма ещё раз')]
    #[Assert\Length(max: 100)]
    public ?string $token = null;

    #[Assert\NotBlank(message: 'Придумайте новый пароль')]
    #[Assert\Length(min: 8, max: 4096, minMessage: 'Пароль — не короче {{ limit }} символов')]
    public ?string $password = null;
}
