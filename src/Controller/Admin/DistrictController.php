<?php

namespace App\Controller\Admin;

use App\Entity\District;
use App\Form\DistrictFormType;
use App\Repository\DistrictRepository;
use App\Repository\ProductRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;

#[Route('/admin/districts', name: 'admin_district_')]
final class DistrictController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(DistrictRepository $districts, ProductRepository $products): Response
    {
        return $this->render('admin/district/index.html.twig', [
            'districts' => $districts->findBy([], ['name' => 'ASC']),
            'productCounts' => $products->countGroupedBy('district'),
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        return $this->handleForm($request, new District(), 'Район создан');
    }

    #[Route('/{id}/edit', name: 'edit', requirements: ['id' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function edit(Request $request, District $district): Response
    {
        return $this->handleForm($request, $district, 'Изменения сохранены');
    }

    /**
     * Products in the district keep existing; their district is cleared (ON DELETE SET NULL).
     */
    #[Route('/{id}/delete', name: 'delete', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"delete-district-" ~ args["district"].getId()'))]
    public function delete(District $district): Response
    {
        $this->entityManager->remove($district);
        $this->entityManager->flush();

        $this->addFlash('success', 'Район удалён');

        return $this->redirectToRoute('admin_district_index', status: Response::HTTP_SEE_OTHER);
    }

    private function handleForm(Request $request, District $district, string $successMessage): Response
    {
        $form = $this->createForm(DistrictFormType::class, $district);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($district);
            $this->entityManager->flush();

            $this->addFlash('success', $successMessage);

            return $this->redirectToRoute('admin_district_index', status: Response::HTTP_SEE_OTHER);
        }

        return $this->render('admin/district/form.html.twig', [
            'district' => $district,
            'form' => $form,
        ]);
    }
}
