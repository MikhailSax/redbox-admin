<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Service\GlobalSearch;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;

final class SearchController extends AbstractController
{
    /**
     * JSON for the Ctrl/⌘+K command palette (assets/admin/command-palette.js).
     */
    #[Route('/admin/search', name: 'admin_search', methods: ['GET'])]
    public function __invoke(GlobalSearch $search, #[MapQueryParameter] string $q = ''): JsonResponse
    {
        return $this->json([
            'query' => $q,
            'groups' => $search->search($q, $this->isGranted(User::ROLE_ADMIN)),
        ]);
    }
}
