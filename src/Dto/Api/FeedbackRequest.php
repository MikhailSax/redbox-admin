<?php

namespace App\Dto\Api;

use App\Validator\SmartCaptcha;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The website's feedback form ("Обратная связь"): who to call back and what about.
 * It lands in the CRM's requests without structures (Lead::SOURCE_FEEDBACK).
 */
final class FeedbackRequest
{
    #[Assert\NotBlank(message: 'Укажите имя', normalizer: 'trim')]
    #[Assert\Length(max: 255)]
    public ?string $name = null;

    #[Assert\NotBlank(message: 'Укажите телефон', normalizer: 'trim')]
    #[Assert\Length(max: 50)]
    #[Assert\Regex('/\d.*\d.*\d.*\d.*\d/', message: 'Проверьте номер телефона')]
    public ?string $phone = null;

    #[Assert\NotBlank(message: 'Напишите сообщение', normalizer: 'trim')]
    #[Assert\Length(max: 2000)]
    public ?string $message = null;

    #[Assert\Email(message: 'Неверный email')]
    #[Assert\Length(max: 180)]
    public ?string $email = null;

    #[Assert\Length(max: 255)]
    public ?string $company = null;

    /** Honeypot: a real visitor leaves it empty, bots fill every field they find */
    #[Assert\Blank]
    public ?string $website = null;

    /** Consent to the processing of personal data (152-ФЗ), ticked by the visitor */
    #[Assert\IsTrue(message: 'Нужно согласие на обработку персональных данных')]
    public bool $agree = false;

    /** Token of Yandex SmartCaptcha from the form */
    #[SmartCaptcha]
    public ?string $captchaToken = null;
}
