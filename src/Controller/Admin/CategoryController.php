<?php

namespace App\Controller\Admin;

use App\Entity\Category;
use App\Form\CategoryFormType;
use App\Repository\CategoryRepository;
use App\Repository\ProductRepository;
use App\Service\FileUploader;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;

#[Route('/admin/categories', name: 'admin_category_')]
final class CategoryController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FileUploader $uploader,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(CategoryRepository $categories, ProductRepository $products): Response
    {
        return $this->render('admin/category/index.html.twig', [
            'categories' => $categories->findBy([], ['name' => 'ASC']),
            'productCounts' => $products->countGroupedBy('category'),
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        return $this->handleForm($request, new Category(), 'Категория создана');
    }

    #[Route('/{id}/edit', name: 'edit', requirements: ['id' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function edit(Request $request, Category $category): Response
    {
        return $this->handleForm($request, $category, 'Изменения сохранены');
    }

    #[Route('/{id}/delete', name: 'delete', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"delete-category-" ~ args["category"].getId()'))]
    public function delete(Category $category, ProductRepository $products): Response
    {
        $used = $products->count(['category' => $category]);
        if ($used > 0) {
            $this->addFlash('error', \sprintf('Нельзя удалить «%s»: категория используется в конструкциях (%d).', $category->getName(), $used));

            return $this->redirectToRoute('admin_category_index', status: Response::HTTP_SEE_OTHER);
        }

        $this->entityManager->remove($category);
        $this->entityManager->flush();

        $this->addFlash('success', 'Категория удалена');

        return $this->redirectToRoute('admin_category_index', status: Response::HTTP_SEE_OTHER);
    }

    private function handleForm(Request $request, Category $category, string $successMessage): Response
    {
        $form = $this->createForm(CategoryFormType::class, $category);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $previousImage = $category->getImage();

            /** @var UploadedFile|null $file */
            $file = $form->get('imageFile')->getData();
            if (null !== $file) {
                $category->setImage($this->uploader->upload($file, Category::UPLOAD_FOLDER));
            } elseif ($form->get('removeImage')->getData()) {
                $category->setImage(null);
            }

            $category->touch();
            $this->entityManager->persist($category);
            $this->entityManager->flush();

            if ($previousImage !== $category->getImage()) {
                $this->uploader->remove(Category::UPLOAD_FOLDER, $previousImage);
            }

            $this->addFlash('success', $successMessage);

            return $this->redirectToRoute('admin_category_edit', ['id' => $category->getId()], Response::HTTP_SEE_OTHER);
        }

        return $this->render('admin/category/form.html.twig', [
            'category' => $category,
            'form' => $form,
        ]);
    }
}
