<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Service\EmailVerification;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use SymfonyCasts\Bundle\VerifyEmail\Exception\VerifyEmailExceptionInterface;

/**
 * Confirmation of the e-mail of a website account (see EmailVerification).
 */
final class EmailVerificationController extends AbstractController
{
    public function __construct(
        private readonly EmailVerification $verification,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * POST {"id", "expires", "signature", "token"} — the query of the link from the e-mail.
     * Needs no sign-in: the link is often opened on another device or browser.
     */
    #[Route('/api/v1/auth/verify-email', name: EmailVerification::ROUTE, methods: ['POST'])]
    public function confirm(Request $request): JsonResponse
    {
        try {
            $user = $this->verification->confirm($request->getPayload()->all());
        } catch (VerifyEmailExceptionInterface $exception) {
            return $this->json([
                'error' => 'invalid_verification_link',
                'message' => EmailVerification::isExpired($exception)
                    ? 'Ссылка устарела. Войдите в кабинет и отправьте письмо ещё раз.'
                    : 'Ссылка недействительна: её могли скопировать не полностью или почта аккаунта изменилась. Войдите в кабинет и отправьте письмо ещё раз.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->entityManager->flush();

        return $this->json(['message' => \sprintf('Почта %s подтверждена', $user->getEmail()), 'email' => $user->getEmail()]);
    }

    /** Sends the confirmation e-mail again to the signed-in client */
    #[Route('/api/v1/me/verify-email', name: 'api_me_verify_email_resend', methods: ['POST'])]
    public function resend(#[CurrentUser] User $user, #[Target('email_verifications')] RateLimiterFactoryInterface $emailVerificationsLimiter): JsonResponse
    {
        if ($user->isEmailVerified()) {
            return $this->json(['message' => 'Почта уже подтверждена']);
        }

        $limit = $emailVerificationsLimiter->create('client-'.$user->getId())->consume();
        if (!$limit->isAccepted()) {
            return $this->json([
                'error' => 'too_many_requests',
                'message' => \sprintf('Письмо уже отправлено. Повторить можно через %d мин.', max(1, (int) ceil(($limit->getRetryAfter()->getTimestamp() - time()) / 60))),
            ], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $this->verification->send($user);

        return $this->json(['message' => \sprintf('Письмо со ссылкой отправлено на %s', $user->getEmail())], Response::HTTP_ACCEPTED);
    }
}
