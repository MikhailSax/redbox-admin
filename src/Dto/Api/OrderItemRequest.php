<?php

namespace App\Dto\Api;

use App\Enum\BookingMode;
use App\Service\MonthCalendar;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

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

    /** Placement is two weeks at least; the order of the days is checked too */
    #[Assert\Callback]
    public function validatePeriod(ExecutionContextInterface $context): void
    {
        $from = null !== $this->from ? \DateTimeImmutable::createFromFormat('!Y-m-d', $this->from) : false;
        $to = null !== $this->to ? \DateTimeImmutable::createFromFormat('!Y-m-d', $this->to) : false;
        if (false === $from || false === $to) {
            return; // reported by the Date constraints
        }

        if ($to < $from) {
            $context->buildViolation('Окончание размещения раньше начала')->atPath('to')->addViolation();
        } elseif (MonthCalendar::days($from, $to) < BookingMode::MIN_DAYS) {
            $context->buildViolation(\sprintf('Минимальное размещение — %d дней', BookingMode::MIN_DAYS))->atPath('to')->addViolation();
        }
    }
}
