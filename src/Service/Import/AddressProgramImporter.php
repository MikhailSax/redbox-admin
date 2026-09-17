<?php

namespace App\Service\Import;

use App\Entity\Category;
use App\Entity\District;
use App\Entity\Product;
use App\Entity\ProductSide;
use App\Entity\ProductSidePhoto;
use App\Entity\ProductType;
use App\Enum\BookingMode;
use App\Helpers\ProductHelper;
use App\Service\SidePhotoStorage;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Turns address programme rows into structures with sides.
 *
 * Rows are grouped into one structure by address + format: one address may hold structures of different kinds
 * (a digital side A and a static side B), and a structure has a single type. Running the import again updates
 * numbers, sizes, prices and coordinates of the structures it created before (matched by name, category and type,
 * or by name alone when a single structure has it, so a category or type changed by hand does not make a copy).
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

    /** Types sold by airtime (5 s of the loop); created with that booking mode when missing */
    private const AIRTIME_TYPES = ['Видеоэкран'];

    /** Latin letters typed instead of Cyrillic in side names */
    private const SIDE_LETTERS = ['A' => 'А', 'B' => 'В', 'C' => 'С'];

    /** @var array<string, Category|ProductType|District> class|name => entity */
    private array $dictionary = [];

    /** @var array<int, true> ids of structures already matched in this run */
    private array $matched = [];

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

        /** @var array<string, array{category: string, type: string, rows: non-empty-list<AddressProgramRow>, coordinates?: array{0: string, 1: string}|null}> $groups */
        $groups = [];
        foreach ($rows as $row) {
            $kind = self::kind($row->format);
            if (null === $kind) {
                $report->skipped[$row->line] = \sprintf('неизвестный формат «%s»', $row->format);
                continue;
            }
            if (null !== $row->bookingNote) {
                ++$report->bookingNotes;
            }
            $key = mb_strtolower($row->address).'|'.$kind[0].'|'.$kind[1];
            $groups[$key] ??= ['category' => $kind[0], 'type' => $kind[1], 'rows' => []];
            $groups[$key]['rows'][] = $row;
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
            foreach ($groups as $group) {
                $this->importStructure($group['rows'], $group['category'], $group['type'], $group['coordinates'], $dryRun, $report);
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
     * @param non-empty-list<AddressProgramRow>  $rows        sides of one structure
     * @param array{0: string, 1: string}|null $coordinates [latitude, longitude] from the map link
     */
    private function importStructure(array $rows, string $categoryName, string $typeName, ?array $coordinates, bool $dryRun, AddressProgramReport $report): void
    {
        $first = $rows[0];
        $category = $this->dictionary(Category::class, $categoryName, 'Категория', $report);
        $type = $this->dictionary(ProductType::class, $typeName, 'Тип', $report);

        $product = $this->findProduct($first->address, $category, $type);
        if (null === $product) {
            $product = (new Product())->setName($first->address)->setCategory($category)->setProductType($type);
            $this->entityManager->persist($product);
            ++$report->productsCreated;
        } else {
            $this->matched[(int) $product->getId()] = true;
            ++$report->productsUpdated;
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
            foreach ($sides as $side) {
                $side->setPrice(null !== $price && $price !== $basePrice ? $price : null);
            }
            if (null === $price) {
                $report->withoutPrice[] = \sprintf('%s, сторона %s', $row->address, $sideName);
            }

            if (null !== ($photoUrl = self::link($row->photoUrl))) {
                $this->importPhoto($sides, $photoUrl, $dryRun, \sprintf('%s, сторона %s', $row->address, $sideName), $report);
            }
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
     * By name, category and type; otherwise by name alone when exactly one structure not matched yet has it.
     */
    private function findProduct(string $name, Category $category, ProductType $type): ?Product
    {
        $repository = $this->entityManager->getRepository(Product::class);
        if (null !== $category->getId() && null !== $type->getId()
            && null !== ($product = $repository->findOneBy(['name' => $name, 'category' => $category, 'productType' => $type]))) {
            return $product;
        }

        $sameName = array_values(array_filter(
            $repository->findBy(['name' => $name]),
            fn (Product $product) => !isset($this->matched[(int) $product->getId()]),
        ));

        return 1 === \count($sameName) ? $sameName[0] : null;
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

    /** The cell when it holds a link; notes like "установка июнь-июль" are ignored */
    private static function link(?string $cell): ?string
    {
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
}
