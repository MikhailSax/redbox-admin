<?php

namespace App\Validator;

use App\Repository\PromotionRepository;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class RunningPromoCodeValidator extends ConstraintValidator
{
    public function __construct(
        private readonly PromotionRepository $promotions,
        private readonly ClockInterface $clock,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof RunningPromoCode) {
            throw new UnexpectedTypeException($constraint, RunningPromoCode::class);
        }
        if (null === $value || '' === trim((string) $value)) {
            return;
        }

        if (null === $this->promotions->findRunningByCode((string) $value, $this->clock->now())) {
            $this->context->buildViolation($constraint->message)
                ->setParameter('{{ code }}', (string) $value)
                ->addViolation();
        }
    }
}
