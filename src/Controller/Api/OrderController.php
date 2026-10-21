<?php

namespace App\Controller\Api;

use App\Dto\Api\OrderItemRequest;
use App\Dto\Api\OrderRequest;
use App\Entity\Lead;
use App\Entity\LeadItem;
use App\Entity\ProductSide;
use App\Entity\User;
use App\Repository\PackageRepository;
use App\Service\BookingManager;
use App\Service\MediaPlanManager;
use App\Service\MonthCalendar;
use App\Service\Notifications;
use App\Service\Package\PackageException;
use App\Service\Package\PackageOffers;
use Symfony\Component\Clock\ClockInterface;
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
        private readonly Notifications $notifications,
        private readonly PackageRepository $packages,
        private readonly PackageOffers $packageOffers,
        private readonly ClockInterface $clock,
        private readonly BookingManager $bookings,
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
        $problems = [];
        foreach ($order->items as $index => $item) {
            $side = $this->entityManager->find(ProductSide::class, (int) $item->sideId);
            // a side out of order is not on the website: as if it were gone
            if (null === $side || !$side->isWorking()) {
                $unknown[] = $index;
                continue;
            }
            if (null !== $problem = $this->staticProblem($side, new \DateTimeImmutable((string) $item->from), new \DateTimeImmutable((string) $item->to))) {
                $problems[$index] = $problem;
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

        if ([] !== $problems) {
            return $this->json([
                'error' => 'unavailable_sides',
                'message' => implode(' ', array_unique($problems)),
                'items' => array_keys($problems),
                'problems' => $problems,
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // A package: its sides at the package prices, checked against the package and the bookings
        if (null !== $order->package) {
            $package = $this->packages->findRunningById((int) $order->package->id, $this->clock->now());
            if (null === $package) {
                return $this->json([
                    'error' => 'package_unavailable',
                    'message' => 'Это пакетное предложение больше не действует. Обновите страницу и выберите другое.',
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $start = new \DateTimeImmutable((string) $order->package->from);
            try {
                $lines = $this->packageOffers->select($package, $start, (int) $order->package->months, $order->package->selection());
            } catch (PackageException $e) {
                return $this->json(['error' => 'package_invalid', 'message' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            [$from, $to] = PackageOffers::period($start, (int) $order->package->months);
            $lead->setPackage($package);
            foreach ($lines as ['slot' => $slot, 'side' => $side, 'price' => $price]) {
                $lead->addItem((new LeadItem(
                    side: $side,
                    productTitle: self::productTitle($side),
                    sideName: $side->getName(),
                    startDate: $from,
                    endDate: $to,
                    slots: MediaPlanManager::slotsFor($side, null),
                    monthlyPrice: number_format($price, 2, '.', ''),
                ))->setPackageSlotTitle($slot->getTitle()));
            }
        }

        $this->entityManager->persist($lead);
        $this->entityManager->flush();
        $this->notifications->leadReceived($lead);

        return $this->json([
            'id' => $lead->getId(),
            'number' => $lead->getId(),
            'status' => $lead->getStatus()->value,
            'message' => \sprintf('Заявка №%d принята. Менеджер свяжется с вами.', (int) $lead->getId()),
        ], Response::HTTP_CREATED);
    }

    private function item(ProductSide $side, OrderItemRequest $request): LeadItem
    {
        $start = new \DateTimeImmutable((string) $request->from);
        $end = new \DateTimeImmutable((string) $request->to);
        // the site sells a screen by one slot; the manager changes the slots in the media plan if needed
        $slots = MediaPlanManager::slotsFor($side, null);
        $monthly = (float) ($side->getMonthlyPriceForDays(MonthCalendar::days($start, $end)) ?? 0) * ($slots ?? 1);

        return new LeadItem(
            side: $side,
            productTitle: self::productTitle($side),
            sideName: $side->getName(),
            startDate: $start,
            endDate: $end,
            slots: $slots,
            monthlyPrice: number_format($monthly, 2, '.', ''),
        );
    }

    /**
     * Why a static side can't be requested for the days: it is sold by whole calendar months, from a month
     * that hasn't begun yet, and never over another client's booking (confirmed or a hold in force).
     * Screens are sold by days and their slots are left for the manager to check.
     */
    private function staticProblem(ProductSide $side, \DateTimeImmutable $from, \DateTimeImmutable $to): ?string
    {
        if ($side->isAirtime()) {
            return null;
        }

        $name = \sprintf('«%s», сторона %s', $side->getProduct()?->getName(), $side->getName());
        if (!MonthCalendar::isWholeMonths($from, $to)) {
            return \sprintf('%s продаётся целыми месяцами — с 1-го по последнее число.', $name);
        }
        if ($from < $this->clock->now()->setTime(0, 0)) {
            return \sprintf('%s: месяц уже начался — выберите следующий.', $name);
        }
        if (null !== $this->bookings->availabilityProblem($side, $from, $to, null)) {
            return \sprintf('%s уже занята на %s — выберите другие месяцы.', $name, MonthCalendar::periodLabel($from, $to));
        }

        return null;
    }

    /** "№7/23 · Щит на Ленина" */
    private static function productTitle(ProductSide $side): string
    {
        $product = $side->getProduct();

        return trim(\sprintf('%s%s', $product?->getSchemeNumber() ? '№'.$product->getSchemeNumber().' · ' : '', (string) $product?->getName()));
    }
}
