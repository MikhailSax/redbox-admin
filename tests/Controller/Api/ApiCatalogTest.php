<?php

namespace App\Tests\Controller\Api;

use App\Dto\BookingRequest;
use App\Entity\Category;
use App\Entity\District;
use App\Entity\Lead;
use App\Entity\Partner;
use App\Entity\Product;
use App\Entity\ProductSide;
use App\Entity\ProductType;
use App\Entity\Promotion;
use App\Enum\BookingMode;
use App\Enum\LeadStatus;
use App\Helpers\ProductHelper;
use App\Service\BookingManager;
use App\Tests\Controller\Admin\AdminWebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * The public API the website talks to: catalogue, availability and "заявка на размещение".
 */
final class ApiCatalogTest extends AdminWebTestCase
{
    use ClockSensitiveTrait;

    /** The website is not signed in to the CRM */
    protected ?string $loginAs = null;

    private Product $billboard;
    private Product $screen;

    protected function setUp(): void
    {
        parent::setUp();
        self::mockTime('2026-09-10 12:00:00');

        $category = (new Category())->setName('Билборд');
        $district = (new District())->setName('Центральный');
        $static = (new ProductType())->setName('Статика');
        $video = (new ProductType())->setName('Видеоэкран')->setBookingMode(BookingMode::Airtime);
        $partner = (new Partner())->setName('ООО «Наружка Плюс»');

        $this->billboard = (new Product())->setName('Щит на Ленина')->setSchemeNumber('7/23')->setSize(ProductHelper::SIZE_6X3)
            ->setCategory($category)->setProductType($static)->setDistrict($district)
            ->setPrice('25000')->setOwner($partner)->setPurchasePrice('18000')->setLatitude('51.83')->setLongitude('107.58')
            ->addSide((new ProductSide())->setName('А')->setPrice('28000'))
            ->addSide((new ProductSide())->setName('В'));
        $this->screen = (new Product())->setName('Экран у вокзала')->setSize(ProductHelper::SIZE_12X4)
            ->setCategory($category)->setProductType($video)->setDistrict($district)
            ->setPrice('10000')->setLatitude('51.84')->setLongitude('107.61')
            ->addSide((new ProductSide())->setName('А'));

        foreach ([$category, $district, $static, $video, $partner, $this->billboard, $this->screen] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
    }

    public function testCatalogueListsStructuresWithoutPartnerData(): void
    {
        $this->em->persist((new Promotion())->setTitle('Осень −15%')->setDiscountValue('15')->setAppliesToAll(true)->setStartsAt(new \DateTimeImmutable('2026-09-01')));
        $this->em->flush();

        $data = $this->get('/api/v1/structures');

        self::assertSame(2, $data['total']);
        self::assertSame(1, $data['pages']);
        $billboard = $this->itemOf($data, 'Щит на Ленина');
        self::assertSame('7/23', $billboard['code']);
        self::assertSame('Билборд', $billboard['category']);
        self::assertSame('6x3', $billboard['size']);
        self::assertSame('6 × 3 м', $billboard['sizeLabel']);
        self::assertEquals(25000, $billboard['priceFrom']); // JSON has no 25000.0: AbstractController::json() drops JSON_PRESERVE_ZERO_FRACTION
        self::assertSame('free', $billboard['status']);
        self::assertSame([['title' => 'Осень −15%', 'label' => '−15%']], $billboard['promotions']);
        self::assertEquals(['А' => 28000, 'В' => 25000], array_column($billboard['sides'], 'price', 'name'));

        // what a visitor must never see
        $json = json_encode($data, \JSON_UNESCAPED_UNICODE);
        self::assertStringNotContainsString('18000', $json, 'partner price leaked');
        self::assertStringNotContainsString('Наружка', $json, 'partner name leaked');

        $screen = $this->itemOf($data, 'Экран у вокзала');
        self::assertTrue($screen['airtime']);
        self::assertSame(14, $screen['minDays']);
        self::assertSame('perMonth', $screen['sides'][0]['priceUnit']);
        // how the block is split into slots stays in the CRM: a screen is only free or not
        self::assertSame('free', $screen['sides'][0]['status']);
        foreach (['slots', 'slotCount', 'freeSlots', 'freeSeconds', 'loadPercent', 'loopSeconds', 'clipDurations'] as $key) {
            self::assertStringNotContainsString('"'.$key.'"', $json);
        }
    }

    public function testCatalogueFilters(): void
    {
        self::assertSame(1, $this->get('/api/v1/structures?size=12x4')['total']);
        self::assertSame(2, $this->get('/api/v1/structures?district='.$this->billboard->getDistrict()->getId())['total']);
        self::assertSame(1, $this->get('/api/v1/structures?q=вокзал')['total']);
        self::assertSame(0, $this->get('/api/v1/structures?q=нет+такого')['total']);

        $filters = $this->get('/api/v1/filters');
        self::assertSame(['Билборд'], array_column($filters['categories'], 'name'));
        self::assertSame(['Видеоэкран', 'Статика'], array_column($filters['types'], 'name'));
        self::assertContains(['id' => '6x3', 'name' => '6 × 3 м'], $filters['sizes']);
    }

    public function testEachSideSaysHowItIsSold(): void
    {
        // the static billboard gets a video screen on side В
        $video = $this->em->getRepository(ProductType::class)->findOneBy(['name' => 'Видеоэкран']);
        $this->billboard->getSides()->last()->setProductType($video);
        $this->em->flush();

        $data = $this->get('/api/v1/structures/'.$this->billboard->getId());
        self::assertSame('Статика + Видеоэкран', $data['type']); // the sides' own types, not the structure's for both
        self::assertTrue($data['airtime']); // some side is a screen
        $sides = array_column($data['sides'], null, 'name');
        self::assertSame(['Статика', false, 'free'], [$sides['А']['type'], $sides['А']['airtime'], $sides['А']['status']]);
        self::assertSame(['Видеоэкран', true, 'free'], [$sides['В']['type'], $sides['В']['airtime'], $sides['В']['status']]);

        $availability = $this->get('/api/v1/structures/'.$this->billboard->getId().'/availability?from=2026-09-10&to=2026-09-30');
        self::assertSame([false, true], array_column($availability['sides'], 'airtime'));
        self::assertSame(['Статика', 'Видеоэкран'], array_column($availability['sides'], 'type'));

        // the type filter finds the structure through its side
        self::assertSame(2, $this->get('/api/v1/structures?type='.$video->getId())['total']);
    }

    public function testScreenIsBusyOnlyWhenEverySlotIsTaken(): void
    {
        $side = $this->screen->getSides()->first()->setSlotCount(2);
        $this->em->flush();
        $client = $this->createClientCard('Кафе «Лето»', 'cafe@example.com');
        foreach ([['2026-09-14', '2026-09-27'], ['2026-09-20', '2026-10-03']] as [$from, $to]) {
            $request = new BookingRequest();
            $request->side = $side;
            $request->startDate = new \DateTimeImmutable($from);
            $request->endDate = new \DateTimeImmutable($to);
            $request->slots = 1;
            $request->client = $client;
            $request->clientPhone = '+7 900 000-00-00';
            static::getContainer()->get(BookingManager::class)->hold($request);
        }

        $data = $this->get('/api/v1/structures/'.$this->screen->getId().'/availability?from=2026-09-10&to=2026-09-30');

        self::assertTrue($data['airtime']);
        self::assertSame(14, $data['minDays']);
        // one slot of two is taken from the 14th, both only from the 20th to the 27th
        self::assertSame([['from' => '2026-09-20', 'to' => '2026-09-27']], $data['sides'][0]['busy']);
        self::assertArrayNotHasKey('busySeconds', $data['sides'][0]);
        // the client's name is not public
        self::assertStringNotContainsString('Лето', json_encode($data, \JSON_UNESCAPED_UNICODE));

        self::assertSame(400, $this->request('GET', '/api/v1/structures/'.$this->screen->getId().'/availability?from=2026-09-30&to=2026-09-01')['status']);
    }

    public function testOrderFromTheWebsiteBecomesALead(): void
    {
        $side = $this->billboard->getSides()->first();
        $screenSide = $this->screen->getSides()->first();

        $response = $this->post('/api/v1/orders', [
            'contactName' => 'Иван Петров',
            'phone' => '+7 900 111-22-33',
            'email' => 'Ivan@Romashka.ru',
            'company' => 'ООО «Ромашка»',
            'inn' => '0300000000',
            'kpp' => '030001001',
            'payment' => 'postpay',
            'comment' => 'Нужен монтаж',
            'items' => [
                ['sideId' => $side->getId(), 'from' => '2026-10-01', 'to' => '2026-10-31'],
                ['sideId' => $screenSide->getId(), 'from' => '2026-10-05', 'to' => '2026-10-18', 'clip' => 10], // an old site's clip is ignored
            ],
        ]);

        self::assertSame(201, $response['status']);
        $lead = $this->em->getRepository(Lead::class)->findOneBy([]);
        self::assertSame($lead->getId(), $response['body']['number']);
        self::assertStringContainsString('принята', $response['body']['message']);
        self::assertSame(LeadStatus::New, $lead->getStatus());
        self::assertSame('ivan@romashka.ru', $lead->getEmail());
        self::assertSame('postpay', $lead->getPaymentType());
        self::assertCount(2, $lead->getItems());

        [$first, $second] = $lead->getItems()->getValues();
        self::assertSame('№7/23 · Щит на Ленина', $first->getProductTitle());
        self::assertSame(28000.0, $first->getMonthlyPrice()); // the side's own price
        self::assertSame(31, $first->getDays());
        self::assertSame(1, $second->getSlots()); // the site sells one slot of a screen
        self::assertSame(10000.0, $second->getMonthlyPrice());
    }

    public function testOrderValidation(): void
    {
        $side = $this->billboard->getSides()->first();
        $valid = ['contactName' => 'Иван', 'phone' => '+7 900 111-22-33', 'items' => [['sideId' => $side->getId(), 'from' => '2026-10-01', 'to' => '2026-10-31']]];

        $noContacts = $this->post('/api/v1/orders', ['items' => $valid['items']]);
        self::assertSame(422, $noContacts['status']);
        self::assertSame('validation_failed', $noContacts['body']['error']);
        self::assertSame(['contactName', 'phone'], array_column($noContacts['body']['violations'], 'field'));

        $empty = $this->post('/api/v1/orders', ['contactName' => 'Иван', 'phone' => '+7 900', 'items' => []]);
        self::assertSame(422, $empty['status']);
        self::assertStringContainsString('хотя бы одну конструкцию', $empty['body']['violations'][0]['message']);

        // a bot fills every field it finds, including the hidden one
        $bot = $this->post('/api/v1/orders', $valid + ['website' => 'http://spam.example']);
        self::assertSame(422, $bot['status']);

        $gone = $this->post('/api/v1/orders', ['contactName' => 'Иван', 'phone' => '+7 900', 'items' => [['sideId' => 999999, 'from' => '2026-10-01', 'to' => '2026-10-31']]]);
        self::assertSame(422, $gone['status']);
        self::assertSame('unknown_sides', $gone['body']['error']);

        // placement is two weeks at least
        $short = $this->post('/api/v1/orders', ['contactName' => 'Иван', 'phone' => '+7 900', 'items' => [['sideId' => $side->getId(), 'from' => '2026-10-01', 'to' => '2026-10-13']]]);
        self::assertSame(422, $short['status']);
        self::assertSame('Минимальное размещение — 14 дней', $short['body']['violations'][0]['message']);

        self::assertSame(0, $this->em->getRepository(Lead::class)->count([]));
    }

    public function testApiNeedsNoLoginButTheCrmStillDoes(): void
    {
        self::assertSame(200, $this->request('GET', '/api/v1/filters')['status']);
        $this->client->request('GET', '/admin/leads');
        self::assertResponseRedirects('/login');
    }

    /**
     * @return array<string, mixed>
     */
    private function get(string $url): array
    {
        $response = $this->request('GET', $url);
        self::assertSame(200, $response['status'], $url);

        return $response['body'];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    private function post(string $url, array $payload): array
    {
        return $this->request('POST', $url, json_encode($payload, \JSON_THROW_ON_ERROR));
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    private function request(string $method, string $url, ?string $json = null): array
    {
        $this->client->request($method, $url, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: $json);
        $response = $this->client->getResponse();

        return ['status' => $response->getStatusCode(), 'body' => json_decode((string) $response->getContent(), true) ?? []];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function itemOf(array $data, string $name): array
    {
        foreach ($data['items'] as $item) {
            if ($item['name'] === $name) {
                return $item;
            }
        }

        self::fail('No structure '.$name.' in the response');
    }
}
