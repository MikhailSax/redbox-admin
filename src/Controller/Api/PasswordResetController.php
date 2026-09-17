<?php

namespace App\Controller\Api;

use App\Dto\Api\ForgotPasswordRequest;
use App\Dto\Api\NewPasswordRequest;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Gesdinet\JWTRefreshTokenBundle\Model\RevokeRefreshTokenManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Security\Http\Authentication\AuthenticationSuccessHandler;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use SymfonyCasts\Bundle\ResetPassword\Exception\ExpiredResetPasswordTokenException;
use SymfonyCasts\Bundle\ResetPassword\Exception\ResetPasswordExceptionInterface;
use SymfonyCasts\Bundle\ResetPassword\Exception\TooManyPasswordRequestsException;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;

/**
 * "Забыли пароль?" of the website's personal account: a one-time link by e-mail, then a new password.
 */
#[Route('/api/v1/auth', name: 'api_auth_')]
final class PasswordResetController extends AbstractController
{
    public function __construct(
        private readonly ResetPasswordHelperInterface $resetPasswordHelper,
    ) {
    }

    /**
     * POST {"email"}. The answer is the same whether the account exists or not, so the form can't be used
     * to find out who is a client; the e-mail goes to clients only and not more often than throttle_limit.
     */
    #[Route('/forgot-password', name: 'forgot_password', methods: ['POST'])]
    public function forgot(
        Request $request,
        #[MapRequestPayload] ForgotPasswordRequest $form,
        UserRepository $users,
        MailerInterface $mailer,
        #[Target('password_resets')] RateLimiterFactoryInterface $passwordResetsLimiter,
        #[Autowire('%env(WEBSITE_URL)%')] string $websiteUrl,
        #[Autowire('%env(MAILER_FROM)%')] string $from,
    ): JsonResponse {
        $limit = $passwordResetsLimiter->create($request->getClientIp())->consume();
        if (!$limit->isAccepted()) {
            return $this->json([
                'error' => 'too_many_requests',
                'message' => 'Слишком много запросов с этого адреса. Попробуйте позже.',
            ], Response::HTTP_TOO_MANY_REQUESTS, ['Retry-After' => $limit->getRetryAfter()->getTimestamp() - time()]);
        }

        $user = $users->findOneBy(['email' => mb_strtolower(trim((string) $form->email))]);
        if ($user instanceof User && $user->isClient()) {
            try {
                $token = $this->resetPasswordHelper->generateResetToken($user);
                $mailer->send((new TemplatedEmail())
                    ->from(Address::create($from))
                    ->to(new Address((string) $user->getEmail(), (string) $user->getName()))
                    ->subject('Восстановление пароля — REDBOX')
                    ->htmlTemplate('emails/reset_password.html.twig')
                    ->textTemplate('emails/reset_password.txt.twig')
                    ->context([
                        'name' => $user->getName(),
                        'url' => rtrim($websiteUrl, '/').'/reset-password?token='.urlencode($token->getToken()),
                        'minutes' => intdiv($this->resetPasswordHelper->getTokenLifetime(), 60),
                    ]));
            } catch (TooManyPasswordRequestsException) {
                // an e-mail was sent a moment ago: the client already has a working link
            }
        }

        return $this->json([
            'message' => 'Если такой аккаунт есть, мы отправили на почту ссылку для смены пароля. Она действует час.',
        ], Response::HTTP_ACCEPTED);
    }

    /**
     * POST {"token", "password"}: sets the password, signs the client out everywhere else and in here
     * (same body as a sign-in: {"token", "refresh_token"}).
     */
    #[Route('/reset-password', name: 'reset_password', methods: ['POST'])]
    public function reset(
        #[MapRequestPayload] NewPasswordRequest $form,
        UserPasswordHasherInterface $hasher,
        EntityManagerInterface $entityManager,
        RevokeRefreshTokenManagerInterface $refreshTokens,
        ClockInterface $clock,
        #[Autowire(service: 'lexik_jwt_authentication.handler.authentication_success')] AuthenticationSuccessHandler $authenticationSuccess,
    ): Response {
        try {
            $user = $this->resetPasswordHelper->validateTokenAndFetchUser((string) $form->token);
        } catch (ResetPasswordExceptionInterface $exception) {
            return $this->json([
                'error' => 'invalid_reset_token',
                'message' => $exception instanceof ExpiredResetPasswordTokenException
                    ? 'Ссылка устарела. Запросите восстановление пароля ещё раз.'
                    : 'Ссылка недействительна или уже использована. Запросите восстановление пароля ещё раз.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (!$user instanceof User || !$user->isClient()) {
            $this->resetPasswordHelper->removeResetRequest((string) $form->token);

            return $this->json(['error' => 'invalid_reset_token', 'message' => 'Ссылка недействительна. Запросите восстановление пароля ещё раз.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // The link works once; following it proves the e-mail is the client's
        $this->resetPasswordHelper->removeResetRequest((string) $form->token);
        $user->setPassword($hasher->hashPassword($user, (string) $form->password))
            ->markEmailVerified($clock->now());
        $entityManager->flush();
        $refreshTokens->revokeAllForUser($user);

        return $authenticationSuccess->handleAuthenticationSuccess($user);
    }
}
