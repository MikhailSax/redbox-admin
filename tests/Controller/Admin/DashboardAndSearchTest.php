<?php

namespace App\Tests\Controller\Admin;

use App\Dto\BookingRequest;
use App\Entity\Category;
use App\Entity\District;
use App\Entity\Product;
use App\Entity\ProductSide;
use App\Entity\ProductType;
use App\Entity\User;
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
        $this->billboard = $this->product('Щит на Ленина', $category, $static, $district, ['A', 'B']);
        $this->screen = $this->product('Экран у вокзала', $category, $static, $district, ['A']);

        foreach ([$category, $district, $static, $this->billboard, $this->screen] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
    }

    public function testDashboardShowsKpisForecastAndHolds(): void
    {
        $manager = static::getContainer()->get(BookingManager::class);
        $manager->markPaid($this->hold($this->billboard, 'A', 'Оплатил'));
        $this->hold($this->billboard, 'B', 'ООО Ромашка');

        $crawler = $this->client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Добрый день');

        // 2 structures: billboard fully taken with a hold on B → booked; screen free. The total itself is not shown.
        $kpis = $crawler->filter('main a.card')->each(fn ($card) => preg_replace('/\s+/', ' ', trim($card->text())));
        self::assertCount(3, $kpis);
        self::assertStringContainsString('Свободны 1 50%', $kpis[0]);
        self::assertStringContainsString('Забронированы 1 50%', $kpis[1]);
        self::assertStringContainsString('Заняты 0', $kpis[2]);
        self::assertSelectorTextNotContains('main', 'Конструкций');

        // September: 2 of 3 sides sold
        self::assertSelectorTextContains('main', '67%');
        // The hold is listed with its pay button
        self::assertSelectorTextContains('main', 'ООО Ромашка');
        self::assertCount(1, $crawler->filter('main form[action$="/pay"] input[name="return"][value="dashboard"]'));
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

    private function hold(Product $product, string $side, string $client): \App\Entity\Booking
    {
        $request = new BookingRequest();
        $request->side = $product->getSides()->filter(fn (ProductSide $s) => $s->getName() === $side)->first();
        $request->startMonth = '2026-09';
        $request->client = $this->customer;
        $request->clientName = $client;
        $request->clientPhone = '+7 900 000-00-00';

        return static::getContainer()->get(BookingManager::class)->hold($request);
    }
}
