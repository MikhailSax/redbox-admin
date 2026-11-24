<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Service\DashboardStats;
use App\Service\FinanceKpi;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardController extends AbstractController
{
    /**
     * Money of the month, occupancy, holds and payments for managers; an agent starts from the media plans.
     */
    #[Route('/admin', name: 'admin_dashboard', methods: ['GET'])]
    public function __invoke(DashboardStats $stats, FinanceKpi $finance, ClockInterface $clock): Response
    {
        if (!$this->isGranted(User::ROLE_SUPER_MANAGER)) {
            return $this->redirectToRoute('admin_media_plan_index');
        }

        return $this->render('admin/dashboard.html.twig', $stats->overview() + [
            'finance' => $finance->overview(),
            'now' => $clock->now(),
        ]);
    }
}
