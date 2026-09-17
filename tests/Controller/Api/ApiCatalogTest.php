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
        self::assertSame(25000.0, $billboard['priceFrom']);
        self::assertSame('free', $billboard['status']);
        self::assertSame([['title' => 'Осень −15%', 'label' => '−15%']], $billboard['promotions']);
        self::assertSame(['А' => 28000.0, 'В' => 25000.0], array_column($billboard['sides'], 'price', 'name'));

        // what a visitor must never see
        $json = json_encode($data, \JSON_UNESCAPED_UNICODE);
        self::assertStringNotContainsString('18000', $json, 'partner price leaked');
        self::assertStringNotContainsString('Наружка', $json, 'partner name leaked');

        $screen = $this->itemOf($data, 'Экран у вокзала');
        self::assertTrue($screen['airtime']);
        self::assertSame(120, $screen['loopSeconds']);
        self::assertSame([5, 10, 15], $screen['clipDurations']);
        self::assertSame('per5sec', $screen['sides'][0]['priceUnit']);
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
        self::assertSame('Статика', $data['type']);
        self::assertTrue($data['airtime']); // some side is a screen
        $sides = array_column($data['sides'], null, 'name');
        self::assertSame(['Статика', false, 'perMonth'], [$sides['А']['type'], $sides['А']['airtime'], $sides['А']['priceUnit']]);
        self::assertSame(['Видеоэкран', true, 'per5sec', 120, 0], [$sides['В']['type'], $sides['В']['airtime'], $sides['В']['priceUnit'], $sides['В']['freeSeconds'], $sides['В']['loadPercent']]);

        $availability = $this->get('/api/v1/structures/'.$this->billboard->getId().'/availability?from=2026-09-10&to=2026-09-30');
        self::assertSame([false, true], array_column($availability['sides'], 'airtime'));
        self::assertSame([null, 0], array_map(static fn (array $side) => $side['busySeconds'] ?? null, $availability['sides']));

        // the type filter finds the structure through its side
        self::assertSame(2, $this->get('/api/v1/structures?type='.$video->getId())['total']);
    }

    public function testAvailabilityShowsBusyDays(): void
    {
        $side = $this->screen->getSides()->first();
        $request = new BookingRequest();
        $request->side = $side;
        $request->startDate = new \DateTimeImmutable('2026-09-14');
        $request->endDate = new \DateTimeImmutable('2026-09-20');
        $request->clipDuration = 15;
        $request->clientName = 'Кафе «Лето»';
        $request->clientPhone = '+7 900 000-00-00';
        static::getContainer()->get(BookingManager::class)->hold($request);

        $data = $this->get('/api/v1/structures/'.$this->screen->getId().'/availability?from=2026-09-10&to=2026-09-30');

        self::assertTrue($data['airtime']);
        self::assertSame(120, $data['loopSeconds']);
        self::assertSame([['from' => '2026-09-14', 'to' => '2026-09-20', 'seconds' => 15]], $data['sides'][0]['busy']);
        self::assertSame(15, $data['sides'][0]['busySeconds']);
        // the client's name is not public
        self::assertStringNotContainsString('Лето', json_encode($data, \JSON_UNESCAPED_UNICODE));

        self::assertSame(422, $this->request('GET', '/api/v1/structures/'.$this->screen->getId().'/availability?from=2026-09-30&to=2026-09-01')['status'] ?? 400);
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
                ['sideId' => $screenSide->getId(), 'from' => '2026-10-05', 'to' => '2026-10-11', 'clip' => 10],
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
        self::assertSame(10, $second->getClipDuration());
        self::assertSame(20000.0, $second->getMonthlyPrice()); // 10 s clip = two times the price per 5 s
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
