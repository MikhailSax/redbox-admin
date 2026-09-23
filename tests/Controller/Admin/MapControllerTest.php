<?php

namespace App\Tests\Controller\Admin;

use App\Dto\BookingRequest;
use App\Entity\Category;
use App\Entity\District;
use App\Entity\Partner;
use App\Entity\Product;
use App\Entity\ProductSide;
use App\Entity\ProductType;
use App\Service\BookingManager;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class MapControllerTest extends AdminWebTestCase
{
    use ClockSensitiveTrait;

    private Product $center;
    private Product $suburb;
    private Product $noCoordinates;
    private Partner $partner;

    protected function setUp(): void
    {
        parent::setUp();
        self::mockTime('2026-09-10 12:00:00');

        $category = (new Category())->setName('Билборд 6х3');
        $type = (new ProductType())->setName('Статика');
        $district = (new District())->setName('Центральный');
        $this->partner = (new Partner())->setName('Наружка');
        foreach ([$category, $type, $district, $this->partner] as $entity) {
            $this->em->persist($entity);
        }

        $make = function (string $name, ?string $lat, ?string $lng, ?Partner $owner = null) use ($category, $type, $district) {
            $product = (new Product())->setName($name)->setCategory($category)->setProductType($type)->setDistrict($district)
                ->setPrice('40000')->setLatitude($lat)->setLongitude($lng)
                ->setOwner($owner)->setPurchasePrice($owner ? '30000' : null)
                ->addSide((new ProductSide())->setName('A'));
            $this->em->persist($product);

            return $product;
        };
        $this->center = $make('Щит в центре', '55.7539303', '37.6205606');
        $this->suburb = $make('Щит на окраине', '55.6000000', '37.4000000', $this->partner);
        $this->noCoordinates = $make('Щит без координат', null, null);
        $this->em->flush();
    }

    public function testMapPageRenders(): void
    {
        $crawler = $this->client->request('GET', '/admin/map');
        self::assertResponseIsSuccessful();

        $map = $crawler->filter('#construction-map');
        self::assertSame('/admin/map/data', $map->attr('data-url'));
        self::assertNotNull($map->attr('data-api-key'));
        self::assertSame('51.8335,107.5841,12', $map->attr('data-view'));
        self::assertSelectorExists('form[data-map-filter] select[name="owner"] option[value="'.$this->partner->getId().'"]');
        self::assertSelectorExists('#default-sidebar a[href="/admin/map"][aria-current="page"]');
    }

    public function testDataReturnsPointsWithStatusAndSkipsMissingCoordinates(): void
    {
        $request = new BookingRequest();
        $request->side = $this->center->getSides()->first();
        $request->startMonth = '2026-09';
        $request->client = $this->createClientCard();
        $request->clientPhone = '123';
        static::getContainer()->get(BookingManager::class)->hold($request);

        $data = $this->data('/admin/map/data');

        self::assertSame('Сентябрь 2026', $data['month']);
        self::assertSame(1, $data['withoutCoordinates']);
        // counts cover every matching structure, also the one that can't be placed on the map
        self::assertSame(1, $data['statusCounts']['booked']);
        self::assertSame(2, $data['statusCounts']['free']);

        $points = array_column($data['points'], null, 'name');
        self::assertCount(2, $points);
        self::assertSame('booked', $points['Щит в центре']['status']);
        self::assertEqualsWithDelta(55.7539303, $points['Щит в центре']['lat'], 1e-7);
        self::assertSame([['id' => $this->center->getSides()->first()->getId(), 'name' => 'A', 'status' => 'booked', 'label' => 'Забронирована', 'airtime' => false, 'used' => 1, 'slots' => 1]], $points['Щит в центре']['sides']);
        self::assertSame('/admin/products/'.$this->center->getId().'/bookings', $points['Щит в центре']['urls']['booking']);
        self::assertSame('Наружка', $points['Щит на окраине']['owner']);
        self::assertNull($points['Щит в центре']['owner']);
    }

    public function testDataUsesTheListFilters(): void
    {
        $data = $this->data('/admin/map/data?owner='.$this->partner->getId());
        self::assertSame(['Щит на окраине'], array_column($data['points'], 'name'));

        $data = $this->data('/admin/map/data?q=окраин');
        self::assertSame(['Щит на окраине'], array_column($data['points'], 'name'));

        $data = $this->data('/admin/map/data?status=occupied');
        self::assertSame([], $data['points']);
    }

    public function testLocationTabHasCoordinatePicker(): void
    {
        $crawler = $this->client->request('GET', '/admin/products/'.$this->center->getId().'/edit');

        $picker = $crawler->filter('[data-coordinate-picker]');
        self::assertSame('product_form_latitude', $picker->attr('data-lat-input'));
        self::assertSame('product_form_longitude', $picker->attr('data-lng-input'));
        self::assertSelectorExists('a[href="/admin/map?focus='.$this->center->getId().'"]');
    }

    private function data(string $url): array
    {
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        return json_decode($this->client->getResponse()->getContent(), true);
    }
}
