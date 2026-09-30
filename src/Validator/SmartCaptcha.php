<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * The value is a Yandex SmartCaptcha token the visitor got on the website.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class SmartCaptcha extends Constraint
{
    public string $message = 'Подтвердите, что вы не робот';

    public function __construct(?string $message = null, ?array $groups = null, mixed $payload = null)
    {
        parent::__construct(null, $groups, $payload);
        $this->message = $message ?? $this->message;
    }
}
