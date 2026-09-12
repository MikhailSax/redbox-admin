<?php

namespace App\Controller\Admin;

use App\Service\DashboardStats;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardController extends AbstractController
{
    #[Route('/admin', name: 'admin_dashboard', methods: ['GET'])]
    public function __invoke(DashboardStats $stats, ClockInterface $clock): Response
    {
        return $this->render('admin/dashboard.html.twig', $stats->overview() + [
            'now' => $clock->now(),
        ]);
    }
}
