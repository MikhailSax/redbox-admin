<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * The value is the code of a promotion that is running today (empty values are fine).
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class RunningPromoCode extends Constraint
{
    public string $message = 'Промокод «{{ code }}» не найден или сейчас не действует';

    public function __construct(?string $message = null, ?array $groups = null, mixed $payload = null)
    {
        parent::__construct(null, $groups, $payload);
        $this->message = $message ?? $this->message;
    }
}
