<?php

namespace App\Tests\Command;

use App\Entity\Booking;
use App\Entity\Category;
use App\Entity\District;
use App\Entity\MediaPlan;
use App\Entity\MediaPlanItem;
use App\Entity\MediaPlanServiceLine;
use App\Entity\Product;
use App\Entity\ProductSide;
use App\Entity\ProductSidePhoto;
use App\Entity\ProductType;
use App\Entity\Promotion;
use App\Entity\User;
use App\Enum\BookingMode;
use App\Helpers\ProductHelper;
use App\Service\Import\AddressProgramImporter;
use App\Service\MediaPlanManager;
use Doctrine\ORM\EntityManagerInterface;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ImportAddressProgramCommandTest extends KernelTestCase
{
    private const HEADER_DIGITAL = ['Номер в схеме', 'Адрес', 'Фото', 'Карта', 'Сторона', 'Формат', 'Хронометраж', 'длина блока', 'Размер, м', 'месяц', '3 месяца', '6 месяцев', '14 дней'];
    private const HEADER_STATIC = ['Номер в схеме', 'Адрес', 'Фото', 'Карта', 'Сторона', 'Тип конструкции, размер', 'Стоимость размещения', 'Печать баннера/пленки', '3 мес', '6 мес', 'Бронь'];

    private CommandTester $tester;
    private EntityManagerInterface $em;
    private string $file;

    protected function setUp(): void
    {
        $application = new Application(self::bootKernel());
        // The short map link of the file resolves to a full link with the pin; the photo link gives a 1×1 PNG
        static::getContainer()->set('http_client', new MockHttpClient(static fn (string $method, string $url) => match ($url) {
            'https://yandex.ru/maps/-/X' => new MockResponse('', ['http_code' => 302, 'response_headers' => ['Location: https://yandex.ru/maps/?ll=107.628476%2C51.841500&mode=whatshere&whatshere%5Bpoint%5D=107.627856%2C51.841310']]),
            'https://example.com/a.jpg' => new MockResponse(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFBQIAX8jx0gAAAABJRU5ErkJggg==')),
            default => new MockResponse('not found', ['http_code' => 404]),
        }));
        $this->tester = new CommandTester($application->find('app:import:address-program'));
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        foreach ([MediaPlanServiceLine::class, MediaPlanItem::class, MediaPlan::class, Promotion::class, Booking::class, ProductSidePhoto::class, ProductSide::class, Product::class, ProductType::class, Category::class, District::class] as $class) {
            $this->em->createQuery(\sprintf('DELETE FROM %s e', $class))->execute();
        }
        $this->file = sys_get_temp_dir().'/address-program-'.bin2hex(random_bytes(4)).'.xlsx';
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        parent::tearDown();
    }

    public function testImportsStructuresSidesSizesAndPrices(): void
    {
        $this->writeFile(staticPriceA: 28800);

        $this->tester->execute(['file' => $this->file]);

        $this->tester->assertCommandIsSuccessful();
        $display = $this->tester->getDisplay();
        self::assertStringContainsString('Лист «РК с 01.09.2026г»', $display); // the last sheet by default
        self::assertStringContainsString('Строка 13 пропущена: неизвестный формат «Остановочный павильон»', $display);
        self::assertStringContainsString('с. Иволгинск, вблизи ул. Ленина №3, сторона А', $display); // no price
        self::assertStringContainsString('строк с отметкой — 1', $display);
        self::assertMatchesRegularExpression('/Конструкции\s+4\s+0/u', $display);

        // one address is one structure, even when its sides are listed in different tables:
        // a digital side А and static sides В, С; the structure takes the type of its first row
        $supersite = $this->product('ул. Ботаническая, 2');
        self::assertSame('Суперсайт', $supersite->getCategory()->getName());
        self::assertSame('Видеоэкран', $supersite->getProductType()->getName());
        self::assertSame(BookingMode::Airtime, $supersite->getProductType()->getBookingMode());
        self::assertSame('858', $supersite->getSchemeNumber());
        self::assertSame(ProductHelper::SIZE_12X4, $supersite->getSize());
        self::assertSame('Улан-Удэ', $supersite->getDistrict()->getName());
        self::assertSame(['51.8413100', '107.6278560'], [$supersite->getLatitude(), $supersite->getLongitude()]);
        self::assertStringContainsString('Координаты из ссылок на карту: 1', $display);
        self::assertStringContainsString('ул.Сухэ-Батора,7: https://yandex.ru/maps/-/Broken', $display);
        self::assertSame('Видеоэкран + Статика', $supersite->getTypeLabel());

        // latin "B" becomes Cyrillic "В"; the static sides get a type of their own
        self::assertSame(['А', 'В', 'С'], $supersite->getSides()->map(static fn (ProductSide $s) => $s->getName())->getValues());
        [$sideA, $sideB, $sideC] = $supersite->getSides()->getValues();
        self::assertNull($sideA->getProductType()); // follows the structure: a screen
        self::assertTrue($sideA->isAirtime());
        self::assertSame(['Статика', 'Статика'], [$sideB->getProductType()?->getName(), $sideC->getProductType()?->getName()]);
        self::assertFalse($sideB->isAirtime());

        // the screen: 5 s slots in a 60 s block
        self::assertSame([5, 12], [$sideA->getSlotSeconds(), $sideA->getSlotCount()]);

        // the cheapest side sets the structure price, the dearer ones keep their own; 3/6-month and print prices per side
        self::assertSame('26500.00', $supersite->getPrice());
        self::assertSame(['47200.00', '28800.00', null], [$sideA->getPrice(), $sideB->getPrice(), $sideC->getPrice()]);
        self::assertSame(['40250.00', null, '25000.00'], [$sideA->getPrice3Months(), $sideA->getPrice6Months(), $sideA->getPrice2Weeks()]);
        self::assertSame(['25900.00', '24200.00'], [$sideB->getPrice3Months(), $sideB->getPrice6Months()]);
        self::assertSame(['3500.00', 'баннер'], [$sideB->getPrintPrice(), $sideB->getPrintNote()]);
        self::assertSame(28800.0, MediaPlanManager::priceFor($sideB, null));
        self::assertSame(25900.0, MediaPlanManager::priceFor($sideB, null, 3));
        self::assertSame(24200.0, MediaPlanManager::priceFor($sideB, null, 6));
        self::assertSame(26500.0, MediaPlanManager::priceFor($sideC, null));
        self::assertSame(94400.0, MediaPlanManager::priceFor($sideA, 2)); // 2 slots

        // the photo link of side А is downloaded into side photos; "сентябрь 2026" in the photo column is not a link
        self::assertStringContainsString('Фото сторон загружено: 1', $display);
        self::assertStringContainsString('ул.Сухэ-Батора,7, сторона А: https://example.com/missing.jpg', $display);
        $photo = $sideA->getPhotos()->first();
        self::assertSame('a.jpg', $photo->getOriginalName());
        self::assertStringEndsWith('.png', $photo->getFilename());
        self::assertFileExists(static::getContainer()->getParameter('app.uploads_dir').'/'.ProductSidePhoto::UPLOAD_FOLDER.'/'.$photo->getFilename());

        $city = $this->product('ул.Сухэ-Батора,7');
        self::assertSame('Сити-формат', $city->getCategory()->getName());
        self::assertSame(ProductHelper::SIZE_1_2X1_8, $city->getSize());
        self::assertSame(['3300.00', 'бэклит'], [$city->getSides()->first()->getPrintPrice(), $city->getSides()->first()->getPrintNote()]);

        $rural = $this->product('с. Иволгинск, вблизи ул. Ленина №3');
        self::assertNull($rural->getSchemeNumber()); // "б/н"
        self::assertNull($rural->getPrice());
        self::assertSame('Иволгинский район', $rural->getDistrict()->getName());
        self::assertNull($rural->getLatitude()); // no map link
        self::assertNull($city->getLatitude()); // the link did not resolve

        // the regional table has "3 мес | 6 мес" in a second header row
        $village = $this->product('с. Сотниково, Иволгинский р-он')->getSides()->first();
        self::assertSame(['28800.00', '25900.00', '24150.00'], [$village->getProduct()->getPrice(), $village->getPrice3Months(), $village->getPrice6Months()]);
    }

    public function testJoinsStructuresOfOneAddressSplitByAnEarlierImport(): void
    {
        // what the earlier import made of "ул. Ботаническая, 2": a screen and a static supersite, addresses typed differently
        $category = (new Category())->setName('Суперсайт');
        $video = (new ProductType())->setName('Видеоэкран 12*4')->setBookingMode(BookingMode::Airtime);
        $static = (new ProductType())->setName('Статика');
        $screen = (new Product())->setName('ул.Ботаническая,2')->setCategory($category)->setProductType($video)->setPrice('47200')
            ->addSide($screenSide = (new ProductSide())->setName('А'));
        $poster = (new Product())->setName('ул. Ботаническая, 2')->setCategory($category)->setProductType($static)->setPrice('46000')
            ->addSide($posterSide = (new ProductSide())->setName('В'))
            ->addSide($twin = (new ProductSide())->setName('А')); // listed twice by mistake
        foreach ([$category, $video, $static, $screen, $poster] as $entity) {
            $this->em->persist($entity);
        }
        $client = (new User())->setEmail(uniqid('cafe').'@example.com')->setName('Кафе')->setRole(User::ROLE_CLIENT)->setPassword('x');
        $this->em->persist($client);
        $this->em->flush();
        $this->em->persist(new Booking($posterSide, new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-31'), null, $client, 'Кафе', '1'));
        $this->em->persist(new Booking($twin, new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable('2026-11-30'), 1, $client, 'Кафе', '1'));
        $this->em->flush();
        [$screenId, $posterId] = [$screen->getId(), $poster->getId()];

        $this->writeFile(staticPriceA: 28800);
        $this->tester->execute(['file' => $this->file]);

        $this->tester->assertCommandIsSuccessful();
        self::assertStringContainsString('«ул. Ботаническая, 2» #'.$posterId.' → «ул.Ботаническая,2» #'.$screenId, $this->tester->getDisplay());
        $this->em->clear();
        self::assertNull($this->em->find(Product::class, $posterId));

        // the screen stays with its hand-made type; the static side now says it is static
        $supersite = $this->em->find(Product::class, $screenId);
        self::assertSame('Видеоэкран 12*4', $supersite->getProductType()->getName());
        $sides = [];
        foreach ($supersite->getSides() as $side) {
            $sides[$side->getName()] = [$side->getEffectiveProductType()->getName(), $side->isAirtime()];
        }
        self::assertSame(['А' => ['Видеоэкран 12*4', true], 'В' => ['Статика', false], 'С' => ['Статика', false]], $sides);

        // the bookings of both sides are kept: the twin side А handed its booking to the one that stays
        $bookings = $this->em->getRepository(Booking::class)->findBy([], ['startDate' => 'ASC']);
        self::assertCount(2, $bookings);
        self::assertSame(['В', 'А'], array_map(static fn (Booking $b) => $b->getSide()->getName(), $bookings));
        self::assertSame([$screenId, $screenId], array_map(static fn (Booking $b) => $b->getProduct()->getId(), $bookings));
    }

    public function testMatchesStructuresEditedByHand(): void
    {
        $this->writeFile(staticPriceA: 28800);
        $this->tester->execute(['file' => $this->file]);

        // A manager gave the structure a type of their own and typed side "В" in Latin
        $supersite = $this->product('ул. Ботаническая, 2');
        $screen = (new ProductType())->setName('Видеоэкран 12*4')->setBookingMode(BookingMode::Airtime);
        $this->em->persist($screen);
        $supersite->setProductType($screen)->setLatitude(null)->setLongitude(null);
        $supersite->getSides()->get(1)->setName('B');
        // ...and split the digital side А into faces А1, А2
        $supersite->getSides()->first()->setName('А1');
        $supersite->addSide((new ProductSide())->setName('А2')->setPrice('1.00'));
        $this->em->flush();

        $this->tester->execute(['file' => $this->file]);

        $this->tester->assertCommandIsSuccessful();
        self::assertMatchesRegularExpression('/Конструкции\s+0\s+4/u', $this->tester->getDisplay());
        self::assertMatchesRegularExpression('/Стороны\s+0\s+6/u', $this->tester->getDisplay());
        $supersite = $this->product('ул. Ботаническая, 2');
        self::assertSame('Видеоэкран 12*4', $supersite->getProductType()->getName()); // kept
        self::assertSame(['А1', 'А2', 'В', 'С'], $supersite->getSides()->map(static fn (ProductSide $s) => $s->getName())->getValues());
        self::assertSame([null, null, 'Статика', 'Статика'], $supersite->getSides()->map(static fn (ProductSide $s) => $s->getProductType()?->getName())->getValues());
        self::assertSame('51.8413100', $supersite->getLatitude());

        $faces = $supersite->getSides()->filter(static fn (ProductSide $s) => str_starts_with((string) $s->getName(), 'А'));
        self::assertSame(['47200.00', '47200.00'], $faces->map(static fn (ProductSide $s) => $s->getPrice())->getValues());
        // the photo of side А: А1 already has it, the new face А2 gets a copy
        self::assertSame([1, 1], $faces->map(static fn (ProductSide $s) => $s->getPhotos()->count())->getValues());
        self::assertStringContainsString('Фото сторон загружено: 1', $this->tester->getDisplay());
    }

    public function testRunningAgainUpdatesInsteadOfDuplicating(): void
    {
        $this->writeFile(staticPriceA: 28800);
        $this->tester->execute(['file' => $this->file]);

        $this->writeFile(staticPriceA: 30000);
        $this->tester->execute(['file' => $this->file]);

        $this->tester->assertCommandIsSuccessful();
        self::assertMatchesRegularExpression('/Конструкции\s+0\s+4/u', $this->tester->getDisplay());
        self::assertSame(4, $this->em->getRepository(Product::class)->count([]));
        self::assertSame('30000.00', $this->product('ул. Ботаническая, 2')->getSides()->get(1)->getPrice());
        self::assertStringContainsString('Фото сторон загружено: 0', $this->tester->getDisplay()); // already attached
        self::assertSame(1, $this->product('ул. Ботаническая, 2')->getSides()->first()->getPhotos()->count());
    }

    public function testAddressesAreComparedLoosely(): void
    {
        self::assertSame(AddressProgramImporter::addressKey('ул.Ботаническая,2'), AddressProgramImporter::addressKey('ул. Ботаническая, 2'));
        self::assertSame(AddressProgramImporter::addressKey('ул.Бабушкина, д. 65 "Тамир"'), AddressProgramImporter::addressKey('ул.Бабушкина, д. 65 (Тамир)'));
        self::assertSame(AddressProgramImporter::addressKey('ул. Мокрова, 32'), AddressProgramImporter::addressKey('у. Мокрова, 32'));
        self::assertNotSame(AddressProgramImporter::addressKey('ул. Мокрова, 30'), AddressProgramImporter::addressKey('ул. Мокрова, 32'));
        self::assertNotSame(AddressProgramImporter::addressKey('ул. Смолина, 54, 1ый'), AddressProgramImporter::addressKey('ул. Смолина, 54, 2ой'));
    }

    public function testDryRunSavesNothingAndOtherSheetsCanBeChosen(): void
    {
        $this->writeFile(staticPriceA: 28800);

        $this->tester->execute(['file' => $this->file, '--dry-run' => true, '--sheet' => 'Старый прайс']);

        $this->tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Лист «Старый прайс»', $this->tester->getDisplay());
        self::assertStringContainsString('Пробный запуск', $this->tester->getDisplay());
        self::assertStringContainsString('Фото сторон к загрузке: 1', $this->tester->getDisplay());
        self::assertSame(0, $this->em->getRepository(Product::class)->count([]));
        self::assertSame(0, $this->em->getRepository(Category::class)->count([]));

        $this->tester->execute(['file' => $this->file, '--sheet' => 'Нет такого']);
        self::assertStringContainsString('Лист «Нет такого» не найден', $this->tester->getDisplay());
    }

    public function testSizeHelperReadsSizesFromText(): void
    {
        self::assertSame(ProductHelper::SIZE_6X3, ProductHelper::sizeFromText('Щит 6Х3'));
        self::assertSame(ProductHelper::SIZE_10_5X3_5, ProductHelper::sizeFromText('10,5*3,5'));
        self::assertSame(ProductHelper::SIZE_3_3X7_7, ProductHelper::sizeFromText('Фасад дома 3,3*7,7 м.'));
        self::assertNull(ProductHelper::sizeFromText('Сити-формат'));
        self::assertNull(ProductHelper::sizeFromText('7 × 9')); // not in the list
        self::assertSame('12 × 4 м', ProductHelper::sizeLabel(ProductHelper::SIZE_12X4));
    }

    private function product(string $name): Product
    {
        $this->em->clear();

        return $this->em->getRepository(Product::class)->findOneBy(['name' => $name]) ?? self::fail('No structure '.$name);
    }

    /**
     * Two sheets like the real file: an old price list and the current one (last); the current one holds
     * a digital table, a static table with city formats, and a regional table.
     */
    private function writeFile(int $staticPriceA): void
    {
        $writer = new Writer();
        $writer->openToFile($this->file);
        $writer->getCurrentSheet()->setName('Старый прайс');
        $writer->addRows([
            Row::fromValues(['Улан-Удэ']),
            Row::fromValues(self::HEADER_DIGITAL),
            Row::fromValues([858, 'ул. Ботаническая, 2', 'https://example.com/a.jpg', '', 'А', 'Диджитал Суперсайт', '5 сек', '60 сек', '12*4', '41 000,00']),
        ]);

        $writer->addNewSheetAndMakeItCurrent()->setName('РК с 01.09.2026г');
        $writer->addRows([
            Row::fromValues(['Улан-Удэ']),                                                                                         // 1
            Row::fromValues(['Диджитал билборды и суперсайты', '', '', '', '', '', '', '', '', 'Стоимость']),                        // 2
            Row::fromValues(self::HEADER_DIGITAL),                                                                                 // 3
            Row::fromValues([858, 'ул. Ботаническая, 2', 'https://example.com/a.jpg', 'https://yandex.ru/maps/-/X', 'А', 'Диджитал Суперсайт', '5 сек', '60 сек', '12*4', 47200, 40250, '', '25 000,00']), // 4
            Row::fromValues(['Улан-Удэ']),                                                                                         // 5
            Row::fromValues(self::HEADER_STATIC),                                                                                  // 6
            Row::fromValues([858, 'ул.Ботаническая,2', '', 'https://yandex.ru/maps/198/ulan-ude/?ll=107.628476%2C51.841500&z=18', 'B', 'Суперсайт 12Х4', $staticPriceA, '3 500,00 (баннер)', 25900, 24200]), // 7
            Row::fromValues([858, 'ул. Ботаническая, 2', '', '', 'С', 'Суперсайт 12Х4', '26 500,00', '3 500,00 (баннер)', 24200, 23000, 'до конца года']), // 8
            Row::fromValues([761, 'ул.Сухэ-Батора,7', 'https://example.com/missing.jpg', 'https://yandex.ru/maps/-/Broken', 'А', 'Сити-формат', 10500, '3 300,00 (бэклит)', 9300, 8800]), // 9
            Row::fromValues(['Районы Республики (Иволгинский и г. Гусиноозерск (Селенгинский район)']),                             // 10
            Row::fromValues(['Номер', 'Адрес', 'фото', 'Карта', 'Сторона', 'Тип конструкции', 'Стоимость размещения', 'Печать баннера/пленки', 'Стоимость']), // 11
            Row::fromValues(['', '', '', '', '', '', '', '', '3 мес', '6 мес']),                                                    // 12
            Row::fromValues([5, 'ул. Неизвестная', '', '', 'А', 'Остановочный павильон', 9000]),                                    // 13
            Row::fromValues(['б/н', 'с. Иволгинск, вблизи ул. Ленина №3', 'сентябрь 2026', '', 'А', 'Щиты 6Х3']),                   // 14
            Row::fromValues([16, 'с. Сотниково, Иволгинский р-он', '', '', 'А', 'Щиты 6Х3', 28800, '3 500,00 (баннер)', 25900, 24150]), // 15
        ]);
        $writer->close();
    }
}
