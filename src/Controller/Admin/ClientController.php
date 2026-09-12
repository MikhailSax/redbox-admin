<?php

namespace App\Controller\Admin;

use App\Dto\ClientDocumentUpload;
use App\Entity\ClientDocument;
use App\Entity\PhotoReport;
use App\Entity\PhotoReportPhoto;
use App\Entity\User;
use App\Form\ClientDocumentFormType;
use App\Form\ClientFormType;
use App\Form\PhotoReportFormType;
use App\Repository\ClientDocumentRepository;
use App\Repository\PhotoReportRepository;
use App\Repository\UserRepository;
use App\Security\ClientFileVoter;
use App\Service\PrivateFileStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Website clients: their accounts, and the documents and photo reports put into their personal account.
 * Files live in private storage; the download routes check ClientFileVoter (the personal account will use it too).
 */
#[Route('/admin/clients', name: 'admin_client_')]
final class ClientController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $users,
        private readonly ClientDocumentRepository $documents,
        private readonly PhotoReportRepository $reports,
        private readonly PrivateFileStorage $storage,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request, #[MapQueryParameter] ?string $q = null): Response
    {
        $clients = $this->users->findClients($q);
        $ids = array_map(static fn (User $c) => $c->getId(), $clients);
        $params = [
            'clients' => $clients,
            'documentCounts' => $this->documents->countByClient($ids),
            'reportCounts' => $this->reports->countByClient($ids),
            'q' => $q,
        ];

        if ($request->headers->has('X-Live-Filter')) {
            return $this->renderBlock('admin/client/index.html.twig', 'results', $params);
        }

        return $this->render('admin/client/index.html.twig', $params);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        return $this->profile($request, (new User())->setRole(User::ROLE_CLIENT), 'Клиент добавлен — прикрепите ему документы и фотоотчёты');
    }

    /**
     * The client card: profile, documents and photo reports as tabs.
     */
    #[Route('/{id}', name: 'show', requirements: ['id' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function show(Request $request, User $client): Response
    {
        $this->assertClient($client);

        return $this->profile($request, $client, 'Изменения сохранены');
    }

    #[Route('/{id}/delete', name: 'delete', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"delete-client-" ~ args["client"].getId()'))]
    public function delete(User $client): Response
    {
        $this->assertClient($client);

        // Documents and reports go with the account (ON DELETE CASCADE); their files too
        $folder = 'clients/'.$client->getId();
        $this->entityManager->remove($client);
        $this->entityManager->flush();
        $this->storage->removeFolder($folder);
        $this->addFlash('success', 'Клиент удалён вместе с документами и фотоотчётами');

        return $this->redirectToRoute('admin_client_index', status: Response::HTTP_SEE_OTHER);
    }

    /* ---------- Documents ---------- */

    #[Route('/{id}/documents', name: 'upload_documents', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    public function uploadDocuments(Request $request, User $client): Response
    {
        $this->assertClient($client);
        $form = $this->documentForm($client);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var ClientDocumentUpload $upload */
            $upload = $form->getData();
            foreach ($upload->files as $index => $file) {
                $document = new ClientDocument($client, $upload->type, $upload->titleFor($file, $index), $this->storage->store($file, ClientDocument::folder($client)), $upload->comment, $this->currentUser());
                $this->entityManager->persist($document);
            }
            $this->entityManager->flush();
            $this->addFlash('success', \sprintf('Загружено документов: %d. Клиент увидит их в личном кабинете.', \count($upload->files)));
        } else {
            $this->flashErrors($form);
        }

        return $this->redirectToRoute('admin_client_show', ['id' => $client->getId(), '_fragment' => 'documents'], Response::HTTP_SEE_OTHER);
    }

    #[Route('/documents/{document}', name: 'document', requirements: ['document' => Requirement::DIGITS], methods: ['GET'])]
    #[IsGranted(ClientFileVoter::VIEW, subject: 'document')]
    public function document(ClientDocument $document, #[MapQueryParameter] bool $inline = false): BinaryFileResponse
    {
        return $this->file(
            $this->storage->path($document->getFolder(), $document->getFileName()),
            $document->getOriginalName(),
            $inline && $document->isViewable() ? ResponseHeaderBag::DISPOSITION_INLINE : ResponseHeaderBag::DISPOSITION_ATTACHMENT,
        );
    }

    #[Route('/documents/{document}/delete', name: 'delete_document', requirements: ['document' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"delete-document-" ~ args["document"].getId()'))]
    public function deleteDocument(ClientDocument $document): Response
    {
        $client = $document->getClient();
        $this->storage->remove($document->getFolder(), $document->getFileName());
        $this->entityManager->remove($document);
        $this->entityManager->flush();
        $this->addFlash('success', 'Документ удалён');

        return $this->redirectToRoute('admin_client_show', ['id' => $client->getId(), '_fragment' => 'documents'], Response::HTTP_SEE_OTHER);
    }

    /* ---------- Photo reports ---------- */

    #[Route('/{id}/reports', name: 'create_report', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    public function createReport(Request $request, User $client): Response
    {
        $this->assertClient($client);
        $report = $this->newReport($client);
        $form = $this->reportForm($client, $report);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var list<UploadedFile> $photos */
            $photos = $form->get('photos')->getData();
            foreach ($photos as $photo) {
                $report->addPhoto(new PhotoReportPhoto($this->storage->store($photo, PhotoReport::folder($client))));
            }
            $report->setUploadedBy($this->currentUser());
            $this->entityManager->persist($report);
            $this->entityManager->flush();
            $this->addFlash('success', \sprintf('Фотоотчёт «%s» добавлен: фото — %d', $report->getTitle(), \count($photos)));
        } else {
            $this->flashErrors($form);
        }

        return $this->redirectToRoute('admin_client_show', ['id' => $client->getId(), '_fragment' => 'reports'], Response::HTTP_SEE_OTHER);
    }

    #[Route('/reports/{report}/delete', name: 'delete_report', requirements: ['report' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"delete-report-" ~ args["report"].getId()'))]
    public function deleteReport(PhotoReport $report): Response
    {
        $client = $report->getClient();
        foreach ($report->getPhotos() as $photo) {
            $this->storage->remove($report->getFolder(), $photo->getFileName());
        }
        $this->entityManager->remove($report);
        $this->entityManager->flush();
        $this->addFlash('success', 'Фотоотчёт удалён');

        return $this->redirectToRoute('admin_client_show', ['id' => $client->getId(), '_fragment' => 'reports'], Response::HTTP_SEE_OTHER);
    }

    #[Route('/photos/{photo}', name: 'photo', requirements: ['photo' => Requirement::DIGITS], methods: ['GET'])]
    #[IsGranted(ClientFileVoter::VIEW, subject: 'photo')]
    public function photo(PhotoReportPhoto $photo): BinaryFileResponse
    {
        $response = $this->file($this->storage->path($photo->getReport()->getFolder(), $photo->getFileName()), $photo->getOriginalName(), ResponseHeaderBag::DISPOSITION_INLINE);
        $response->setPrivate();
        $response->setMaxAge(86400);

        return $response;
    }

    /* ---------- Helpers ---------- */

    private function profile(Request $request, User $client, string $successMessage): Response
    {
        $form = $this->createForm(ClientFormType::class, $client);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $password = $form->get('plainPassword')->getData();
            if (null !== $password && '' !== $password) {
                $client->setPassword($this->passwordHasher->hashPassword($client, $password));
            } elseif (null === $client->getPassword()) {
                // No password yet: nobody can sign in until the client sets one on the website
                $client->setPassword($this->passwordHasher->hashPassword($client, bin2hex(random_bytes(24))));
            }
            $client->touch();
            $this->entityManager->persist($client);
            $this->entityManager->flush();
            $this->addFlash('success', $successMessage);

            return $this->redirectToRoute('admin_client_show', ['id' => $client->getId()], Response::HTTP_SEE_OTHER);
        }

        $params = ['client' => $client, 'form' => $form, 'initialTab' => 'profile'];
        if (null !== $client->getId()) {
            $params += [
                'documents' => $this->documents->findForClient($client),
                'reports' => $this->reports->findForClient($client),
                'documentForm' => $this->documentForm($client),
                'reportForm' => $this->reportForm($client, $this->newReport($client)),
            ];
        }

        return $this->render('admin/client/show.html.twig', $params);
    }

    private function documentForm(User $client): FormInterface
    {
        return $this->createForm(ClientDocumentFormType::class, new ClientDocumentUpload(), [
            'action' => $this->generateUrl('admin_client_upload_documents', ['id' => $client->getId()]),
        ]);
    }

    private function reportForm(User $client, PhotoReport $report): FormInterface
    {
        return $this->createForm(PhotoReportFormType::class, $report, [
            'action' => $this->generateUrl('admin_client_create_report', ['id' => $client->getId()]),
        ]);
    }

    private function newReport(User $client): PhotoReport
    {
        return (new PhotoReport())->setClient($client)->setTitle('Фотоотчёт')->setShotAt($this->clock->now()->setTime(0, 0));
    }

    private function flashErrors(FormInterface $form): void
    {
        foreach ($form->getErrors(true) as $error) {
            $this->addFlash('error', $error->getMessage());
        }
    }

    private function assertClient(User $user): void
    {
        if (!$user->isClient()) {
            throw $this->createNotFoundException();
        }
    }

    private function currentUser(): ?User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : null;
    }
}
