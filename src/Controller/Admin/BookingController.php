<?php

namespace App\Controller\Admin;

use App\Dto\BookingRequest;
use App\Entity\Booking;
use App\Entity\Product;
use App\Entity\ProductSide;
use App\Entity\User;
use App\Enum\BookingMode;
use App\Enum\BookingStatus;
use App\Form\BookingFormType;
use App\Form\NewClientFields;
use App\Repository\BookingRepository;
use App\Service\Availability\AvailabilityResolver;
use App\Service\BookingException;
use App\Service\BookingManager;
use App\Service\ClientCardException;
use App\Service\ClientCards;
use App\Service\MonthCalendar;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class BookingController extends AbstractController
{
    private const GRID_MONTHS = 12;

    /** Airtime is sold by days: its grid shows this many days from today */
    private const GRID_DAYS = 42;

    public function __construct(
        private readonly BookingManager $bookingManager,
        private readonly BookingRepository $bookings,
        private readonly AvailabilityResolver $availability,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/admin/bookings', name: 'admin_booking_index', methods: ['GET'])]
    #[IsGranted(User::ROLE_SUPER_MANAGER)]
    public function index(Request $request, #[MapQueryParameter] ?string $status = null, #[MapQueryParameter] ?string $q = null): Response
    {
        // "unpaid" is not a status: confirmed bookings of post-paying clients still waiting for the money
        $unpaid = 'unpaid' === $status;
        $filter = null !== $status && !$unpaid ? BookingStatus::tryFrom($status) : null;
        $params = [
            'bookings' => $this->bookings->findForList($filter, $q, unpaid: $unpaid),
            'filter' => $filter,
            'unpaid' => $unpaid,
            'q' => $q,
            'now' => $this->clock->now(),
        ];

        if ($request->headers->has('X-Live-Filter')) {
            return $this->renderBlock('admin/booking/index.html.twig', 'results', $params);
        }

        return $this->render('admin/booking/index.html.twig', $params);
    }

    /**
     * Occupancy grid of the product's sides for the next months, its bookings and the "new booking" form.
     * Agents see the grid only: they make no bookings.
     */
    #[Route('/admin/products/{id}/bookings', name: 'admin_booking_product', requirements: ['id' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function product(Request $request, Product $product, ClientCards $clientCards): Response
    {
        if ($request->isMethod('POST')) {
            $this->denyAccessUnlessGranted(User::ROLE_SUPER_MANAGER);
        }
        $now = $this->clock->now();
        // Each side is booked by its own type: airtime sides by days, the others by months
        $airtime = $product->hasAirtimeSides();
        $whole = $product->hasWholeSides();
        if (!$airtime && !$whole) { // no sides yet
            $airtime = (bool) $product->getProductType()?->getBookingMode()->isAirtime();
            $whole = !$airtime;
        }

        $bookingRequest = new BookingRequest();
        if (1 === $product->getSides()->count()) {
            $bookingRequest->side = $product->getSides()->first();
        }
        if ($airtime) {
            $bookingRequest->startDate = $now->setTime(0, 0);
            $bookingRequest->endDate = $now->setTime(0, 0)->modify(\sprintf('+%d days', BookingMode::MIN_DAYS - 1));
        }

        $form = $this->createForm(BookingFormType::class, $bookingRequest, ['product' => $product, 'now' => $now]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                // "Новый клиент" instead of one from the list: its card is saved together with the booking
                $newClient = null === $bookingRequest->client ? NewClientFields::data($form) : null;
                if (null !== $newClient) {
                    [$bookingRequest->client, $created] = $clientCards->findOrCreate($newClient['title'], $newClient['phone'], $newClient['email'], $newClient['inn']);
                }
                $user = $this->getUser();
                $booking = $this->bookingManager->hold($bookingRequest, $user instanceof User ? $user : null);

                $this->addFlash('success', \sprintf(
                    'Бронь создана и ждёт подтверждения до %s. Без подтверждения или оплаты она снимется автоматически.',
                    $booking->getExpiresAt()->format('d.m.Y H:i'),
                ));
                if (null !== $newClient) {
                    $this->addFlash('success', \sprintf($created ? 'Клиент «%s» добавлен в базу клиентов' : 'Клиент «%s» уже был в базе — бронь на его карточке', $booking->getClientTitle()));
                }

                return $this->redirectToRoute('admin_booking_product', ['id' => $product->getId()], Response::HTTP_SEE_OTHER);
            } catch (BookingException $e) {
                $form->addError(new FormError($e->getMessage()));
            } catch (ClientCardException $e) {
                $form->get('newClient')->addError(new FormError($e->getMessage()));
            }
        }

        // One occupancy grid per way of booking: airtime by days, whole sides by months
        $grids = [];
        foreach (array_filter([true => $airtime, false => $whole]) as $mode => $present) {
            $columns = $mode ? self::dayColumns($now) : self::monthColumns($now);
            $grids[] = [
                'airtime' => (bool) $mode,
                'columns' => $columns,
                'cells' => $this->bookingManager->occupancy($product, array_map(static fn (array $c) => [$c['from'], $c['to']], $columns)),
                'sides' => $product->getSides()->filter(static fn (ProductSide $side) => $side->isAirtime() === (bool) $mode)->getValues(),
            ];
        }

        return $this->render('admin/booking/product.html.twig', [
            'product' => $product,
            'form' => $form,
            'grids' => $grids,
            'bookings' => $this->bookings->findForProduct($product),
            'availability' => $this->availability->forProduct($product->getId(), $now),
            'activeBookings' => $this->bookings->countActiveForProduct($product, $now),
            'airtime' => $airtime,
            'whole' => $whole,
            'mixed' => $airtime && $whole,
            'minDays' => BookingMode::MIN_DAYS,
            'now' => $now,
        ]);
    }

    /** Post-paying clients: the side is theirs before the money comes */
    #[Route('/admin/bookings/{id}/confirm', name: 'admin_booking_confirm', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"confirm-booking-" ~ args["booking"].getId()'))]
    #[IsGranted(User::ROLE_SUPER_MANAGER)]
    public function confirm(Request $request, Booking $booking): Response
    {
        return $this->apply($request, $booking, fn () => $this->bookingManager->confirm($booking), 'Бронь подтверждена и больше не снимется автоматически. Оплату отметьте, когда придут деньги.');
    }

    #[Route('/admin/bookings/{id}/pay', name: 'admin_booking_pay', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"pay-booking-" ~ args["booking"].getId()'))]
    #[IsGranted(User::ROLE_SUPER_MANAGER)]
    public function pay(Request $request, Booking $booking): Response
    {
        $wasHold = BookingStatus::Hold === $booking->getStatus();

        return $this->apply($request, $booking, fn () => $this->bookingManager->markPaid($booking), $wasHold ? 'Оплата отмечена, бронь подтверждена' : 'Оплата отмечена');
    }

    #[Route('/admin/bookings/{id}/unpay', name: 'admin_booking_unpay', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"unpay-booking-" ~ args["booking"].getId()'))]
    #[IsGranted(User::ROLE_SUPER_MANAGER)]
    public function unpay(Request $request, Booking $booking): Response
    {
        return $this->apply($request, $booking, fn () => $this->bookingManager->markUnpaid($booking), 'Отметка об оплате снята, бронь остаётся подтверждённой');
    }

    #[Route('/admin/bookings/{id}/cancel', name: 'admin_booking_cancel', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"cancel-booking-" ~ args["booking"].getId()'))]
    #[IsGranted(User::ROLE_SUPER_MANAGER)]
    public function cancel(Request $request, Booking $booking): Response
    {
        return $this->apply($request, $booking, fn () => $this->bookingManager->cancel($booking), 'Бронь отменена, место освобождено');
    }

    /**
     * @return array<string, array{from: \DateTimeImmutable, to: \DateTimeImmutable, label: string, sub: ?string, weekend: bool}>
     */
    private static function monthColumns(\DateTimeImmutable $now): array
    {
        $columns = [];
        foreach (MonthCalendar::range($now, self::GRID_MONTHS) as $month) {
            $columns[$month->format('Y-m')] = ['from' => $month, 'to' => MonthCalendar::lastDay($month), 'label' => MonthCalendar::label($month, true), 'sub' => null, 'weekend' => false];
        }

        return $columns;
    }

    /**
     * @return array<string, array{from: \DateTimeImmutable, to: \DateTimeImmutable, label: string, sub: ?string, weekend: bool}>
     */
    private static function dayColumns(\DateTimeImmutable $now): array
    {
        $weekdays = ['пн', 'вт', 'ср', 'чт', 'пт', 'сб', 'вс'];
        $columns = [];
        for ($i = 0, $day = $now->setTime(0, 0); $i < self::GRID_DAYS; ++$i, $day = $day->modify('+1 day')) {
            $columns[$day->format('Y-m-d')] = [
                'from' => $day,
                'to' => $day,
                'label' => $day->format('j'),
                // month name above the first day and every 1st
                'sub' => 0 === $i || '1' === $day->format('j') ? MonthCalendar::label($day, true) : $weekdays[(int) $day->format('N') - 1],
                'weekend' => (int) $day->format('N') >= 6,
            ];
        }

        return $columns;
    }

    private function apply(Request $request, Booking $booking, callable $action, string $successMessage): Response
    {
        try {
            $action();
            $this->addFlash('success', $successMessage);
        } catch (BookingException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        // Back to where the button was pressed: dashboard, the global list or the product's booking page
        $return = $request->getPayload()->getString('return');
        if ('dashboard' === $return) {
            return $this->redirectToRoute('admin_dashboard', status: Response::HTTP_SEE_OTHER);
        }
        if ('list' === $return) {
            return $this->redirectToRoute('admin_booking_index', status: Response::HTTP_SEE_OTHER);
        }

        return $this->redirectToRoute('admin_booking_product', ['id' => $booking->getProduct()->getId()], Response::HTTP_SEE_OTHER);
    }
}
