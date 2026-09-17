<?php

namespace App\Dto;

use App\Entity\ProductSide;
use App\Enum\BookingMode;
use App\Service\MonthCalendar;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Data of the "new booking" form; turned into a Booking by BookingManager::hold().
 *
 * Whole sides are booked by month ($startMonth + $months), airtime by days ($startDate … $endDate).
 * Airtime may also be booked by month (media plans): then $startDate stays empty.
 * The mode is the chosen side's: a structure may have a screen on one side and a static poster on another,
 * so the form sends both sets of fields and only the side's set counts.
 */
final class BookingRequest
{
    /** Longest airtime booking by days */
    public const MAX_DAYS = 366;

    #[Assert\NotNull(message: 'Выберите сторону')]
    public ?ProductSide $side = null;

    /** "YYYY-MM" */
    public ?string $startMonth = null;

    public ?int $months = 1;

    /** First day, airtime by days */
    public ?\DateTimeImmutable $startDate = null;

    /** Last day (inclusive), airtime by days */
    public ?\DateTimeImmutable $endDate = null;

    /** Seconds; required for airtime (video) sides only */
    public ?int $clipDuration = null;

    #[Assert\NotBlank(message: 'Укажите клиента', normalizer: 'trim')]
    #[Assert\Length(max: 255)]
    public ?string $clientName = null;

    #[Assert\NotBlank(message: 'Укажите телефон клиента', normalizer: 'trim')]
    #[Assert\Length(max: 50)]
    public ?string $clientPhone = null;

    public ?string $comment = null;

    public function isAirtime(): bool
    {
        return $this->side?->isAirtime() ?? false;
    }

    public function isByDays(): bool
    {
        return $this->isAirtime() && null !== $this->startDate;
    }

    /**
     * The booked days: the given dates, or the whole months.
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    public function period(): array
    {
        if ($this->isByDays()) {
            return [$this->startDate->setTime(0, 0), ($this->endDate ?? $this->startDate)->setTime(0, 0)];
        }

        $start = MonthCalendar::parse((string) $this->startMonth);

        return [$start, MonthCalendar::lastDay($start->modify(\sprintf('+%d months', max(1, (int) $this->months) - 1)))];
    }

    #[Assert\Callback]
    public function validatePeriod(ExecutionContextInterface $context): void
    {
        if ($this->isAirtime() && !\in_array($this->clipDuration, BookingMode::CLIP_DURATIONS, true)) {
            $context->buildViolation('Выберите длину ролика')->atPath('clipDuration')->addViolation();
        }

        if ($this->isAirtime() && ($this->isByDays() || null === $this->startMonth)) {
            if (null === $this->startDate) {
                $context->buildViolation('Укажите первый день')->atPath('startDate')->addViolation();
            } elseif (null === $this->endDate) {
                $context->buildViolation('Укажите последний день')->atPath('endDate')->addViolation();
            } elseif ($this->endDate < $this->startDate) {
                $context->buildViolation('Последний день не может быть раньше первого')->atPath('endDate')->addViolation();
            } elseif (MonthCalendar::days($this->startDate, $this->endDate) > self::MAX_DAYS) {
                $context->buildViolation(\sprintf('Не больше %d дней за одну бронь', self::MAX_DAYS))->atPath('endDate')->addViolation();
            }

            return;
        }

        if (null === $this->startMonth || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $this->startMonth)) {
            $context->buildViolation('Выберите месяц')->atPath('startMonth')->addViolation();
        }
        if (null === $this->months || $this->months < 1 || $this->months > 12) {
            $context->buildViolation('Срок — от 1 до 12 месяцев')->atPath('months')->addViolation();
        }
    }
}
