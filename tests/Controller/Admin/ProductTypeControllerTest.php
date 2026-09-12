<?php

namespace App\Tests\Controller\Admin;

use App\Entity\Category;
use App\Entity\Product;
use App\Entity\ProductType;

final class ProductTypeControllerTest extends AdminWebTestCase
{
    private Category $billboard;
    private Category $cityFormat;

    protected function setUp(): void
    {
        parent::setUp();

        $this->billboard = (new Category())->setName('Билборд 6х3');
        $this->cityFormat = (new Category())->setName('Ситиформат');
        $this->em->persist($this->billboard);
        $this->em->persist($this->cityFormat);
        $this->em->flush();
    }

    public function testCreateWithCategories(): void
    {
        $crawler = $this->client->request('GET', '/admin/types/new');
        self::assertResponseIsSuccessful();

        [$values, $uri] = $this->formValues($crawler, 'Создать');
        $values['product_type_form']['name'] = 'Призматрон';
        $values['product_type_form']['description'] = 'Три сюжета на одной стороне';
        $values['product_type_form']['categories'] = [(string) $this->billboard->getId()];
        $this->submit($uri, $values);

        self::assertResponseRedirects('/admin/types');
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('tbody', 'Призматрон');
        self::assertSelectorTextContains('tbody', 'Билборд 6х3');

        $this->em->clear();
        $type = $this->em->getRepository(ProductType::class)->findOneBy(['name' => 'Призматрон']);
        self::assertSame(['Билборд 6х3'], $type->getCategories()->map(fn (Category $c) => $c->getName())->getValues());
    }

    public function testEditChangesCategories(): void
    {
        $type = (new ProductType())->setName('Статика')->addCategory($this->billboard);
        $this->em->persist($type);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/admin/types/'.$type->getId().'/edit');
        [$values, $uri] = $this->formValues($crawler, 'Сохранить');
        $values['product_type_form']['categories'] = [(string) $this->cityFormat->getId()];
        $this->submit($uri, $values);

        self::assertResponseRedirects('/admin/types');
        $this->em->clear();
        $type = $this->em->find(ProductType::class, $type->getId());
        self::assertSame(['Ситиформат'], $type->getCategories()->map(fn (Category $c) => $c->getName())->getValues());
    }

    public function testDelete(): void
    {
        $type = (new ProductType())->setName('Видеоэкран')->addCategory($this->billboard);
        $this->em->persist($type);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/admin/types');
        $this->submitPostForm($crawler, 'form[action="/admin/types/'.$type->getId().'/delete"]');

        self::assertResponseRedirects('/admin/types');
        $this->em->clear();
        self::assertNull($this->em->find(ProductType::class, $type->getId()));
        self::assertCount(0, $this->em->find(Category::class, $this->billboard->getId())->getProductTypes());
    }

    public function testCannotDeleteTypeInUse(): void
    {
        $type = (new ProductType())->setName('Статика');
        $this->em->persist($type);
        $this->em->persist((new Product())->setName('Щит')->setCategory($this->billboard)->setProductType($type));
        $this->em->flush();

        $crawler = $this->client->request('GET', '/admin/types');
        $this->submitPostForm($crawler, 'form[action="/admin/types/'.$type->getId().'/delete"]');

        $this->client->followRedirect();
        self::assertSelectorTextContains('[role=alert]', 'используется в конструкциях (1)');
        $this->em->clear();
        self::assertNotNull($this->em->find(ProductType::class, $type->getId()));
    }
}
