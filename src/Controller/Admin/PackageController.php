<?php

namespace App\Controller\Admin;

use App\Entity\Package;
use App\Entity\PackageSlot;
use App\Entity\User;
use App\Form\PackageFormType;
use App\Repository\PackageRepository;
use App\Service\Package\PackageOffers;
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
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Package offers: sets of sides sold together at the package's prices, shown on the website
 * with the sets that are free (see PackageOffers and the API's /packages).
 */
#[Route('/admin/packages', name: 'admin_package_')]
#[IsGranted(User::ROLE_SUPER_MANAGER)]
final class PackageController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
        private readonly PackageRepository $packages,
        private readonly PackageOffers $offers,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request, #[MapQueryParameter] ?string $q = null): Response
    {
        $params = [
            'packages' => $this->packages->findForList($q),
            'leads' => $this->packages->countLeads(),
            'q' => $q,
            'now' => $this->clock->now(),
        ];

        if ($request->headers->has('X-Live-Filter')) {
            return $this->renderBlock('admin/package/index.html.twig', 'results', $params);
        }

        return $this->render('admin/package/index.html.twig', $params);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $package = (new Package())
            ->setStartsAt($this->clock->now()->setTime(0, 0))
            ->addSlot((new PackageSlot())->setQuantity(1));

        return $this->handleForm($request, $package, 'Пакет создан');
    }

    #[Route('/{id}/edit', name: 'edit', requirements: ['id' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function edit(Request $request, Package $package): Response
    {
        return $this->handleForm($request, $package, 'Изменения сохранены');
    }

    #[Route('/{id}/toggle', name: 'toggle', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"package-" ~ args["package"].getId()'))]
    public function toggle(Request $request, Package $package): Response
    {
        $package->setActive(!$package->isActive())->touch();
        $this->entityManager->flush();
        $this->addFlash('success', \sprintf('Пакет «%s» %s', $package->getTitle(), $package->isActive() ? 'включён' : 'выключен'));

        return 'card' === $request->getPayload()->getString('back')
            ? $this->redirectToRoute('admin_package_edit', ['id' => $package->getId()], Response::HTTP_SEE_OTHER)
            : $this->redirectToRoute('admin_package_index', status: Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}/delete', name: 'delete', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"delete-package-" ~ args["package"].getId()'))]
    public function delete(Package $package): Response
    {
        // Requests keep the package's title and prices (Lead::$packageTitle, LeadItem::$monthlyPrice)
        $this->entityManager->remove($package);
        $this->entityManager->flush();
        $this->addFlash('success', 'Пакет удалён');

        return $this->redirectToRoute('admin_package_index', status: Response::HTTP_SEE_OTHER);
    }

    private function handleForm(Request $request, Package $package, string $successMessage): Response
    {
        $form = $this->createForm(PackageFormType::class, $package);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // positions keep the order of the form
            foreach (array_values($package->getSlots()->toArray()) as $index => $slot) {
                $slot->setPosition($index);
            }
            $package->touch();
            $this->entityManager->persist($package);
            $this->entityManager->flush();
            $this->addFlash('success', $successMessage);

            return $this->redirectToRoute('admin_package_edit', ['id' => $package->getId()], Response::HTTP_SEE_OTHER);
        }

        $saved = null !== $package->getId() && !$form->isSubmitted();

        return $this->render('admin/package/form.html.twig', [
            'package' => $package,
            'form' => $form,
            // what the website shows for next month: only for a saved package, unchanged by the form
            'offer' => $saved ? $this->offers->offer($package, $this->offers->defaultStart()) : null,
            'leads' => null !== $package->getId() ? $this->packages->findLeads($package) : [],
            'now' => $this->clock->now(),
        ]);
    }
}
