<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * One month of a payment schedule the manager fills in: how much the client pays for it and by when.
 * An empty amount means nothing is paid for that month.
 */
final class PaymentScheduleLine
{
    public function __construct(
        /** First day of the month of placement the line is for */
        public readonly \DateTimeImmutable $month,
        #[Assert\Positive(message: 'Сумма должна быть больше нуля')]
        public ?string $amount = null,
        public ?\DateTimeImmutable $dueDate = null,
    ) {
    }

    public function hasAmount(): bool
    {
        return null !== $this->amount && '' !== trim($this->amount) && (float) $this->amount > 0;
    }

    #[Assert\Callback]
    public function validateDueDate(ExecutionContextInterface $context): void
    {
        if ($this->hasAmount() && null === $this->dueDate) {
            $context->buildViolation('Укажите срок оплаты')->atPath('dueDate')->addViolation();
        }
    }
}
