<?php

namespace App\Controller\Admin;

use App\Entity\Partner;
use App\Form\PartnerFormType;
use App\Repository\PartnerRepository;
use App\Repository\ProductRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;

#[Route('/admin/partners', name: 'admin_partner_')]
final class PartnerController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(PartnerRepository $partners, ProductRepository $products): Response
    {
        return $this->render('admin/partner/index.html.twig', [
            'partners' => $partners->findBy([], ['name' => 'ASC']),
            'productCounts' => $products->countGroupedBy('owner'),
            'ownCount' => $products->count(['owner' => null]),
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        return $this->handleForm($request, new Partner(), 'Партнёр добавлен');
    }

    #[Route('/{id}/edit', name: 'edit', requirements: ['id' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function edit(Request $request, Partner $partner): Response
    {
        return $this->handleForm($request, $partner, 'Изменения сохранены');
    }

    #[Route('/{id}/delete', name: 'delete', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"delete-partner-" ~ args["partner"].getId()'))]
    public function delete(Partner $partner, ProductRepository $products): Response
    {
        $used = $products->count(['owner' => $partner]);
        if ($used > 0) {
            $this->addFlash('error', \sprintf('Нельзя удалить «%s»: у партнёра есть конструкции (%d). Сначала передайте их другому владельцу.', $partner->getName(), $used));

            return $this->redirectToRoute('admin_partner_index', status: Response::HTTP_SEE_OTHER);
        }

        $this->entityManager->remove($partner);
        $this->entityManager->flush();

        $this->addFlash('success', 'Партнёр удалён');

        return $this->redirectToRoute('admin_partner_index', status: Response::HTTP_SEE_OTHER);
    }

    private function handleForm(Request $request, Partner $partner, string $successMessage): Response
    {
        $form = $this->createForm(PartnerFormType::class, $partner);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $partner->touch();
            $this->entityManager->persist($partner);
            $this->entityManager->flush();

            $this->addFlash('success', $successMessage);

            return $this->redirectToRoute('admin_partner_index', status: Response::HTTP_SEE_OTHER);
        }

        return $this->render('admin/partner/form.html.twig', [
            'partner' => $partner,
            'form' => $form,
        ]);
    }
}
