<?php

namespace App\EventListener;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Validator\Exception\ValidationFailedException;

/**
 * Errors of /api/... always come back as JSON, with the invalid fields named,
 * so the website can show them next to its inputs instead of a Symfony error page.
 */
#[AsEventListener(event: 'kernel.exception', priority: 64)]
final class ApiExceptionListener
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        if (!str_starts_with($event->getRequest()->getPathInfo(), '/api/')) {
            return;
        }

        $exception = $event->getThrowable();
        // Left to the firewall: it answers with its entry point (401 from the JWT authenticator, see ApiAuthListener)
        if ($exception instanceof AuthenticationException || $exception instanceof AccessDeniedException) {
            return;
        }

        $status = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : Response::HTTP_INTERNAL_SERVER_ERROR;
        // Setting a response stops Symfony's own listener, which would have logged the error
        if ($status >= 500) {
            $this->logger->critical('API error: {message}', ['message' => $exception->getMessage(), 'exception' => $exception]);
        }

        $validation = $exception->getPrevious();
        if ($validation instanceof ValidationFailedException) {
            $violations = [];
            foreach ($validation->getViolations() as $violation) {
                $violations[] = ['field' => $violation->getPropertyPath(), 'message' => $violation->getMessage()];
            }

            $event->setResponse(new JsonResponse([
                'error' => 'validation_failed',
                'message' => 'Проверьте заполненные поля',
                'violations' => $violations,
            ], Response::HTTP_UNPROCESSABLE_ENTITY));

            return;
        }

        $event->setResponse(new JsonResponse([
            'error' => self::code($status),
            'message' => $status < 500 && $exception instanceof HttpExceptionInterface ? $exception->getMessage() : 'Внутренняя ошибка сервиса',
        ], $status, $exception instanceof HttpExceptionInterface ? $exception->getHeaders() : []));
    }

    private static function code(int $status): string
    {
        return match ($status) {
            Response::HTTP_BAD_REQUEST => 'bad_request',
            Response::HTTP_UNAUTHORIZED => 'unauthorized',
            Response::HTTP_FORBIDDEN => 'forbidden',
            Response::HTTP_NOT_FOUND => 'not_found',
            Response::HTTP_METHOD_NOT_ALLOWED => 'method_not_allowed',
            Response::HTTP_TOO_MANY_REQUESTS => 'too_many_requests',
            default => 'server_error',
        };
    }
}
