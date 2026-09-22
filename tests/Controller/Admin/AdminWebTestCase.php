<?php

namespace App\Tests\Controller\Admin;

use App\Entity\AdditionalService;
use App\Entity\Booking;
use App\Entity\MediaPlan;
use App\Entity\MediaPlanItem;
use App\Entity\MediaPlanServiceLine;
use App\Entity\Payment;
use App\Entity\Category;
use App\Entity\District;
use App\Entity\Partner;
use App\Entity\Product;
use App\Entity\ProductSide;
use App\Entity\ProductSidePhoto;
use App\Entity\Lead;
use App\Entity\LeadItem;
use App\Entity\ProductType;
use App\Entity\ClientDocument;
use App\Entity\PhotoReport;
use App\Entity\PhotoReportPhoto;
use App\Entity\Promotion;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Starts every test with empty tables and an empty uploads directory,
 * logged in as an administrator (set $loginAs to change or disable that).
 */
abstract class AdminWebTestCase extends WebTestCase
{
    protected const PASSWORD = 'secret-password';

    protected KernelBrowser $client;
    protected EntityManagerInterface $em;
    protected string $uploadsDir;
    protected ?User $currentUser = null;

    /** Role of the user logged in before each test; null = anonymous */
    protected ?string $loginAs = User::ROLE_ADMIN;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->uploadsDir = $container->getParameter('app.uploads_dir');
        (new Filesystem())->remove([$this->uploadsDir, $container->getParameter('app.private_storage_dir')]);
        // Login throttling state lives in a filesystem cache: reset it so failed logins don't leak between tests
        $container->get('cache.rate_limiter')->clear();

        foreach ([Payment::class, LeadItem::class, Lead::class, MediaPlanServiceLine::class, MediaPlanItem::class, MediaPlan::class, Promotion::class, AdditionalService::class, PhotoReportPhoto::class, PhotoReport::class, ClientDocument::class, Booking::class, ProductSidePhoto::class, ProductSide::class, Product::class, Partner::class, ProductType::class, Category::class, District::class, User::class] as $class) {
            $this->em->createQuery(\sprintf('DELETE FROM %s e', $class))->execute();
        }

        if (null !== $this->loginAs) {
            $this->currentUser = $this->createUser('me@redbox.local', $this->loginAs);
            $this->client->loginUser($this->currentUser);
        }
    }

    protected function createUser(string $email, string $role, string $password = self::PASSWORD): User
    {
        $user = (new User())->setEmail($email)->setName('Пользователь '.$email)->setRole($role)->setEmailVerifiedAt(new \DateTimeImmutable());
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, $password));
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /** A client card bookings, media plans and payments can be made out to */
    protected function createClientCard(string $company = 'ООО Ромашка', string $email = 'client@romashka.ru'): User
    {
        $client = $this->createUser($email, User::ROLE_CLIENT)
            ->setName('Иван Петров')
            ->setCompany($company)
            ->setPhone('+7 900 111-22-33');
        $this->em->flush();

        return $client;
    }

    protected function makeImage(string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'img');
        $image = imagecreatetruecolor(30, 20);
        imagepng($image, $path);

        return new UploadedFile($path, $name, 'image/png', null, true);
    }

    /**
     * @return array{0: array<string, mixed>, 1: string} PHP-style form values and the action URI
     */
    protected function formValues(Crawler $crawler, string $button): array
    {
        $form = $crawler->selectButton($button)->form();

        return [$form->getPhpValues(), $form->getUri()];
    }

    protected function submit(string $uri, array $values, array $files = []): void
    {
        // Same-origin header satisfies Symfony's stateless CSRF check for the form token.
        $this->client->request('POST', $uri, $values, $files, ['HTTP_ORIGIN' => 'http://localhost']);
    }

    /**
     * Submits one of the small POST forms (delete buttons) found on the current page.
     */
    protected function submitPostForm(Crawler $crawler, string $selector): void
    {
        $form = $crawler->filter($selector)->form();
        $this->client->request('POST', $form->getUri(), $form->getPhpValues());
    }
}
