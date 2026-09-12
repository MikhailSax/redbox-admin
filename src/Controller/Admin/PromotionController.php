<?php

namespace App\Controller\Admin;

use App\Entity\Promotion;
use App\Form\PromotionFormType;
use App\Repository\PromotionRepository;
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
 * Promotions: discounts on structures (all, by category or one by one), optionally with a promo code,
 * for a first order only or from N months. Applied in media plans by MediaPlanManager.
 */
#[Route('/admin/promotions', name: 'admin_promotion_')]
final class PromotionController extends AbstractController
{
    public const STATES = [
        'active' => 'Действуют',
        'scheduled' => 'Запланированы',
        'finished' => 'Завершены',
        'disabled' => 'Выключены',
    ];

    /** Card tabs and the form fields on each (the tab with the first invalid field opens) */
    private const CARD_TABS = [
        'main' => ['title', 'description', 'discountType', 'discountValue', 'startsAt', 'endsAt', 'active'],
        'targets' => ['appliesToAll', 'categories', 'products'],
        'conditions' => ['code', 'firstOrderOnly', 'minMonths'],
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
        private readonly PromotionRepository $promotions,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(
        Request $request,
        #[MapQueryParameter] ?string $q = null,
        #[MapQueryParameter] ?string $state = null,
    ): Response {
        $now = $this->clock->now();
        $all = $this->promotions->findForList($q);

        $stateCounts = array_fill_keys(array_keys(self::STATES), 0);
        foreach ($all as $promotion) {
            ++$stateCounts[$promotion->getStateOn($now)];
        }
        $state = isset(self::STATES[(string) $state]) ? $state : null;

        $params = [
            'promotions' => null === $state ? $all : array_values(array_filter($all, static fn (Promotion $p) => $p->getStateOn($now) === $state)),
            'usage' => $this->promotions->countUsage(),
            'stateCounts' => $stateCounts,
            'states' => self::STATES,
            'state' => $state,
            'q' => $q,
            'now' => $now,
        ];

        if ($request->headers->has('X-Live-Filter')) {
            return $this->renderBlock('admin/promotion/index.html.twig', 'results', $params);
        }

        return $this->render('admin/promotion/index.html.twig', $params);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $promotion = (new Promotion())->setStartsAt($this->clock->now()->setTime(0, 0));

        return $this->handleForm($request, $promotion, 'Акция создана');
    }

    #[Route('/{id}/edit', name: 'edit', requirements: ['id' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function edit(Request $request, Promotion $promotion): Response
    {
        return $this->handleForm($request, $promotion, 'Изменения сохранены');
    }

    /**
     * Switches a promotion on or off right from the list.
     */
    #[Route('/{id}/toggle', name: 'toggle', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"promotion-" ~ args["promotion"].getId()'))]
    public function toggle(Request $request, Promotion $promotion): Response
    {
        $promotion->setActive(!$promotion->isActive())->touch();
        $this->entityManager->flush();
        $this->addFlash('success', \sprintf('Акция «%s» %s', $promotion->getTitle(), $promotion->isActive() ? 'включена' : 'выключена'));

        // from the card (back=card) stay on the card, from the list go back to the list
        return 'card' === $request->getPayload()->getString('back')
            ? $this->redirectToRoute('admin_promotion_edit', ['id' => $promotion->getId()], Response::HTTP_SEE_OTHER)
            : $this->redirectToRoute('admin_promotion_index', status: Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}/delete', name: 'delete', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"delete-promotion-" ~ args["promotion"].getId()'))]
    public function delete(Promotion $promotion): Response
    {
        // Media plan items keep their prices and the promotion title (MediaPlanItem::$promotionTitle)
        $this->entityManager->remove($promotion);
        $this->entityManager->flush();
        $this->addFlash('success', 'Акция удалена');

        return $this->redirectToRoute('admin_promotion_index', status: Response::HTTP_SEE_OTHER);
    }

    private function handleForm(Request $request, Promotion $promotion, string $successMessage): Response
    {
        $form = $this->createForm(PromotionFormType::class, $promotion);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($promotion->isAppliesToAll()) {
                // targets would be ignored anyway; don't keep them around to surprise later
                $promotion->getCategories()->clear();
                $promotion->getProducts()->clear();
            }
            $promotion->touch();
            $this->entityManager->persist($promotion);
            $this->entityManager->flush();
            $this->addFlash('success', $successMessage);

            // Reopen the card on the tab the user saved from
            $tab = $request->request->getString('_tab');

            return $this->redirectToRoute('admin_promotion_edit', [
                'id' => $promotion->getId(),
                '_fragment' => isset(self::CARD_TABS[$tab]) ? $tab : null,
            ], Response::HTTP_SEE_OTHER);
        }

        // Tabs holding invalid fields get a marker; the first of them is opened
        $tabErrors = [];
        if ($form->isSubmitted()) {
            foreach (self::CARD_TABS as $tab => $fields) {
                foreach ($fields as $field) {
                    if (!$form->get($field)->isValid()) {
                        $tabErrors[] = $tab;
                        continue 2;
                    }
                }
            }
        }

        return $this->render('admin/promotion/form.html.twig', [
            'promotion' => $promotion,
            'form' => $form,
            'tabErrors' => $tabErrors,
            'initialTab' => $tabErrors[0] ?? 'main',
            'usage' => null !== $promotion->getId() ? $this->promotions->findUsage($promotion) : [],
            'now' => $this->clock->now(),
        ]);
    }
}
