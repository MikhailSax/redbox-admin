<?php

namespace App\Tests\Service;

use App\Dto\BookingRequest;
use App\Entity\Booking;
use App\Entity\Category;
use App\Entity\District;
use App\Entity\Product;
use App\Entity\ProductSide;
use App\Entity\ProductSidePhoto;
use App\Entity\ProductType;
use App\Enum\AvailabilityStatus;
use App\Enum\BookingMode;
use App\Service\Availability\AvailabilityResolver;
use App\Service\BookingManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class AvailabilityResolverTest extends KernelTestCase
{
    use ClockSensitiveTrait;

    private const SEPTEMBER = '2026-09-01';

    private AvailabilityResolver $resolver;
    private BookingManager $bookings;
    private MockClock $clock;
    private Product $billboard;
    private Product $screen;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->clock = self::mockTime('2026-09-10 12:00:00');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->resolver = static::getContainer()->get(AvailabilityResolver::class);
        $this->bookings = static::getContainer()->get(BookingManager::class);

        foreach ([Booking::class, ProductSidePhoto::class, ProductSide::class, Product::class, ProductType::class, Category::class, District::class] as $class) {
            $em->createQuery(\sprintf('DELETE FROM %s e', $class))->execute();
        }

        $category = (new Category())->setName('Билборд 6х3');
        $static = (new ProductType())->setName('Статика');
        $video = (new ProductType())->setName('Видеоэкран')->setBookingMode(BookingMode::Airtime);
        $this->billboard = (new Product())->setName('Щит')->setCategory($category)->setProductType($static)
            ->addSide((new ProductSide())->setName('A'))
            ->addSide((new ProductSide())->setName('B'));
        $this->screen = (new Product())->setName('Экран')->setCategory($category)->setProductType($video)
            ->addSide((new ProductSide())->setName('A'));

        foreach ([$category, $static, $video, $this->billboard, $this->screen] as $entity) {
            $em->persist($entity);
        }
        $em->flush();
    }

    public function testFreeWithoutBookings(): void
    {
        $availability = $this->resolve($this->billboard);

        self::assertSame(AvailabilityStatus::Free, $availability->status());
        self::assertSame(['A', 'B'], array_map(fn ($s) => $s->sideName, $availability->sides));
    }

    public function testStructureIsFreeWhileAnySideCanBeSold(): void
    {
        $this->pay($this->book($this->billboard, 'A'));

        $availability = $this->resolve($this->billboard);
        self::assertSame(AvailabilityStatus::Occupied, $availability->sides[0]->status());
        self::assertSame(AvailabilityStatus::Free, $availability->sides[1]->status());
        self::assertSame(AvailabilityStatus::Free, $availability->status());
    }

    public function testBookedWhenEverySideIsTakenAndSomeAreUnpaid(): void
    {
        $this->pay($this->book($this->billboard, 'A'));
        $this->book($this->billboard, 'B'); // 24h hold

        $availability = $this->resolve($this->billboard);
        self::assertSame(AvailabilityStatus::Booked, $availability->sides[1]->status());
        self::assertSame(AvailabilityStatus::Booked, $availability->status());
    }

    public function testOccupiedWhenEverythingIsPaid(): void
    {
        $this->pay($this->book($this->billboard, 'A'));
        $this->pay($this->book($this->billboard, 'B'));

        self::assertSame(AvailabilityStatus::Occupied, $this->resolve($this->billboard)->status());
    }

    public function testExpiredHoldFreesTheSide(): void
    {
        $this->book($this->billboard, 'A');
        $this->book($this->billboard, 'B');
        self::assertSame(AvailabilityStatus::Booked, $this->resolve($this->billboard)->status());

        $this->clock->modify('+24 hours');
        self::assertSame(AvailabilityStatus::Free, $this->resolve($this->billboard)->status());
    }

    public function testStatusIsPerMonth(): void
    {
        $this->pay($this->book($this->billboard, 'A', '2026-10'));
        $this->pay($this->book($this->billboard, 'B', '2026-10'));

        self::assertSame(AvailabilityStatus::Free, $this->resolve($this->billboard)->status());
        self::assertSame(AvailabilityStatus::Occupied, $this->resolve($this->billboard, '2026-10-01')->status());
    }

    public function testAirtimeIsFreeUntilNotEvenAFiveSecondClipFits(): void
    {
        for ($i = 0; $i < 7; ++$i) {
            $this->pay($this->book($this->screen, 'A', clip: 15)); // 105 s paid
        }
        $this->book($this->screen, 'A', clip: 10); // 115 s used, 5 s left

        $side = $this->resolve($this->screen)->sides[0];
        self::assertSame(115, $side->usedSeconds());
        self::assertSame(95, $side->loadPercent()); // 115 of 120 s, rounded down: 100% only when the loop is full
        self::assertSame(AvailabilityStatus::Free, $side->status());

        $this->book($this->screen, 'A', clip: 5); // loop full, part of it on hold
        self::assertSame(AvailabilityStatus::Booked, $this->resolve($this->screen)->status());

        $this->clock->modify('+25 hours'); // holds (10 s + 5 s) expire
        self::assertSame(105, $this->resolve($this->screen)->sides[0]->usedSeconds());
        self::assertSame(AvailabilityStatus::Free, $this->resolve($this->screen)->status());
    }

    public function testAirtimeStatusUsesTheBusiestDayFromToday(): void
    {
        // the loop is full from the 20th to the 25th only
        for ($i = 0; $i < 8; ++$i) {
            $this->pay($this->bookDays('2026-09-20', '2026-09-25', 15));
        }
        self::assertSame(AvailabilityStatus::Occupied, $this->resolve($this->screen)->status());
        self::assertSame(120, $this->resolve($this->screen)->sides[0]->usedSeconds());
        self::assertSame(100, $this->resolve($this->screen)->sides[0]->loadPercent());

        // after the 25th the rest of September is free again
        $this->clock->modify('2026-09-26 09:00');
        self::assertSame(AvailabilityStatus::Free, $this->resolve($this->screen)->status());
        self::assertSame(0, $this->resolve($this->screen)->sides[0]->usedSeconds());
    }

    public function testEachSideUsesItsOwnType(): void
    {
        // a static structure with a video screen on side B
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->billboard->getSides()->last()->setProductType($em->getRepository(ProductType::class)->findOneBy(['name' => 'Видеоэкран']));
        $em->flush();

        $this->pay($this->book($this->billboard, 'A'));
        $this->pay($this->book($this->billboard, 'B', clip: 15));

        [$sideA, $sideB] = $this->resolve($this->billboard)->sides;
        self::assertFalse($sideA->airtime);
        self::assertSame(AvailabilityStatus::Occupied, $sideA->status());
        self::assertTrue($sideB->airtime);
        self::assertSame(15, $sideB->usedSeconds());
        self::assertSame(12, $sideB->loadPercent());
        self::assertSame(AvailabilityStatus::Free, $sideB->status()); // the rest of the loop is still for sale
        self::assertSame(AvailabilityStatus::Free, $this->resolve($this->billboard)->status());
    }

    public function testDaysBeforeTodayDoNotCount(): void
    {
        $this->pay($this->book($this->billboard, 'A'));
        $this->pay($this->book($this->billboard, 'B'));
        self::assertSame(AvailabilityStatus::Occupied, $this->resolve($this->billboard)->status());

        // a paid month is not over until its last day
        $this->clock->modify('2026-09-30 18:00');
        self::assertSame(AvailabilityStatus::Occupied, $this->resolve($this->billboard)->status());
        self::assertSame(AvailabilityStatus::Free, $this->resolve($this->billboard, '2026-10-01')->status());
    }

    public function testResolvesManyProductsAtOnce(): void
    {
        $this->pay($this->book($this->screen, 'A', clip: 15));

        $all = $this->resolver->forProducts([$this->screen->getId(), $this->billboard->getId()], new \DateTimeImmutable(self::SEPTEMBER));

        self::assertSame([$this->screen->getId(), $this->billboard->getId()], array_keys($all));
        self::assertSame(15, $all[$this->screen->getId()]->sides[0]->paidSeconds);
        self::assertSame(0, $all[$this->billboard->getId()]->sides[0]->paidSeconds);
    }

    private function resolve(Product $product, string $month = self::SEPTEMBER): \App\Service\Availability\ProductAvailability
    {
        return $this->resolver->forProduct($product->getId(), new \DateTimeImmutable($month));
    }

    private function book(Product $product, string $side, string $month = '2026-09', ?int $clip = null): Booking
    {
        $request = new BookingRequest();
        $request->side = $product->getSides()->filter(fn (ProductSide $s) => $s->getName() === $side)->first();
        $request->startMonth = $month;
        $request->clipDuration = $clip;
        $request->clientName = 'Клиент';
        $request->clientPhone = '123';

        return $this->bookings->hold($request);
    }

    private function bookDays(string $from, string $to, int $clip): Booking
    {
        $request = new BookingRequest();
        $request->side = $this->screen->getSides()->first();
        $request->startDate = new \DateTimeImmutable($from);
        $request->endDate = new \DateTimeImmutable($to);
        $request->clipDuration = $clip;
        $request->clientName = 'Клиент';
        $request->clientPhone = '123';

        return $this->bookings->hold($request);
    }

    private function pay(Booking $booking): void
    {
        $this->bookings->markPaid($booking);
    }
}
