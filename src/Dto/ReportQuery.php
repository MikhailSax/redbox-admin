<?php

namespace App\Dto;

use App\Service\MonthCalendar;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Period of the sales report, bound from the query string: months "YYYY-MM" from and to, inclusive.
 * Either left out — the current month.
 */
final readonly class ReportQuery
{
    public function __construct(
        #[Assert\Regex('/^\d{4}-(0[1-9]|1[0-2])$/')]
        public ?string $from = null,
        #[Assert\Regex('/^\d{4}-(0[1-9]|1[0-2])$/')]
        public ?string $to = null,
    ) {
    }

    /**
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable} the first day of the first month, the last day of the last; turned around when given backwards
     */
    public function period(\DateTimeImmutable $now): array
    {
        $from = null !== $this->from && '' !== $this->from ? MonthCalendar::parse($this->from) : MonthCalendar::firstDay($now);
        $to = null !== $this->to && '' !== $this->to ? MonthCalendar::parse($this->to) : $from;
        if ($to < $from) {
            [$from, $to] = [$to, $from];
        }

        return [$from, MonthCalendar::lastDay($to)];
    }
}
