<?php

namespace App\Tests\Controller\Admin;

use App\Entity\Category;
use App\Entity\District;
use App\Entity\Product;
use App\Entity\ProductType;

final class DistrictControllerTest extends AdminWebTestCase
{
    public function testCreateAndEdit(): void
    {
        $crawler = $this->client->request('GET', '/admin/districts/new');
        self::assertResponseIsSuccessful();

        [$values, $uri] = $this->formValues($crawler, 'Создать');
        $values['district_form']['name'] = 'Центральный';
        $this->submit($uri, $values);

        self::assertResponseRedirects('/admin/districts');
        $this->client->followRedirect();
        self::assertSelectorTextContains('tbody', 'Центральный');

        $district = $this->em->getRepository(District::class)->findOneBy(['name' => 'Центральный']);
        $crawler = $this->client->request('GET', '/admin/districts/'.$district->getId().'/edit');
        [$values, $uri] = $this->formValues($crawler, 'Сохранить');
        $values['district_form']['name'] = 'Ленинский';
        $values['district_form']['shortDescription'] = 'Левый берег';
        $this->submit($uri, $values);

        self::assertResponseRedirects('/admin/districts');
        $this->em->clear();
        $district = $this->em->find(District::class, $district->getId());
        self::assertSame('Ленинский', $district->getName());
        self::assertSame('Левый берег', $district->getShortDescription());
    }

    public function testNameIsRequired(): void
    {
        $crawler = $this->client->request('GET', '/admin/districts/new');
        [$values, $uri] = $this->formValues($crawler, 'Создать');
        $this->submit($uri, $values);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->em->getRepository(District::class)->count([]));
    }

    public function testDeleteKeepsProductsWithoutDistrict(): void
    {
        $district = (new District())->setName('Центральный');
        $category = (new Category())->setName('Билборд 6х3');
        $type = (new ProductType())->setName('Статика');
        $product = (new Product())->setName('Щит')->setCategory($category)->setProductType($type)->setDistrict($district);
        foreach ([$district, $category, $type, $product] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();

        $crawler = $this->client->request('GET', '/admin/districts');
        self::assertSelectorTextContains('tbody', '1');
        $this->submitPostForm($crawler, 'form[action="/admin/districts/'.$district->getId().'/delete"]');

        self::assertResponseRedirects('/admin/districts');
        $this->em->clear();
        self::assertNull($this->em->find(District::class, $district->getId()));
        $product = $this->em->find(Product::class, $product->getId());
        self::assertNotNull($product);
        self::assertNull($product->getDistrict());
    }
}
