<?php

namespace App\Dto\Api;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Sign-up on the website. The kind of client follows from the ИНН: none — a private person,
 * 10 digits — a company, 12 digits — an entrepreneur.
 */
final class RegisterRequest
{
    #[Assert\NotBlank(message: 'Укажите контактное лицо', normalizer: 'trim')]
    #[Assert\Length(max: 100)]
    public ?string $name = null;

    #[Assert\NotBlank(message: 'Укажите рабочую почту', normalizer: 'trim')]
    #[Assert\Email(message: 'Неверный email')]
    #[Assert\Length(max: 180)]
    public ?string $email = null;

    #[Assert\NotBlank(message: 'Укажите телефон', normalizer: 'trim')]
    #[Assert\Length(max: 50)]
    public ?string $phone = null;

    #[Assert\NotBlank(message: 'Придумайте пароль')]
    #[Assert\Length(min: 8, max: 4096, minMessage: 'Пароль — не короче {{ limit }} символов')]
    public ?string $password = null;

    /** Company or "ИП Иванов И. И."; required with an ИНН */
    #[Assert\Length(max: 255)]
    public ?string $company = null;

    /** Spaces are fine: "0326 000 000" */
    #[Assert\Regex('/^\s*(\d\s*){10}((\d\s*){2})?$/', message: 'ИНН — 10 цифр у организации или 12 у ИП')]
    public ?string $inn = null;

    #[Assert\IsTrue(message: 'Нужно согласие на обработку данных')]
    public bool $agree = false;

    /** Honeypot, see OrderRequest */
    #[Assert\Blank]
    public ?string $website = null;
}
