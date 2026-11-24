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

    /**
     * A sum is entered. Not named hasAmount(): the form reads "amount" through PropertyAccess, which would take
     * a has*() method for the property and hand the money field a bool.
     */
    public function isFilled(): bool
    {
        return null !== $this->amount && '' !== trim($this->amount) && (float) $this->amount > 0;
    }

    #[Assert\Callback]
    public function validateDueDate(ExecutionContextInterface $context): void
    {
        if ($this->isFilled() && null === $this->dueDate) {
            $context->buildViolation('Укажите срок оплаты')->atPath('dueDate')->addViolation();
        }
    }
}
