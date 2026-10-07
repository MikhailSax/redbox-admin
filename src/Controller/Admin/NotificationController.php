<?php

namespace App\Controller\Admin;

use App\Entity\Notification;
use App\Entity\User;
use App\Repository\NotificationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;

/**
 * The bell of the top bar: new clients, confirmed e-mails and requests from the website, per staff member.
 */
#[Route('/admin/notifications', name: 'admin_notification_')]
final class NotificationController extends AbstractController
{
    public function __construct(
        private readonly NotificationRepository $notifications,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(#[CurrentUser] User $user): Response
    {
        return $this->render('admin/notification/index.html.twig', [
            'notifications' => $this->notifications->findLatest($user, 200),
        ]);
    }

    /** Unread count for the badge, polled by assets/admin/notifications.js */
    #[Route('/unread', name: 'unread', methods: ['GET'])]
    public function unread(#[CurrentUser] User $user): JsonResponse
    {
        return $this->json(['unread' => $this->notifications->countUnread($user)]);
    }

    /** Marks the notification read and goes where it points */
    #[Route('/{id}', name: 'open', requirements: ['id' => Requirement::DIGITS], methods: ['GET'])]
    public function open(#[CurrentUser] User $user, Notification $notification, EntityManagerInterface $entityManager): RedirectResponse
    {
        if ($notification->getRecipient()->getId() !== $user->getId()) {
            throw $this->createNotFoundException('Уведомление не найдено');
        }
        $notification->markRead($this->clock->now());
        $entityManager->flush();

        return $this->redirect($notification->getLink() ?? $this->generateUrl('admin_notification_index'), Response::HTTP_SEE_OTHER);
    }

    #[Route('/read', name: 'read_all', methods: ['POST'])]
    #[IsCsrfTokenValid('notifications-read')]
    public function readAll(#[CurrentUser] User $user, Request $request): RedirectResponse
    {
        $this->notifications->markAllRead($user, $this->clock->now());

        // back to the page the bell was used on, if it is one of ours
        $back = $request->headers->get('referer');
        $path = null !== $back ? parse_url($back, \PHP_URL_PATH) : null;

        return $this->redirect(\is_string($path) && str_starts_with($path, '/admin') ? $path : $this->generateUrl('admin_notification_index'), Response::HTTP_SEE_OTHER);
    }
}
