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
use App\Enum\BookingMode;
use App\Helpers\ProductHelper;
use App\Service\MediaPlanManager;
use Doctrine\ORM\EntityManagerInterface;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class ImportAddressProgramCommandTest extends KernelTestCase
{
    private const HEADER_DIGITAL = ['Номер в схеме', 'Адрес', 'Фото', 'Карта', 'Сторона', 'Формат', 'Хронометраж', 'длина блока', 'Размер, м', 'месяц', '3 месяца'];
    private const HEADER_STATIC = ['Номер в схеме', 'Адрес', 'Фото', 'Карта', 'Сторона', 'Тип конструкции, размер', 'Стоимость размещения', 'Печать баннера/пленки', '3 мес', '6 мес', 'Бронь'];

    private CommandTester $tester;
    private EntityManagerInterface $em;
    private string $file;

    protected function setUp(): void
    {
        $application = new Application(self::bootKernel());
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
        self::assertStringContainsString('Строка 12 пропущена: неизвестный формат «Остановочный павильон»', $display);
        self::assertStringContainsString('с. Иволгинск, вблизи ул. Ленина №3, сторона А', $display); // no price
        self::assertStringContainsString('строк с отметкой — 1', $display);

        // one address, two kinds of structure: digital side A and static sides B, C
        $digital = $this->product('ул. Ботаническая, 2', 'Видеоэкран');
        self::assertSame('Суперсайт', $digital->getCategory()->getName());
        self::assertSame(BookingMode::Airtime, $digital->getProductType()->getBookingMode());
        self::assertSame('858', $digital->getSchemeNumber());
        self::assertSame(ProductHelper::SIZE_12X4, $digital->getSize());
        self::assertSame('Улан-Удэ', $digital->getDistrict()->getName());
        self::assertSame('47200.00', $digital->getPrice());

        $static = $this->product('ул. Ботаническая, 2', 'Статика');
        self::assertSame('Билборд', $static->getCategory()->getName());
        self::assertSame(ProductHelper::SIZE_6X3, $static->getSize());
        // latin "B" becomes Cyrillic "В"; the cheaper side sets the structure price, the dearer one keeps its own
        self::assertSame(['В', 'С'], $static->getSides()->map(static fn (ProductSide $s) => $s->getName())->getValues());
        self::assertSame('26500.00', $static->getPrice());
        [$sideB, $sideC] = $static->getSides()->getValues();
        self::assertSame('28800.00', $sideB->getPrice());
        self::assertNull($sideC->getPrice());
        self::assertSame(28800.0, MediaPlanManager::priceFor($sideB, null));
        self::assertSame(26500.0, MediaPlanManager::priceFor($sideC, null));

        $city = $this->product('ул.Сухэ-Батора,7', 'Статика');
        self::assertSame('Сити-формат', $city->getCategory()->getName());
        self::assertSame(ProductHelper::SIZE_1_2X1_8, $city->getSize());

        $rural = $this->product('с. Иволгинск, вблизи ул. Ленина №3', 'Статика');
        self::assertNull($rural->getSchemeNumber()); // "б/н"
        self::assertNull($rural->getPrice());
        self::assertSame('Иволгинский район', $rural->getDistrict()->getName());
        self::assertNull($rural->getLatitude()); // map links are not imported
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
        self::assertSame('30000.00', $this->product('ул. Ботаническая, 2', 'Статика')->getSides()->first()->getPrice());
    }

    public function testDryRunSavesNothingAndOtherSheetsCanBeChosen(): void
    {
        $this->writeFile(staticPriceA: 28800);

        $this->tester->execute(['file' => $this->file, '--dry-run' => true, '--sheet' => 'Старый прайс']);

        $this->tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Лист «Старый прайс»', $this->tester->getDisplay());
        self::assertStringContainsString('Пробный запуск', $this->tester->getDisplay());
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

    private function product(string $name, string $type): Product
    {
        $this->em->clear();

        return $this->em->createQuery('SELECT p FROM '.Product::class.' p JOIN p.productType t WHERE p.name = :name AND t.name = :type')
            ->setParameters(['name' => $name, 'type' => $type])
            ->getSingleResult();
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
            Row::fromValues([858, 'ул. Ботаническая, 2', 'https://example.com/a.jpg', 'https://yandex.ru/maps/-/X', 'А', 'Диджитал Суперсайт', '5 сек', '60 сек', '12*4', 47200, 40250]), // 4
            Row::fromValues(['Улан-Удэ']),                                                                                         // 5
            Row::fromValues(self::HEADER_STATIC),                                                                                  // 6
            Row::fromValues([858, 'ул. Ботаническая, 2', '', '', 'B', 'Щит 6Х3', $staticPriceA, '3 500,00 (баннер)', 25900, 24200]), // 7
            Row::fromValues([858, 'ул. Ботаническая, 2', '', '', 'С', 'Щит 6Х3', '26 500,00', '3 500,00 (баннер)', 24200, 23000, 'до конца года']), // 8
            Row::fromValues([761, 'ул.Сухэ-Батора,7', '', '', 'А', 'Сити-формат', 10500, '3 300,00 (бэклит)', 9300, 8800]),      // 9
            Row::fromValues(['Районы Республики (Иволгинский и г. Гусиноозерск (Селенгинский район)']),                             // 10
            Row::fromValues(['Номер', 'Адрес', 'фото', 'Карта', 'Сторона', 'Тип конструкции', 'Стоимость размещения']),              // 11
            Row::fromValues([5, 'ул. Неизвестная', '', '', 'А', 'Остановочный павильон', 9000]),                                    // 12
            Row::fromValues(['б/н', 'с. Иволгинск, вблизи ул. Ленина №3', 'сентябрь 2026', '', 'А', 'Щиты 6Х3']),                   // 13
        ]);
        $writer->close();
    }
}
