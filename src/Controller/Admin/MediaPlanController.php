<?php

namespace App\Controller\Admin;

use App\Dto\ProductListQuery;
use App\Dto\ServiceLineInput;
use App\Entity\MediaPlan;
use App\Entity\MediaPlanItem;
use App\Entity\MediaPlanServiceLine;
use App\Entity\ProductSide;
use App\Entity\User;
use App\Form\MediaPlanFormType;
use App\Form\ServiceLineFormType;
use App\Repository\MediaPlanRepository;
use App\Service\BookingManager;
use App\Service\MediaPlanManager;
use App\Service\MediaPlanPdf;
use App\Service\MonthCalendar;
use App\Service\ProductListing;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;

#[Route('/admin/media-plans', name: 'admin_media_plan_')]
final class MediaPlanController extends AbstractController
{
    private const PICKER_LIMIT = 12;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MediaPlanManager $manager,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request, MediaPlanRepository $plans, #[MapQueryParameter] ?string $q = null): Response
    {
        $params = ['plans' => $plans->findForList($q), 'q' => $q];

        if ($request->headers->has('X-Live-Filter')) {
            return $this->renderBlock('admin/media_plan/index.html.twig', 'results', $params);
        }

        return $this->render('admin/media_plan/index.html.twig', $params);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $plan = (new MediaPlan())->setStartMonth(MonthCalendar::firstDay($this->clock->now()->modify('first day of next month')));

        return $this->handleForm($request, $plan, 'Медиаплан создан — добавьте в него конструкции');
    }

    #[Route('/{id}/edit', name: 'edit', requirements: ['id' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function edit(Request $request, MediaPlan $plan): Response
    {
        return $this->handleForm($request, $plan, 'Изменения сохранены');
    }

    /**
     * The plan with its items, totals and a picker to add structures (live search, "picker" block).
     */
    #[Route('/{id}', name: 'show', requirements: ['id' => Requirement::DIGITS], methods: ['GET'])]
    public function show(Request $request, MediaPlan $plan, ProductListing $listing, BookingManager $bookings, #[MapQueryParameter] ?string $q = null): Response
    {
        // Picker: structures matching the search, each side checked for the whole plan period
        $candidates = $listing->all(new ProductListQuery(q: $q))['products'];
        $candidates = \array_slice($candidates, 0, self::PICKER_LIMIT);
        $sideProblems = [];
        foreach ($candidates as $product) {
            foreach ($product->getSides() as $side) {
                $sideProblems[$side->getId()] = $bookings->availabilityProblem($side, $plan->getStartMonth(), $plan->getEndDate(), MediaPlanManager::DEFAULT_CLIP);
            }
        }

        $params = [
            'plan' => $plan,
            'q' => $q,
            'candidates' => $candidates,
            'sideProblems' => $sideProblems,
            'problems' => $this->manager->problems($plan),
            'now' => $this->clock->now(),
        ];

        if ($request->headers->has('X-Live-Filter')) {
            return $this->renderBlock('admin/media_plan/show.html.twig', 'picker', $params);
        }

        return $this->render('admin/media_plan/show.html.twig', $params + [
            'serviceForm' => $this->serviceForm($plan),
        ]);
    }

    /**
     * Adds a one-off service (layout, printing, mounting…) to the plan.
     */
    #[Route('/{id}/services', name: 'add_service', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    public function addService(Request $request, MediaPlan $plan): Response
    {
        $form = $this->serviceForm($plan);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var ServiceLineInput $input */
            $input = $form->getData();
            $line = new MediaPlanServiceLine(
                trim($input->name),
                $input->unit,
                $input->quantity,
                $input->unitPrice,
                $input->service?->getCostPrice(),
                $input->service,
            );
            $plan->addServiceLine($line);
            $plan->touch();
            $this->entityManager->persist($line);
            $this->entityManager->flush();
            $this->addFlash('success', \sprintf('Услуга «%s» добавлена', $line->getName()));
        } else {
            foreach ($form->getErrors(true) as $error) {
                $this->addFlash('error', $error->getMessage());
            }
        }

        return $this->redirectToRoute('admin_media_plan_show', ['id' => $plan->getId(), '_fragment' => 'services'], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}/services/{line}/update', name: 'update_service', requirements: ['id' => Requirement::DIGITS, 'line' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"media-plan-" ~ args["plan"].getId()'))]
    public function updateService(Request $request, MediaPlan $plan, MediaPlanServiceLine $line): Response
    {
        if ($line->getPlan() !== $plan) {
            throw $this->createNotFoundException();
        }

        $quantity = self::parseNumber($request->getPayload()->getString('quantity'));
        $price = self::parseNumber($request->getPayload()->getString('price'));
        if (null === $quantity || $quantity <= 0 || null === $price || $price < 0) {
            $this->addFlash('error', 'Количество должно быть больше нуля, цена — не меньше нуля');
        } else {
            $line->setQuantity(number_format($quantity, 2, '.', ''))->setUnitPrice(number_format($price, 2, '.', ''));
            $plan->touch();
            $this->entityManager->flush();
            $this->addFlash('success', 'Услуга обновлена');
        }

        return $this->redirectToRoute('admin_media_plan_show', ['id' => $plan->getId(), '_fragment' => 'services'], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}/services/{line}/delete', name: 'remove_service', requirements: ['id' => Requirement::DIGITS, 'line' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"media-plan-" ~ args["plan"].getId()'))]
    public function removeService(MediaPlan $plan, MediaPlanServiceLine $line): Response
    {
        if ($line->getPlan() !== $plan) {
            throw $this->createNotFoundException();
        }

        $plan->removeServiceLine($line);
        $plan->touch();
        $this->entityManager->flush();
        $this->addFlash('success', 'Услуга убрана');

        return $this->redirectToRoute('admin_media_plan_show', ['id' => $plan->getId(), '_fragment' => 'services'], Response::HTTP_SEE_OTHER);
    }

    private function serviceForm(MediaPlan $plan): FormInterface
    {
        return $this->createForm(ServiceLineFormType::class, new ServiceLineInput(), [
            'action' => $this->generateUrl('admin_media_plan_add_service', ['id' => $plan->getId()]),
        ]);
    }

    /**
     * "1 500,5" / "1500.5" → 1500.5; null when not a number.
     */
    private static function parseNumber(string $value): ?float
    {
        $value = str_replace([' ', "\u{00A0}", ','], ['', '', '.'], trim($value));

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * Adds sides to the plan: from the picker on the plan page (form) or from the map popup (fetch, JSON).
     */
    #[Route('/{id}/items', name: 'add_items', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"media-plan-" ~ args["plan"].getId()'))]
    public function addItems(Request $request, MediaPlan $plan): Response
    {
        $payload = $request->getPayload();
        // getInt() rejects an empty string with a 400; an empty or missing clip just means "default"
        $clip = (int) $payload->getString('clip') ?: null;
        $added = 0;
        foreach ($payload->all('sides') as $sideId) {
            $side = $this->entityManager->find(ProductSide::class, (int) $sideId);
            if (null !== $side && null !== $this->manager->addSide($plan, $side, $clip)) {
                ++$added;
            }
        }
        $this->entityManager->flush();

        $message = $added > 0 ? \sprintf('Добавлено в медиаплан «%s»: %d', $plan->getTitle(), $added) : 'Эти стороны уже есть в медиаплане';

        if ('json' === $request->getPreferredFormat()) {
            return $this->json(['added' => $added, 'message' => $message, 'items' => $plan->getItems()->count()]);
        }

        $this->addFlash($added > 0 ? 'success' : 'error', $message);

        return $this->redirectToRoute('admin_media_plan_show', ['id' => $plan->getId()], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}/items/{item}/price', name: 'item_price', requirements: ['id' => Requirement::DIGITS, 'item' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"media-plan-" ~ args["plan"].getId()'))]
    public function updatePrice(Request $request, MediaPlan $plan, MediaPlanItem $item): Response
    {
        $this->assertItemOf($plan, $item);

        $price = self::parseNumber($request->getPayload()->getString('price'));
        if (null === $price || $price < 0) {
            $this->addFlash('error', 'Цена должна быть неотрицательным числом');
        } else {
            $item->setManualPrice(number_format($price, 2, '.', ''));
            $plan->touch();
            $this->entityManager->flush();
            $this->addFlash('success', 'Цена обновлена — акции её больше не меняют');
        }

        return $this->redirectToRoute('admin_media_plan_show', ['id' => $plan->getId()], Response::HTTP_SEE_OTHER);
    }

    /**
     * Back from a hand-typed price to the list price with promotions.
     */
    #[Route('/{id}/items/{item}/auto-price', name: 'item_auto_price', requirements: ['id' => Requirement::DIGITS, 'item' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"media-plan-" ~ args["plan"].getId()'))]
    public function autoPrice(MediaPlan $plan, MediaPlanItem $item): Response
    {
        $this->assertItemOf($plan, $item);

        $this->manager->resetPrice($item);
        $this->entityManager->flush();
        $this->addFlash('success', 'Цена снова считается по прайсу и акциям');

        return $this->redirectToRoute('admin_media_plan_show', ['id' => $plan->getId()], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}/recalculate', name: 'recalculate', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"media-plan-" ~ args["plan"].getId()'))]
    public function recalculate(MediaPlan $plan): Response
    {
        $changed = $this->manager->recalculate($plan);
        $this->entityManager->flush();
        $this->addFlash('success', $changed > 0 ? \sprintf('Цены пересчитаны по акциям: изменилось позиций — %d', $changed) : 'Цены актуальны — ничего не изменилось');

        return $this->redirectToRoute('admin_media_plan_show', ['id' => $plan->getId()], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}/items/{item}/delete', name: 'remove_item', requirements: ['id' => Requirement::DIGITS, 'item' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"media-plan-" ~ args["plan"].getId()'))]
    public function removeItem(MediaPlan $plan, MediaPlanItem $item): Response
    {
        $this->assertItemOf($plan, $item);

        $plan->removeItem($item);
        $plan->touch();
        $this->entityManager->flush();
        $this->addFlash('success', 'Позиция убрана из медиаплана');

        return $this->redirectToRoute('admin_media_plan_show', ['id' => $plan->getId()], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}/book', name: 'book', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"media-plan-" ~ args["plan"].getId()'))]
    public function book(MediaPlan $plan): Response
    {
        $user = $this->getUser();
        $result = $this->manager->bookAll($plan, $user instanceof User ? $user : null);

        if ($result['booked'] > 0) {
            $this->addFlash('success', \sprintf('Создано броней: %d. Они ждут оплаты 24 часа.', $result['booked']));
        }
        foreach ($result['failed'] as $message) {
            $this->addFlash('error', $message);
        }
        if (0 === $result['booked'] && [] === $result['failed']) {
            $this->addFlash('success', 'Все позиции уже забронированы');
        }

        return $this->redirectToRoute('admin_media_plan_show', ['id' => $plan->getId()], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}/pdf', name: 'pdf', requirements: ['id' => Requirement::DIGITS], methods: ['GET'])]
    public function pdf(MediaPlan $plan, MediaPlanPdf $pdf): Response
    {
        return new Response($pdf->render($plan), Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_INLINE,
                'Медиаплан — '.$plan->getTitle().'.pdf',
                $pdf->asciiFilename($plan),
            ),
        ]);
    }

    #[Route('/{id}/delete', name: 'delete', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"delete-media-plan-" ~ args["plan"].getId()'))]
    public function delete(MediaPlan $plan): Response
    {
        // Bookings made from the plan stay; only the plan and its items go
        $this->entityManager->remove($plan);
        $this->entityManager->flush();
        $this->addFlash('success', 'Медиаплан удалён');

        return $this->redirectToRoute('admin_media_plan_index', status: Response::HTTP_SEE_OTHER);
    }

    private function handleForm(Request $request, MediaPlan $plan, string $successMessage): Response
    {
        $now = $this->clock->now();
        $form = $this->createForm(MediaPlanFormType::class, $plan, [
            // keep an old plan's (past) start month selectable
            'now' => min($plan->getStartMonth() ?? $now, $now),
        ]);
        // promotions depend on these: prices are recalculated only when they change
        $promoTerms = static fn (MediaPlan $plan) => [$plan->getPromoCode(), $plan->isFirstOrder(), $plan->getMonths()];
        $termsBefore = $promoTerms($plan);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if (null === $plan->getId()) {
                $user = $this->getUser();
                $plan->setCreatedBy($user instanceof User ? $user : null);
            } elseif ($promoTerms($plan) !== $termsBefore && ($changed = $this->manager->recalculate($plan)) > 0) {
                $successMessage .= \sprintf('. Цены пересчитаны по акциям: изменилось позиций — %d', $changed);
            }
            $plan->touch();
            $this->entityManager->persist($plan);
            $this->entityManager->flush();
            $this->addFlash('success', $successMessage);

            return $this->redirectToRoute('admin_media_plan_show', ['id' => $plan->getId()], Response::HTTP_SEE_OTHER);
        }

        return $this->render('admin/media_plan/form.html.twig', ['plan' => $plan, 'form' => $form]);
    }

    private function assertItemOf(MediaPlan $plan, MediaPlanItem $item): void
    {
        if ($item->getPlan() !== $plan) {
            throw $this->createNotFoundException();
        }
    }
}
