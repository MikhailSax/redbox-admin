<?php

namespace App\Dto\Api;

use App\Validator\SmartCaptcha;
use Symfony\Component\Validator\Constraints as Assert;

final class ForgotPasswordRequest
{
    #[Assert\NotBlank(message: 'Укажите почту', normalizer: 'trim')]
    #[Assert\Email(message: 'Неверный email', normalizer: 'trim')]
    #[Assert\Length(max: 180)]
    public ?string $email = null;

    /** Token of Yandex SmartCaptcha from the form */
    #[SmartCaptcha]
    public ?string $captchaToken = null;
}
