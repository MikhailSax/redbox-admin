<?php

namespace App\Controller\Admin;

use App\Dto\ProductListQuery;
use App\Entity\Product;
use App\Entity\ProductSide;
use App\Entity\ProductSidePhoto;
use App\Form\ProductFormType;
use App\Repository\BookingRepository;
use App\Repository\CategoryRepository;
use App\Repository\PartnerRepository;
use App\Repository\ProductTypeRepository;
use App\Service\Availability\AvailabilityResolver;
use App\Service\MonthCalendar;
use App\Service\ProductListing;
use App\Service\SidePhotoStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;

#[Route('/admin/products', name: 'admin_product_')]
final class ProductController extends AbstractController
{
    private const PER_PAGE = 20;

    /** Client-side tabs of the product card and the form fields on each, see templates/admin/product/_form.html.twig */
    private const CARD_TABS = [
        'main' => ['name', 'schemeNumber', 'category', 'productType', 'size', 'price', 'owner', 'purchasePrice', 'shortDescription', 'description'],
        'location' => ['district', 'latitude', 'longitude'],
        'sides' => ['sides'],
        'seo' => ['seoTitle', 'seoDescription', 'seoKeywords'],
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SidePhotoStorage $photoStorage,
        private readonly BookingRepository $bookings,
        private readonly AvailabilityResolver $availability,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(
        Request $request,
        ProductListing $listing,
        CategoryRepository $categories,
        ProductTypeRepository $types,
        PartnerRepository $partners,
        #[MapQueryString] ProductListQuery $query = new ProductListQuery(),
    ): Response {
        $params = $listing->page($query, self::PER_PAGE) + [
            'query' => $query,
            'months' => MonthCalendar::range($this->clock->now(), 12),
        ];

        // Live search (assets/admin/live-filter.js) only needs the results block
        if ($request->headers->has('X-Live-Filter')) {
            return $this->renderBlock('admin/product/index.html.twig', 'results', $params);
        }

        return $this->render('admin/product/index.html.twig', $params + [
            'categories' => $categories->findBy([], ['name' => 'ASC']),
            'types' => $types->findBy([], ['name' => 'ASC']),
            'partners' => $partners->findBy([], ['name' => 'ASC']),
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $product = new Product();
        // Most structures are two-sided; extra sides can be added or removed in the form.
        $product->addSide((new ProductSide())->setName('A'));
        $product->addSide((new ProductSide())->setName('B'));

        return $this->handleForm($request, $product, 'admin/product/new.html.twig', 'Конструкция создана');
    }

    #[Route('/{id}/edit', name: 'edit', requirements: ['id' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function edit(Request $request, Product $product): Response
    {
        return $this->handleForm($request, $product, 'admin/product/edit.html.twig', 'Изменения сохранены');
    }

    #[Route('/{id}/delete', name: 'delete', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"delete-product-" ~ args["product"].getId()'))]
    public function delete(Product $product): Response
    {
        $active = $this->bookings->countActiveForProduct($product, $this->clock->now());
        if ($active > 0) {
            $this->addFlash('error', \sprintf('Нельзя удалить «%s»: есть действующие брони (%d). Сначала отмените их.', $product->getName(), $active));

            return $this->redirectToRoute('admin_product_index', status: Response::HTTP_SEE_OTHER);
        }

        $this->entityManager->remove($product);
        $this->entityManager->flush();

        $this->addFlash('success', 'Конструкция удалена');

        return $this->redirectToRoute('admin_product_index', status: Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}/photos/{photo}/delete', name: 'photo_delete', requirements: ['id' => Requirement::DIGITS, 'photo' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"delete-photo-" ~ args["photo"].getId()'))]
    public function deletePhoto(Product $product, ProductSidePhoto $photo): Response
    {
        $side = $photo->getSide();
        if ($side?->getProduct() !== $product) {
            throw $this->createNotFoundException();
        }

        $side->removePhoto($photo);
        $this->entityManager->flush();

        $this->addFlash('success', 'Фото удалено');

        return $this->redirectToRoute('admin_product_edit', ['id' => $product->getId(), '_fragment' => 'sides'], Response::HTTP_SEE_OTHER);
    }

    private function handleForm(Request $request, Product $product, string $template, string $successMessage): Response
    {
        $originalSides = $product->getSides()->toArray();
        $form = $this->createForm(ProductFormType::class, $product);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // A side removed on the page would take its bookings with it: refuse while any is active.
            foreach ($originalSides as $side) {
                if (!$product->getSides()->contains($side) && $this->bookings->countActiveForSide($side, $this->clock->now()) > 0) {
                    $form->get('sides')->addError(new FormError(\sprintf('Сторону %s нельзя удалить: на неё есть действующие брони.', $side->getName())));
                }
            }
        }

        if ($form->isSubmitted() && $form->isValid()) {
            foreach ($form->get('sides') as $sideForm) {
                /** @var UploadedFile[] $files */
                $files = $sideForm->get('newPhotos')->getData() ?? [];
                foreach ($files as $file) {
                    $this->photoStorage->attach($sideForm->getData(), $file);
                }
            }

            // Changes to sides or photos alone do not touch the product row, so bump it explicitly.
            $product->touch();
            $this->entityManager->persist($product);
            $this->entityManager->flush();

            $this->addFlash('success', $successMessage);

            // Reopen the card on the tab the user saved from
            $tab = $request->request->getString('_tab');

            return $this->redirectToRoute('admin_product_edit', [
                'id' => $product->getId(),
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
        $now = $this->clock->now();

        return $this->render($template, [
            'product' => $product,
            'form' => $form,
            'tabErrors' => $tabErrors,
            'initialTab' => $tabErrors[0] ?? 'main',
            'availability' => null !== $product->getId() ? $this->availability->forProduct($product->getId(), $now) : null,
            'activeBookings' => null !== $product->getId() ? $this->bookings->countActiveForProduct($product, $now) : 0,
        ]);
    }
}
