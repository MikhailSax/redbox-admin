<?php

namespace App\Tests\Controller\Admin;

use App\Dto\BookingRequest;
use App\Entity\AdditionalService;
use App\Entity\Category;
use App\Entity\District;
use App\Entity\MediaPlan;
use App\Entity\Partner;
use App\Entity\Product;
use App\Entity\ProductSide;
use App\Entity\ProductType;
use App\Entity\Promotion;
use App\Enum\BookingMode;
use App\Enum\BookingStatus;
use App\Service\BookingManager;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class MediaPlanControllerTest extends AdminWebTestCase
{
    use ClockSensitiveTrait;

    private Product $billboard;
    private Product $screen;

    protected function setUp(): void
    {
        parent::setUp();
        self::mockTime('2026-09-10 12:00:00');

        $category = (new Category())->setName('Билборд 6х3');
        $district = (new District())->setName('Центральный');
        $static = (new ProductType())->setName('Статика');
        $video = (new ProductType())->setName('Видеоэкран')->setBookingMode(BookingMode::Airtime);
        $partner = (new Partner())->setName('Наружка');

        // billboard: partner structure, 40 000 ₽/side, partner price 30 000 ₽
        $this->billboard = (new Product())->setName('Щит на Ленина')->setCategory($category)->setProductType($static)->setDistrict($district)
            ->setPrice('40000')->setOwner($partner)->setPurchasePrice('30000')->setLatitude('55.75')->setLongitude('37.62')
            ->addSide((new ProductSide())->setName('A'))->addSide((new ProductSide())->setName('B'));
        // screen: own, 10 000 ₽ per 5 s of the loop
        $this->screen = (new Product())->setName('Экран у вокзала')->setCategory($category)->setProductType($video)->setDistrict($district)
            ->setPrice('10000')->setLatitude('55.76')->setLongitude('37.63')
            ->addSide((new ProductSide())->setName('A'));

        foreach ([$category, $district, $static, $video, $partner, $this->billboard, $this->screen] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
    }

    public function testCreatePlan(): void
    {
        $crawler = $this->client->request('GET', '/admin/media-plans/new');
        self::assertResponseIsSuccessful();

        [$values, $uri] = $this->formValues($crawler, 'Создать и подобрать конструкции');
        $values['media_plan_form']['title'] = 'Осень в центре';
        $values['media_plan_form']['clientName'] = 'Кафе «Лето»';
        $values['media_plan_form']['clientContact'] = '+7 900 111-22-33';
        $values['media_plan_form']['startMonth'] = '2026-10';
        $values['media_plan_form']['months'] = '2';
        $values['media_plan_form']['discountPercent'] = '10';
        $this->submit($uri, $values);

        $plan = $this->em->getRepository(MediaPlan::class)->findOneBy([]);
        self::assertResponseRedirects('/admin/media-plans/'.$plan->getId());
        self::assertEquals(new \DateTimeImmutable('2026-10-01'), $plan->getStartMonth());
        self::assertEquals(new \DateTimeImmutable('2026-11-01'), $plan->getEndMonth());
        self::assertSame('me@redbox.local', $plan->getCreatedBy()?->getEmail());
    }

    public function testAddSidesFromPickerWithPricesAndStatus(): void
    {
        $plan = $this->plan(months: 2);

        $crawler = $this->client->request('GET', '/admin/media-plans/'.$plan->getId().'?q=Ленина');
        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter('#plan-picker form')); // sides A and B of the billboard

        $form = $crawler->filter('#plan-picker form')->first()->form();
        $this->client->submit($form);
        self::assertResponseRedirects('/admin/media-plans/'.$plan->getId());

        // airtime side with a 10 s clip costs 2 × the price per 5 s
        $this->addSides($plan, [$this->screen->getSides()->first()], clip: 10);

        $plan = $this->reload($plan);
        self::assertCount(2, $plan->getItems());
        [$billboardItem, $screenItem] = $plan->getItems()->getValues();
        self::assertSame(40000.0, $billboardItem->getMonthlyPrice());
        self::assertSame(20000.0, $screenItem->getMonthlyPrice());
        self::assertSame(10, $screenItem->getClipDuration());

        // adding the same side again does nothing
        $this->addSides($plan, [$billboardItem->getSide()]);
        $this->client->followRedirect();
        self::assertSelectorTextContains('[role=alert]', 'уже есть в медиаплане');
        self::assertCount(2, $this->reload($plan)->getItems());
    }

    public function testAddFromMapReturnsJson(): void
    {
        $plan = $this->plan();
        $crawler = $this->client->request('GET', '/admin/map?plan='.$plan->getId());
        $option = $crawler->filter('select[data-map-plan] option[value="'.$plan->getId().'"]');
        self::assertNotNull($option->attr('selected'));

        $this->client->request('POST', $option->attr('data-url'), [
            '_token' => $option->attr('data-token'),
            'sides' => [$this->billboard->getSides()->first()->getId(), $this->billboard->getSides()->last()->getId()],
        ], server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame(2, $data['added']);
        self::assertSame(2, $data['items']);
    }

    public function testServicesTotalsAndMargin(): void
    {
        $printing = (new AdditionalService())->setName('Печать баннера')->setUnit('м²')->setPrice('350')->setCostPrice('200');
        $this->em->persist($printing);
        $plan = $this->plan(months: 2, discount: 10);
        $this->addSides($plan, [$this->billboard->getSides()->first()]);

        // from the catalog: name, unit and price are filled on the server when left empty
        $crawler = $this->client->request('GET', '/admin/media-plans/'.$plan->getId());
        [$values, $uri] = $this->formValues($crawler, 'Добавить');
        $values['service_line_form']['service'] = (string) $printing->getId();
        $values['service_line_form']['name'] = '';
        $values['service_line_form']['quantity'] = '18';
        $values['service_line_form']['unitPrice'] = '';
        $this->submit($uri, $values);
        self::assertResponseRedirects('/admin/media-plans/'.$plan->getId().'#services');

        // a custom service
        $values['service_line_form'] = ['service' => '', 'name' => 'Разработка макета', 'quantity' => '1', 'unit' => 'макет', 'unitPrice' => '5000', '_token' => $values['service_line_form']['_token'] ?? ''];
        $this->submit($uri, $values);

        $plan = $this->reload($plan);
        [$print, $layout] = $plan->getServiceLines()->getValues();
        self::assertSame(['Печать баннера', 'м²', 18.0, 350.0, 200.0], [$print->getName(), $print->getUnit(), $print->getQuantity(), $print->getUnitPrice(), $print->getUnitCost()]);
        self::assertSame(['Разработка макета', 5000.0, null], [$layout->getName(), $layout->getTotal(), $layout->getUnitCost()]);

        // placement 40 000 × 2 = 80 000, −10% = 72 000; services 6 300 + 5 000; not discounted
        self::assertSame(80000.0, $plan->getPlacementSubtotal());
        self::assertSame(8000.0, $plan->getDiscountAmount());
        self::assertSame(11300.0, $plan->getServicesTotal());
        self::assertSame(83300.0, $plan->getTotal());
        // costs: partner 30 000 × 2, printing 200 × 18
        self::assertSame(60000.0, $plan->getPartnerCost());
        self::assertSame(3600.0, $plan->getServicesCost());
        self::assertSame(19700.0, $plan->getMargin());

        $crawler = $this->client->request('GET', '/admin/media-plans/'.$plan->getId());
        self::assertSelectorTextContains('main aside', '83 300 ₽');
        self::assertCount(2, $crawler->filter('#services tbody tr'));
    }

    public function testUpdateAndRemoveServiceLine(): void
    {
        $plan = $this->plan();
        $crawler = $this->client->request('GET', '/admin/media-plans/'.$plan->getId());
        [$values, $uri] = $this->formValues($crawler, 'Добавить');
        $values['service_line_form']['name'] = 'Монтаж';
        $values['service_line_form']['quantity'] = '2';
        $values['service_line_form']['unitPrice'] = '3000';
        $this->submit($uri, $values);

        $crawler = $this->client->followRedirect();
        $form = $crawler->filter('#services form[action$="/update"]')->form(['quantity' => '3', 'price' => '2 500,50']);
        $this->client->submit($form);
        $line = $this->reload($plan)->getServiceLines()->first();
        self::assertSame([3.0, 2500.5, 7501.5], [$line->getQuantity(), $line->getUnitPrice(), $line->getTotal()]);

        $crawler = $this->client->request('GET', '/admin/media-plans/'.$plan->getId());
        $this->submitPostForm($crawler, '#services form[action$="/delete"]');
        self::assertCount(0, $this->reload($plan)->getServiceLines());
    }

    public function testServiceNeedsName(): void
    {
        $plan = $this->plan();
        $crawler = $this->client->request('GET', '/admin/media-plans/'.$plan->getId());
        [$values, $uri] = $this->formValues($crawler, 'Добавить');
        $values['service_line_form']['name'] = '';
        $values['service_line_form']['unitPrice'] = '100';
        $this->submit($uri, $values);

        $this->client->followRedirect();
        self::assertSelectorTextContains('[role=alert]', 'Выберите услугу или впишите название');
        self::assertCount(0, $this->reload($plan)->getServiceLines());
    }

    public function testInactiveServicesAreNotOffered(): void
    {
        $this->em->persist((new AdditionalService())->setName('Старая услуга')->setPrice('1')->setActive(false));
        $this->em->persist((new AdditionalService())->setName('Печать')->setPrice('1'));
        $this->em->flush();

        $crawler = $this->client->request('GET', '/admin/media-plans/'.$this->plan()->getId());
        $options = $crawler->filter('#service_line_form_service option')->each(fn ($o) => $o->text());
        self::assertSame(['Своя услуга…', 'Печать — 1 ₽/шт'], $options);
    }

    public function testBookAllSkipsTakenSides(): void
    {
        $plan = $this->plan(); // September 2026
        [$sideA, $sideB] = $this->billboard->getSides()->getValues();

        // side B is already taken by someone else (created before any request: the kernel reboots between requests)
        $request = new BookingRequest();
        $request->side = $sideB;
        $request->startMonth = '2026-09';
        $request->client = $this->createClientCard('Конкурент', 'rival@example.com');
        $request->clientPhone = '1';
        static::getContainer()->get(BookingManager::class)->hold($request);

        $this->addSides($plan, [$sideA, $sideB]);

        $crawler = $this->client->request('GET', '/admin/media-plans/'.$plan->getId());
        self::assertSelectorTextContains('main', 'Забронировать свободные (1)');
        $this->submitPostForm($crawler, 'form[action$="/book"]');
        $this->client->followRedirect();
        self::assertSelectorTextContains('[role=alert]', 'Создано броней: 1');

        $plan = $this->reload($plan);
        [$itemA, $itemB] = $plan->getItems()->getValues();
        self::assertSame(BookingStatus::Hold, $itemA->getBooking()?->getStatus());
        self::assertSame('Кафе «Лето»', $itemA->getBooking()->getClientName());
        // the booking is made out to the plan's client card, not just to the name printed in the PDF
        self::assertSame($plan->getClient()?->getId(), $itemA->getBooking()->getClient()?->getId());
        self::assertNull($itemB->getBooking());
    }

    public function testBookAllNeedsTheClientCardOfThePlan(): void
    {
        $plan = $this->plan();
        $plan->setClient(null);
        $this->em->flush();
        $this->addSides($plan, [$this->billboard->getSides()->first()]);

        $crawler = $this->client->request('GET', '/admin/media-plans/'.$plan->getId());
        $this->submitPostForm($crawler, 'form[action$="/book"]');
        $this->client->followRedirect();

        self::assertSelectorTextContains('[role=alert]', 'Выберите клиента в параметрах медиаплана');
        self::assertNull($this->reload($plan)->getItems()->first()->getBooking());
    }

    public function testPdf(): void
    {
        $plan = $this->plan();
        $this->addSides($plan, [$this->billboard->getSides()->first()]);

        $this->client->request('GET', '/admin/media-plans/'.$plan->getId().'/pdf');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/pdf');
        self::assertStringContainsString('Mediaplan-', $this->client->getResponse()->headers->get('Content-Disposition'));
        self::assertStringStartsWith('%PDF', $this->client->getResponse()->getContent());
    }

    public function testListAndLiveSearch(): void
    {
        $this->plan();
        $this->client->request('GET', '/admin/media-plans?q=лето', server: ['HTTP_X_LIVE_FILTER' => '1']);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Кафе «<mark class="hl">Лето</mark>»', $this->client->getResponse()->getContent());
    }

    public function testBestPromotionIsAppliedWhenAddingSides(): void
    {
        $this->promotion('Осень в центре', '10', categories: true);
        $plan = $this->plan(months: 2);

        $this->addSides($plan, [$this->billboard->getSides()->first()]);

        $item = $this->reload($plan)->getItems()->first();
        self::assertSame(40000.0, $item->getBasePrice());
        self::assertSame(36000.0, $item->getMonthlyPrice());
        self::assertSame('Осень в центре', $item->getPromotionTitle());
        self::assertSame(8000.0, $item->getPlan()->getPromotionSavings());

        $this->client->request('GET', '/admin/media-plans/'.$plan->getId());
        self::assertSelectorTextContains('.data-table', 'Осень в центре');
        self::assertSelectorTextContains('main aside', 'Акции');
        self::assertSelectorTextContains('main aside', '−8 000');
    }

    public function testPromoCodeAndFirstOrderRecalculatePricesOnSave(): void
    {
        $this->promotion('Промокод AUTUMN', '25', code: 'AUTUMN');
        $this->promotion('Первый заказ', '20')->setFirstOrderOnly(true);
        $this->em->flush();
        $plan = $this->plan();
        $this->addSides($plan, [$this->billboard->getSides()->first()]);
        self::assertSame(40000.0, $this->reload($plan)->getItems()->first()->getMonthlyPrice());

        // an unknown code is rejected
        $crawler = $this->client->request('GET', '/admin/media-plans/'.$plan->getId().'/edit');
        [$values, $uri] = $this->formValues($crawler, 'Сохранить');
        $values['media_plan_form']['promoCode'] = 'nope';
        $this->submit($uri, $values);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Промокод «NOPE» не найден', $this->client->getResponse()->getContent());

        // first order: −20%
        $values['media_plan_form']['promoCode'] = '';
        $values['media_plan_form']['firstOrder'] = '1';
        $this->submit($uri, $values);
        self::assertResponseRedirects('/admin/media-plans/'.$plan->getId());
        self::assertSame(32000.0, $this->reload($plan)->getItems()->first()->getMonthlyPrice());

        // with the code the better −25% wins, they don't add up
        $values['media_plan_form']['promoCode'] = 'autumn';
        $this->submit($uri, $values);
        $this->client->followRedirect();
        self::assertSelectorTextContains('[role=alert]', 'Цены пересчитаны по акциям');
        $plan = $this->reload($plan);
        self::assertSame('AUTUMN', $plan->getPromoCode());
        self::assertSame(30000.0, $plan->getItems()->first()->getMonthlyPrice());
        self::assertSame('Промокод AUTUMN', $plan->getItems()->first()->getPromotionTitle());
    }

    public function testManualPriceIsKeptUntilReset(): void
    {
        $this->promotion('Всем −10%', '10');
        $plan = $this->plan();
        $this->addSides($plan, [$this->billboard->getSides()->first()]);
        $item = $this->reload($plan)->getItems()->first();

        $crawler = $this->client->request('GET', '/admin/media-plans/'.$plan->getId());
        $this->client->request('POST', '/admin/media-plans/'.$plan->getId().'/items/'.$item->getId().'/price', [
            '_token' => $crawler->filter('form[action$="/price"] input[name="_token"]')->attr('value'),
            'price' => '38 000',
        ]);
        $item = $this->reload($plan)->getItems()->first();
        self::assertTrue($item->isManualPrice());
        self::assertNull($item->getPromotionTitle());
        self::assertSame(38000.0, $item->getMonthlyPrice());

        $crawler = $this->client->request('GET', '/admin/media-plans/'.$plan->getId());
        $this->submitPostForm($crawler, 'form[action$="/recalculate"]');
        self::assertSame(38000.0, $this->reload($plan)->getItems()->first()->getMonthlyPrice());

        $crawler = $this->client->request('GET', '/admin/media-plans/'.$plan->getId());
        $this->submitPostForm($crawler, 'form[action$="/auto-price"]');
        $item = $this->reload($plan)->getItems()->first();
        self::assertFalse($item->isManualPrice());
        self::assertSame(36000.0, $item->getMonthlyPrice());
    }

    public function testPdfWithPromotion(): void
    {
        $this->promotion('Осень', '10')->setDescription('Скидка на размещение до конца октября');
        $this->em->flush();
        $plan = $this->plan();
        $this->addSides($plan, [$this->billboard->getSides()->first()]);

        $this->client->request('GET', '/admin/media-plans/'.$plan->getId().'/pdf');

        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('%PDF', $this->client->getResponse()->getContent());
    }

    private function promotion(string $title, string $percent, bool $categories = false, ?string $code = null): Promotion
    {
        $promotion = (new Promotion())->setTitle($title)->setDiscountValue($percent)->setCode($code)
            ->setStartsAt(new \DateTimeImmutable('2026-09-01'))->setAppliesToAll(!$categories);
        if ($categories) {
            $promotion->addCategory($this->billboard->getCategory());
        }
        $this->em->persist($promotion);
        $this->em->flush();

        return $promotion;
    }

    private function plan(int $months = 1, int $discount = 0): MediaPlan
    {
        $plan = (new MediaPlan())->setTitle('Осень')->setClientName('Кафе «Лето»')->setClientContact('+7 900 111-22-33')
            ->setClient($this->createClientCard('Кафе «Лето»', 'cafe@example.com'))
            ->setStartMonth(new \DateTimeImmutable('2026-09-01'))->setMonths($months)->setDiscountPercent($discount);
        $this->em->persist($plan);
        $this->em->flush();

        return $plan;
    }

    /**
     * @param list<ProductSide> $sides
     */
    private function addSides(MediaPlan $plan, array $sides, ?int $clip = null): void
    {
        $crawler = $this->client->request('GET', '/admin/media-plans/'.$plan->getId());
        $token = $crawler->filter('form[action$="/items"] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/admin/media-plans/'.$plan->getId().'/items', [
            '_token' => $token,
            'sides' => array_map(fn (ProductSide $s) => $s->getId(), $sides),
            'clip' => $clip,
        ]);
    }

    private function reload(MediaPlan $plan): MediaPlan
    {
        $this->em->clear();

        return $this->em->find(MediaPlan::class, $plan->getId());
    }
}
