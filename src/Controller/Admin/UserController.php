<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Form\UserFormType;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/users', name: 'admin_user_')]
#[IsGranted(User::ROLE_ADMIN)]
final class UserController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(UserRepository $users): Response
    {
        return $this->render('admin/user/index.html.twig', [
            'users' => $users->findStaff(), // clients are managed in «Клиенты»
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $user = (new User())->setRole(User::ROLE_SUPER_MANAGER);

        return $this->handleForm($request, $user, 'Пользователь создан');
    }

    #[Route('/{id}/edit', name: 'edit', requirements: ['id' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function edit(Request $request, User $user): Response
    {
        if ($user->isClient()) {
            throw $this->createNotFoundException();
        }

        return $this->handleForm($request, $user, 'Изменения сохранены');
    }

    #[Route('/{id}/delete', name: 'delete', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"delete-user-" ~ args["user"].getId()'))]
    public function delete(User $user): Response
    {
        if ($this->isCurrentUser($user)) {
            $this->addFlash('error', 'Нельзя удалить свою учётную запись');

            return $this->redirectToRoute('admin_user_index', status: Response::HTTP_SEE_OTHER);
        }

        $this->entityManager->remove($user);
        $this->entityManager->flush();

        $this->addFlash('success', 'Пользователь удалён');

        return $this->redirectToRoute('admin_user_index', status: Response::HTTP_SEE_OTHER);
    }

    private function handleForm(Request $request, User $user, string $successMessage): Response
    {
        $form = $this->createForm(UserFormType::class, $user, [
            'require_password' => null === $user->getId(),
            // An admin can't demote themselves, so there is always at least one admin left.
            'can_change_role' => !$this->isCurrentUser($user),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $plainPassword = $form->get('plainPassword')->getData();
            if (null !== $plainPassword && '' !== $plainPassword) {
                $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));
            }

            $user->touch();
            $this->entityManager->persist($user);
            $this->entityManager->flush();

            $this->addFlash('success', $successMessage);

            return $this->redirectToRoute('admin_user_index', status: Response::HTTP_SEE_OTHER);
        }

        return $this->render('admin/user/form.html.twig', [
            'user' => $user,
            'form' => $form,
        ]);
    }

    private function isCurrentUser(User $user): bool
    {
        $current = $this->getUser();

        return $current instanceof User && null !== $user->getId() && $current->getId() === $user->getId();
    }
}
