<?php

namespace App\Controller\Admin;

use App\Dto\BookingListQuery;
use App\Dto\BookingRequest;
use App\Dto\ServiceLineInput;
use App\Entity\Booking;
use App\Entity\BookingServiceLine;
use App\Entity\Product;
use App\Entity\ProductSide;
use App\Entity\User;
use App\Enum\BookingMode;
use App\Enum\BookingStatus;
use App\Form\BookingFormType;
use App\Form\NewClientFields;
use App\Form\ServiceLineFormType;
use App\Repository\BookingRepository;
use App\Service\Availability\AvailabilityResolver;
use App\Service\BookingException;
use App\Service\BookingManager;
use App\Service\ClientCardException;
use App\Service\ClientCards;
use App\Service\MonthCalendar;
use App\Service\PaymentException;
use App\Service\PaymentScheduler;
use App\Service\ShipmentReport;
use App\Twig\AdminExtension;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
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
    public function index(Request $request, #[MapQueryString] BookingListQuery $query = new BookingListQuery()): Response
    {
        $params = [
            'bookings' => $this->bookings->findForList($query),
            'statusCounts' => $this->bookings->countByStatus($query),
            'query' => $query,
            'now' => $this->clock->now(),
        ];

        if ($request->headers->has('X-Live-Filter')) {
            return $this->renderBlock('admin/booking/index.html.twig', 'results', $params);
        }

        return $this->render('admin/booking/index.html.twig', $params + [
            // half a year back for the bookings sold, a year ahead for the ones to come
            'months' => MonthCalendar::range($this->clock->now()->modify('-6 months'), 18),
            'authors' => $this->bookings->findAuthors(),
        ]);
    }

    /**
     * Occupancy grid of the product's sides for the next months, its bookings and the "new booking" form.
     * Agents book too (a 24h hold); confirming, payments, cancelling and the price stay with managers.
     */
    #[Route('/admin/products/{id}/bookings', name: 'admin_booking_product', requirements: ['id' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function product(
        Request $request,
        Product $product,
        ClientCards $clientCards,
        #[MapQueryParameter] ?string $month = null,
        // the side and the month picked in the structures list: the new booking form starts with them
        #[MapQueryParameter] ?int $side = null,
        #[MapQueryParameter(filter: \FILTER_VALIDATE_REGEXP, options: ['regexp' => '/^\d{4}-(0[1-9]|1[0-2])$/'])] ?string $from = null,
        // the bookings list: one side only, and the column it is sorted by
        #[MapQueryParameter(name: 'list_side')] ?int $listSide = null,
        #[MapQueryParameter] ?string $sort = null,
        #[MapQueryParameter] ?string $dir = null,
    ): Response {
        $now = $this->clock->now();
        // Each side is booked by its own type: airtime sides by days, the others by months
        $airtime = $product->hasAirtimeSides();
        $whole = $product->hasWholeSides();
        if (!$airtime && !$whole) { // no sides yet
            $airtime = (bool) $product->getProductType()?->getBookingMode()->isAirtime();
            $whole = !$airtime;
        }

        $bookingRequest = new BookingRequest();
        $working = $product->getSides()->filter(static fn (ProductSide $side) => $side->isWorking());
        if (1 === $working->count()) {
            $bookingRequest->side = $working->first();
        }
        $picked = $working->findFirst(static fn (int $i, ProductSide $s) => $s->getId() === $side);
        if (null !== $picked) {
            $bookingRequest->side = $picked;
        }
        // a month ahead starts the booking on its 1st, the current one today
        $start = null !== $from && $from > $now->format('Y-m') ? MonthCalendar::parse($from) : $now->setTime(0, 0);
        if ($airtime) {
            $bookingRequest->startDate = $start;
            $bookingRequest->endDate = $start->modify(\sprintf('+%d days', BookingMode::MIN_DAYS - 1));
        }
        if ($whole && null !== $from && $from >= $now->format('Y-m')) {
            $bookingRequest->startMonth = $from;
        }

        $form = $this->createForm(BookingFormType::class, $bookingRequest, ['product' => $product, 'now' => $now]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                // "Новый клиент" instead of one from the list: its card is saved together with the booking
                $newClient = null === $bookingRequest->client ? NewClientFields::data($form) : null;
                if (null !== $newClient) {
                    [$bookingRequest->client, $created] = $clientCards->findOrCreate($newClient['title'], $newClient['phone'], $newClient['email'], $newClient['inn'], type: $newClient['type']);
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

                // straight to the new booking's card: what was made, and what to do with it next
                return $this->redirectToRoute('admin_booking_show', ['id' => $booking->getId()], Response::HTTP_SEE_OTHER);
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

        // The bookings list goes by month tabs: the current month and every month with a booking, or all at once
        $bookings = $allBookings = $this->bookings->findForProduct($product);
        // one side picked: its bookings alone, in the list, the tabs and the shipments
        $listSide = $product->getSides()->exists(static fn (int $i, ProductSide $s) => $s->getId() === $listSide) ? $listSide : null;
        if (null !== $listSide) {
            $bookings = $allBookings = array_values(array_filter($allBookings, static fn (Booking $b) => $b->getSide()->getId() === $listSide));
        }
        $monthTabs = self::monthTabs($allBookings, $now);
        $defaultTab = self::defaultMonthTab($monthTabs, $now);
        $monthTab = 'all' === $month || isset($monthTabs[(string) $month]) ? $month : $defaultTab;
        $tabMonth = null;
        if ('all' !== $monthTab) {
            $tabMonth = $monthTabs[$monthTab]['month'];
            $bookings = array_values(array_filter($allBookings, static fn (Booking $b) => $b->overlaps($tabMonth, MonthCalendar::lastDay($tabMonth))));
        }

        $sort = isset(self::PRODUCT_LIST_SORTS[(string) $sort]) ? $sort : null;
        $descending = null !== $sort && ('desc' === $dir || ('asc' !== $dir && self::PRODUCT_LIST_SORTS[$sort]));
        if (null !== $sort) {
            // usort() is stable: ties keep the newest first
            usort($bookings, static fn (Booking $a, Booking $b) => ($descending ? -1 : 1) * (self::listSortValue($sort, $a, $now) <=> self::listSortValue($sort, $b, $now)));
        }

        // Shipments of the tab: what the confirmed bookings bring to its month by days (a booking for three months
        // brings a third of its price to each), services in the month the booking starts; the paid part apart.
        // Holds ship nothing yet: they are shown apart, as awaiting confirmation.
        $tabFrom = $tabMonth;
        $tabTo = null !== $tabMonth ? MonthCalendar::lastDay($tabMonth) : null;
        $serviceForms = [];
        foreach ($bookings as $booking) {
            if ($booking->isActiveAt($now)) {
                $serviceForms[$booking->getId()] = $this->serviceForm($booking)->createView();
            }
        }
        $confirmed = static fn (Booking $b) => BookingStatus::Confirmed === $b->getStatus();
        $holds = array_filter($bookings, static fn (Booking $b) => BookingStatus::Hold === $b->getStatus() && $b->isActiveAt($now));
        $revenue = ShipmentReport::sum(array_filter($bookings, $confirmed), $tabFrom, $tabTo);
        $revenue['holds'] = \count($holds);
        $revenue['holdsTotal'] = ShipmentReport::sum($holds, $tabFrom, $tabTo)['total'];

        // The structure's shipments by month: how much it is loaded with money, every month a booking takes
        $shipments = [];
        $confirmedAll = array_filter($allBookings, $confirmed);
        foreach ($monthTabs as $key => $tab) {
            $shipments[$key] = ShipmentReport::sum($confirmedAll, $tab['month'], MonthCalendar::lastDay($tab['month']))['total'];
        }

        return $this->render('admin/booking/product.html.twig', [
            'product' => $product,
            'form' => $form,
            'grids' => $grids,
            'bookings' => $bookings,
            'allBookings' => \count($allBookings),
            'monthTabs' => $monthTabs,
            'monthTab' => $monthTab,
            'defaultMonthTab' => $defaultTab,
            'revenue' => $revenue,
            'shipments' => $shipments,
            'listSide' => $listSide,
            'listSort' => $sort,
            'listDescending' => $descending,
            'serviceForms' => $serviceForms,
            'availability' => $this->availability->forProduct($product->getId(), $now),
            'activeBookings' => $this->bookings->countActiveForProduct($product, $now),
            'airtime' => $airtime,
            'whole' => $whole,
            'mixed' => $airtime && $whole,
            'minDays' => BookingMode::MIN_DAYS,
            'now' => $now,
        ]);
    }

    /**
     * The booking's card: everything about it, its services and what can be done with it.
     * Agents see it read-only, like the structure's bookings.
     */
    #[Route('/admin/bookings/{id}', name: 'admin_booking_show', requirements: ['id' => Requirement::DIGITS], methods: ['GET'])]
    public function show(Booking $booking): Response
    {
        $now = $this->clock->now();

        return $this->render('admin/booking/show.html.twig', [
            'booking' => $booking,
            'product' => $booking->getProduct(),
            'serviceForm' => $booking->isActiveAt($now) && $this->isGranted(User::ROLE_SUPER_MANAGER) ? $this->serviceForm($booking)->createView() : null,
            'now' => $now,
        ]);
    }

    /**
     * Changes a booking in force: client, contact, comment, price, side, period, slots.
     * Saved, it comes back to its card.
     */
    #[Route('/admin/bookings/{id}/edit', name: 'admin_booking_edit', requirements: ['id' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    #[IsGranted(User::ROLE_SUPER_MANAGER)]
    public function edit(Request $request, Booking $booking, ClientCards $clientCards): Response
    {
        $now = $this->clock->now();
        if (!$booking->isActiveAt($now)) {
            $this->addFlash('error', 'Эта бронь уже не действует — её не изменить. Создайте новую.');

            return $this->redirectToRoute('admin_booking_show', ['id' => $booking->getId()], Response::HTTP_SEE_OTHER);
        }

        $product = $booking->getProduct();
        $bookingRequest = BookingRequest::fromBooking($booking);
        $form = $this->createForm(BookingFormType::class, $bookingRequest, ['product' => $product, 'now' => $now, 'booking' => $booking]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                // "Новый клиент" instead of one from the list: its card is made first
                $newClient = null === $bookingRequest->client ? NewClientFields::data($form) : null;
                if (null !== $newClient) {
                    [$bookingRequest->client] = $clientCards->findOrCreate($newClient['title'], $newClient['phone'], $newClient['email'], $newClient['inn'], type: $newClient['type']);
                }
                $this->bookingManager->update($booking, $bookingRequest);
                $this->addFlash('success', 'Бронь изменена');

                return $this->redirectToRoute('admin_booking_show', ['id' => $booking->getId()], Response::HTTP_SEE_OTHER);
            } catch (BookingException $e) {
                $form->addError(new FormError($e->getMessage()));
            } catch (ClientCardException $e) {
                $form->get('newClient')->addError(new FormError($e->getMessage()));
            }
        }

        $airtime = $product->hasAirtimeSides();
        $whole = $product->hasWholeSides();

        return $this->render('admin/booking/edit.html.twig', [
            'booking' => $booking,
            'product' => $product,
            'form' => $form,
            'mixed' => $airtime && $whole,
            'now' => $now,
        ]);
    }

    /**
     * Payment schedule of the booking: what it is sold for split by the months it runs, due on the day of the month
     * the manager picks, into the payment calendar.
     */
    #[Route('/admin/bookings/{id}/payments/schedule', name: 'admin_booking_schedule_payments', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"schedule-booking-" ~ args["booking"].getId()'))]
    #[IsGranted(User::ROLE_SUPER_MANAGER)]
    public function schedulePayments(Request $request, Booking $booking, PaymentScheduler $scheduler): Response
    {
        $user = $this->getUser();
        try {
            $payments = $scheduler->scheduleBooking($booking, $request->getPayload()->getInt('day', 1), $user instanceof User ? $user : null);
            $this->addFlash('success', \sprintf('График платежей составлен: %d — по месяцам брони. Сроки и суммы можно поправить.', \count($payments)));
        } catch (PaymentException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_booking_show', ['id' => $booking->getId(), '_fragment' => 'payments'], Response::HTTP_SEE_OTHER);
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

    #[Route('/admin/bookings/{id}/slots', name: 'admin_booking_slots', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"slots-booking-" ~ args["booking"].getId()'))]
    #[IsGranted(User::ROLE_SUPER_MANAGER)]
    public function slots(Request $request, Booking $booking): Response
    {
        $slots = (int) $request->getPayload()->getString('slots');

        return $this->apply($request, $booking, fn () => $this->bookingManager->changeSlots($booking, $slots), \sprintf('Слотов в брони: %d', $slots));
    }

    /** "Продано за": the final price of the booking, empty clears it */
    #[Route('/admin/bookings/{id}/price', name: 'admin_booking_price', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"price-booking-" ~ args["booking"].getId()'))]
    #[IsGranted(User::ROLE_SUPER_MANAGER)]
    public function price(Request $request, Booking $booking): Response
    {
        // "45 000", "45000", "45 000,50" and "45000.50" alike
        $typed = str_replace(',', '.', preg_replace('/[^\d.,]+/', '', $request->getPayload()->getString('price')));
        if ('' !== $typed && !is_numeric($typed)) {
            return $this->apply($request, $booking, static fn () => throw new BookingException('Введите сумму числом, например 45000 или 45000,50.'), '');
        }
        $price = '' === $typed ? null : round((float) $typed, 2);

        return $this->apply($request, $booking, fn () => $this->bookingManager->setSoldPrice($booking, $price), null === $price
            ? 'Сумма продажи стёрта'
            : 'Продано за '.AdminExtension::money($price));
    }

    /** A one-off service sold with the booking: from the catalog (prefilled) or typed in */
    #[Route('/admin/bookings/{id}/services', name: 'admin_booking_add_service', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsGranted(User::ROLE_SUPER_MANAGER)]
    public function addService(Request $request, Booking $booking): Response
    {
        $form = $this->serviceForm($booking);
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            foreach ($form->getErrors(true) as $error) {
                $this->addFlash('error', $error->getMessage());
            }

            return $this->backToProduct($request, $booking);
        }

        /** @var ServiceLineInput $input */
        $input = $form->getData();

        return $this->apply($request, $booking, fn () => $this->bookingManager->addService($booking, $input), \sprintf('Услуга «%s» добавлена к брони', trim((string) $input->name)));
    }

    #[Route('/admin/bookings/{id}/services/{line}/delete', name: 'admin_booking_remove_service', requirements: ['id' => Requirement::DIGITS, 'line' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"service-booking-" ~ args["booking"].getId()'))]
    #[IsGranted(User::ROLE_SUPER_MANAGER)]
    public function removeService(Request $request, Booking $booking, BookingServiceLine $line): Response
    {
        if ($line->getBooking() !== $booking) {
            throw $this->createNotFoundException();
        }

        return $this->apply($request, $booking, fn () => $this->bookingManager->removeService($booking, $line), \sprintf('Услуга «%s» убрана из брони', $line->getName()));
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

    /**
     * Month tabs over the product's bookings: the current month and every month a booking touches, in order.
     *
     * @param list<Booking> $bookings
     *
     * @return array<string, array{month: \DateTimeImmutable, count: int}> keyed "2026-10"
     */
    private static function monthTabs(array $bookings, \DateTimeImmutable $now): array
    {
        $tabs = [$now->format('Y-m') => ['month' => MonthCalendar::firstDay($now), 'count' => 0]];
        foreach ($bookings as $booking) {
            foreach (MonthCalendar::between($booking->getStartDate(), $booking->getEndDate()) as $month) {
                $tabs[$month->format('Y-m')] ??= ['month' => $month, 'count' => 0];
                ++$tabs[$month->format('Y-m')]['count'];
            }
        }
        ksort($tabs);

        return $tabs;
    }

    /**
     * The tab that opens first: the current month if it has bookings, else the nearest month ahead that has,
     * else all of them (only past bookings, or none).
     *
     * @param array<string, array{month: \DateTimeImmutable, count: int}> $tabs
     */
    private static function defaultMonthTab(array $tabs, \DateTimeImmutable $now): string
    {
        foreach ($tabs as $key => $tab) {
            if ($key >= $now->format('Y-m') && $tab['count'] > 0) {
                return $key;
            }
        }

        return 'all';
    }

    private function apply(Request $request, Booking $booking, callable $action, string $successMessage): Response
    {
        try {
            $action();
            $this->addFlash('success', $successMessage);
        } catch (BookingException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        // Back to where the button was pressed: dashboard, the global list, the booking's card or the product's booking page
        $return = $request->getPayload()->getString('return');
        if ('card' === $return) {
            return $this->redirectToRoute('admin_booking_show', ['id' => $booking->getId()], Response::HTTP_SEE_OTHER);
        }
        if ('dashboard' === $return) {
            return $this->redirectToRoute('admin_dashboard', status: Response::HTTP_SEE_OTHER);
        }
        if ('list' === $return) {
            return $this->redirectToRoute('admin_booking_index', status: Response::HTTP_SEE_OTHER);
        }

        return $this->backToProduct($request, $booking);
    }

    /** Columns the bookings list of a structure is sorted by: key => whether the first click sorts from the biggest */
    private const PRODUCT_LIST_SORTS = ['side' => false, 'period' => false, 'client' => false, 'status' => false, 'sold' => true];

    private static function listSortValue(string $sort, Booking $booking, \DateTimeImmutable $now): mixed
    {
        return match ($sort) {
            'side' => [$booking->getSide()->getName(), $booking->getStartDate()],
            'period' => [$booking->getStartDate(), $booking->getEndDate()],
            'client' => mb_strtolower($booking->getClientTitle()),
            'status' => $booking->getStatusAt($now)->label(),
            'sold' => $booking->getSoldPrice() ?? -1.0,
            default => null,
        };
    }

    /** The product's booking page, on the month tab the button was pressed on */
    private function backToProduct(Request $request, Booking $booking): Response
    {
        // services are added and removed on the booking's card too
        if ('card' === $request->getPayload()->getString('return')) {
            return $this->redirectToRoute('admin_booking_show', ['id' => $booking->getId()], Response::HTTP_SEE_OTHER);
        }
        $month = $request->getPayload()->getString('month');
        $params = ['id' => $booking->getProduct()->getId(), 'month' => $month];

        // the side picked and the sorting of the list stay as they were on the page the button was pressed on
        $page = $this->generateUrl('admin_booking_product', ['id' => $booking->getProduct()->getId()]);
        $referer = parse_url((string) $request->headers->get('referer'));
        if (($referer['path'] ?? null) === $page) {
            parse_str($referer['query'] ?? '', $query);
            $params += array_intersect_key($query, array_flip(['list_side', 'sort', 'dir']));
        }

        return $this->redirectToRoute('admin_booking_product', array_filter($params, static fn ($value) => \is_string($value) && '' !== $value || \is_int($value)), Response::HTTP_SEE_OTHER);
    }

    /** "Add a service" of one booking in the list: named apart, so the forms of different rows don't share ids */
    private function serviceForm(Booking $booking): FormInterface
    {
        return $this->container->get('form.factory')->createNamed('booking_service_'.$booking->getId(), ServiceLineFormType::class, new ServiceLineInput(), [
            'action' => $this->generateUrl('admin_booking_add_service', ['id' => $booking->getId()]),
        ]);
    }
}
