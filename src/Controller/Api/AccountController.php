<?php

namespace App\Controller\Api;

use App\Dto\Api\PasswordRequest;
use App\Dto\Api\ProfileRequest;
use App\Entity\ClientDocument;
use App\Entity\MediaPlan;
use App\Entity\PhotoReportPhoto;
use App\Entity\User;
use App\Repository\ClientDocumentRepository;
use App\Repository\LeadRepository;
use App\Repository\MediaPlanRepository;
use App\Repository\PaymentRepository;
use App\Repository\PhotoReportRepository;
use App\Security\ClientFileVoter;
use App\Service\Api\AccountPresenter;
use App\Service\MediaPlanPdf;
use App\Service\PrivateFileStorage;
use Doctrine\ORM\EntityManagerInterface;
use Gesdinet\JWTRefreshTokenBundle\Model\RevokeRefreshTokenManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Security\Http\Authentication\AuthenticationSuccessHandler;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * The client's personal account on the website. Only ROLE_CLIENT gets here (access_control ^/api/v1/me),
 * and every query is narrowed to the signed-in client.
 */
#[Route('/api/v1/me', name: 'api_me_')]
final class AccountController extends AbstractController
{
    public function __construct(
        private readonly AccountPresenter $presenter,
    ) {
    }

    #[Route('', name: 'profile', methods: ['GET'])]
    public function profile(#[CurrentUser] User $user): JsonResponse
    {
        return $this->json($this->presenter->profile($user));
    }

    #[Route('', name: 'profile_update', methods: ['PATCH', 'PUT'])]
    public function updateProfile(#[CurrentUser] User $user, #[MapRequestPayload] ProfileRequest $form, ValidatorInterface $validator, EntityManagerInterface $entityManager): JsonResponse
    {
        $user->setName(trim((string) $form->name))
            ->setPhone($form->phone)
            ->setClientType($form->clientType)
            ->setCompany($form->company)
            ->setInn($form->inn)
            ->setKpp($form->kpp)
            ->setOgrn($form->ogrn)
            ->setLegalAddress($form->legalAddress)
            ->clearRequisitesOfOtherTypes();

        $violations = $validator->validate($user);
        if (\count($violations) > 0) {
            throw new UnprocessableEntityHttpException('', new ValidationFailedException($user, $violations));
        }
        $entityManager->flush();

        return $this->json($this->presenter->profile($user));
    }

    /**
     * Signs the client out everywhere else: every refresh token is revoked and a new pair is returned for this device.
     */
    #[Route('/password', name: 'password', methods: ['POST'])]
    public function changePassword(
        #[CurrentUser] User $user,
        #[MapRequestPayload] PasswordRequest $form,
        UserPasswordHasherInterface $hasher,
        EntityManagerInterface $entityManager,
        RevokeRefreshTokenManagerInterface $refreshTokens,
        #[Autowire(service: 'lexik_jwt_authentication.handler.authentication_success')] AuthenticationSuccessHandler $authenticationSuccess,
    ): Response {
        $user->setPassword($hasher->hashPassword($user, (string) $form->new));
        $entityManager->flush();
        $refreshTokens->revokeAllForUser($user);

        return $authenticationSuccess->handleAuthenticationSuccess($user);
    }

    /** Media plans made for the client */
    #[Route('/campaigns', name: 'campaigns', methods: ['GET'])]
    public function campaigns(#[CurrentUser] User $user, MediaPlanRepository $plans): JsonResponse
    {
        return $this->json(array_map($this->presenter->campaign(...), $plans->findForClient($user)));
    }

    #[Route('/campaigns/{id}/pdf', name: 'campaign_pdf', requirements: ['id' => Requirement::DIGITS], methods: ['GET'])]
    public function campaignPdf(#[CurrentUser] User $user, MediaPlan $plan, MediaPlanPdf $pdf): Response
    {
        if ($plan->getClient()?->getId() !== $user->getId()) {
            throw $this->createNotFoundException('Кампания не найдена');
        }

        return new Response($pdf->render($plan), Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_INLINE, 'Медиаплан — '.$plan->getTitle().'.pdf', $pdf->asciiFilename($plan)),
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** Requests sent from the website while signed in */
    #[Route('/requests', name: 'requests', methods: ['GET'])]
    public function requests(#[CurrentUser] User $user, LeadRepository $leads): JsonResponse
    {
        return $this->json(array_map($this->presenter->lead(...), $leads->findBy(['client' => $user], ['createdAt' => 'DESC', 'id' => 'DESC'])));
    }

    #[Route('/payments', name: 'payments', methods: ['GET'])]
    public function payments(#[CurrentUser] User $user, PaymentRepository $payments): JsonResponse
    {
        return $this->json(array_map($this->presenter->payment(...), $payments->findForClient($user)));
    }

    #[Route('/documents', name: 'documents', methods: ['GET'])]
    public function documents(#[CurrentUser] User $user, ClientDocumentRepository $documents): JsonResponse
    {
        return $this->json(array_map($this->presenter->document(...), $documents->findForClient($user)));
    }

    #[Route('/documents/{document}/file', name: 'document_file', requirements: ['document' => Requirement::DIGITS], methods: ['GET'])]
    #[IsGranted(ClientFileVoter::VIEW, subject: 'document', statusCode: Response::HTTP_NOT_FOUND)]
    public function documentFile(ClientDocument $document, PrivateFileStorage $storage, #[MapQueryParameter] bool $inline = false): BinaryFileResponse
    {
        $response = $this->file(
            $storage->path($document->getFolder(), $document->getFileName()),
            $document->getOriginalName(),
            $inline && $document->isViewable() ? ResponseHeaderBag::DISPOSITION_INLINE : ResponseHeaderBag::DISPOSITION_ATTACHMENT,
        );
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    #[Route('/reports', name: 'reports', methods: ['GET'])]
    public function reports(#[CurrentUser] User $user, PhotoReportRepository $reports): JsonResponse
    {
        return $this->json(array_map($this->presenter->report(...), $reports->findForClient($user)));
    }

    #[Route('/photos/{photo}', name: 'photo', requirements: ['photo' => Requirement::DIGITS], methods: ['GET'])]
    #[IsGranted(ClientFileVoter::VIEW, subject: 'photo', statusCode: Response::HTTP_NOT_FOUND)]
    public function photo(PhotoReportPhoto $photo, PrivateFileStorage $storage): BinaryFileResponse
    {
        $response = $this->file($storage->path($photo->getReport()->getFolder(), $photo->getFileName()), $photo->getOriginalName(), ResponseHeaderBag::DISPOSITION_INLINE);
        $response->setPrivate();
        $response->setMaxAge(86400);

        return $response;
    }
}
