<?php

namespace App\Tests\Controller\Admin;

use App\Entity\Category;
use App\Entity\District;
use App\Entity\Product;
use App\Entity\ProductSide;
use App\Entity\ProductSidePhoto;
use App\Entity\ProductType;
use App\Service\SidePhotoStorage;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class ProductControllerTest extends AdminWebTestCase
{
    private string $photosDir;
    private Category $billboard;
    private ProductType $static;
    private ProductType $prismatron;
    private District $center;

    protected function setUp(): void
    {
        parent::setUp();
        $this->photosDir = $this->uploadsDir.'/'.ProductSidePhoto::UPLOAD_FOLDER;

        $this->billboard = (new Category())->setName('Билборд 6x3');
        $this->static = (new ProductType())->setName('Статика')->addCategory($this->billboard);
        $this->prismatron = (new ProductType())->setName('Призматрон')->addCategory($this->billboard);
        $this->center = (new District())->setName('Центральный');

        foreach ([$this->billboard, $this->static, $this->prismatron, $this->center] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
    }

    public function testIndexListsAndFiltersProducts(): void
    {
        $this->createProduct('Билборд, пр. Ленина 12', $this->static);
        $this->createProduct('Призматрон, ул. Мира 5', $this->prismatron);

        $crawler = $this->client->request('GET', '/admin/products');
        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter('tbody tr'));
        self::assertSelectorTextContains('tbody', 'пр. Ленина 12');
        self::assertSelectorTextContains('tbody', 'ул. Мира 5');

        $crawler = $this->client->request('GET', '/admin/products?q=Ленина');
        self::assertCount(1, $crawler->filter('tbody tr'));
        self::assertSelectorTextContains('tbody', 'пр. Ленина 12');

        $crawler = $this->client->request('GET', '/admin/products?type='.$this->prismatron->getId().'&category=');
        self::assertCount(1, $crawler->filter('tbody tr'));
        self::assertSelectorTextContains('tbody', 'ул. Мира 5');
    }

    public function testIndexPaginates(): void
    {
        for ($i = 1; $i <= 21; ++$i) {
            $this->createProduct('Конструкция '.$i, $this->static);
        }

        $crawler = $this->client->request('GET', '/admin/products');
        self::assertCount(20, $crawler->filter('tbody tr'));

        $crawler = $this->client->request('GET', '/admin/products?page=2');
        self::assertCount(1, $crawler->filter('tbody tr'));
    }

    public function testCreateProductWithSidesAndPhotos(): void
    {
        $crawler = $this->client->request('GET', '/admin/products/new');
        self::assertResponseIsSuccessful();

        [$values, $uri] = $this->formValues($crawler, 'Создать');
        $values['product_form']['name'] = 'Билборд, пр. Ленина 12';
        $values['product_form']['category'] = (string) $this->billboard->getId();
        $values['product_form']['productType'] = (string) $this->static->getId();
        $values['product_form']['district'] = (string) $this->center->getId();
        $values['product_form']['price'] = '45000';
        $values['product_form']['latitude'] = '55.7539303';
        $values['product_form']['longitude'] = '37.6205606';
        // A third side added on the page via the collection prototype
        $values['product_form']['sides'][2] = ['name' => 'C', 'description' => 'Со стороны парка', 'productType' => (string) $this->prismatron->getId()];

        $this->submit($uri, $values, [
            'product_form' => ['sides' => [
                0 => ['newPhotos' => [$this->makeImage('front.png'), $this->makeImage('night.png')]],
                2 => ['newPhotos' => [$this->makeImage('park.png')]],
            ]],
        ]);

        self::assertResponseRedirects();
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('[role=alert]', 'Конструкция создана');

        // Product card shows the uploaded photos: large preview of the first one plus a thumbnail per photo
        self::assertSelectorTextContains('[data-gallery-caption]', 'Сторона A');
        self::assertCount(3, $crawler->filter('[data-gallery] [data-gallery-thumb]'));
        self::assertStringContainsString('/uploads/sides/front-', $crawler->filter('[data-gallery-main]')->attr('src'));

        $product = $this->em->getRepository(Product::class)->findOneBy(['name' => 'Билборд, пр. Ленина 12']);
        self::assertNotNull($product);
        self::assertSame('45000.00', $product->getPrice());
        self::assertSame('55.7539303', $product->getLatitude());
        self::assertSame('37.6205606', $product->getLongitude());
        self::assertSame($this->center->getId(), $product->getDistrict()?->getId());
        self::assertSame(['A', 'B', 'C'], $product->getSides()->map(fn (ProductSide $s) => $s->getName())->getValues());

        [$sideA, $sideB, $sideC] = $product->getSides()->getValues();
        self::assertSame(['front.png', 'night.png'], $sideA->getPhotos()->map(fn (ProductSidePhoto $p) => $p->getOriginalName())->getValues());
        self::assertSame([0, 1], $sideA->getPhotos()->map(fn (ProductSidePhoto $p) => $p->getPosition())->getValues());
        self::assertCount(0, $sideB->getPhotos());
        self::assertCount(1, $sideC->getPhotos());
        // side C has its own type, the others follow the structure
        self::assertNull($sideA->getProductType());
        self::assertSame('Статика', $sideA->getEffectiveProductType()->getName());
        self::assertSame('Призматрон', $sideC->getEffectiveProductType()->getName());
        foreach ($sideA->getPhotos() as $photo) {
            self::assertFileExists($this->photosDir.'/'.$photo->getFilename());
        }

        // Edit page shows a photo table per side
        self::assertSelectorCount(2, '#product-sides [data-collection-item]:nth-child(1) tbody tr');
    }

    public function testCreateRequiresAllMainFields(): void
    {
        $crawler = $this->client->request('GET', '/admin/products/new');
        [$values, $uri] = $this->formValues($crawler, 'Создать');
        $values['product_form']['name'] = '   '; // whitespace only counts as empty
        $values['product_form']['sides'][0]['name'] = '';

        $this->submit($uri, $values);

        self::assertResponseStatusCodeSame(422);
        $errors = [
            'name' => 'Укажите название',
            'category' => 'Выберите категорию',
            'productType' => 'Выберите тип конструкции',
            'district' => 'Выберите район',
            'price' => 'Укажите цену',
            'latitude' => 'Укажите широту',
            'longitude' => 'Укажите долготу',
            'sides_0_name' => 'Укажите название стороны',
        ];
        foreach ($errors as $field => $message) {
            self::assertSelectorTextContains('#product_form_'.$field.'_error1', $message);
        }
        self::assertSame(0, $this->em->getRepository(Product::class)->count([]));
    }

    public function testValidatesCoordinatesAndSides(): void
    {
        $product = $this->createProduct('Билборд', $this->static);

        $crawler = $this->client->request('GET', '/admin/products/'.$product->getId().'/edit');
        [$values, $uri] = $this->formValues($crawler, 'Сохранить');
        $values['product_form']['latitude'] = '91';
        $values['product_form']['longitude'] = 'восток';
        $values['product_form']['sides'] = []; // every side removed on the page

        $this->submit($uri, $values);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#product_form_latitude_error1', 'Широта должна быть от -90 до 90');
        self::assertSelectorTextContains('#product_form_longitude_error1', 'Введите координату числом');
        self::assertSelectorTextContains('#product-form', 'Добавьте хотя бы одну сторону');
        $this->em->clear();
        self::assertSame('55.0000000', $this->em->find(Product::class, $product->getId())->getLatitude());
    }

    public function testRejectsNonImageUpload(): void
    {
        $product = $this->createProduct('Билборд', $this->static);

        $crawler = $this->client->request('GET', '/admin/products/'.$product->getId().'/edit');
        [$values, $uri] = $this->formValues($crawler, 'Сохранить');

        $text = tempnam(sys_get_temp_dir(), 'txt');
        file_put_contents($text, 'not an image');
        $this->submit($uri, $values, [
            'product_form' => ['sides' => [0 => ['newPhotos' => [new UploadedFile($text, 'doc.txt', 'text/plain', null, true)]]]],
        ]);

        self::assertResponseStatusCodeSame(422);
        $this->em->clear();
        self::assertSame(0, $this->em->getRepository(ProductSidePhoto::class)->count([]));
    }

    public function testEditUpdatesFieldsAndRemovesSide(): void
    {
        $product = $this->createProduct('Старое имя', $this->static, withPhotoOnSideB: true);
        $photoFile = $this->photosDir.'/'.$product->getSides()->last()->getPhotos()->first()->getFilename();
        self::assertFileExists($photoFile);

        $crawler = $this->client->request('GET', '/admin/products/'.$product->getId().'/edit');
        self::assertResponseIsSuccessful();

        [$values, $uri] = $this->formValues($crawler, 'Сохранить');
        $values['product_form']['name'] = 'Новое имя';
        $values['product_form']['productType'] = (string) $this->prismatron->getId();
        unset($values['product_form']['sides'][1]); // side B removed on the page

        $this->submit($uri, $values);
        self::assertResponseRedirects('/admin/products/'.$product->getId().'/edit#main'); // back to the tab the form was saved from

        $this->em->clear();
        $product = $this->em->find(Product::class, $product->getId());
        self::assertSame('Новое имя', $product->getName());
        self::assertSame('Призматрон', $product->getProductType()->getName());
        self::assertSame(['A'], $product->getSides()->map(fn (ProductSide $s) => $s->getName())->getValues());
        self::assertSame(0, $this->em->getRepository(ProductSidePhoto::class)->count([]));
        self::assertFileDoesNotExist($photoFile);
    }

    public function testDeletePhoto(): void
    {
        $product = $this->createProduct('Билборд', $this->static, withPhotoOnSideB: true);
        $photo = $product->getSides()->last()->getPhotos()->first();
        $file = $this->photosDir.'/'.$photo->getFilename();

        $crawler = $this->client->request('GET', '/admin/products/'.$product->getId().'/edit');
        $form = $crawler->filter('#delete-photo-'.$photo->getId())->form();
        $this->client->request('POST', $form->getUri(), $form->getPhpValues());

        self::assertResponseRedirects('/admin/products/'.$product->getId().'/edit#sides');
        $this->em->clear();
        self::assertNull($this->em->find(ProductSidePhoto::class, $photo->getId()));
        self::assertFileDoesNotExist($file);
    }

    public function testDeletePhotoRejectsInvalidToken(): void
    {
        $product = $this->createProduct('Билборд', $this->static, withPhotoOnSideB: true);
        $photo = $product->getSides()->last()->getPhotos()->first();

        $this->client->request('POST', \sprintf('/admin/products/%d/photos/%d/delete', $product->getId(), $photo->getId()), ['_token' => 'wrong']);

        // Invalid CSRF is an authentication failure: the firewall sends the user to the login page
        self::assertResponseRedirects('/login');
        $this->em->clear();
        self::assertNotNull($this->em->find(ProductSidePhoto::class, $photo->getId()));
    }

    public function testDeleteProductRemovesSidesAndPhotos(): void
    {
        $product = $this->createProduct('Билборд', $this->static, withPhotoOnSideB: true);
        $file = $this->photosDir.'/'.$product->getSides()->last()->getPhotos()->first()->getFilename();

        $crawler = $this->client->request('GET', '/admin/products');
        $form = $crawler->filter('form[action$="/'.$product->getId().'/delete"]')->form();
        $this->client->submit($form);

        self::assertResponseRedirects('/admin/products');
        $this->em->clear();
        self::assertSame(0, $this->em->getRepository(Product::class)->count([]));
        self::assertSame(0, $this->em->getRepository(ProductSide::class)->count([]));
        self::assertSame(0, $this->em->getRepository(ProductSidePhoto::class)->count([]));
        self::assertFileDoesNotExist($file);
    }

    private function createProduct(string $name, ProductType $type, bool $withPhotoOnSideB = false): Product
    {
        $product = (new Product())
            ->setName($name)
            ->setCategory($this->billboard)
            ->setProductType($type)
            ->setDistrict($this->center)
            ->setPrice('30000')
            ->setLatitude('55.0000000')
            ->setLongitude('37.0000000')
            ->addSide((new ProductSide())->setName('A'))
            ->addSide($sideB = (new ProductSide())->setName('B'));

        if ($withPhotoOnSideB) {
            static::getContainer()->get(SidePhotoStorage::class)->attach($sideB, $this->makeImage('b.png'));
        }

        $this->em->persist($product);
        $this->em->flush();

        return $product;
    }
}
