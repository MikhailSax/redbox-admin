<?php

namespace App\Controller\Admin;

use App\Entity\MediaPlan;
use App\Entity\Payment;
use App\Entity\User;
use App\Enum\ClientType;
use App\Enum\PaymentStatus;
use App\Form\PaymentFormType;
use App\Repository\PaymentRepository;
use App\Service\MonthCalendar;
use App\Service\PaymentCalendar;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;

/**
 * Payment calendar: when and which client has to pay, what is overdue, what came in.
 */
#[Route('/admin/payments', name: 'admin_payment_')]
final class PaymentController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PaymentRepository $payments,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * The month as a calendar and a list, with the overdue payments of every month above it.
     */
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(
        #[MapQueryParameter] ?string $month = null,
        #[MapQueryParameter] ?string $status = null,
        #[MapQueryParameter] ?string $type = null,
        #[MapQueryParameter] ?string $q = null,
    ): Response {
        $now = $this->clock->now();
        $month = null !== $month && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) ? MonthCalendar::parse($month) : MonthCalendar::firstDay($now);
        $status = null !== $status ? PaymentStatus::tryFrom($status) : null;
        $type = null !== $type ? ClientType::tryFrom($type) : null;

        // everything the calendar grid shows (a few days of the neighbouring months too)
        [$gridFrom, $gridTo] = PaymentCalendar::gridRange($month);
        $inGrid = $this->payments->findDueBetween($gridFrom, $gridTo, $now, clientType: $type, q: $q);
        $inMonth = array_values(array_filter($inGrid, static fn (Payment $p) => $p->getDueDate()->format('Y-m') === $month->format('Y-m')));
        $paid = array_values(array_filter($inMonth, static fn (Payment $p) => $p->isPaid()));
        $sum = static fn (array $list) => array_sum(array_map(static fn (Payment $p) => (float) $p->getAmount(), $list));

        return $this->render('admin/payment/index.html.twig', [
            'month' => $month,
            'status' => $status,
            'type' => $type,
            'q' => $q,
            'now' => $now,
            'weeks' => PaymentCalendar::weeks($month, $inGrid, $now),
            'list' => null !== $status ? array_values(array_filter($inMonth, static fn (Payment $p) => $p->statusAt($now) === $status)) : $inMonth,
            'overdue' => $this->payments->findOverdue($now, clientType: $type, q: $q),
            'totals' => [
                'overdue' => $this->payments->totals(PaymentStatus::Overdue, $now),
                'soon' => $this->payments->totals(PaymentStatus::DueSoon, $now),
                'monthExpected' => ['count' => \count($inMonth) - \count($paid), 'sum' => $sum($inMonth) - $sum($paid)],
                'monthPaid' => ['count' => \count($paid), 'sum' => $sum($paid)],
            ],
        ]);
    }

    /**
     * ?client= and ?plan= prefill the form (links from the client card and the media plan).
     */
    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request, #[MapQueryParameter] ?int $client = null, #[MapQueryParameter] ?int $plan = null): Response
    {
        $payment = (new Payment())->setDueDate($this->clock->now()->modify(\sprintf('+%d days', PaymentStatus::SOON_DAYS)));
        $mediaPlan = null !== $plan ? $this->entityManager->find(MediaPlan::class, $plan) : null;
        if (null !== $mediaPlan) {
            $payment->setMediaPlan($mediaPlan)->setClient($mediaPlan->getClient())->setTitle(\sprintf('Медиаплан «%s»', $mediaPlan->getTitle()));
        }
        $owner = null !== $client ? $this->entityManager->find(User::class, $client) : null;
        if (null !== $owner && $owner->isClient()) {
            $payment->setClient($owner);
        }

        return $this->handleForm($request, $payment, 'Платёж добавлен в календарь');
    }

    #[Route('/{id}/edit', name: 'edit', requirements: ['id' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function edit(Request $request, Payment $payment): Response
    {
        return $this->handleForm($request, $payment, 'Платёж сохранён');
    }

    #[Route('/{id}/pay', name: 'pay', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"payment-" ~ args["payment"].getId()'))]
    public function pay(Request $request, Payment $payment): Response
    {
        if (!$payment->isPaid()) {
            $payment->markPaid($this->clock->now());
            $this->entityManager->flush();
        }
        $this->addFlash('success', \sprintf('Оплата отмечена: %s', $payment->getClient()->getClientTitle()));

        return $this->back($request, $payment);
    }

    #[Route('/{id}/unpay', name: 'unpay', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"payment-" ~ args["payment"].getId()'))]
    public function unpay(Request $request, Payment $payment): Response
    {
        $payment->markUnpaid();
        $this->entityManager->flush();
        $this->addFlash('success', 'Отметка об оплате снята');

        return $this->back($request, $payment);
    }

    #[Route('/{id}/delete', name: 'delete', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"delete-payment-" ~ args["payment"].getId()'))]
    public function delete(Request $request, Payment $payment): Response
    {
        $response = $this->back($request, $payment);
        $this->entityManager->remove($payment);
        $this->entityManager->flush();
        $this->addFlash('success', 'Платёж удалён');

        return $response;
    }

    private function handleForm(Request $request, Payment $payment, string $successMessage): Response
    {
        $form = $this->createForm(PaymentFormType::class, $payment);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if (null === $payment->getId()) {
                $user = $this->getUser();
                $payment->setCreatedBy($user instanceof User ? $user : null);
            }
            $payment->touch();
            $this->entityManager->persist($payment);
            $this->entityManager->flush();
            $this->addFlash('success', $successMessage);

            return $this->redirectToRoute('admin_payment_index', ['month' => $payment->getDueDate()->format('Y-m')], Response::HTTP_SEE_OTHER);
        }

        return $this->render('admin/payment/form.html.twig', ['payment' => $payment, 'form' => $form, 'now' => $this->clock->now()]);
    }

    /**
     * Back to where the button was pressed: the client card, the media plan, the dashboard or the calendar.
     */
    private function back(Request $request, Payment $payment): Response
    {
        $plan = $payment->getMediaPlan();

        return match (true) {
            'client' === $request->getPayload()->getString('return') => $this->redirectToRoute('admin_client_show', ['id' => $payment->getClient()->getId(), '_fragment' => 'payments'], Response::HTTP_SEE_OTHER),
            'plan' === $request->getPayload()->getString('return') && null !== $plan => $this->redirectToRoute('admin_media_plan_show', ['id' => $plan->getId(), '_fragment' => 'payments'], Response::HTTP_SEE_OTHER),
            'dashboard' === $request->getPayload()->getString('return') => $this->redirectToRoute('admin_dashboard', status: Response::HTTP_SEE_OTHER),
            default => $this->redirectToRoute('admin_payment_index', ['month' => $payment->getDueDate()->format('Y-m')], Response::HTTP_SEE_OTHER),
        };
    }
}
