<?php

namespace App\Controller\Admin;

use App\Entity\ProductType;
use App\Form\ProductTypeFormType;
use App\Repository\ProductRepository;
use App\Repository\ProductTypeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;

#[Route('/admin/types', name: 'admin_product_type_')]
final class ProductTypeController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(ProductTypeRepository $types, ProductRepository $products): Response
    {
        return $this->render('admin/product_type/index.html.twig', [
            'types' => $types->findBy([], ['name' => 'ASC']),
            'productCounts' => $products->countGroupedBy('productType'),
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        return $this->handleForm($request, new ProductType(), 'Тип создан');
    }

    #[Route('/{id}/edit', name: 'edit', requirements: ['id' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function edit(Request $request, ProductType $type): Response
    {
        return $this->handleForm($request, $type, 'Изменения сохранены');
    }

    #[Route('/{id}/delete', name: 'delete', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"delete-type-" ~ args["type"].getId()'))]
    public function delete(ProductType $type, ProductRepository $products): Response
    {
        $used = $products->count(['productType' => $type]);
        if ($used > 0) {
            $this->addFlash('error', \sprintf('Нельзя удалить «%s»: тип используется в конструкциях (%d).', $type->getName(), $used));

            return $this->redirectToRoute('admin_product_type_index', status: Response::HTTP_SEE_OTHER);
        }

        $this->entityManager->remove($type);
        $this->entityManager->flush();

        $this->addFlash('success', 'Тип удалён');

        return $this->redirectToRoute('admin_product_type_index', status: Response::HTTP_SEE_OTHER);
    }

    private function handleForm(Request $request, ProductType $type, string $successMessage): Response
    {
        $form = $this->createForm(ProductTypeFormType::class, $type);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $type->touch();
            $this->entityManager->persist($type);
            $this->entityManager->flush();

            $this->addFlash('success', $successMessage);

            return $this->redirectToRoute('admin_product_type_index', status: Response::HTTP_SEE_OTHER);
        }

        return $this->render('admin/product_type/form.html.twig', [
            'type' => $type,
            'form' => $form,
        ]);
    }
}
