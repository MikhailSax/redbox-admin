<?php

namespace App\Controller\Admin;

use App\Entity\AdditionalService;
use App\Form\AdditionalServiceFormType;
use App\Repository\AdditionalServiceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;

#[Route('/admin/services', name: 'admin_service_')]
final class AdditionalServiceController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(AdditionalServiceRepository $services): Response
    {
        return $this->render('admin/service/index.html.twig', [
            'services' => $services->findBy([], ['active' => 'DESC', 'name' => 'ASC']),
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        return $this->handleForm($request, new AdditionalService(), 'Услуга добавлена');
    }

    #[Route('/{id}/edit', name: 'edit', requirements: ['id' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function edit(Request $request, AdditionalService $service): Response
    {
        return $this->handleForm($request, $service, 'Изменения сохранены');
    }

    /**
     * Media plans keep their copies of the name and prices, so deleting is always safe.
     */
    #[Route('/{id}/delete', name: 'delete', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"delete-service-" ~ args["service"].getId()'))]
    public function delete(AdditionalService $service): Response
    {
        $this->entityManager->remove($service);
        $this->entityManager->flush();
        $this->addFlash('success', 'Услуга удалена');

        return $this->redirectToRoute('admin_service_index', status: Response::HTTP_SEE_OTHER);
    }

    private function handleForm(Request $request, AdditionalService $service, string $successMessage): Response
    {
        $form = $this->createForm(AdditionalServiceFormType::class, $service);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $service->touch();
            $this->entityManager->persist($service);
            $this->entityManager->flush();
            $this->addFlash('success', $successMessage);

            return $this->redirectToRoute('admin_service_index', status: Response::HTTP_SEE_OTHER);
        }

        return $this->render('admin/service/form.html.twig', ['service' => $service, 'form' => $form]);
    }
}
