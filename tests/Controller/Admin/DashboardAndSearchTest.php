<?php

namespace App\Tests\Controller\Admin;

use App\Dto\BookingRequest;
use App\Entity\Category;
use App\Entity\District;
use App\Entity\Product;
use App\Entity\ProductSide;
use App\Entity\ProductType;
use App\Entity\User;
use App\Enum\BookingMode;
use App\Service\BookingManager;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class DashboardAndSearchTest extends AdminWebTestCase
{
    use ClockSensitiveTrait;

    private Product $billboard;
    private Product $screen;
    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();
        self::mockTime('2026-09-10 12:00:00');
        $this->customer = $this->createClientCard();

        $category = (new Category())->setName('Билборд 6х3');
        $district = (new District())->setName('Центральный');
        $static = (new ProductType())->setName('Статика');
        $video = (new ProductType())->setName('Видеоэкран')->setBookingMode(BookingMode::Airtime);
        $this->billboard = $this->product('Щит на Ленина', $category, $static, $district, ['A', 'B']);
        $this->screen = $this->product('Экран у вокзала', $category, $video, $district, ['A']);

        foreach ([$category, $district, $static, $video, $this->billboard, $this->screen] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
    }

    public function testDashboardShowsBillboardsAndScreensApart(): void
    {
        $manager = static::getContainer()->get(BookingManager::class);
        $manager->markPaid($this->hold($this->billboard, 'A', 'Оплатил'));
        $this->hold($this->billboard, 'B', 'ООО Ромашка');
        // the screen's block is 12 slots × 5 s: 3 slots paid and 3 on hold fill half of it
        $manager->markPaid($this->hold($this->screen, 'A', 'Кафе', slots: 3));
        $this->hold($this->screen, 'A', 'Кафе', slots: 3);

        $crawler = $this->client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Добрый день');

        // Billboards: sides only, the screen is not among them; September is sold out
        $billboards = preg_replace('/\s+/', ' ', $crawler->filter('#dashboard-billboards')->text());
        self::assertStringContainsString('2 стороны', $billboards);
        self::assertStringContainsString('Свободны 0 0%', $billboards);
        self::assertStringContainsString('Забронированы 1 50%', $billboards);
        self::assertStringContainsString('Заняты 1 50%', $billboards);
        self::assertStringContainsString('100%', $billboards);

        // Screens: loaded by the seconds of airtime sold
        $screens = preg_replace('/\s+/', ' ', $crawler->filter('#dashboard-screens')->text());
        self::assertStringContainsString('1 экран', $screens);
        self::assertStringContainsString('Загрузка 50%', $screens);
        self::assertStringContainsString('Занято 25%', $screens);
        self::assertStringContainsString('Бронь 25%', $screens);

        // ...and each screen with its own load
        $rows = $crawler->filter('#dashboard-screen-load li');
        self::assertCount(1, $rows);
        self::assertStringContainsString('Экран у вокзала', $rows->text());
        self::assertStringContainsString('6 из 12 слотов', $rows->text());
        self::assertStringContainsString('50%', $rows->text());

        // The holds are listed with their pay buttons
        self::assertSelectorTextContains('main', 'ООО Ромашка');
        self::assertCount(2, $crawler->filter('main form[action$="/pay"] input[name="return"][value="dashboard"]'));
    }

    public function testDashboardWithoutScreens(): void
    {
        $this->em->remove($this->screen);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('0 экранов', $crawler->filter('#dashboard-screens')->text());
        self::assertSelectorTextContains('#dashboard-screen-load', 'Видеоэкранов нет');
    }

    public function testSidebarShowsHoldsButNotTheStructureCount(): void
    {
        $this->hold($this->billboard, 'A', 'Клиент');

        $crawler = $this->client->request('GET', '/admin/products');
        $sidebar = $crawler->filter('#default-sidebar');
        self::assertSame('Конструкции', trim($sidebar->filter('a[href="/admin/products"]')->text()));
        self::assertStringContainsString('1', $sidebar->filter('a[href="/admin/bookings"]')->text());
    }

    public function testBrandIconsAndLogo(): void
    {
        $crawler = $this->client->request('GET', '/admin');
        self::assertSame('/favicon.ico', $crawler->filter('link[rel="icon"][sizes="any"]')->attr('href'));
        self::assertSame('/apple-touch-icon.png', $crawler->filter('link[rel="apple-touch-icon"]')->attr('href'));
        self::assertCount(1, $crawler->filter('#default-sidebar img[src="/images/brand/logo-28.png"]'));
        foreach (['favicon.ico', 'apple-touch-icon.png', 'images/brand/logo-28.png', 'images/brand/logo-32.png', 'images/brand/logo-250.png'] as $file) {
            self::assertFileExists(static::getContainer()->getParameter('kernel.project_dir').'/public/'.$file);
        }
    }

    public function testCommandPaletteSearch(): void
    {
        $this->hold($this->billboard, 'A', 'ООО Ромашка');

        $this->client->request('GET', '/admin/search?q=ленин');
        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('Конструкции', $data['groups'][0]['title']);
        self::assertSame('Щит на Ленина', $data['groups'][0]['items'][0]['title']);
        self::assertSame('/admin/products/'.$this->billboard->getId().'/edit', $data['groups'][0]['items'][0]['url']);
        self::assertSame('free', $data['groups'][0]['items'][0]['badge']['tone']);

        // clients by name or phone, dictionaries by name; a client's name finds their card and their bookings
        $this->client->request('GET', '/admin/search?q=ромаш');
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame(['Брони и клиенты', 'Клиенты'], array_column($data['groups'], 'title'));
        self::assertStringStartsWith('ООО Ромашка', $data['groups'][0]['items'][0]['title']);

        $this->client->request('GET', '/admin/search?q=центр');
        $titles = array_column(json_decode($this->client->getResponse()->getContent(), true)['groups'], 'title');
        self::assertContains('Справочники', $titles);
        self::assertContains('Конструкции', $titles); // matched by district

        // too short → nothing
        $this->client->request('GET', '/admin/search?q=щ');
        self::assertSame([], json_decode($this->client->getResponse()->getContent(), true)['groups']);
    }

    public function testUsersAreSearchableForAdminsOnly(): void
    {
        $this->client->request('GET', '/admin/search?q=redbox');
        self::assertContains('Пользователи', array_column(json_decode($this->client->getResponse()->getContent(), true)['groups'], 'title'));

        $this->client->loginUser($this->createUser('manager@redbox.local', User::ROLE_SUPER_MANAGER));
        $this->client->request('GET', '/admin/search?q=redbox');
        self::assertNotContains('Пользователи', array_column(json_decode($this->client->getResponse()->getContent(), true)['groups'], 'title'));
    }

    public function testLiveFilterReturnsOnlyTheResultsBlock(): void
    {
        $this->client->request('GET', '/admin/products?q=вокзал', server: ['HTTP_X_LIVE_FILTER' => '1']);
        self::assertResponseIsSuccessful();
        $html = $this->client->getResponse()->getContent();

        self::assertStringNotContainsString('<html', $html);
        self::assertStringNotContainsString('default-sidebar', $html);
        self::assertStringContainsString('Экран у <mark class="hl">вокзал</mark>а', $html);
        self::assertStringNotContainsString('Щит на Ленина', $html);
        self::assertStringContainsString('data-live-link', $html);

        // bookings list: search by phone
        $this->hold($this->billboard, 'A', 'Кафе «Лето»');
        $this->client->request('GET', '/admin/bookings?q=900', server: ['HTTP_X_LIVE_FILTER' => '1']);
        $html = $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('<html', $html);
        self::assertStringContainsString('Кафе «Лето»', $html);
        self::assertStringContainsString('<mark class="hl">900</mark>', $html);
    }

    public function testFullPageStillWorksWithoutJavascript(): void
    {
        $crawler = $this->client->request('GET', '/admin/products?q=вокзал');
        self::assertCount(1, $crawler->filter('#product-results tbody tr'));
        self::assertSelectorExists('form[data-live-filter="product-results"] input[name="q"][value="вокзал"]');
        self::assertSelectorExists('#command-palette[data-search-url="/admin/search"]');
    }

    /**
     * @param list<string> $sides
     */
    private function product(string $name, Category $category, ProductType $type, District $district, array $sides): Product
    {
        $product = (new Product())->setName($name)->setCategory($category)->setProductType($type)
            ->setDistrict($district)->setPrice('30000')->setLatitude('55.0000000')->setLongitude('37.0000000');
        foreach ($sides as $side) {
            $product->addSide((new ProductSide())->setName($side));
        }

        return $product;
    }

    private function hold(Product $product, string $side, string $client, int $slots = 1): \App\Entity\Booking
    {
        $request = new BookingRequest();
        $request->side = $product->getSides()->filter(fn (ProductSide $s) => $s->getName() === $side)->first();
        $request->startMonth = '2026-09';
        $request->client = $this->customer;
        $request->clientName = $client;
        $request->clientPhone = '+7 900 000-00-00';
        $request->slots = $slots;

        return static::getContainer()->get(BookingManager::class)->hold($request);
    }
}
