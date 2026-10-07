<?php

namespace App\Tests\Controller\Admin;

use App\Dto\BookingRequest;
use App\Entity\Booking;
use App\Entity\Category;
use App\Entity\District;
use App\Entity\Product;
use App\Entity\ProductSide;
use App\Entity\ProductType;
use App\Entity\User;
use App\Enum\BookingMode;
use App\Service\BookingManager;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * Sales report: a booking counts whole in the month it was confirmed, by structure and by who made it.
 */
final class ReportControllerTest extends AdminWebTestCase
{
    use ClockSensitiveTrait;

    private MockClock $clock;
    private Product $billboard;
    private Product $screen;
    private User $customer;
    private User $anna;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = self::mockTime('2026-09-10 12:00:00');
        $this->customer = $this->createClientCard();
        $this->anna = $this->createUser('anna@redbox.local', User::ROLE_SUPER_MANAGER)->setName('Анна Котова');

        $category = (new Category())->setName('Билборд 6х3');
        $district = (new District())->setName('Центральный');
        $static = (new ProductType())->setName('Статика');
        $video = (new ProductType())->setName('Видеоэкран')->setBookingMode(BookingMode::Airtime);
        $this->billboard = $this->product('Щит на Ленина', $category, $static, $district, ['A', 'B']);
        $this->screen = $this->product('Экран на площади', $category, $video, $district, ['A']);
        $idle = $this->product('Щит на Мира', $category, $static, $district, ['A']);
        foreach ([$category, $district, $static, $video, $this->billboard, $this->screen, $idle] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
    }

    public function testSalesOfTheMonthByStructureAndManager(): void
    {
        $manager = static::getContainer()->get(BookingManager::class);
        // September: two billboard sides by me (one paid), the screen by Anna
        $a = $this->whole('A', '2026-10', 3, 90000, $this->currentUser);
        $manager->markPaid($a);
        $manager->confirm($this->whole('B', '2026-11', 1, 30000, $this->currentUser));
        $manager->confirm($this->airtime('2026-09-15', '2026-09-28', 20000, $this->anna));
        // made by Anna, never confirmed: burns out
        $this->whole('B', '2026-12', 1, 30000, $this->anna);
        // cancelled after confirming: no sale
        $cancelled = $this->whole('A', '2027-03', 1, 50000, $this->anna);
        $manager->confirm($cancelled);
        $manager->cancel($cancelled);
        // October: confirmed next month, not in September
        $this->clock->modify('+1 month');
        $manager->confirm($this->whole('A', '2027-01', 1, 40000, $this->anna));

        // non-breaking spaces ("140 000 ₽") as plain ones
        $text = static fn ($node): string => trim(str_replace("\u{a0}", ' ', $node->text()));

        $crawler = $this->client->request('GET', '/admin/reports?from=2026-09&to=2026-09');
        self::assertResponseIsSuccessful();
        self::assertSame('3', $text($crawler->filter('[data-total="1"] dd')->first()));
        self::assertSame('140 000 ₽', $text($crawler->filter('[data-total="2"] dd')->first()));
        self::assertSame('90 000 ₽', $text($crawler->filter('[data-total="3"] dd')->first()));
        self::assertSame('2 из 3', $text($crawler->filter('[data-total="5"] dd')->first()));

        // structures: the best seller first, months of whole sides, days of airtime
        $rows = $crawler->filter('#report-products tbody tr');
        self::assertCount(2, $rows);
        self::assertStringContainsString('Щит на Ленина', $text($rows->eq(0)));
        self::assertStringContainsString('4 мес.', $text($rows->eq(0)));
        self::assertStringContainsString('120 000 ₽', $text($rows->eq(0)));
        self::assertStringContainsString('86%', $text($rows->eq(0)));
        self::assertStringContainsString('14 дн. эфира', $text($rows->eq(1)));
        self::assertSelectorTextContains('details summary', 'Не продавались за период: 1 конструкция');

        // managers: what they sold, and Anna's hold that burned out
        $managers = $crawler->filter('#report-managers tbody tr');
        self::assertStringContainsString('Пользователь me@redbox.local', $text($managers->eq(0)));
        self::assertStringContainsString('120 000 ₽', $text($managers->eq(0)));
        self::assertStringContainsString('60 000 ₽', $text($managers->eq(0))); // average
        self::assertStringContainsString('Анна Котова', $text($managers->eq(1)));
        self::assertSame('1', trim($managers->eq(1)->filter('td')->last()->text()));

        // the quarter takes October in too
        $crawler = $this->client->request('GET', '/admin/reports?from=2026-09&to=2026-10');
        self::assertSame('4', trim($crawler->filter('[data-total="1"] dd')->first()->text()));
        // the current month by default
        $crawler = $this->client->request('GET', '/admin/reports');
        self::assertSame('1', trim($crawler->filter('[data-total="1"] dd')->first()->text()));
        self::assertSelectorExists('a.chip-on:contains("Этот месяц")');
        // the period only, for the live filter
        $this->client->request('GET', '/admin/reports?from=2026-09', server: ['HTTP_X_LIVE_FILTER' => '1']);
        self::assertSelectorNotExists('form[data-live-filter]');
        self::assertSelectorExists('#report-products');
    }

    public function testAgentsHaveNoReports(): void
    {
        $agent = $this->createUser('agent@redbox.local', User::ROLE_AGENT);
        $this->client->loginUser($agent);
        $this->client->request('GET', '/admin/reports');
        self::assertResponseStatusCodeSame(403);
    }

    private function whole(string $side, string $month, int $months, int $price, User $author): Booking
    {
        $request = new BookingRequest();
        $request->side = $this->billboard->getSides()->filter(fn (ProductSide $s) => $s->getName() === $side)->first();
        $request->startMonth = $month;
        $request->months = $months;

        return $this->hold($request, $price, $author);
    }

    private function airtime(string $start, string $end, int $price, User $author): Booking
    {
        $request = new BookingRequest();
        $request->side = $this->screen->getSides()->first();
        $request->startDate = new \DateTimeImmutable($start);
        $request->endDate = new \DateTimeImmutable($end);

        return $this->hold($request, $price, $author);
    }

    private function hold(BookingRequest $request, int $price, User $author): Booking
    {
        $request->client = $this->customer;
        $request->clientName = 'ООО Ромашка';
        $request->clientPhone = '+7 900 000-00-00';
        $request->soldPrice = $price;

        return static::getContainer()->get(BookingManager::class)->hold($request, $author);
    }

    /** @param list<string> $sides */
    private function product(string $name, Category $category, ProductType $type, District $district, array $sides): Product
    {
        $product = (new Product())->setName($name)->setCategory($category)->setProductType($type)
            ->setDistrict($district)->setPrice('30000')->setLatitude('55.0000000')->setLongitude('37.0000000');
        foreach ($sides as $side) {
            $product->addSide((new ProductSide())->setName($side));
        }

        return $product;
    }
}
