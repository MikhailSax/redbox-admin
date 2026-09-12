<?php

namespace App\Tests\Controller\Admin;

use App\Entity\Category;
use App\Entity\Product;
use App\Entity\ProductType;

final class CategoryControllerTest extends AdminWebTestCase
{
    private ProductType $static;
    private ProductType $video;

    protected function setUp(): void
    {
        parent::setUp();

        $this->static = (new ProductType())->setName('Статика');
        $this->video = (new ProductType())->setName('Видеоэкран');
        $this->em->persist($this->static);
        $this->em->persist($this->video);
        $this->em->flush();
    }

    public function testCreateWithImageAndTypes(): void
    {
        $crawler = $this->client->request('GET', '/admin/categories/new');
        self::assertResponseIsSuccessful();

        [$values, $uri] = $this->formValues($crawler, 'Создать');
        $values['category_form']['name'] = 'Билборд 6х3';
        $values['category_form']['shortDescription'] = 'Классический щит';
        $values['category_form']['productTypes'] = [(string) $this->static->getId(), (string) $this->video->getId()];

        $this->submit($uri, $values, ['category_form' => ['imageFile' => $this->makeImage('billboard.png')]]);

        self::assertResponseRedirects();
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('[role=alert]', 'Категория создана');

        $this->em->clear();
        $category = $this->em->getRepository(Category::class)->findOneBy(['name' => 'Билборд 6х3']);
        self::assertNotNull($category);
        self::assertStringStartsWith('billboard-', $category->getImage());
        self::assertFileExists($this->imageFile($category));
        self::assertEqualsCanonicalizing(['Статика', 'Видеоэкран'], $category->getProductTypes()->map(fn (ProductType $t) => $t->getName())->getValues());

        // Edit page shows the current image
        self::assertStringContainsString('/uploads/categories/billboard-', $crawler->filter('img[alt="Билборд 6х3"]')->attr('src'));
    }

    public function testNameMustBeUnique(): void
    {
        $this->createCategory('Билборд 6х3');

        $crawler = $this->client->request('GET', '/admin/categories/new');
        [$values, $uri] = $this->formValues($crawler, 'Создать');
        $values['category_form']['name'] = 'Билборд 6х3';
        $this->submit($uri, $values);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#category_form_name_error1', 'Категория с таким названием уже есть');
    }

    public function testReplaceAndRemoveImage(): void
    {
        $category = $this->createCategory('Суперсайт');
        $crawler = $this->client->request('GET', '/admin/categories/'.$category->getId().'/edit');
        [$values, $uri] = $this->formValues($crawler, 'Сохранить');
        $this->submit($uri, $values, ['category_form' => ['imageFile' => $this->makeImage('first.png')]]);
        $this->em->clear();
        $first = $this->imageFile($this->em->find(Category::class, $category->getId()));
        self::assertFileExists($first);

        // Upload a new image: the old file is deleted
        $crawler = $this->client->request('GET', '/admin/categories/'.$category->getId().'/edit');
        [$values, $uri] = $this->formValues($crawler, 'Сохранить');
        $this->submit($uri, $values, ['category_form' => ['imageFile' => $this->makeImage('second.png')]]);
        $this->em->clear();
        $second = $this->imageFile($this->em->find(Category::class, $category->getId()));
        self::assertFileDoesNotExist($first);
        self::assertFileExists($second);

        // Tick "remove image"
        $crawler = $this->client->request('GET', '/admin/categories/'.$category->getId().'/edit');
        [$values, $uri] = $this->formValues($crawler, 'Сохранить');
        $values['category_form']['removeImage'] = '1';
        $this->submit($uri, $values);
        $this->em->clear();
        self::assertNull($this->em->find(Category::class, $category->getId())->getImage());
        self::assertFileDoesNotExist($second);
    }

    public function testIndexShowsProductCount(): void
    {
        $category = $this->createCategory('Билборд 6х3');
        $this->createProduct($category);
        $this->createCategory('Ситиформат');

        $crawler = $this->client->request('GET', '/admin/categories');
        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter('tbody tr'));
        self::assertSelectorExists('a[href="/admin/products?category='.$category->getId().'"]');
    }

    public function testDeleteRemovesCategoryAndImage(): void
    {
        $category = $this->createCategory('Суперсайт');
        $crawler = $this->client->request('GET', '/admin/categories/'.$category->getId().'/edit');
        [$values, $uri] = $this->formValues($crawler, 'Сохранить');
        $this->submit($uri, $values, ['category_form' => ['imageFile' => $this->makeImage('x.png')]]);
        $this->em->clear();
        $file = $this->imageFile($this->em->find(Category::class, $category->getId()));

        $crawler = $this->client->request('GET', '/admin/categories');
        $this->submitPostForm($crawler, 'form[action="/admin/categories/'.$category->getId().'/delete"]');

        self::assertResponseRedirects('/admin/categories');
        $this->em->clear();
        self::assertNull($this->em->find(Category::class, $category->getId()));
        self::assertFileDoesNotExist($file);
    }

    public function testCannotDeleteCategoryInUse(): void
    {
        $category = $this->createCategory('Билборд 6х3');
        $this->createProduct($category);

        $crawler = $this->client->request('GET', '/admin/categories');
        $this->submitPostForm($crawler, 'form[action="/admin/categories/'.$category->getId().'/delete"]');

        self::assertResponseRedirects('/admin/categories');
        $this->client->followRedirect();
        self::assertSelectorTextContains('[role=alert]', 'используется в конструкциях (1)');
        $this->em->clear();
        self::assertNotNull($this->em->find(Category::class, $category->getId()));
    }

    private function createCategory(string $name): Category
    {
        $category = (new Category())->setName($name);
        $this->em->persist($category);
        $this->em->flush();

        return $category;
    }

    private function createProduct(Category $category): Product
    {
        $product = (new Product())->setName('Щит')->setCategory($category)->setProductType($this->static);
        $this->em->persist($product);
        $this->em->flush();

        return $product;
    }

    private function imageFile(Category $category): string
    {
        return $this->uploadsDir.'/'.Category::UPLOAD_FOLDER.'/'.$category->getImage();
    }
}
