<?php

namespace App\Controller\Api;

use App\Entity\Package;
use App\Repository\PackageRepository;
use App\Service\Api\CatalogPresenter;
use App\Service\Package\PackageOffers;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * "Пакетные предложения" for the catalogue: running packages with the sets of structures free for the period.
 * The client sends the set back with the order (POST /api/v1/orders, "package").
 */
#[Route('/api/v1', name: 'api_')]
final class PackageController extends AbstractController
{
    public function __construct(
        private readonly PackageRepository $packages,
        private readonly PackageOffers $offers,
        private readonly CatalogPresenter $presenter,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * /api/v1/packages?from=2026-11-01&months=2: from the 1st of next month and each package's shortest term by default.
     */
    #[Route('/packages', name: 'packages', methods: ['GET'])]
    public function index(#[MapQueryParameter] ?string $from = null, #[MapQueryParameter] ?int $months = null): JsonResponse
    {
        $start = self::date($from) ?? $this->offers->defaultStart();

        return $this->json([
            'items' => array_map(fn (Package $package) => $this->presenter->package($this->offers->offer($package, $start, $months)), $this->packages->findRunning($this->clock->now())),
        ]);
    }

    #[Route('/packages/{id}', name: 'package', requirements: ['id' => Requirement::DIGITS], methods: ['GET'])]
    public function show(int $id, #[MapQueryParameter] ?string $from = null, #[MapQueryParameter] ?int $months = null): JsonResponse
    {
        $package = $this->packages->findRunningById($id, $this->clock->now()) ?? throw $this->createNotFoundException();

        return $this->json($this->presenter->package($this->offers->offer($package, self::date($from) ?? $this->offers->defaultStart(), $months)));
    }

    private static function date(?string $value): ?\DateTimeImmutable
    {
        if (null === $value || '' === $value) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return false !== $date ? $date : null;
    }
}
