<?php

namespace App\Tests\Controller\Admin;

use App\Entity\Category;
use App\Entity\District;
use App\Entity\Product;
use App\Entity\ProductSide;
use App\Entity\ProductType;
use App\Enum\BookingMode;

/**
 * Hours of work of a video screen's side: set on the structure's page, empty = around the clock.
 */
final class ScreenWorkHoursTest extends AdminWebTestCase
{
    private Product $screen;

    protected function setUp(): void
    {
        parent::setUp();

        $category = (new Category())->setName('Суперсайт');
        $district = (new District())->setName('Центральный');
        $video = (new ProductType())->setName('Видеоэкран')->setBookingMode(BookingMode::Airtime);
        $this->screen = (new Product())->setName('Экран у вокзала')
            ->setCategory($category)->setProductType($video)->setDistrict($district)
            ->setPrice('10000')->setLatitude('51.84')->setLongitude('107.61')
            ->addSide((new ProductSide())->setName('А'));

        foreach ([$category, $district, $video, $this->screen] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
    }

    public function testHoursSavedAndShown(): void
    {
        $crawler = $this->client->request('GET', '/admin/products/'.$this->screen->getId().'/edit');
        [$values, $uri] = $this->formValues($crawler, 'Сохранить');
        self::assertSame('', $values['product_form']['sides'][0]['workStartsAt']);
        $values['product_form']['sides'][0]['workStartsAt'] = '07:00';
        $values['product_form']['sides'][0]['workEndsAt'] = '01:00'; // past midnight
        $this->submit($uri, $values);
        self::assertResponseRedirects();

        $this->em->clear();
        $side = $this->em->find(Product::class, $this->screen->getId())->getSides()->first();
        self::assertSame('07:00–01:00', $side->getWorkHoursLabel());
        self::assertSame(18 * 3600, $side->getWorkSecondsPerDay());

        $crawler = $this->client->request('GET', '/admin/products/'.$this->screen->getId().'/bookings');
        self::assertResponseIsSuccessful();
        self::assertSame('07:00–01:00', $crawler->filter('[title="Время работы экрана"]')->text());

        // both emptied: around the clock again
        $crawler = $this->client->request('GET', '/admin/products/'.$this->screen->getId().'/edit');
        [$values, $uri] = $this->formValues($crawler, 'Сохранить');
        self::assertSame('07:00', $values['product_form']['sides'][0]['workStartsAt']);
        $values['product_form']['sides'][0]['workStartsAt'] = '';
        $values['product_form']['sides'][0]['workEndsAt'] = '';
        $this->submit($uri, $values);
        self::assertResponseRedirects();
        $this->em->clear();
        $side = $this->em->find(Product::class, $this->screen->getId())->getSides()->first();
        self::assertTrue($side->isAroundTheClock());
        self::assertSame('круглосуточно', $side->getWorkHoursLabel());
        self::assertSame(86400, $side->getWorkSecondsPerDay());
    }

    public function testBothEndsNeededAndDifferent(): void
    {
        $crawler = $this->client->request('GET', '/admin/products/'.$this->screen->getId().'/edit');
        [$values, $uri] = $this->formValues($crawler, 'Сохранить');
        $values['product_form']['sides'][0]['workStartsAt'] = '08:00';
        $this->submit($uri, $values);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Укажите и начало, и конец работы');

        $values['product_form']['sides'][0]['workEndsAt'] = '08:00';
        $this->submit($uri, $values);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Начало и конец совпадают');

        $this->em->clear();
        self::assertTrue($this->em->find(Product::class, $this->screen->getId())->getSides()->first()->isAroundTheClock());
    }

    public function testStaticSideHasNoHours(): void
    {
        $side = (new ProductSide())->setName('Б')->setProductType((new ProductType())->setName('Статика'));
        $side->setWorkStartsAt(new \DateTimeImmutable('07:00'))->setWorkEndsAt(new \DateTimeImmutable('23:00'));

        self::assertNull($side->getWorkHoursLabel());
    }
}
