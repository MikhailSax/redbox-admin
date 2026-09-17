<?php

namespace App\Dto\Api;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * "Заявка на размещение" from the website: the cart plus the visitor's contacts.
 */
final class OrderRequest
{
    #[Assert\NotBlank(message: 'Укажите контактное лицо', normalizer: 'trim')]
    #[Assert\Length(max: 255)]
    public ?string $contactName = null;

    #[Assert\NotBlank(message: 'Укажите телефон', normalizer: 'trim')]
    #[Assert\Length(max: 50)]
    public ?string $phone = null;

    #[Assert\Email(message: 'Неверный email')]
    #[Assert\Length(max: 180)]
    public ?string $email = null;

    #[Assert\Length(max: 255)]
    public ?string $company = null;

    #[Assert\Length(max: 20)]
    #[Assert\Regex('/^\d{10}(\d{2})?$/', message: 'ИНН — 10 или 12 цифр')]
    public ?string $inn = null;

    #[Assert\Length(max: 20)]
    #[Assert\Regex('/^\d{9}$/', message: 'КПП — 9 цифр')]
    public ?string $kpp = null;

    /** prepay | postpay */
    #[Assert\Choice(choices: ['prepay', 'postpay'], message: 'Условия оплаты: prepay или postpay')]
    public ?string $payment = null;

    #[Assert\Length(max: 2000)]
    public ?string $comment = null;

    /**
     * Honeypot: a real visitor leaves it empty, bots fill every field they find.
     */
    #[Assert\Blank]
    public ?string $website = null;

    /**
     * @var list<OrderItemRequest>
     */
    #[Assert\Valid]
    #[Assert\Count(min: 1, max: 50, minMessage: 'Добавьте хотя бы одну конструкцию', maxMessage: 'Не больше {{ limit }} позиций в заявке')]
    public array $items = [];
}
