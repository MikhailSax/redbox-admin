<?php

namespace App\Service;

use App\Entity\MediaPlan;
use App\Entity\Payment;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Payment schedule of a media plan: one payment per month of placement.
 */
class PaymentScheduler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Splits the plan total into its months, each due on the 1st of its month (today at the earliest).
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
                ->setDueDate(max($month, $today))
                ->setCreatedBy($createdBy);
            $plan->getPayments()->add($payment);
            $this->entityManager->persist($payment);
            $payments[] = $payment;
        }
        $this->entityManager->flush();

        return $payments;
    }
}
