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
use App\Enum\BookingKind;
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
        self::assertSame('75%', $text($crawler->filter('[data-total="5"] dd')->first())); // 3 of 4 sides

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

    /**
     * Shipments: a confirmed booking brings its "Продано за" to the period by the days it runs there;
     * holds ship nothing; by month and by structure.
     */
    public function testShipmentsOfThePeriodByDays(): void
    {
        $manager = static::getContainer()->get(BookingManager::class);
        // October–December, 31 + 30 + 31 = 92 days, paid; November alone, confirmed and not paid yet
        $manager->markPaid($this->whole('A', '2026-10', 3, 90000, $this->currentUser));
        $manager->confirm($this->whole('B', '2026-11', 1, 30000, $this->anna));
        // September: outside the period; a hold in November: not shipped
        $manager->confirm($this->airtime('2026-09-15', '2026-09-28', 20000, $this->anna));
        $this->airtime('2026-11-02', '2026-11-20', 15000, $this->anna);

        $text = static fn ($node): string => trim(preg_replace('/\s+/u', ' ', $node->text()));
        $crawler = $this->client->request('GET', '/admin/reports?view=shipments&from=2026-10&to=2026-11');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-report-view="shipments"][aria-current="page"]', 'Отгрузки');

        // 90 000 × 31 / 92 = 30 326,09 in October, 90 000 × 61 / 92 − October = 29 347,82 in November, and 30 000
        self::assertSame('89 673,91 ₽', $text($crawler->filter('[data-shipment-total="1"] dd')->first()));
        self::assertSame('59 673,91 ₽', $text($crawler->filter('[data-shipment-total="2"] dd')->first()));
        self::assertSame('30 000 ₽', $text($crawler->filter('[data-shipment-total="3"] dd')->first()));
        self::assertSame('50%', $text($crawler->filter('[data-shipment-total="5"] dd')->first())); // sides A and B of 4

        $months = $crawler->filter('#shipment-months tbody tr');
        self::assertCount(2, $months);
        self::assertStringContainsString('Октябрь 2026', $text($months->eq(0)));
        self::assertStringContainsString('30 326,09 ₽', $text($months->eq(0)));
        self::assertStringContainsString('59 347,82 ₽', $text($months->eq(1)));

        $rows = $crawler->filter('#shipment-products tbody tr');
        self::assertCount(1, $rows);
        self::assertStringContainsString('Щит на Ленина', $text($rows->eq(0)));
        self::assertStringContainsString('2 91 ', $text($rows->eq(0))); // bookings, days of the sides in the period

        // the period switch keeps the report
        self::assertStringContainsString('view=shipments', $crawler->filter('a.chip:contains("Этот месяц")')->attr('href'));
        // all three months of the paid booking add up to its price
        $crawler = $this->client->request('GET', '/admin/reports?view=shipments&from=2026-10&to=2026-12');
        self::assertStringContainsString('90 000 ₽', $text($crawler->filter('#shipment-products tbody tr')->eq(0)->filter('td')->eq(7)));
    }

    /** Narrowed to a side letter or to one manager ("who collected how much"), tables sorted by any column */
    public function testReportsBySideAndManagerSorted(): void
    {
        $manager = static::getContainer()->get(BookingManager::class);
        $manager->confirm($this->whole('A', '2026-10', 1, 40000, $this->currentUser));
        $manager->confirm($this->whole('B', '2026-10', 1, 30000, $this->anna));
        $manager->confirm($this->airtime('2026-09-15', '2026-09-28', 20000, $this->anna));

        $text = static fn ($node): string => trim(preg_replace('/\s+/u', ' ', $node->text()));
        $tile = fn (string $query, int $n) => $text($this->client->request('GET', '/admin/reports?from=2026-09'.$query)->filter('[data-total="'.$n.'"] dd')->first());

        self::assertSame('90 000 ₽', $tile('', 2));
        self::assertSame('Загружено на', $text($this->client->getCrawler()->filter('[data-total="2"] dt')));
        self::assertSame('Загружено поверхностей', $text($this->client->getCrawler()->filter('[data-total="5"] dt')));
        // side "В" is the Latin "B" of the billboard alone: 1 of its 1 side
        self::assertSame('30 000 ₽', $tile('&side=В', 2));
        self::assertSame('100%', $tile('&side=В', 5));
        // side "А": the billboard's A and the screen
        self::assertSame('60 000 ₽', $tile('&side=А', 2));
        // Anna's alone
        self::assertSame('50 000 ₽', $tile('&manager='.$this->anna->getId(), 2));
        self::assertCount(1, $this->client->getCrawler()->filter('#report-managers tbody tr'));

        $products = fn (string $query) => $this->client->request('GET', '/admin/reports?from=2026-09'.$query)->filter('#report-products tbody th')
            ->each(static fn ($th) => $th->filter('a')->text());
        self::assertSame(['Щит на Ленина', 'Экран на площади'], $products(''));
        self::assertSame(['Экран на площади', 'Щит на Ленина'], $products('&sort=total&dir=asc'));
        self::assertSame(['Экран на площади', 'Щит на Ленина'], $products('&sort=name&dir=desc'));
        // the sorting is carried by the other links
        self::assertStringContainsString('sort=name', $this->client->getCrawler()->filter('a.chip:contains("Этот месяц")')->attr('href'));

        // shipments of October by manager: who collected how much
        $managers = fn (string $query) => $this->client->request('GET', '/admin/reports?view=shipments&from=2026-10&to=2026-10'.$query)->filter('#shipment-managers tbody tr')
            ->each(static fn ($row) => $text($row));
        $rows = $managers('');
        self::assertCount(2, $rows);
        self::assertStringContainsString('40 000 ₽', $rows[0]);
        self::assertStringContainsString('Анна Котова', $rows[1]);
        self::assertStringContainsString('Анна Котова', $managers('&msort=total&mdir=asc')[0]);
        self::assertCount(1, $managers('&manager='.$this->anna->getId()));
    }

    /** A bonus and a certificate load the surfaces but bring no money; the certificate's sum is shown apart */
    public function testBookingsNotSoldForMoney(): void
    {
        $manager = static::getContainer()->get(BookingManager::class);
        // October–November, 31 + 30 = 61 days
        $manager->confirm($this->whole('A', '2026-10', 2, 0, $this->currentUser)->changeKind(BookingKind::Certificate, 60000));
        $manager->confirm($this->whole('B', '2026-10', 1, 30000, $this->anna)->changeKind(BookingKind::Bonus));

        $text = static fn ($node): string => trim(preg_replace('/\s+/u', ' ', $node->text()));
        $crawler = $this->client->request('GET', '/admin/reports?from=2026-09');
        self::assertSame('2', $text($crawler->filter('[data-total="1"] dd')->first()));
        self::assertSame('0 ₽', $text($crawler->filter('[data-total="2"] dd')->first()));
        self::assertSame('50%', $text($crawler->filter('[data-total="5"] dd')->first())); // 2 of 4 sides
        self::assertStringNotContainsString('без суммы', $text($crawler->filter('[data-total="1"]')));
        $kinds = $text($crawler->filter('[data-report-kinds]'));
        self::assertStringContainsString('Сертификат — 1 на 60 000 ₽', $kinds);
        self::assertStringContainsString('Бонус — 1', $kinds);

        // shipments of October: the certificate's sum by days, 60 000 × 31 / 61
        $crawler = $this->client->request('GET', '/admin/reports?view=shipments&from=2026-10&to=2026-10');
        self::assertSame('0 ₽', $text($crawler->filter('[data-shipment-total="1"] dd')->first()));
        self::assertStringContainsString('Сертификат — 1 на 30 491,80 ₽', $text($crawler->filter('[data-report-kinds]')));
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
