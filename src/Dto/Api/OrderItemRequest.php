<?php

namespace App\Dto\Api;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * One side in the cart the website sends with an order.
 */
final class OrderItemRequest
{
    #[Assert\NotNull(message: 'Укажите сторону конструкции')]
    #[Assert\Positive]
    public ?int $sideId = null;

    /** First day, "YYYY-MM-DD" */
    #[Assert\NotBlank(message: 'Укажите начало размещения')]
    #[Assert\Date(message: 'Дата в формате ГГГГ-ММ-ДД')]
    public ?string $from = null;

    /** Last day (inclusive), "YYYY-MM-DD" */
    #[Assert\NotBlank(message: 'Укажите окончание размещения')]
    #[Assert\Date(message: 'Дата в формате ГГГГ-ММ-ДД')]
    public ?string $to = null;

    /** Clip length in seconds, for screens */
    #[Assert\Choice(choices: [5, 10, 15], message: 'Ролик — 5, 10 или 15 секунд')]
    public ?int $clip = null;
}
