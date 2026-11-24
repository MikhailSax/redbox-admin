<?php

namespace App\Service;

use App\Dto\PaymentSchedule;
use App\Dto\PaymentScheduleLine;
use App\Entity\Booking;
use App\Entity\MediaPlan;
use App\Entity\Payment;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Payment schedule of a media plan or of a booking, typed in by the manager: one line per month of placement
 * with the sum the client pays for it. Nothing is split automatically — the draft only lists the months and
 * suggests a due date; the amounts are empty until the manager enters them.
 */
class PaymentScheduler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * The months of the plan, each due on the plan's payment day of its month (today at the earliest).
     *
     * @throws PaymentException when the plan has no client, costs nothing or already has a schedule
     */
    public function draftMediaPlan(MediaPlan $plan): PaymentSchedule
    {
        $this->checkMediaPlan($plan);
        $today = $this->today();

        return new PaymentSchedule(array_map(
            static fn (\DateTimeImmutable $month) => new PaymentScheduleLine($month, dueDate: max($plan->paymentDateIn($month), $today)),
            MonthCalendar::range($plan->getStartMonth(), $plan->getMonths()),
        ));
    }

    /**
     * The months the booking runs, each due on the 1st of its month (today at the earliest).
     *
     * @throws PaymentException when the booking has no client, is not in force, costs nothing or already has a schedule
     */
    public function draftBooking(Booking $booking): PaymentSchedule
    {
        $this->checkBooking($booking);
        $today = $this->today();

        return new PaymentSchedule(array_map(
            static fn (\DateTimeImmutable $month) => new PaymentScheduleLine($month, dueDate: max($month, $today)),
            MonthCalendar::between($booking->getStartDate(), $booking->getEndDate()),
        ));
    }

    /**
     * One payment per month with a sum entered, as the manager typed it.
     *
     * @return list<Payment>
     *
     * @throws PaymentException
     */
    public function scheduleMediaPlan(MediaPlan $plan, PaymentSchedule $schedule, ?User $createdBy = null): array
    {
        $this->checkMediaPlan($plan);

        return $this->create($schedule, function (PaymentScheduleLine $line) use ($plan, $createdBy): Payment {
            $payment = (new Payment())
                ->setClient($plan->getClient())
                ->setMediaPlan($plan)
                ->setTitle(\sprintf('Медиаплан «%s» — %s', $plan->getTitle(), MonthCalendar::label($line->month)))
                ->setCreatedBy($createdBy);
            $plan->getPayments()->add($payment);

            return $payment;
        });
    }

    /**
     * One payment per month with a sum entered, as the manager typed it.
     *
     * @return list<Payment>
     *
     * @throws PaymentException
     */
    public function scheduleBooking(Booking $booking, PaymentSchedule $schedule, ?User $createdBy = null): array
    {
        $this->checkBooking($booking);

        return $this->create($schedule, function (PaymentScheduleLine $line) use ($booking, $createdBy): Payment {
            $payment = (new Payment())
                ->setClient($booking->getClient())
                ->setBooking($booking)
                ->setTitle(\sprintf('Бронь «%s», сторона %s — %s', $booking->getProduct()?->getName(), $booking->getSide()->getName(), MonthCalendar::label($line->month)))
                ->setCreatedBy($createdBy);
            $booking->getPayments()->add($payment);

            return $payment;
        });
    }

    /**
     * @param callable(PaymentScheduleLine): Payment $make
     *
     * @return list<Payment>
     */
    private function create(PaymentSchedule $schedule, callable $make): array
    {
        $lines = $schedule->filled();
        if ([] === $lines) {
            throw new PaymentException('Введите сумму хотя бы за один месяц.');
        }

        $payments = [];
        foreach ($lines as $line) {
            $payment = $make($line)
                ->setAmount(number_format(round((float) $line->amount, 2), 2, '.', ''))
                ->setDueDate($line->dueDate);
            $this->entityManager->persist($payment);
            $payments[] = $payment;
        }
        $this->entityManager->flush();

        return $payments;
    }

    private function checkMediaPlan(MediaPlan $plan): void
    {
        if (null === $plan->getClient()) {
            throw new PaymentException('Выберите клиента в параметрах медиаплана — платежи ведутся по клиентам.');
        }
        if (!$plan->getPayments()->isEmpty()) {
            throw new PaymentException('У медиаплана уже есть график платежей. Измените или удалите платежи в нём.');
        }
        if ($plan->getTotal() <= 0) {
            throw new PaymentException('В медиаплане нечего оплачивать — добавьте конструкции или услуги.');
        }
    }

    private function checkBooking(Booking $booking): void
    {
        if (null === $booking->getClient()) {
            throw new PaymentException('У брони нет клиента — платежи ведутся по клиентам. Выберите клиента в брони.');
        }
        if (!$booking->isActiveAt($this->clock->now())) {
            throw new PaymentException('Бронь уже не действует — составлять график не для чего.');
        }
        if (!$booking->getPayments()->isEmpty()) {
            throw new PaymentException('У брони уже есть график платежей. Измените или удалите платежи в нём.');
        }
        if (($booking->getPlacementPrice() ?? 0) + $booking->getServicesTotal() <= 0) {
            throw new PaymentException($booking->getKind()->isSold()
                ? 'В брони нечего оплачивать — укажите «Продано за» или добавьте услуги.'
                : \sprintf('Бронь размещена как «%s» — платить не за что, кроме услуг, а их нет.', $booking->getKind()->label()));
        }
    }

    private function today(): \DateTimeImmutable
    {
        return $this->clock->now()->setTime(0, 0);
    }
}
