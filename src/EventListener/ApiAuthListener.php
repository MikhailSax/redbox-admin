<?php

namespace App\EventListener;

use Lexik\Bundle\JWTAuthenticationBundle\Event\AuthenticationFailureEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTExpiredEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTInvalidEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTNotFoundEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;

/**
 * Sign-in and token errors of the website API in the same shape as the other API errors (ApiExceptionListener):
 * {"error": code, "message": text for the visitor}. The website tells "token_expired" apart to refresh the token.
 */
final class ApiAuthListener
{
    #[AsEventListener(event: Events::AUTHENTICATION_FAILURE)]
    public function onLoginFailure(AuthenticationFailureEvent $event): void
    {
        $exception = $event->getException();

        [$status, $code, $message] = match (true) {
            $exception instanceof TooManyLoginAttemptsAuthenticationException => [
                Response::HTTP_TOO_MANY_REQUESTS, 'too_many_attempts',
                \sprintf('Слишком много попыток входа. Попробуйте через %d мин.', max(1, (int) ceil(($exception->getMessageData()['%minutes%'] ?? 15)))),
            ],
            $exception instanceof CustomUserMessageAccountStatusException => [Response::HTTP_FORBIDDEN, 'not_a_client', $exception->getMessageKey()],
            default => [Response::HTTP_UNAUTHORIZED, 'invalid_credentials', 'Неверная почта или пароль'],
        };

        $event->setResponse(new JsonResponse(['error' => $code, 'message' => $message], $status));
    }

    #[AsEventListener(event: Events::JWT_EXPIRED)]
    public function onExpired(JWTExpiredEvent $event): void
    {
        $event->setResponse(self::unauthorized('token_expired', 'Сессия истекла, войдите снова'));
    }

    #[AsEventListener(event: Events::JWT_INVALID)]
    public function onInvalid(JWTInvalidEvent $event): void
    {
        $event->setResponse(self::unauthorized('token_invalid', 'Войдите в личный кабинет'));
    }

    #[AsEventListener(event: Events::JWT_NOT_FOUND)]
    public function onNotFound(JWTNotFoundEvent $event): void
    {
        $event->setResponse(self::unauthorized('unauthorized', 'Войдите в личный кабинет'));
    }

    private static function unauthorized(string $code, string $message): JsonResponse
    {
        return new JsonResponse(['error' => $code, 'message' => $message], Response::HTTP_UNAUTHORIZED, ['WWW-Authenticate' => 'Bearer']);
    }
}
