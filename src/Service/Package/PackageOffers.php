<?php

namespace App\Service\Package;

use App\Entity\Package;
use App\Entity\PackageSlot;
use App\Entity\ProductSide;
use App\Service\BookingManager;
use App\Service\MediaPlanManager;
use App\Service\MonthCalendar;
use Symfony\Component\Clock\ClockInterface;

/**
 * What a package offer gives for a period: the sides of each position that are free all the period,
 * a ready set the website shows first, and the check of the set the client sends back.
 *
 * A side is free when it could be booked for the whole period right now (for a screen, one slot of its block);
 * nothing is held here: the manager books the request as usual.
 */
class PackageOffers
{
    /** @var array<string, bool> side id and period => free, for one request */
    private array $free = [];

    public function __construct(
        private readonly BookingManager $bookings,
        private readonly ClockInterface $clock,
    ) {
    }

    /** Packages start on the 1st of next month unless the client picks a day */
    public function defaultStart(): \DateTimeImmutable
    {
        return MonthCalendar::firstDay($this->clock->now())->modify('+1 month');
    }

    /**
     * [first day, last day] of $months months from $from: from the 15th of November for 2 months is up to the 14th of January.
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    public static function period(\DateTimeImmutable $from, int $months): array
    {
        $from = $from->setTime(0, 0);

        return [$from, $from->modify(\sprintf('+%d months', $months))->modify('-1 day')];
    }

    /**
     * The free sides of every position for $months months from $from ($months is brought into the package's range).
     */
    public function offer(Package $package, \DateTimeImmutable $from, ?int $months = null): PackageOffer
    {
        $months = $package->clampMonths($months);
        [$from, $to] = self::period($from, $months);
        $tooEarly = $from < $this->clock->now()->setTime(0, 0);

        $taken = [];
        $slots = [];
        foreach ($package->getSlots() as $slot) {
            $free = $tooEarly ? [] : array_values(array_filter(self::sorted($slot), fn (ProductSide $side) => $this->isFree($side, $from, $to)));

            // a side goes into one position only: the ready set takes the first free ones not taken above
            $suggested = \array_slice(array_values(array_filter($free, static fn (ProductSide $side) => !isset($taken[$side->getId()]))), 0, (int) $slot->getQuantity());
            if (\count($suggested) < $slot->getQuantity()) {
                $suggested = [];
            }
            foreach ($suggested as $side) {
                $taken[$side->getId()] = true;
            }

            $slots[] = new SlotOffer($slot, $free, $suggested, $months);
        }

        return new PackageOffer($package, $from, $to, $months, $slots);
    }

    /**
     * Checks the set the client chose: every position gets exactly its number of sides, each from its list,
     * no side twice, and all of them free for the period.
     *
     * @param array<int, list<int>> $selection position id => side ids
     *
     * @return list<array{slot: PackageSlot, side: ProductSide, price: float}> in the order of the positions
     *
     * @throws PackageException with a message for the client
     */
    public function select(Package $package, \DateTimeImmutable $from, int $months, array $selection): array
    {
        if (!$package->isRunningOn($this->clock->now())) {
            throw new PackageException(\sprintf('Пакет «%s» сейчас не действует.', $package->getTitle()));
        }
        if (!$package->allowsMonths($months)) {
            throw new PackageException(\sprintf('Пакет «%s» — на срок %s.', $package->getTitle(), $package->getMonthsLabel()));
        }
        [$from, $to] = self::period($from, $months);
        if ($from < $this->clock->now()->setTime(0, 0)) {
            throw new PackageException('Начало размещения уже прошло — выберите другую дату.');
        }

        foreach (array_keys($selection) as $slotId) {
            if (null === $package->getSlot((int) $slotId)) {
                throw new PackageException(\sprintf('В пакете «%s» нет такой позиции. Обновите страницу и попробуйте снова.', $package->getTitle()));
            }
        }

        $lines = [];
        $seen = [];
        foreach ($package->getSlots() as $slot) {
            $sideIds = array_values(array_unique(array_map('intval', $selection[$slot->getId()] ?? [])));
            if (\count($sideIds) !== $slot->getQuantity()) {
                throw new PackageException(\sprintf('«%s»: нужно выбрать %d, выбрано %d.', $slot->getTitle(), $slot->getQuantity(), \count($sideIds)));
            }

            foreach ($sideIds as $sideId) {
                $side = self::find($slot, $sideId);
                if (null === $side || !$side->isWorking()) {
                    throw new PackageException(\sprintf('«%s»: этой конструкции нет в пакете. Обновите страницу и попробуйте снова.', $slot->getTitle()));
                }
                if (isset($seen[$sideId])) {
                    throw new PackageException(\sprintf('Конструкция «%s», сторона %s выбрана дважды.', $side->getProduct()?->getName(), $side->getName()));
                }
                if (!$this->isFree($side, $from, $to)) {
                    throw new PackageException(\sprintf('«%s», сторона %s уже занята на %s. Выберите другую.', $side->getProduct()?->getName(), $side->getName(), MonthCalendar::periodLabel($from, $to)));
                }
                $seen[$sideId] = true;
                $lines[] = ['slot' => $slot, 'side' => $side, 'price' => $slot->priceFor($side, $months)];
            }
        }

        return $lines;
    }

    private function isFree(ProductSide $side, \DateTimeImmutable $from, \DateTimeImmutable $to): bool
    {
        $key = $side->getId().'|'.$from->format('Y-m-d').'|'.$to->format('Y-m-d');

        return $this->free[$key] ??= $side->isWorking()
            && null === $this->bookings->availabilityProblem($side, $from, $to, MediaPlanManager::slotsFor($side, null));
    }

    /**
     * The position's sides by structure and side name.
     *
     * @return list<ProductSide>
     */
    private static function sorted(PackageSlot $slot): array
    {
        $sides = $slot->getSides()->toArray();
        usort($sides, static fn (ProductSide $a, ProductSide $b) => [$a->getProduct()?->getName(), $a->getName()] <=> [$b->getProduct()?->getName(), $b->getName()]);

        return $sides;
    }

    private static function find(PackageSlot $slot, int $sideId): ?ProductSide
    {
        foreach ($slot->getSides() as $side) {
            if ($side->getId() === $sideId) {
                return $side;
            }
        }

        return null;
    }
}
