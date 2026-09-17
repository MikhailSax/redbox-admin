<?php

namespace App\Dto\Api;

use Symfony\Component\Validator\Constraints as Assert;

final class ForgotPasswordRequest
{
    #[Assert\NotBlank(message: 'Укажите почту', normalizer: 'trim')]
    #[Assert\Email(message: 'Неверный email', normalizer: 'trim')]
    #[Assert\Length(max: 180)]
    public ?string $email = null;
}
