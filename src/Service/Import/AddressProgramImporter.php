<?php

namespace App\Service\Import;

use App\Entity\Category;
use App\Entity\District;
use App\Entity\Product;
use App\Entity\ProductSide;
use App\Entity\ProductType;
use App\Enum\BookingMode;
use App\Helpers\ProductHelper;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Turns address programme rows into structures with sides.
 *
 * Rows are grouped into one structure by address + format: one address may hold structures of different kinds
 * (a digital side A and a static side B), and a structure has a single type. Running the import again updates
 * numbers, sizes and prices of the structures it created before (matched by name, category and type).
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

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param list<AddressProgramRow> $rows
     */
    public function import(array $rows, bool $dryRun = false): AddressProgramReport
    {
        $report = new AddressProgramReport();
        $this->dictionary = [];

        /** @var array<string, array{category: string, type: string, rows: non-empty-list<AddressProgramRow>}> $groups */
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

        $import = function () use ($groups, $report): void {
            foreach ($groups as $group) {
                $this->importStructure($group['rows'], $group['category'], $group['type'], $report);
            }
        };

        if ($dryRun) {
            // Nothing is flushed; the changes are dropped with the unit of work
            $import();
            $this->entityManager->clear();
        } else {
            $this->entityManager->wrapInTransaction($import); // flushes and commits, or rolls back on error
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
     * @param non-empty-list<AddressProgramRow> $rows sides of one structure
     */
    private function importStructure(array $rows, string $categoryName, string $typeName, AddressProgramReport $report): void
    {
        $first = $rows[0];
        $category = $this->dictionary(Category::class, $categoryName, 'Категория', $report);
        $type = $this->dictionary(ProductType::class, $typeName, 'Тип', $report);

        $product = null !== $category->getId() && null !== $type->getId()
            ? $this->entityManager->getRepository(Product::class)->findOneBy(['name' => $first->address, 'category' => $category, 'productType' => $type])
            : null;
        if (null === $product) {
            $product = (new Product())->setName($first->address)->setCategory($category)->setProductType($type);
            $this->entityManager->persist($product);
            ++$report->productsCreated;
        } else {
            ++$report->productsUpdated;
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
            $side = $product->getSides()->findFirst(static fn (int|string $i, ProductSide $s) => mb_strtoupper((string) $s->getName()) === $sideName);
            if (null === $side) {
                $side = (new ProductSide())->setName($sideName);
                $product->addSide($side);
                ++$report->sidesCreated;
            } else {
                ++$report->sidesUpdated;
            }

            $price = self::price($row->price);
            $side->setPrice(null !== $price && $price !== $basePrice ? $price : null);
            if (null === $price) {
                $report->withoutPrice[] = \sprintf('%s, сторона %s', $row->address, $sideName);
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
