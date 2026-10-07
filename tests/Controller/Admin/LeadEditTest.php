<?php

namespace App\Tests\Controller\Admin;

use App\Entity\Category;
use App\Entity\District;
use App\Entity\Lead;
use App\Entity\LeadItem;
use App\Entity\Product;
use App\Entity\ProductSide;
use App\Entity\ProductType;
use App\Enum\BookingMode;

/**
 * A manager corrects a request: the client's contacts and requisites, the payment, the comment,
 * the periods and slots of its positions, and drops the positions the client no longer wants.
 */
final class LeadEditTest extends AdminWebTestCase
{
    private Lead $lead;

    protected function setUp(): void
    {
        parent::setUp();
        $billboardSide = (new ProductSide())->setName('А');
        $screenSide = (new ProductSide())->setName('Б');
        $category = (new Category())->setName('Билборд');
        $district = (new District())->setName('Центр');
        $static = (new ProductType())->setName('Статика');
        $video = (new ProductType())->setName('Экран')->setBookingMode(BookingMode::Airtime);
        $billboard = (new Product())->setName('Щит на Ленина')->setCategory($category)->setProductType($static)
            ->setDistrict($district)->setPrice('20000')->setLatitude('51.8')->setLongitude('107.6')->addSide($billboardSide);
        $screen = (new Product())->setName('Экран на Мира')->setCategory($category)->setProductType($video)
            ->setDistrict($district)->setPrice('30000')->setLatitude('51.8')->setLongitude('107.6')->addSide($screenSide);

        $this->lead = (new Lead())->setContactName('Иван')->setPhone('+7 900 111-22-33')->setEmail('ivan@baikal.ru')
            ->addItem(new LeadItem($billboardSide, 'Щит на Ленина', 'А', new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-31')))
            ->addItem(new LeadItem($screenSide, 'Экран на Мира', 'Б', new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-14'), 1));
        foreach ([$category, $district, $static, $video, $billboard, $screen, $this->lead] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
    }

    public function testManagerCorrectsTheRequest(): void
    {
        $crawler = $this->client->request('GET', '/admin/leads/'.$this->lead->getId());
        self::assertSelectorExists('a[href="/admin/leads/'.$this->lead->getId().'/edit"]');

        $crawler = $this->client->request('GET', '/admin/leads/'.$this->lead->getId().'/edit');
        self::assertResponseIsSuccessful();
        // a whole side has no slots, a screen's position has
        self::assertSelectorNotExists('input[name="lead_form[items][0][slots]"]');
        self::assertSelectorExists('input[name="lead_form[items][1][slots]"]');

        [$values, $uri] = $this->formValues($crawler, 'Сохранить');
        $values['lead_form']['contactName'] = 'Иван Петров';
        $values['lead_form']['companyName'] = 'ООО «Байкал»';
        $values['lead_form']['inn'] = '0326021373';
        $values['lead_form']['paymentType'] = Lead::PAYMENT_POSTPAY;
        $values['lead_form']['comment'] = 'Нужен макет';
        // the billboard is dropped, the screen gets a longer period and two slots
        unset($values['lead_form']['items'][0]);
        $values['lead_form']['items'][1]['endDate'] = '2026-10-20';
        $values['lead_form']['items'][1]['slots'] = '2';
        $this->submit($uri, $values);

        self::assertResponseRedirects('/admin/leads/'.$this->lead->getId());
        $this->client->followRedirect();
        self::assertAnySelectorTextContains('[role=alert]', 'Заявка изменена');

        $this->em->clear();
        $lead = $this->em->find(Lead::class, $this->lead->getId());
        self::assertSame(['Иван Петров', 'ООО «Байкал»', '0326021373', Lead::PAYMENT_POSTPAY, 'Нужен макет'],
            [$lead->getContactName(), $lead->getCompanyName(), $lead->getInn(), $lead->getPaymentType(), $lead->getComment()]);
        self::assertCount(1, $lead->getItems());
        $item = $lead->getItems()->first();
        self::assertSame(['Экран на Мира', '2026-10-20', 20, 2], [$item->getProductTitle(), $item->getEndDate()->format('Y-m-d'), $item->getDays(), $item->getSlots()]);
    }

    public function testInvalidDataIsShownBack(): void
    {
        $crawler = $this->client->request('GET', '/admin/leads/'.$this->lead->getId().'/edit');
        [$values, $uri] = $this->formValues($crawler, 'Сохранить');
        $values['lead_form']['phone'] = '';
        $values['lead_form']['items'][0]['endDate'] = '2026-09-01';
        $this->submit($uri, $values);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'Укажите телефон');
        self::assertSelectorTextContains('main', 'Период заканчивается раньше, чем начинается');
        $this->em->clear();
        self::assertSame('+7 900 111-22-33', $this->em->find(Lead::class, $this->lead->getId())->getPhone());
    }
}
