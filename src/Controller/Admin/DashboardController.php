<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Service\DashboardStats;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardController extends AbstractController
{
    /**
     * Occupancy, holds and payments for managers; an agent starts from the media plans.
     */
    #[Route('/admin', name: 'admin_dashboard', methods: ['GET'])]
    public function __invoke(DashboardStats $stats, ClockInterface $clock): Response
    {
        if (!$this->isGranted(User::ROLE_SUPER_MANAGER)) {
            return $this->redirectToRoute('admin_media_plan_index');
        }

        return $this->render('admin/dashboard.html.twig', $stats->overview() + [
            'now' => $clock->now(),
        ]);
    }
}
