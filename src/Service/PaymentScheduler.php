<?php

namespace App\Service;

use App\Entity\Booking;
use App\Entity\MediaPlan;
use App\Entity\Payment;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Payment schedule of a media plan or of a booking: one payment per month of placement.
 */
class PaymentScheduler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Splits the plan total into its months, each due on the plan's payment day of its month (today at the earliest).
     * Placement is divided equally; the kopecks left over and the one-off services go into the first payment.
     *
     * @return list<Payment>
     *
     * @throws PaymentException when the plan has no client, costs nothing or already has a schedule
     */
    public function scheduleMediaPlan(MediaPlan $plan, ?User $createdBy = null): array
    {
        $client = $plan->getClient();
        if (null === $client) {
            throw new PaymentException('Выберите клиента в параметрах медиаплана — платежи ведутся по клиентам.');
        }
        if (!$plan->getPayments()->isEmpty()) {
            throw new PaymentException('У медиаплана уже есть график платежей. Измените или удалите платежи в нём.');
        }

        // in kopecks, so the payments add up to the total exactly
        $placement = (int) round($plan->getPlacementTotal() * 100);
        $services = (int) round($plan->getServicesTotal() * 100);
        if ($placement + $services <= 0) {
            throw new PaymentException('В медиаплане нечего оплачивать — добавьте конструкции или услуги.');
        }

        $months = MonthCalendar::range($plan->getStartMonth(), $plan->getMonths());
        $monthly = intdiv($placement, \count($months));
        $today = $this->clock->now()->setTime(0, 0);

        $payments = [];
        foreach ($months as $i => $month) {
            $kopecks = $monthly + (0 === $i ? $placement - $monthly * \count($months) + $services : 0);
            $payment = (new Payment())
                ->setClient($client)
                ->setMediaPlan($plan)
                ->setTitle(\sprintf('Медиаплан «%s» — %s%s', $plan->getTitle(), MonthCalendar::label($month), 0 === $i && $services > 0 ? ' и услуги' : ''))
                ->setAmount(number_format($kopecks / 100, 2, '.', ''))
                ->setDueDate(max($plan->paymentDateIn($month), $today))
                ->setCreatedBy($createdBy);
            $plan->getPayments()->add($payment);
            $this->entityManager->persist($payment);
            $payments[] = $payment;
        }
        $this->entityManager->flush();

        return $payments;
    }

    /**
     * Splits what the booking is sold for into the months it runs, each due on day $day of its month (today at the
     * earliest; a shorter month — on its last day). Each month pays for its days, as the booking ships them
     * (Booking::getSoldPriceWithin()), so the payments add up to the price exactly; the services go into the first one.
     *
     * @return list<Payment>
     *
     * @throws PaymentException when the booking has no client, is not in force, costs nothing or already has a schedule
     */
    public function scheduleBooking(Booking $booking, int $day, ?User $createdBy = null): array
    {
        $client = $booking->getClient();
        if (null === $client) {
            throw new PaymentException('У брони нет клиента — платежи ведутся по клиентам. Выберите клиента в брони.');
        }
        if (!$booking->isActiveAt($this->clock->now())) {
            throw new PaymentException('Бронь уже не действует — составлять график не для чего.');
        }
        if (!$booking->getPayments()->isEmpty()) {
            throw new PaymentException('У брони уже есть график платежей. Измените или удалите платежи в нём.');
        }
        if ($day < 1 || $day > 31) {
            throw new PaymentException('Число оплаты — от 1 до 31.');
        }
        if (($booking->getSoldPrice() ?? 0) + $booking->getServicesTotal() <= 0) {
            throw new PaymentException('В брони нечего оплачивать — укажите «Продано за» или добавьте услуги.');
        }

        $today = $this->clock->now()->setTime(0, 0);
        $product = $booking->getProduct();
        $payments = [];
        foreach (MonthCalendar::between($booking->getStartDate(), $booking->getEndDate()) as $month) {
            $last = MonthCalendar::lastDay($month);
            $services = $booking->getServicesWithin($month, $last);
            $kopecks = (int) round((($booking->getSoldPriceWithin($month, $last) ?? 0) + $services) * 100);
            if ($kopecks <= 0) {
                continue;
            }
            $payment = (new Payment())
                ->setClient($client)
                ->setBooking($booking)
                ->setTitle(\sprintf('Бронь «%s», сторона %s — %s%s', $product?->getName(), $booking->getSide()->getName(), MonthCalendar::label($month), $services > 0 ? ' и услуги' : ''))
                ->setAmount(number_format($kopecks / 100, 2, '.', ''))
                ->setDueDate(max(MonthCalendar::dayOf($month, $day), $today))
                ->setCreatedBy($createdBy);
            $booking->getPayments()->add($payment);
            $this->entityManager->persist($payment);
            $payments[] = $payment;
        }
        $this->entityManager->flush();

        return $payments;
    }
}
