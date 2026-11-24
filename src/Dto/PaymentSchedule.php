<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * A payment schedule typed in by the manager: one line per month of placement (PaymentScheduler::draft*()).
 * Nothing is split automatically: what the client pays in each month is what the manager enters.
 */
final class PaymentSchedule
{
    /**
     * @param list<PaymentScheduleLine> $lines
     */
    public function __construct(
        #[Assert\Valid]
        public array $lines = [],
    ) {
    }

    /** @return list<PaymentScheduleLine> */
    public function filled(): array
    {
        return array_values(array_filter($this->lines, static fn (PaymentScheduleLine $line) => $line->isFilled()));
    }

    /** Sum of the amounts entered, rubles */
    public function total(): float
    {
        return round(array_sum(array_map(static fn (PaymentScheduleLine $line) => (float) $line->amount, $this->filled())), 2);
    }

    #[Assert\Callback]
    public function validateSomethingEntered(ExecutionContextInterface $context): void
    {
        if ([] === $this->filled()) {
            $context->buildViolation('Введите сумму хотя бы за один месяц')->addViolation();
        }
    }
}
