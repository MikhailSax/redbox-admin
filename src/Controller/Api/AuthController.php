<?php

namespace App\Controller\Api;

use App\Dto\Api\RegisterRequest;
use App\Entity\User;
use App\Enum\ClientType;
use App\Service\Api\AccountPresenter;
use App\Service\EmailVerification;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Security\Http\Authentication\AuthenticationSuccessHandler;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Personal account on the website: sign-up here; sign-in, refresh and sign-out are handled by the "api" firewall
 * (json_login, refresh_jwt, logout in security.yaml) — their actions below only give the paths a route.
 *
 * Every successful response carries {"token": JWT (15 min), "refresh_token": …}.
 */
#[Route('/api/v1/auth', name: 'api_auth_')]
final class AuthController extends AbstractController
{
    /** POST {"email", "password"} */
    #[Route('/login', name: 'login', methods: ['POST'])]
    public function login(): never
    {
        throw new \LogicException('Handled by json_login of the "api" firewall.');
    }

    /** POST {"refresh_token"} */
    #[Route('/refresh', name: 'refresh', methods: ['POST'])]
    public function refresh(): never
    {
        throw new \LogicException('Handled by refresh_jwt of the "api" firewall.');
    }

    /** POST {"refresh_token"} with the JWT: forgets the refresh token */
    #[Route('/logout', name: 'logout', methods: ['POST'])]
    public function logout(): never
    {
        throw new \LogicException('Handled by logout of the "api" firewall.');
    }

    #[Route('/register', name: 'register', methods: ['POST'])]
    public function register(
        Request $request,
        #[MapRequestPayload] RegisterRequest $form,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $hasher,
        ValidatorInterface $validator,
        AccountPresenter $presenter,
        EmailVerification $emailVerification,
        #[Target('registrations')] RateLimiterFactoryInterface $registrationsLimiter,
        #[Autowire(service: 'lexik_jwt_authentication.handler.authentication_success')] AuthenticationSuccessHandler $authenticationSuccess,
    ): Response {
        $limit = $registrationsLimiter->create($request->getClientIp())->consume();
        if (!$limit->isAccepted()) {
            return $this->json([
                'error' => 'too_many_requests',
                'message' => 'Слишком много регистраций с этого адреса. Попробуйте позже.',
            ], Response::HTTP_TOO_MANY_REQUESTS, ['Retry-After' => $limit->getRetryAfter()->getTimestamp() - time()]);
        }

        $inn = null !== $form->inn ? preg_replace('/\D+/', '', $form->inn) : '';
        $user = (new User())
            ->setRole(User::ROLE_CLIENT)
            ->setEmail($form->email)
            ->setName(trim((string) $form->name))
            ->setPhone($form->phone)
            ->setClientType(ClientType::fromInn($inn))
            ->setCompany($form->company)
            ->setInn($inn)
            ->clearRequisitesOfOtherTypes();
        $user->setPassword($hasher->hashPassword($user, (string) $form->password));

        $violations = $validator->validate($user);
        if (\count($violations) > 0) {
            throw new UnprocessableEntityHttpException('', new ValidationFailedException($user, $violations));
        }

        $entityManager->persist($user);
        $entityManager->flush();
        $emailVerification->send($user);

        // Same body as a sign-in (the refresh token is attached by gesdinet's success listener), plus the profile
        $response = $authenticationSuccess->handleAuthenticationSuccess($user, null, ['user' => $presenter->profile($user)]);
        $response->setStatusCode(Response::HTTP_CREATED);

        return $response;
    }
}
