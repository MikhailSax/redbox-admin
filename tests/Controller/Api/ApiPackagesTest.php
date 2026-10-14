<?php

namespace App\Tests\Controller\Api;

use App\Dto\BookingRequest;
use App\Entity\Category;
use App\Entity\Lead;
use App\Entity\Package;
use App\Entity\PackageSlot;
use App\Entity\Product;
use App\Entity\ProductSide;
use App\Entity\ProductType;
use App\Enum\BookingMode;
use App\Service\BookingManager;
use App\Tests\Controller\Admin\AdminWebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * "Пакетные предложения" on the website: the sets free for a period, and an order with a package.
 */
final class ApiPackagesTest extends AdminWebTestCase
{
    use ClockSensitiveTrait;

    protected ?string $loginAs = null;

    private ProductSide $superSite;
    /** @var list<ProductSide> billboards "Щит 1".."Щит 3", side А */
    private array $billboards = [];
    private ProductSide $screen;

    protected function setUp(): void
    {
        parent::setUp();
        self::mockTime('2026-10-14 12:00:00');

        $category = (new Category())->setName('Билборд');
        $static = (new ProductType())->setName('Статика');
        $video = (new ProductType())->setName('Видеоэкран')->setBookingMode(BookingMode::Airtime);
        foreach ([$category, $static, $video] as $entity) {
            $this->em->persist($entity);
        }

        $make = function (string $name, ProductType $type, string $price, string $side = 'А') use ($category): ProductSide {
            $productSide = (new ProductSide())->setName($side)->setPrice($price);
            $this->em->persist((new Product())->setName($name)->setCategory($category)->setProductType($type)->setPrice($price)->addSide($productSide));

            return $productSide;
        };
        $this->superSite = $make('Суперсайт Борсоева', $static, '30000', 'Б');
        foreach (['Щит 1', 'Щит 2', 'Щит 3'] as $name) {
            $this->billboards[] = $make($name, $static, '20000');
        }
        $this->screen = $make('Экран Терешковой', $video, '9000');
        $this->em->flush();
    }

    public function testListShowsTheSidesFreeForThePeriod(): void
    {
        $package = $this->package('Максимум', [
            ['Суперсайт 12×4', 1, '14800', [$this->superSite]],
            ['Щит 6×3 на выбор', 2, '14800', $this->billboards],
        ]);
        $this->hold($this->billboards[0], '2026-11');

        $items = $this->get('/api/v1/packages')['items'];

        self::assertCount(1, $items);
        $offer = $items[0];
        self::assertSame($package->getId(), $offer['id']);
        // from the 1st of next month, for the package's shortest term
        self::assertSame(['2026-11-01', '2026-11-30', 1], [$offer['from'], $offer['to'], $offer['months']]);
        self::assertTrue($offer['available']);
        self::assertEquals(44400, $offer['monthlyTotal']);
        self::assertEquals(44400, $offer['total']);
        self::assertEquals(70000, $offer['listMonthlyTotal']);
        self::assertEquals(25600, $offer['savingsMonthly']);

        [$superSite, $billboards] = $offer['slots'];
        self::assertSame([$this->superSite->getId()], $superSite['suggested']);
        self::assertSame('Суперсайт Борсоева', $superSite['options'][0]['structure']['name']);
        self::assertEquals(14800, $superSite['options'][0]['price']);
        self::assertEquals(30000, $superSite['options'][0]['listPrice']);
        // "Щит 1" is booked in November: only the two others are offered
        self::assertSame([$this->billboards[1]->getId(), $this->billboards[2]->getId()], array_column($billboards['options'], 'sideId'));
        self::assertSame([$this->billboards[1]->getId(), $this->billboards[2]->getId()], $billboards['suggested']);

        // in December all three are free
        $december = $this->get('/api/v1/packages/'.$package->getId().'?from=2026-12-01');
        self::assertCount(3, $december['slots'][1]['options']);
    }

    public function testNotEnoughFreeSidesMakesThePackageUnavailable(): void
    {
        $this->package('Выгодный', [['Щиты', 3, '11250', $this->billboards]]);
        $this->hold($this->billboards[2], '2026-11');

        $offer = $this->get('/api/v1/packages')['items'][0];

        self::assertFalse($offer['available']);
        self::assertArrayNotHasKey('monthlyTotal', $offer);
        self::assertSame([], $offer['slots'][0]['suggested']);
        self::assertCount(2, $offer['slots'][0]['options']);
    }

    public function testPackagesOffOrOverAreNotShown(): void
    {
        $this->package('Выключен', [['Щит', 1, '1000', $this->billboards]])->setActive(false);
        $over = $this->package('Прошёл', [['Щит', 1, '1000', $this->billboards]]);
        $over->setStartsAt(new \DateTimeImmutable('2026-09-01'))->setEndsAt(new \DateTimeImmutable('2026-09-30'));
        $this->em->flush();

        self::assertSame([], $this->get('/api/v1/packages')['items']);
        self::assertSame(404, $this->request('GET', '/api/v1/packages/'.$over->getId())['status']);
    }

    public function testOrderWithAPackageLandsInTheCrmAtThePackagePrices(): void
    {
        $package = $this->package('Специальный', [
            ['Щит или призматрон', 2, null, $this->billboards],
            ['Экран в подарок', 1, '0', [$this->screen]],
        ], minMonths: 2, maxMonths: 3);
        [$billboards, $gift] = $package->getSlots()->toArray();

        // months outside of 2–3 are brought into the range
        self::assertSame(2, $this->get('/api/v1/packages?months=1')['items'][0]['months']);
        self::assertSame(3, $this->get('/api/v1/packages?months=6')['items'][0]['months']);

        $response = $this->post('/api/v1/orders', $this->contacts() + [
            'package' => [
                'id' => $package->getId(),
                'from' => '2026-11-01',
                'months' => 3,
                'items' => [
                    ['slotId' => $billboards->getId(), 'sideId' => $this->billboards[0]->getId()],
                    ['slotId' => $billboards->getId(), 'sideId' => $this->billboards[2]->getId()],
                    ['slotId' => $gift->getId(), 'sideId' => $this->screen->getId()],
                ],
            ],
        ]);

        self::assertSame(201, $response['status'], json_encode($response['body'], \JSON_UNESCAPED_UNICODE));
        $this->em->clear();
        $lead = $this->em->find(Lead::class, $response['body']['id']);
        self::assertSame('Специальный', $lead->getPackageTitle());
        self::assertSame($package->getId(), $lead->getPackage()?->getId());

        $items = $lead->getItems()->toArray();
        self::assertSame(['Щит или призматрон', 'Щит или призматрон', 'Экран в подарок'], array_map(static fn ($item) => $item->getPackageSlotTitle(), $items));
        // the billboards at their list price, the screen for free (one slot of the block)
        self::assertSame([20000.0, 20000.0, 0.0], array_map(static fn ($item) => $item->getMonthlyPrice(), $items));
        self::assertSame([null, null, 1], array_map(static fn ($item) => $item->getSlots(), $items));
        foreach ($items as $item) {
            self::assertSame(['2026-11-01', '2027-01-31'], [$item->getStartDate()->format('Y-m-d'), $item->getEndDate()->format('Y-m-d')]);
        }
    }

    public function testOrderWithAWrongSetIsRejected(): void
    {
        $package = $this->package('Максимум', [
            ['Суперсайт', 1, '14800', [$this->superSite]],
            ['Щиты', 2, '14800', $this->billboards],
        ]);
        [$superSite, $billboards] = $package->getSlots()->toArray();
        $this->hold($this->billboards[1], '2026-11');

        $order = fn (array $items, int $months = 1, string $from = '2026-11-01') => $this->post('/api/v1/orders', $this->contacts() + [
            'package' => ['id' => $package->getId(), 'from' => $from, 'months' => $months, 'items' => $items],
        ]);
        $pick = static fn (PackageSlot $slot, ProductSide $side) => ['slotId' => $slot->getId(), 'sideId' => $side->getId()];

        // one billboard instead of two
        $short = $order([$pick($superSite, $this->superSite), $pick($billboards, $this->billboards[0])]);
        self::assertSame(422, $short['status']);
        self::assertSame('package_invalid', $short['body']['error']);
        self::assertStringContainsString('нужно выбрать 2, выбрано 1', $short['body']['message']);

        // a billboard booked in November
        $busy = $order([$pick($superSite, $this->superSite), $pick($billboards, $this->billboards[0]), $pick($billboards, $this->billboards[1])]);
        self::assertSame(422, $busy['status']);
        self::assertStringContainsString('уже занята', $busy['body']['message']);

        // a side that isn't in the position
        $foreign = $order([$pick($superSite, $this->screen), $pick($billboards, $this->billboards[0]), $pick($billboards, $this->billboards[2])]);
        self::assertSame(422, $foreign['status']);
        self::assertStringContainsString('нет в пакете', $foreign['body']['message']);

        // a start in the past
        $past = $order([$pick($superSite, $this->superSite), $pick($billboards, $this->billboards[0]), $pick($billboards, $this->billboards[2])], from: '2026-10-01');
        self::assertSame(422, $past['status']);

        // a package that is off
        $package->setActive(false);
        $this->em->flush();
        $off = $order([$pick($superSite, $this->superSite), $pick($billboards, $this->billboards[0]), $pick($billboards, $this->billboards[2])]);
        self::assertSame('package_unavailable', $off['body']['error']);

        self::assertSame(0, $this->em->getRepository(Lead::class)->count([]));
    }

    public function testOrderNeedsSidesOrAPackage(): void
    {
        $response = $this->post('/api/v1/orders', $this->contacts());

        self::assertSame(422, $response['status']);
        self::assertStringContainsString('Добавьте хотя бы одну конструкцию', json_encode($response['body'], \JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param list<array{0: string, 1: int, 2: ?string, 3: list<ProductSide>}> $slots title, quantity, price, sides
     */
    private function package(string $title, array $slots, int $minMonths = 1, ?int $maxMonths = null): Package
    {
        $package = (new Package())->setTitle($title)->setStartsAt(new \DateTimeImmutable('2026-10-01'))->setMinMonths($minMonths)->setMaxMonths($maxMonths);
        foreach ($slots as $position => [$slotTitle, $quantity, $price, $sides]) {
            $slot = (new PackageSlot())->setTitle($slotTitle)->setQuantity($quantity)->setPrice($price)->setPosition($position);
            foreach ($sides as $side) {
                $slot->addSide($side);
            }
            $package->addSlot($slot);
        }
        $this->em->persist($package);
        $this->em->flush();

        return $package;
    }

    private function hold(ProductSide $side, string $month): void
    {
        $request = new BookingRequest();
        $request->side = $side;
        $request->startMonth = $month;
        $request->months = 1;
        $request->client = $this->createClientCard('Кафе «Лето» '.$side->getId(), 'cafe'.$side->getId().'@example.com');
        $request->clientPhone = '+7 900 000-00-00';
        static::getContainer()->get(BookingManager::class)->hold($request);
    }

    /**
     * @return array<string, mixed>
     */
    private function contacts(): array
    {
        return ['contactName' => 'Иван', 'phone' => '+7 900 111-22-33', 'agree' => true];
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
}
