<?php

namespace App\Controller\Admin;

use App\Entity\Lead;
use App\Entity\LeadItem;
use App\Entity\MediaPlan;
use App\Entity\User;
use App\Enum\LeadStatus;
use App\Repository\LeadRepository;
use App\Service\ClientCardException;
use App\Service\ClientCards;
use App\Service\MediaPlanManager;
use App\Service\MonthCalendar;
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
 * Requests from the website: the manager's inbox. A request blocks nothing until it is turned
 * into a media plan (and from there into bookings).
 */
#[Route('/admin/leads', name: 'admin_lead_')]
final class LeadController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LeadRepository $leads,
        private readonly MediaPlanManager $mediaPlans,
        private readonly ClockInterface $clock,
        private readonly ClientCards $clientCards,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request, #[MapQueryParameter] ?string $status = null, #[MapQueryParameter] ?string $q = null): Response
    {
        $filter = null !== $status ? LeadStatus::tryFrom($status) : null;
        $params = [
            'leads' => $this->leads->findForList($filter, $q),
            'counts' => $this->leads->countByStatus(),
            'filter' => $filter,
            'q' => $q,
        ];

        if ($request->headers->has('X-Live-Filter')) {
            return $this->renderBlock('admin/lead/index.html.twig', 'results', $params);
        }

        return $this->render('admin/lead/index.html.twig', $params);
    }

    #[Route('/{id}', name: 'show', requirements: ['id' => Requirement::DIGITS], methods: ['GET'])]
    public function show(Lead $lead): Response
    {
        return $this->render('admin/lead/show.html.twig', [
            'lead' => $lead,
            'statuses' => LeadStatus::cases(),
            'managers' => $this->entityManager->getRepository(User::class)->findStaff(),
        ]);
    }

    /**
     * Status, assignee and the manager's note — one small form on the card.
     */
    #[Route('/{id}/update', name: 'update', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"lead-" ~ args["lead"].getId()'))]
    public function update(Request $request, Lead $lead): Response
    {
        $payload = $request->getPayload();
        $status = LeadStatus::tryFrom($payload->getString('status'));
        if (null !== $status) {
            $lead->setStatus($status);
        }

        $assigneeId = (int) $payload->getString('assignee');
        $assignee = $assigneeId > 0 ? $this->entityManager->find(User::class, $assigneeId) : null;
        $lead->setAssignee(null !== $assignee && !$assignee->isClient() ? $assignee : null);

        $note = trim($payload->getString('note'));
        $lead->setManagerNote('' !== $note ? $note : null);
        $lead->touch();
        $this->entityManager->flush();
        $this->addFlash('success', 'Заявка обновлена');

        return $this->redirectToRoute('admin_lead_show', ['id' => $lead->getId()], Response::HTTP_SEE_OTHER);
    }

    /**
     * Turns the request into a media plan: the sides it asked for, for the months they cover.
     */
    #[Route('/{id}/media-plan', name: 'media_plan', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"lead-" ~ args["lead"].getId()'))]
    public function mediaPlan(Lead $lead): Response
    {
        if (null !== $lead->getMediaPlan()) {
            return $this->redirectToRoute('admin_media_plan_show', ['id' => $lead->getMediaPlan()->getId()], Response::HTTP_SEE_OTHER);
        }

        $sides = array_values(array_filter($lead->getItems()->toArray(), static fn (LeadItem $item) => null !== $item->getSide()));
        if ([] === $sides) {
            $this->addFlash('error', 'В заявке не осталось конструкций из каталога — соберите медиаплан вручную');

            return $this->redirectToRoute('admin_lead_show', ['id' => $lead->getId()], Response::HTTP_SEE_OTHER);
        }

        $start = min(array_map(static fn (LeadItem $item) => $item->getStartDate(), $sides));
        $end = max(array_map(static fn (LeadItem $item) => $item->getEndDate(), $sides));
        $months = \count(MonthCalendar::between(MonthCalendar::firstDay($start), $end));

        $user = $this->getUser();
        // Signed-in visitor: the plan and its payments go to their account, once the account's e-mail is confirmed
        $client = $lead->getClient()?->isEmailVerified() ? $lead->getClient() : null;
        if (null !== $lead->getClient() && null === $client) {
            $this->addFlash('warning', 'Клиент из заявки не подтвердил почту — медиаплан создан без привязки к его кабинету. Привяжите клиента, когда почта будет подтверждена.');
        } elseif (null === $lead->getClient()) {
            // A visitor without an account: their card in the client base (found by ИНН, email or name, or made from the request)
            try {
                [$client, $created] = $this->clientCards->findOrCreate(
                    $lead->getCompanyName() ?? (string) $lead->getContactName(),
                    $lead->getPhone(),
                    $lead->getEmail(),
                    $lead->getInn(),
                    $lead->getKpp(),
                    $lead->getContactName(),
                );
                $this->addFlash('success', \sprintf($created ? 'Клиент «%s» добавлен в базу клиентов из заявки' : 'Клиент «%s» уже был в базе — медиаплан привязан к его карточке', $client->getClientTitle()));
            } catch (ClientCardException $e) {
                $this->addFlash('warning', 'Карточку клиента не удалось завести ('.$e->getMessage().') — медиаплан создан без привязки к клиенту.');
            }
        }
        $plan = (new MediaPlan())
            ->setTitle(\sprintf('Заявка №%d с сайта', (int) $lead->getId()))
            ->setClientName($lead->getClientTitle())
            ->setClient($client)
            ->setClientContact(implode(', ', array_filter([$lead->getPhone(), $lead->getEmail()])))
            ->setStartMonth(MonthCalendar::firstDay($start))
            ->setMonths(min(12, max(1, $months)))
            ->setComment($lead->getComment())
            ->setCreatedBy($user instanceof User ? $user : null);
        $this->entityManager->persist($plan);

        foreach ($sides as $item) {
            $this->mediaPlans->addSide($plan, $item->getSide(), $item->getSlots());
        }

        $lead->setMediaPlan($plan)->setStatus(LeadStatus::Quoted)->touch();
        $this->entityManager->flush();

        $this->addFlash('success', \sprintf('Медиаплан создан из заявки №%d — проверьте цены и период', (int) $lead->getId()));

        return $this->redirectToRoute('admin_media_plan_show', ['id' => $plan->getId()], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}/delete', name: 'delete', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"delete-lead-" ~ args["lead"].getId()'))]
    public function delete(Lead $lead): Response
    {
        $this->entityManager->remove($lead);
        $this->entityManager->flush();
        $this->addFlash('success', 'Заявка удалена');

        return $this->redirectToRoute('admin_lead_index', status: Response::HTTP_SEE_OTHER);
    }
}
