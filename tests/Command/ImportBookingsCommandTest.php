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
use App\Enum\BookingStatus;
use App\Service\ClientCards;
use Doctrine\ORM\EntityManagerInterface;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Console\Tester\CommandTester;

final class ImportBookingsCommandTest extends KernelTestCase
{
    use ClockSensitiveTrait;

    private const CLIENTS = ['СМИТ-ТРЕЙД ООО', 'Каркунова Баярма Солбоновна ИП', 'Ухнеева Зоя Пурбоевна ИП', 'Такси барс', 'Leznova Clinic', 'Кто-то'];

    private CommandTester $tester;
    private EntityManagerInterface $em;
    private string $file;

    protected function setUp(): void
    {
        $application = new Application(self::bootKernel());
        self::mockTime('2026-09-30 12:00:00');
        $this->tester = new CommandTester($application->find('app:import:bookings'));
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        foreach ([MediaPlanServiceLine::class, MediaPlanItem::class, MediaPlan::class, Promotion::class, Booking::class, ProductSidePhoto::class, ProductSide::class, Product::class, ProductType::class, Category::class, District::class] as $class) {
            $this->em->createQuery(\sprintf('DELETE FROM %s e', $class))->execute();
        }
        $this->em->createQuery('DELETE FROM '.User::class.' u WHERE u.company IN (:names) OR u.name IN (:names)')->setParameter('names', self::CLIENTS)->execute();

        $category = (new Category())->setName('Билборд');
        $static = (new ProductType())->setName('Статика');
        $video = (new ProductType())->setName('Видеоэкран')->setBookingMode(BookingMode::Airtime);
        $screen = (new Product())->setName('ул. Борсоева, 54а/1')->setSchemeNumber('884')->setCategory($category)->setProductType($video)
            ->addSide((new ProductSide())->setName('А')->setSlotCount(2))
            ->addSide((new ProductSide())->setName('В')->setSlotCount(2));
        $billboard = (new Product())->setName('Бурвод ул. Кабанская и трасса Р-258')->setCategory($category)->setProductType($static)
            ->addSide((new ProductSide())->setName('А'))
            ->addSide((new ProductSide())->setName('Б'));
        $prismatron = (new Product())->setName('ул. Гагарина-ул.Добролюбова')->setSchemeNumber('6/25')->setCategory($category)->setProductType($static)
            ->addSide((new ProductSide())->setName('А1'))
            ->addSide((new ProductSide())->setName('В'));
        foreach ([$category, $static, $video, $screen, $billboard, $prismatron] as $entity) {
            $this->em->persist($entity);
        }
        static::getContainer()->get(ClientCards::class)->create('СМИТ-ТРЕЙД ООО', inn: '0300010874');
        $this->em->flush();

        $this->file = sys_get_temp_dir().'/occupancy-'.bin2hex(random_bytes(4)).'.xlsx';
        $this->writeFile();
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        parent::tearDown();
    }

    public function testMakesPaidBookingsOfTheSidesInTheLastSheet(): void
    {
        $this->tester->execute(['file' => $this->file]);

        $this->tester->assertCommandIsSuccessful();
        $display = $this->tester->getDisplay();
        self::assertStringContainsString('Лист «октябрь 2026»', $display);
        self::assertMatchesRegularExpression('/\s5\s+0\s/u', $display);

        // the screen: a slot a row, the side from the title, the client found without the note in brackets
        $smith = $this->bookingsOf('СМИТ-ТРЕЙД ООО');
        self::assertSame([
            ['ул. Борсоева, 54а/1', 'А', 1, '2026-10-01', '2026-10-31'],
            ['Бурвод ул. Кабанская и трасса Р-258', 'А', null, '2026-10-01', '2026-12-31'], // "ООО "СМИТ-ТРЕЙД"", "до конца года"
        ], array_map(self::describe(...), $smith));
        self::assertSame(BookingStatus::Paid, $smith[0]->getStatus());
        self::assertSame('Из таблицы занятости: лист «октябрь 2026», строка 3, клиент «СМИТ-ТРЕЙД ООО (Тритон)»', $smith[0]->getComment());

        // a name the CRM doesn't know gets a card; "сентябрь" is the month of the sheet's year
        self::assertSame([
            ['ул. Борсоева, 54а/1', 'А', 1, '2026-09-01', '2026-09-30'],
            ['ул. Борсоева, 54а/1', 'А', 1, '2026-10-01', '2026-10-31'],
        ], array_map(self::describe(...), $this->bookingsOf('Каркунова Баярма Солбоновна ИП')));
        self::assertSame([['ул. Гагарина-ул.Добролюбова', 'А1', null, '2026-08-03', '2026-09-02']], array_map(self::describe(...), $this->bookingsOf('Такси барс')));
        self::assertStringContainsString('Каркунова Баярма Солбоновна ИП', $display);

        // what doesn't fit is reported by its line
        self::assertStringContainsString('строка 6: Ухнеева Зоя Пурбоевна ИП: На 15 октября 2026 у стороны А свободно слотов: 0 из 2', $display);
        self::assertStringContainsString('строка 13: Leznova Clinic: не понятен период «пока есть место»', $display);
        self::assertStringContainsString('строка 16: Такси барс: у конструкции «ул. Гагарина-ул.Добролюбова» не найдена сторона «C»', $display);
        self::assertStringContainsString('строка 17 «Экран Ольхон»: нет конструкции с номером в схеме 32/24', $display);
        self::assertNull($this->em->getRepository(User::class)->findOneBy(['name' => 'Leznova Clinic']));
    }

    public function testRunningAgainAddsNothing(): void
    {
        $this->tester->execute(['file' => $this->file]);
        $this->tester->execute(['file' => $this->file]);

        $this->tester->assertCommandIsSuccessful();
        self::assertMatchesRegularExpression('/\s0\s+5\s/u', $this->tester->getDisplay());
        self::assertSame(5, $this->em->getRepository(Booking::class)->count([]));
        self::assertSame(1, $this->em->getRepository(User::class)->count(['company' => 'Такси барс']) + $this->em->getRepository(User::class)->count(['name' => 'Такси барс']));
    }

    public function testDryRunSavesNothing(): void
    {
        $this->tester->execute(['file' => $this->file, '--dry-run' => true]);

        $this->tester->assertCommandIsSuccessful();
        self::assertMatchesRegularExpression('/\s5\s+0\s/u', $this->tester->getDisplay());
        self::assertStringContainsString('Пробный запуск', $this->tester->getDisplay());
        self::assertSame(0, $this->em->getRepository(Booking::class)->count([]));
        self::assertNull($this->em->getRepository(User::class)->findOneBy(['name' => 'Такси барс']));
    }

    /**
     * @return list<Booking>
     */
    private function bookingsOf(string $client): array
    {
        $bookings = $this->em->getRepository(Booking::class)->findBy([], ['startDate' => 'ASC', 'id' => 'ASC']);

        return array_values(array_filter($bookings, static fn (Booking $b) => $b->getClientTitle() === $client));
    }

    /**
     * @return array{0: ?string, 1: ?string, 2: ?int, 3: string, 4: string}
     */
    private static function describe(Booking $booking): array
    {
        return [$booking->getProduct()?->getName(), $booking->getSide()->getName(), $booking->getSlots(), $booking->getStartDate()->format('Y-m-d'), $booking->getEndDate()->format('Y-m-d')];
    }

    private function writeFile(): void
    {
        $writer = new Writer();
        $writer->openToFile($this->file);
        $writer->getCurrentSheet()->setName('сентябрь 2026');
        $writer->addRow(Row::fromValues(['№', 'Экран/ Борсоева сторона А', 'Период размещения', 'номер в схеме', 884]));
        $writer->addRow(Row::fromValues([1, 'Кто-то', '01.09.2026-30.09.2026']));

        $writer->addNewSheetAndMakeItCurrent()->setName('октябрь 2026');
        $writer->addRows([
            /* 1 */ Row::fromValues([]),
            /* 2 */ Row::fromValues(['№', 'Экран/ Борсоева сторона А', 'Период размещения', 'номер в схеме', 884]),
            /* 3 */ Row::fromValues([1, 'СМИТ-ТРЕЙД ООО (Тритон)', '01.10.2026-31.10.2026']),
            /* 4 */ Row::fromValues([2, 'Каркунова Баярма Солбоновна ИП', 'сентябрь']),
            /* 5 */ Row::fromValues([3, 'Каркунова Баярма Солбоновна ИП ', '01.10.2026-31.10.2026']),
            /* 6 */ Row::fromValues([4, 'Ухнеева Зоя Пурбоевна ИП', '15.10.2026-14.11.2026']), // no slot left
            /* 7 */ Row::fromValues([5]),
            /* 8 */ Row::fromValues(['', 'Итого:']),
            /* 9 */ Row::fromValues(['№', 'Экран/ Борсоева сторона Б', 'Период размещения', 'номер в схеме', 884]),
            /* 10 */ Row::fromValues([1, 'не работает']),
            /* 11 */ Row::fromValues(['№', 'Щит Бурвод', 'Период размещения', 'номер в схеме', 'б/н']),
            /* 12 */ Row::fromValues(['А', 'ООО "СМИТ-ТРЕЙД"', 'до конца года']),
            /* 13 */ Row::fromValues(['B', 'Leznova Clinic бронь', 'пока есть место']),
            /* 14 */ Row::fromValues(['Сторона', 'Призматрон ул. Гагарина и Добролюбова', 'Период размещения', 'номер в схеме', '6/25']),
            /* 15 */ Row::fromValues(['A1', 'Такси барс', '03.08.026-02.09.2026']),
            /* 16 */ Row::fromValues(['C', 'Такси барс', '01.10.2026-31.10.2026']),
            /* 17 */ Row::fromValues(['№', 'Экран Ольхон', 'Период размещения', 'номер в схеме', '32/24']),
            /* 18 */ Row::fromValues([1, 'Кто-то', '01.10.2026-31.10.2026']),
        ]);
        $writer->close();
    }
}
