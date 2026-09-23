<?php

namespace App\Service\Import;

use App\Entity\Booking;
use App\Entity\Category;
use App\Entity\District;
use App\Entity\LeadItem;
use App\Entity\MediaPlanItem;
use App\Entity\Product;
use App\Entity\ProductSide;
use App\Entity\ProductSidePhoto;
use App\Entity\ProductType;
use App\Entity\Promotion;
use App\Enum\BookingMode;
use App\Helpers\ProductHelper;
use App\Service\SidePhotoStorage;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Turns address programme rows into structures with sides.
 *
 * All rows of one address make one structure, whatever the table they are in: a digital side A and a static
 * side B of "ул. Ботаническая, 2" are one supersite whose sides differ in type. The structure takes the type of its
 * first row (the digital table comes first); a side of another kind gets a type of its own. Addresses are compared
 * loosely ("ул.Ботаническая,2" = "ул. Ботаническая, 2", "д. 65 "Тамир"" = "д. 65 (Тамир)").
 *
 * Running the import again updates numbers, sizes, prices and coordinates of the structures it finds by address
 * (or, failing that, by a scheme number only one structure has). Several structures found for one address, e.g. made
 * by an earlier import that split an address by type, are joined into one: the one of the first row's type stays
 * and the others' sides move to it with their bookings. Category and type of a structure found are left as set by hand.
 */
class AddressProgramImporter
{
    /** format text (lower case) contains => [category, type]; the first match wins */
    public const FORMATS = [
        'диджитал суперсайт' => ['Суперсайт', 'Видеоэкран'],
        'диджитал билборд' => ['Билборд', 'Видеоэкран'],
        'диджитал сити' => ['Сити-формат', 'Видеоэкран'],
        'суперсайт' => ['Суперсайт', 'Статика'],
        'призматрон' => ['Билборд', 'Призматрон'],
        'щит' => ['Билборд', 'Статика'],
        'сити' => ['Сити-формат', 'Статика'],
        'фасад' => ['Фасад', 'Статика'],
    ];

    /** Types sold by airtime (slots of the block); created with that booking mode when missing */
    private const AIRTIME_TYPES = ['Видеоэкран'];

    /** Latin letters typed instead of Cyrillic in side names */
    private const SIDE_LETTERS = ['A' => 'А', 'B' => 'В', 'C' => 'С'];

    /** Words that don't tell addresses apart: "ул.", "у." (a typo), "д." */
    private const ADDRESS_NOISE = ['ул', 'у', 'улица', 'д', 'дом'];

    /** @var array<string, Category|ProductType|District> class|name => entity */
    private array $dictionary = [];

    /** @var array<int, true> ids of structures already matched in this run */
    private array $matched = [];

    /** @var list<Product>|null every structure, loaded once per run; new ones are added */
    private ?array $products = null;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MapLinkCoordinates $mapLinks,
        private readonly PhotoDownloader $photos,
        private readonly SidePhotoStorage $photoStorage,
    ) {
    }

    /**
     * @param list<AddressProgramRow> $rows
     */
    public function import(array $rows, bool $dryRun = false): AddressProgramReport
    {
        $report = new AddressProgramReport();
        $this->dictionary = [];
        $this->matched = [];
        $this->products = null;

        /** @var array<string, array{rows: non-empty-list<AddressProgramRow>, coordinates?: array{0: string, 1: string}|null}> $groups */
        $groups = [];
        foreach ($rows as $row) {
            if (null === self::kind($row->format)) {
                $report->skipped[$row->line] = \sprintf('неизвестный формат «%s»', $row->format);
                continue;
            }
            if (null !== $row->bookingNote) {
                ++$report->bookingNotes;
            }
            $groups[self::addressKey($row->address)]['rows'][] = $row;
        }

        // Short map links go over the network: resolved before the transaction is opened
        foreach ($groups as &$group) {
            $url = self::mapUrl($group['rows']);
            $group['coordinates'] = null !== $url ? $this->mapLinks->resolve($url) : null;
            if (null !== $url && null === $group['coordinates']) {
                $report->coordinatesFailed[] = \sprintf('%s: %s', $group['rows'][0]->address, $url);
            }
        }
        unset($group);

        // Photos too, into temporary files (a dry run downloads nothing)
        if (!$dryRun) {
            foreach ($rows as $row) {
                if (null !== ($url = self::link($row->photoUrl))) {
                    $this->photos->download($url);
                }
            }
        }

        $import = function () use ($groups, $report, $dryRun): void {
            foreach ($groups as $key => $group) {
                $this->importStructure((string) $key, $group['rows'], $group['coordinates'], $dryRun, $report);
            }
        };

        try {
            if ($dryRun) {
                // Nothing is flushed; the changes are dropped with the unit of work
                $import();
                $this->entityManager->clear();
            } else {
                $this->entityManager->wrapInTransaction($import); // flushes and commits, or rolls back on error
            }
        } finally {
            $this->photos->cleanup();
        }

        return $report;
    }

    /**
     * @return array{0: string, 1: string}|null [category, type]
     */
    public static function kind(string $format): ?array
    {
        $format = mb_strtolower($format);
        foreach (self::FORMATS as $needle => $kind) {
            if (str_contains($format, $needle)) {
                return $kind;
            }
        }

        return null;
    }

    /**
     * What tells one address from another: letters and digits without "ул.", "д.", punctuation and quotes.
     * "ул.Бабушкина, д. 65 "Тамир"" and "ул.Бабушкина, д. 65 (Тамир)" are both "бабушкина65тамир".
     */
    public static function addressKey(string $address): string
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', str_replace('ё', 'е', mb_strtolower($address)), -1, \PREG_SPLIT_NO_EMPTY) ?: [];

        return implode('', array_diff($words, self::ADDRESS_NOISE));
    }

    /**
     * @param non-empty-list<AddressProgramRow>  $rows        sides of one structure
     * @param array{0: string, 1: string}|null $coordinates [latitude, longitude] from the map link
     */
    private function importStructure(string $key, array $rows, ?array $coordinates, bool $dryRun, AddressProgramReport $report): void
    {
        $first = $rows[0];
        [$categoryName, $typeName] = self::kind($first->format);
        $type = $this->dictionary(ProductType::class, $typeName, 'Тип', $report);

        $product = $this->findProduct($key, $rows, $type, $dryRun, $report);
        if (null === $product) {
            $category = $this->dictionary(Category::class, $categoryName, 'Категория', $report);
            $product = (new Product())->setName($first->address)->setCategory($category)->setProductType($type);
            $this->entityManager->persist($product);
            $this->products[] = $product;
            ++$report->productsCreated;
        } else {
            ++$report->productsUpdated;
        }
        if (null !== $product->getId()) {
            $this->matched[$product->getId()] = true;
        }

        if (null !== $coordinates) {
            [$latitude, $longitude] = $coordinates;
            $product->setLatitude($latitude)->setLongitude($longitude);
            ++$report->coordinatesSet;
        }

        $product->setDistrict($this->dictionary(District::class, self::districtName($first), 'Район', $report));
        if (null !== ($number = self::schemeNumber($rows))) {
            $product->setSchemeNumber($number);
        }
        if (null !== ($size = self::size($rows, $categoryName))) {
            $product->setSize($size);
        }

        // The structure price is the lowest side price; sides that cost more keep their own
        $prices = array_values(array_filter(array_map(static fn (AddressProgramRow $row) => self::price($row->price), $rows)));
        $basePrice = [] !== $prices ? min($prices) : $product->getPrice();
        $product->setPrice($basePrice);

        foreach ($rows as $row) {
            $sideName = self::sideName($row->side);
            $label = \sprintf('%s, сторона %s', $row->address, $sideName);
            $sides = $product->getSides()->filter(static fn (ProductSide $s) => self::sideName((string) $s->getName()) === $sideName)->getValues();
            if ([] !== $sides) {
                $sides[0]->setName($sideName); // "B3" typed in Latin becomes "В3"
            } else {
                // A prismatron split into faces by hand ("А1", "А2", "А3") is still listed as "А" in the price list
                $sides = $product->getSides()->filter(static fn (ProductSide $s) => 1 === preg_match('/^'.preg_quote($sideName, '/').'\d+$/u', self::sideName((string) $s->getName())))->getValues();
            }
            if ([] === $sides) {
                $sides = [(new ProductSide())->setName($sideName)];
                $product->addSide($sides[0]);
                ++$report->sidesCreated;
            } else {
                ++$report->sidesUpdated;
            }

            $price = self::price($row->price);
            $rowType = $this->dictionary(ProductType::class, self::kind($row->format)[1], 'Тип', $report);
            [$printPrice, $printNote] = self::printCost($row->printText);
            foreach ($sides as $side) {
                $side->setPrice(null !== $price && $price !== $basePrice ? $price : null)
                    ->setPrice2Weeks(self::price($row->price2Weeks))
                    ->setPrice3Months(self::price($row->price3Months))
                    ->setPrice6Months(self::price($row->price6Months))
                    ->setPrintPrice($printPrice)
                    ->setPrintNote($printNote);
                $this->applySideType($side, $product, $rowType, $label, $report);
                if ($side->isAirtime()) {
                    self::applySlots($side, $row);
                }
            }
            if (null === $price) {
                $report->withoutPrice[] = $label;
            }

            if (null !== ($photoUrl = self::link($row->photoUrl))) {
                $this->importPhoto($sides, $photoUrl, $dryRun, $label, $report);
            }
        }
    }

    /**
     * The side follows the structure's type when it is of the same kind as the row, otherwise gets the row's type.
     * A type set by hand of the same kind stays ("Видеоэкран 12*4" is still a video screen).
     */
    private function applySideType(ProductSide $side, Product $product, ProductType $rowType, string $label, AddressProgramReport $report): void
    {
        if (self::sameKind($side->getEffectiveProductType(), $rowType)) {
            return;
        }

        $side->setProductType(self::sameKind($product->getProductType(), $rowType) ? null : $rowType);
        $report->sideTypes[] = \sprintf('%s: %s', $label, $side->getEffectiveProductType()?->getName());
    }

    private static function sameKind(?ProductType $type, ProductType $rowType): bool
    {
        if (null === $type) {
            return false;
        }

        return $type === $rowType
            || str_starts_with(mb_strtolower((string) $type->getName()), mb_strtolower((string) $rowType->getName()))
            || ($type->getBookingMode()->isAirtime() && $rowType->getBookingMode()->isAirtime());
    }

    /** "5 сек" and a "60 сек" block: 12 slots of 5 s; left as is when the columns are empty or odd */
    private static function applySlots(ProductSide $side, AddressProgramRow $row): void
    {
        $slot = (int) preg_replace('/\D+/', '', (string) $row->slotText);
        if (!\in_array($slot, BookingMode::SLOT_DURATIONS, true)) {
            return;
        }
        $side->setSlotSeconds($slot);

        $block = (int) preg_replace('/\D+/', '', (string) $row->blockText);
        if ($block > 0 && 0 === $block % $slot && $block / $slot <= BookingMode::MAX_SLOT_COUNT) {
            $side->setSlotCount(intdiv($block, $slot));
        }
    }

    /**
     * @param non-empty-list<ProductSide> $sides
     */
    private function importPhoto(array $sides, string $url, bool $dryRun, string $label, AddressProgramReport $report): void
    {
        $name = PhotoDownloader::originalName($url);
        if ($dryRun) {
            foreach ($sides as $side) {
                if (!$side->getPhotos()->exists(static fn (int|string $i, ProductSidePhoto $p) => $p->getOriginalName() === $name)) {
                    ++$report->photosAdded;
                }
            }

            return;
        }

        if (null === ($file = $this->photos->download($url))) {
            $report->photosFailed[] = \sprintf('%s: %s', $label, $url);

            return;
        }
        foreach ($sides as $side) {
            if (null !== $this->photoStorage->attachCopy($side, $file, $name)) {
                ++$report->photosAdded;
            }
        }
    }

    /**
     * @template T of Category|ProductType|District
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function dictionary(string $class, string $name, string $label, AddressProgramReport $report): object
    {
        $key = $class.'|'.mb_strtolower($name);
        if (isset($this->dictionary[$key])) {
            return $this->dictionary[$key];
        }

        $entity = $this->entityManager->getRepository($class)->findOneBy(['name' => $name]);
        if (null === $entity) {
            $entity = (new $class())->setName($name);
            if ($entity instanceof ProductType && \in_array($name, self::AIRTIME_TYPES, true)) {
                $entity->setBookingMode(BookingMode::Airtime);
            }
            $this->entityManager->persist($entity);
            $report->dictionariesCreated[] = \sprintf('%s «%s»', $label, $name);
        }

        return $this->dictionary[$key] = $entity;
    }

    /**
     * The structure of the address; several are joined into the one of the first row's type (then the one with most
     * sides, then the oldest). Without one by address: the only structure with the rows' scheme number.
     *
     * @param non-empty-list<AddressProgramRow> $rows
     */
    private function findProduct(string $key, array $rows, ProductType $type, bool $dryRun, AddressProgramReport $report): ?Product
    {
        $this->products ??= $this->entityManager->getRepository(Product::class)->findBy([], ['id' => 'ASC']);
        // structures created in this run are taken already
        $free = array_values(array_filter($this->products, fn (Product $p) => null !== $p->getId() && !isset($this->matched[$p->getId()])));

        $found = array_values(array_filter($free, static fn (Product $p) => self::addressKey((string) $p->getName()) === $key));
        if ([] === $found && null !== ($number = self::schemeNumber($rows))) {
            $byNumber = array_values(array_filter($free, static fn (Product $p) => $p->getSchemeNumber() === $number));
            $found = 1 === \count($byNumber) ? $byNumber : [];
        }
        if ([] === $found) {
            return null;
        }

        usort($found, static fn (Product $a, Product $b) => [self::sameKind($b->getProductType(), $type), $b->getSides()->count(), -(int) $b->getId()]
            <=> [self::sameKind($a->getProductType(), $type), $a->getSides()->count(), -(int) $a->getId()]);
        $product = array_shift($found);
        foreach ($found as $other) {
            $this->merge($other, $product, $dryRun, $report);
        }

        return $product;
    }

    /**
     * Moves the sides of $from to $into and removes $from. A side $into already has (same name) is dropped
     * after its bookings, media plan items and requests are moved to the one kept.
     */
    private function merge(Product $from, Product $into, bool $dryRun, AddressProgramReport $report): void
    {
        foreach ($from->getSides()->toArray() as $side) {
            $name = self::sideName((string) $side->getName());
            $twin = $into->getSides()->findFirst(static fn (int|string $i, ProductSide $s) => self::sideName((string) $s->getName()) === $name);
            $from->removeSide($side);
            if (null === $twin) {
                // it followed its old structure's type: keep that explicitly, the rows decide later
                $side->setProductType($side->getEffectiveProductType());
                $into->addSide($side); // cancels the orphan removal
            } elseif (!$dryRun) {
                $this->moveReferences($side, $twin);
            }
        }

        $promotions = $this->entityManager->createQuery('SELECT p FROM '.Promotion::class.' p JOIN p.products x WHERE x = :product')
            ->setParameter('product', $from)
            ->getResult();
        foreach ($promotions as $promotion) {
            $promotion->addProduct($into);
        }

        $this->matched[(int) $from->getId()] = true;
        $this->entityManager->remove($from);
        $report->merged[] = \sprintf('«%s» #%d → «%s» #%d', $from->getName(), $from->getId(), $into->getName(), $into->getId());
    }

    /**
     * Bookings, media plan items and requests of $from now point at $to ($from is about to be deleted).
     * A media plan that has both sides keeps the item of $to.
     */
    private function moveReferences(ProductSide $from, ProductSide $to): void
    {
        $plansWithTo = $this->entityManager->createQuery('SELECT IDENTITY(i.plan) FROM '.MediaPlanItem::class.' i WHERE i.side = :to')
            ->setParameter('to', $to)
            ->getSingleColumnResult();
        if ([] !== $plansWithTo) {
            $this->entityManager->createQuery('DELETE FROM '.MediaPlanItem::class.' i WHERE i.side = :from AND i.plan IN (:plans)')
                ->setParameter('from', $from)
                ->setParameter('plans', $plansWithTo)
                ->execute();
        }

        foreach ([Booking::class, MediaPlanItem::class, LeadItem::class] as $class) {
            $this->entityManager->createQuery(\sprintf('UPDATE %s e SET e.side = :to WHERE e.side = :from', $class))
                ->setParameter('to', $to)
                ->setParameter('from', $from)
                ->execute();
        }
    }

    /**
     * @param list<AddressProgramRow> $rows
     */
    private static function mapUrl(array $rows): ?string
    {
        foreach ($rows as $row) {
            if (null !== ($url = self::link($row->mapUrl))) {
                return $url;
            }
        }

        return null;
    }

    /** The cell when it holds a link; notes like "установка июнь-июль" are ignored; "…jpg," loses the comma */
    private static function link(?string $cell): ?string
    {
        $cell = null !== $cell ? rtrim($cell, ',; ') : null;

        return null !== $cell && preg_match('~^https?://\S+$~i', $cell) ? $cell : null;
    }

    private static function districtName(AddressProgramRow $row): string
    {
        $address = mb_strtolower($row->address);

        return match (true) {
            AddressProgramRow::REGION_CITY === $row->region => 'Улан-Удэ',
            str_contains($address, 'иволг') => 'Иволгинский район',
            str_contains($address, 'гусиноозерск') => 'Селенгинский район',
            default => 'Районы Республики',
        };
    }

    /**
     * @param list<AddressProgramRow> $rows
     */
    private static function schemeNumber(array $rows): ?string
    {
        foreach ($rows as $row) {
            $number = trim((string) $row->number);
            if ('' !== $number && !\in_array(mb_strtolower($number), ['б/н', '-', '—'], true)) {
                return $number;
            }
        }

        return null;
    }

    /**
     * From the size column of the digital table, otherwise from the format text ("Щит 6Х3");
     * city formats are 1.2 × 1.8 m by definition.
     *
     * @param list<AddressProgramRow> $rows
     */
    private static function size(array $rows, string $category): ?string
    {
        foreach ($rows as $row) {
            $size = ProductHelper::sizeFromText($row->sizeText) ?? ProductHelper::sizeFromText($row->format);
            if (null !== $size) {
                return $size;
            }
        }

        return 'Сити-формат' === $category ? ProductHelper::SIZE_1_2X1_8 : null;
    }

    private static function sideName(string $side): string
    {
        return strtr(mb_strtoupper(trim($side)), self::SIDE_LETTERS);
    }

    /** "25 000,00" / "23000" → "25000.00"; null when empty or not a number */
    private static function price(?string $value): ?string
    {
        $value = str_replace([' ', ','], ['', '.'], (string) $value);

        return is_numeric($value) && (float) $value > 0 ? number_format((float) $value, 2, '.', '') : null;
    }

    /**
     * "3 500,00 (баннер)" → ["3500.00", "баннер"]; "11 177,00 (баннер, люверсы, усиление)" keeps the whole note.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private static function printCost(?string $value): array
    {
        if (null === $value || !preg_match('/^\s*([\d\s.,]+?)\s*(?:\((.+)\))?\s*$/u', $value, $m)) {
            return [null, null];
        }

        return [self::price($m[1]), '' !== ($m[2] ?? '') ? trim($m[2]) : null];
    }
}
