<?php

namespace App\Tests\Controller\Admin;

use App\Entity\Category;
use App\Entity\District;
use App\Entity\Partner;
use App\Entity\Product;
use App\Entity\ProductSide;
use App\Entity\ProductType;

final class PartnerControllerTest extends AdminWebTestCase
{
    private Category $category;
    private ProductType $type;
    private District $district;

    protected function setUp(): void
    {
        parent::setUp();

        $this->category = (new Category())->setName('Билборд 6х3');
        $this->type = (new ProductType())->setName('Статика');
        $this->district = (new District())->setName('Центральный');
        foreach ([$this->category, $this->type, $this->district] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
    }

    public function testCreatePartner(): void
    {
        $crawler = $this->client->request('GET', '/admin/partners/new');
        self::assertResponseIsSuccessful();

        [$values, $uri] = $this->formValues($crawler, 'Создать');
        $values['partner_form']['name'] = 'ООО «Наружка Плюс»';
        $values['partner_form']['inn'] = '7707 083893';
        $values['partner_form']['contactName'] = 'Олег';
        $values['partner_form']['phone'] = '+7 900 123-45-67';
        $this->submit($uri, $values);

        self::assertResponseRedirects('/admin/partners');
        $this->client->followRedirect();
        self::assertSelectorTextContains('tbody', 'ООО «Наружка Плюс»');
        self::assertSelectorTextContains('tbody', 'ИНН 7707083893');
    }

    public function testValidatesInnAndUniqueName(): void
    {
        $this->partner('Наружка');

        $crawler = $this->client->request('GET', '/admin/partners/new');
        [$values, $uri] = $this->formValues($crawler, 'Создать');
        $values['partner_form']['name'] = 'Наружка';
        $values['partner_form']['inn'] = '12345';
        $this->submit($uri, $values);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#partner_form_name_error1', 'Партнёр с таким названием уже есть');
        self::assertSelectorTextContains('#partner_form_inn_error1', 'ИНН — 10 или 12 цифр');
    }

    public function testPartnerStructureNeedsPurchasePriceAndShowsMargin(): void
    {
        $partner = $this->partner('Наружка');
        $product = $this->product('Щит на Ленина');

        $crawler = $this->client->request('GET', '/admin/products/'.$product->getId().'/edit');
        [$values, $uri] = $this->formValues($crawler, 'Сохранить');
        $values['product_form']['owner'] = (string) $partner->getId();
        $values['product_form']['price'] = '40000';
        $values['product_form']['purchasePrice'] = '';
        $this->submit($uri, $values);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#product_form_purchasePrice_error1', 'Укажите цену партнёра');

        $values['product_form']['purchasePrice'] = '30000';
        $this->submit($uri, $values);
        self::assertResponseRedirects();
        $this->client->followRedirect();

        self::assertSelectorTextContains('#product-status', 'Наружка');
        self::assertSelectorTextContains('#product-status', '10 000 ₽ · 25%');

        $this->em->clear();
        $saved = $this->em->find(Product::class, $product->getId());
        self::assertSame('30000.00', $saved->getPurchasePrice());
        self::assertEqualsWithDelta(10000.0, $saved->getMargin(), 0.001);
    }

    public function testSwitchingBackToOwnClearsPurchasePrice(): void
    {
        $product = $this->product('Щит', $this->partner('Наружка'), '20000');

        $crawler = $this->client->request('GET', '/admin/products/'.$product->getId().'/edit');
        [$values, $uri] = $this->formValues($crawler, 'Сохранить');
        $values['product_form']['owner'] = '';
        $this->submit($uri, $values);

        self::assertResponseRedirects();
        $this->em->clear();
        $saved = $this->em->find(Product::class, $product->getId());
        self::assertTrue($saved->isOwn());
        self::assertNull($saved->getPurchasePrice());
    }

    public function testProductListFiltersByOwnerAndShowsMargin(): void
    {
        $partner = $this->partner('Наружка');
        $this->product('Своя конструкция');
        $this->product('Партнёрский щит', $partner, '30000');

        $crawler = $this->client->request('GET', '/admin/products?owner=own');
        self::assertCount(1, $crawler->filter('#product-results tbody tr'));
        self::assertSelectorTextContains('#product-results tbody', 'Своя конструкция');

        $crawler = $this->client->request('GET', '/admin/products?owner='.$partner->getId());
        self::assertCount(1, $crawler->filter('#product-results tbody tr'));
        self::assertSelectorTextContains('#product-results tbody', 'Партнёрский щит');
        self::assertSelectorTextContains('#product-results tbody', '+10 000');
        self::assertSelectorTextContains('#product-results tbody', '· 25%');
    }

    public function testCannotDeletePartnerWithStructures(): void
    {
        $partner = $this->partner('Наружка');
        $this->product('Щит', $partner, '30000');
        $empty = $this->partner('Без конструкций');

        $crawler = $this->client->request('GET', '/admin/partners');
        $this->submitPostForm($crawler, 'form[action="/admin/partners/'.$partner->getId().'/delete"]');
        $this->client->followRedirect();
        self::assertSelectorTextContains('[role=alert]', 'у партнёра есть конструкции (1)');

        $crawler = $this->client->request('GET', '/admin/partners');
        $this->submitPostForm($crawler, 'form[action="/admin/partners/'.$empty->getId().'/delete"]');
        self::assertResponseRedirects('/admin/partners');

        $this->em->clear();
        self::assertNotNull($this->em->find(Partner::class, $partner->getId()));
        self::assertNull($this->em->find(Partner::class, $empty->getId()));
    }

    public function testPartnersAreFoundByCommandPalette(): void
    {
        $this->partner('Наружка Плюс')->setPhone('+7 911 000-00-00');
        $this->em->flush();

        $this->client->request('GET', '/admin/search?q=911');
        $groups = json_decode($this->client->getResponse()->getContent(), true)['groups'];

        self::assertSame('Партнёры', $groups[0]['title']);
        self::assertSame('Наружка Плюс', $groups[0]['items'][0]['title']);
    }

    private function partner(string $name): Partner
    {
        $partner = (new Partner())->setName($name);
        $this->em->persist($partner);
        $this->em->flush();

        return $partner;
    }

    private function product(string $name, ?Partner $owner = null, ?string $purchasePrice = null): Product
    {
        $product = (new Product())->setName($name)->setCategory($this->category)->setProductType($this->type)
            ->setDistrict($this->district)->setPrice('40000')->setLatitude('55.0000000')->setLongitude('37.0000000')
            ->setOwner($owner)->setPurchasePrice($purchasePrice)
            ->addSide((new ProductSide())->setName('A'));
        $this->em->persist($product);
        $this->em->flush();

        return $product;
    }
}
