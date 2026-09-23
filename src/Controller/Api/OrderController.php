<?php

namespace App\Controller\Api;

use App\Dto\Api\OrderItemRequest;
use App\Dto\Api\OrderRequest;
use App\Entity\Lead;
use App\Entity\LeadItem;
use App\Entity\ProductSide;
use App\Entity\User;
use App\Service\MediaPlanManager;
use App\Service\MonthCalendar;
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
 * "Заявка на размещение" from the website: the cart lands in the CRM as a request.
 * Nothing is booked here — a manager checks the request and turns it into a media plan or bookings.
 */
#[Route('/api/v1', name: 'api_')]
final class OrderController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        #[Target('orders')]
        private readonly RateLimiterFactoryInterface $ordersLimiter,
    ) {
    }

    #[Route('/orders', name: 'order_create', methods: ['POST'])]
    public function create(Request $request, #[MapRequestPayload] OrderRequest $order): JsonResponse
    {
        // A signed-in client has an allowance of their own, a visitor shares one with their address
        $user = $this->getUser();
        $client = $user instanceof User && $user->isClient() ? $user : null;
        $limit = $this->ordersLimiter->create(null !== $client ? 'client-'.$client->getId() : $request->getClientIp())->consume();
        if (!$limit->isAccepted()) {
            return $this->json([
                'error' => 'too_many_requests',
                'message' => 'Слишком много заявок с этого адреса. Попробуйте позже или позвоните нам.',
            ], Response::HTTP_TOO_MANY_REQUESTS, ['Retry-After' => $limit->getRetryAfter()->getTimestamp() - time()]);
        }

        $lead = (new Lead())
            ->setSource(Lead::SOURCE_SITE)
            ->setContactName($order->contactName)
            ->setPhone($order->phone)
            ->setEmail($order->email)
            ->setCompanyName($order->company)
            ->setInn($order->inn)
            ->setKpp($order->kpp)
            ->setPaymentType($order->payment)
            ->setComment($order->comment)
            ->setClient($client);

        $unknown = [];
        foreach ($order->items as $index => $item) {
            $side = $this->entityManager->find(ProductSide::class, (int) $item->sideId);
            if (null === $side) {
                $unknown[] = $index;
                continue;
            }
            $lead->addItem($this->item($side, $item));
        }

        if ([] !== $unknown) {
            return $this->json([
                'error' => 'unknown_sides',
                'message' => 'Некоторых конструкций из заявки больше нет. Обновите страницу и попробуйте снова.',
                'items' => $unknown,
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->entityManager->persist($lead);
        $this->entityManager->flush();

        return $this->json([
            'id' => $lead->getId(),
            'number' => $lead->getId(),
            'status' => $lead->getStatus()->value,
            'message' => \sprintf('Заявка №%d принята. Менеджер свяжется с вами.', (int) $lead->getId()),
        ], Response::HTTP_CREATED);
    }

    private function item(ProductSide $side, OrderItemRequest $request): LeadItem
    {
        $product = $side->getProduct();
        $start = new \DateTimeImmutable((string) $request->from);
        $end = new \DateTimeImmutable((string) $request->to);
        // the site sells a screen by one slot; the manager changes the slots in the media plan if needed
        $slots = MediaPlanManager::slotsFor($side, null);
        $monthly = (float) ($side->getMonthlyPriceForDays(MonthCalendar::days($start, $end)) ?? 0) * ($slots ?? 1);

        return new LeadItem(
            side: $side,
            productTitle: trim(\sprintf('%s%s', $product?->getSchemeNumber() ? '№'.$product->getSchemeNumber().' · ' : '', (string) $product?->getName())),
            sideName: $side->getName(),
            startDate: $start,
            endDate: $end,
            slots: $slots,
            monthlyPrice: number_format($monthly, 2, '.', ''),
        );
    }
}
