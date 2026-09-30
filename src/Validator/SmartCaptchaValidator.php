<?php

namespace App\Validator;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Asks Yandex whether the token is genuine (https://yandex.cloud/ru/docs/smartcaptcha/concepts/validation).
 *
 * Without a server key nothing is checked, as in dev and tests. When Yandex itself can't be reached the form goes
 * through: a captcha outage must not stop orders; the honeypot and the rate limiter still hold.
 */
final class SmartCaptchaValidator extends ConstraintValidator
{
    public const VALIDATE_URL = 'https://smartcaptcha.yandexcloud.net/validate';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly RequestStack $requests,
        private readonly LoggerInterface $logger,
        #[Autowire(env: 'SMARTCAPTCHA_SERVER_KEY')]
        private readonly string $serverKey,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof SmartCaptcha) {
            throw new UnexpectedTypeException($constraint, SmartCaptcha::class);
        }
        if ('' === $this->serverKey) {
            return;
        }

        $token = trim((string) $value);
        if ('' === $token) {
            $this->context->buildViolation($constraint->message)->addViolation();

            return;
        }

        try {
            $response = $this->httpClient->request('POST', self::VALIDATE_URL, [
                'body' => array_filter([
                    'secret' => $this->serverKey,
                    'token' => $token,
                    'ip' => $this->requests->getCurrentRequest()?->getClientIp(),
                ], static fn (?string $field) => null !== $field),
                'timeout' => 5,
            ]);
            if (200 !== $response->getStatusCode()) {
                $this->logger->warning('SmartCaptcha answered {status}; the form is let through', ['status' => $response->getStatusCode()]);

                return;
            }
            $status = $response->toArray(false)['status'] ?? null;
        } catch (ExceptionInterface $exception) {
            $this->logger->warning('SmartCaptcha is unreachable; the form is let through', ['exception' => $exception]);

            return;
        }

        if ('ok' !== $status) {
            $this->context->buildViolation($constraint->message)->addViolation();
        }
    }
}
