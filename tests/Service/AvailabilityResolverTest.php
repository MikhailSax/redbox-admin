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
use App\Entity\User;
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
    private User $client;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->clock = self::mockTime('2026-09-10 12:00:00');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->resolver = static::getContainer()->get(AvailabilityResolver::class);
        $this->bookings = static::getContainer()->get(BookingManager::class);

        foreach ([Booking::class, ProductSidePhoto::class, ProductSide::class, Product::class, ProductType::class, Category::class, District::class, User::class] as $class) {
            $em->createQuery(\sprintf('DELETE FROM %s e', $class))->execute();
        }

        $this->client = (new User())->setEmail('client@romashka.ru')->setName('Иван Петров')->setCompany('ООО Ромашка')
            ->setRole(User::ROLE_CLIENT)->setPassword('x')->setEmailVerifiedAt(new \DateTimeImmutable());
        $em->persist($this->client);

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

    public function testScreenIsFreeWhileItHasAFreeSlot(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $this->pay($this->book($this->screen, 'A', slots: 2)); // 10 slots paid
        }
        $this->book($this->screen, 'A', slots: 1); // 11 of 12 taken

        $side = $this->resolve($this->screen)->sides[0];
        self::assertSame(11, $side->usedSlots());
        self::assertSame(12, $side->slotCount);
        self::assertSame(91, $side->loadPercent()); // rounded down: 100% only when every slot is taken
        self::assertSame(AvailabilityStatus::Free, $side->status());

        $this->book($this->screen, 'A', slots: 1); // every slot taken, part of them on hold
        self::assertSame(AvailabilityStatus::Booked, $this->resolve($this->screen)->status());

        $this->clock->modify('+25 hours'); // the holds (2 slots) expire
        self::assertSame(10, $this->resolve($this->screen)->sides[0]->usedSlots());
        self::assertSame(AvailabilityStatus::Free, $this->resolve($this->screen)->status());
    }

    public function testAirtimeStatusUsesTheBusiestDayFromToday(): void
    {
        // every slot is taken from the 13th to the 26th only
        for ($i = 0; $i < 12; ++$i) {
            $this->pay($this->bookDays('2026-09-13', '2026-09-26', 1));
        }
        self::assertSame(AvailabilityStatus::Occupied, $this->resolve($this->screen)->status());
        self::assertSame(12, $this->resolve($this->screen)->sides[0]->usedSlots());
        self::assertSame(100, $this->resolve($this->screen)->sides[0]->loadPercent());

        // after the 26th the rest of September is free again
        $this->clock->modify('2026-09-27 09:00');
        self::assertSame(AvailabilityStatus::Free, $this->resolve($this->screen)->status());
        self::assertSame(0, $this->resolve($this->screen)->sides[0]->usedSlots());
    }

    public function testEachSideUsesItsOwnType(): void
    {
        // a static structure with a video screen on side B
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->billboard->getSides()->last()->setProductType($em->getRepository(ProductType::class)->findOneBy(['name' => 'Видеоэкран']));
        $em->flush();

        $this->pay($this->book($this->billboard, 'A'));
        $this->pay($this->book($this->billboard, 'B', slots: 3));

        [$sideA, $sideB] = $this->resolve($this->billboard)->sides;
        self::assertFalse($sideA->airtime);
        self::assertSame(AvailabilityStatus::Occupied, $sideA->status());
        self::assertTrue($sideB->airtime);
        self::assertSame(3, $sideB->usedSlots());
        self::assertSame(25, $sideB->loadPercent());
        self::assertSame(AvailabilityStatus::Free, $sideB->status()); // the rest of the block is still for sale
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
        $this->pay($this->book($this->screen, 'A', slots: 3));

        $all = $this->resolver->forProducts([$this->screen->getId(), $this->billboard->getId()], new \DateTimeImmutable(self::SEPTEMBER));

        self::assertSame([$this->screen->getId(), $this->billboard->getId()], array_keys($all));
        self::assertSame(3, $all[$this->screen->getId()]->sides[0]->paidSlots);
        self::assertSame(0, $all[$this->billboard->getId()]->sides[0]->paidSlots);
    }

    private function resolve(Product $product, string $month = self::SEPTEMBER): \App\Service\Availability\ProductAvailability
    {
        return $this->resolver->forProduct($product->getId(), new \DateTimeImmutable($month));
    }

    private function book(Product $product, string $side, string $month = '2026-09', ?int $slots = null): Booking
    {
        $request = new BookingRequest();
        $request->side = $product->getSides()->filter(fn (ProductSide $s) => $s->getName() === $side)->first();
        $request->startMonth = $month;
        $request->slots = $slots;
        $request->client = $this->client;

        return $this->bookings->hold($request);
    }

    private function bookDays(string $from, string $to, int $slots): Booking
    {
        $request = new BookingRequest();
        $request->side = $this->screen->getSides()->first();
        $request->startDate = new \DateTimeImmutable($from);
        $request->endDate = new \DateTimeImmutable($to);
        $request->slots = $slots;
        $request->client = $this->client;

        return $this->bookings->hold($request);
    }

    private function pay(Booking $booking): void
    {
        $this->bookings->markPaid($booking);
    }
}
