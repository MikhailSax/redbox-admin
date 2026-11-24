<?php

namespace App\Controller\Api;

use App\Dto\Api\FeedbackRequest;
use App\Entity\Lead;
use App\Entity\User;
use App\Service\Notifications;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * "Обратная связь" from the website: a name, a phone and a message land in the CRM's requests
 * (marked as feedback, without structures), and the staff hear about it in the bell.
 */
#[Route('/api/v1', name: 'api_')]
final class FeedbackController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        #[Target('feedback')]
        private readonly RateLimiterFactoryInterface $feedbackLimiter,
        private readonly Notifications $notifications,
    ) {
    }

    #[Route('/feedback', name: 'feedback_create', methods: ['POST'])]
    public function create(Request $request, #[MapRequestPayload] FeedbackRequest $feedback): JsonResponse
    {
        $limit = $this->feedbackLimiter->create($request->getClientIp())->consume();
        if (!$limit->isAccepted()) {
            return $this->json([
                'error' => 'too_many_requests',
                'message' => 'Слишком много сообщений с этого адреса. Попробуйте позже или позвоните нам.',
            ], Response::HTTP_TOO_MANY_REQUESTS, ['Retry-After' => $limit->getRetryAfter()->getTimestamp() - time()]);
        }

        // a signed-in client sees the message in the account's feed
        $user = $this->getUser();
        $lead = (new Lead())
            ->setSource(Lead::SOURCE_FEEDBACK)
            ->setContactName(trim((string) $feedback->name))
            ->setPhone(trim((string) $feedback->phone))
            ->setEmail(null !== $feedback->email && '' !== trim($feedback->email) ? trim($feedback->email) : null)
            ->setCompanyName(null !== $feedback->company && '' !== trim($feedback->company) ? trim($feedback->company) : null)
            ->setComment(trim((string) $feedback->message))
            ->setClient($user instanceof User && $user->isClient() ? $user : null);

        $this->entityManager->persist($lead);
        $this->entityManager->flush();
        $this->notifications->feedbackReceived($lead);

        return $this->json([
            'id' => $lead->getId(),
            'message' => 'Спасибо! Сообщение отправлено, менеджер перезвонит вам в рабочее время.',
        ], Response::HTTP_CREATED);
    }
}
