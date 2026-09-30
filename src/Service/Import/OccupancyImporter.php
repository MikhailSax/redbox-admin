<?php

namespace App\Service\Import;

use App\Entity\Booking;
use App\Entity\Product;
use App\Entity\ProductSide;
use App\Entity\User;
use App\Repository\BookingRepository;
use App\Repository\ProductRepository;
use App\Repository\UserRepository;
use App\Service\BookingManager;
use App\Service\ClientCardException;
use App\Service\ClientCards;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Turns the occupancy spreadsheet into bookings: every client row of a table is a paid booking of the table's side.
 *
 * The structure is found by its scheme number ("номер в схеме"); a table without one ("б/н") by the words its title
 * shares with a structure's address ("Щит Бурвод" = "Бурвод ул. Кабанская и трасса Р-258"). The side is the one in
 * the first column ("А", "B2") or, for a screen whose rows are slot numbers, the one in the title ("… сторона Б");
 * a structure of one side takes every row, and a title naming no side means the structure's only screen.
 * Latin letters and "Б" are read as the Cyrillic "А", "В", "С" of the sides' names.
 * A row of a screen takes one slot of its block; a row of a billboard the whole side.
 *
 * The client is found by name, loosely ("ООО "Диагрупп"" = "ДИАГРУПП ООО", "СМИТ-ТРЕЙД ООО (Тритон)" = "СМИТ-ТРЕЙД ООО");
 * a name the CRM doesn't know gets a new client card. Running the import again adds nothing twice: a booking of the
 * same side, client and days is recognised. A row that doesn't fit (the side is taken, the period is not understood)
 * is left out and reported.
 */
class OccupancyImporter
{
    /** What is written in the client column of a free place */
    private const NOT_CLIENTS = ['не работает'];

    /** Words of titles and addresses that don't tell structures apart */
    private const TITLE_NOISE = ['экран', 'щит', 'сторона', 'сити', 'формат', 'улица', 'вблизи', 'дома', 'призматрон', 'тривижн'];

    /** Latin letters and "Б" typed for the sides "А", "В", "С" */
    private const SIDE_LETTERS = ['A' => 'А', 'B' => 'В', 'C' => 'С', 'Б' => 'В'];

    /** @var list<Product>|null */
    private ?array $products = null;

    /** @var array<string, User>|null exact and loose name keys => client */
    private ?array $clients = null;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ProductRepository $productRepository,
        private readonly UserRepository $users,
        private readonly BookingRepository $bookings,
        private readonly BookingManager $bookingManager,
        private readonly ClientCards $clientCards,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @param list<OccupancyBlock> $blocks
     * @param \DateTimeImmutable   $sheetMonth first day of the month the sheet is about: "сентябрь", "до конца года" count from it
     */
    public function import(array $blocks, string $sheetName, \DateTimeImmutable $sheetMonth, bool $dryRun = false): OccupancyReport
    {
        $report = new OccupancyReport();
        $this->products = null;
        $this->clients = null;
        /** @var array<string, int> side|client|days => rows of the file matched so far */
        $seen = [];

        // every booking is flushed at once, so the next rows see it; a dry run rolls everything back
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        try {
            foreach ($blocks as $block) {
                if ([] !== $block->rows) {
                    $this->importBlock($block, $sheetName, $sheetMonth, $report, $seen);
                }
            }
        } catch (\Throwable $e) {
            $connection->rollBack();
            $this->entityManager->clear();

            throw $e;
        }
        if ($dryRun) {
            $connection->rollBack();
            $this->entityManager->clear();
        } else {
            $connection->commit();
        }

        return $report;
    }

    /**
     * @param array<string, int> $seen
     */
    private function importBlock(OccupancyBlock $block, string $sheetName, \DateTimeImmutable $sheetMonth, OccupancyReport $report, array &$seen): void
    {
        $product = $this->product($block);
        if (\is_string($product)) {
            $report->blocksSkipped[] = \sprintf('строка %d «%s»: %s — клиентов в таблице: %d', $block->line, $block->title, $product, \count($block->rows));

            return;
        }

        $now = $this->clock->now();
        foreach ($block->rows as $row) {
            $name = self::clientName($row->client);
            if ('' === $name || \in_array(mb_strtolower($name), self::NOT_CLIENTS, true)) {
                continue;
            }

            $side = $this->side($product, $row, self::titleSide($block->title));
            if (null === $side) {
                $report->rowsSkipped[$row->line] = \sprintf('%s: у конструкции «%s» не найдена сторона «%s»', $name, $product->getName(), $row->code);
                continue;
            }

            $period = null !== $row->period ? PlacementPeriod::parse($row->period, $sheetMonth) : null;
            if (null === $period) {
                $report->rowsSkipped[$row->line] = null !== $row->period
                    ? \sprintf('%s: не понятен период «%s»', $name, $row->period)
                    : \sprintf('%s: не указан период', $name);
                continue;
            }
            [$start, $end] = $period;

            try {
                $client = $this->client($name, $report);
            } catch (ClientCardException $e) {
                $report->rowsSkipped[$row->line] = $e->getMessage();
                continue;
            }

            // the same client may take two slots of a screen: as many bookings as rows
            $key = \sprintf('%d|%d|%s|%s', $side->getId(), $client->getId(), $start->format('Y-m-d'), $end->format('Y-m-d'));
            $already = \count(array_filter(
                $this->bookings->findActiveOverlapping($side, $start, $end, $now),
                static fn (Booking $b) => $b->getClient() === $client && $b->getStartDate() == $start && $b->getEndDate() == $end,
            ));
            $seen[$key] = ($seen[$key] ?? 0) + 1;
            if ($already >= $seen[$key]) {
                ++$report->existing;
                continue;
            }

            $slots = $side->isAirtime() ? 1 : null;
            if (null !== ($problem = $this->bookingManager->availabilityProblem($side, $start, $end, $slots))) {
                --$seen[$key];
                $report->rowsSkipped[$row->line] = \sprintf('%s: %s', $name, $problem);
                continue;
            }

            $comment = \sprintf('Из таблицы занятости: лист «%s», строка %d', trim($sheetName), $row->line);
            if (mb_strtolower($row->client) !== mb_strtolower($client->getClientTitle())) {
                $comment .= \sprintf(', клиент «%s»', $row->client);
            }
            $booking = new Booking($side, $start, $end, $slots, $client, $client->getName() ?: $client->getClientTitle(), $client->getPhone() ?: '—', $comment);
            $booking->markPaid($now);
            $this->entityManager->persist($booking);
            $this->entityManager->flush();
            ++$report->created;
        }
    }

    /**
     * The structure of the table, or why it wasn't found.
     */
    private function product(OccupancyBlock $block): Product|string
    {
        $this->products ??= $this->productRepository->findBy([], ['id' => 'ASC']);

        if (null !== $block->schemeNumber) {
            $number = self::schemeKey($block->schemeNumber);
            $found = array_values(array_filter($this->products, static fn (Product $p) => null !== $p->getSchemeNumber() && self::schemeKey($p->getSchemeNumber()) === $number));
            if ([] === $found) {
                return \sprintf('нет конструкции с номером в схеме %s', $block->schemeNumber);
            }

            return 1 === \count($found) ? $found[0] : ($this->byTitle($block->title, $found) ?? \sprintf('номер в схеме %s у нескольких конструкций', $block->schemeNumber));
        }

        return $this->byTitle($block->title, $this->products) ?? 'нет номера в схеме, по названию конструкция не найдена';
    }

    /**
     * The structure whose address shares most words with the title, if only one does.
     *
     * @param list<Product> $products
     */
    private function byTitle(string $title, array $products): ?Product
    {
        $words = self::words($title);
        $best = [];
        $bestScore = 0;
        foreach ($products as $product) {
            $score = \count(array_intersect($words, self::words((string) $product->getName())));
            if ($score > $bestScore) {
                [$best, $bestScore] = [[$product], $score];
            } elseif ($score === $bestScore && $score > 0) {
                $best[] = $product;
            }
        }

        return 1 === \count($best) ? $best[0] : null;
    }

    private function side(Product $product, OccupancyRow $row, ?string $titleSide): ?ProductSide
    {
        $sides = $product->getSides()->toArray();
        $wanted = ctype_digit($row->code) ? $titleSide : $row->code;

        if (null !== $wanted) {
            // "Б" is "В" only when the structure has no side "Б" of its own
            foreach ([false, true] as $loose) {
                foreach ($sides as $side) {
                    if (self::sideKey((string) $side->getName(), $loose) === self::sideKey($wanted, $loose)) {
                        return $side;
                    }
                }
            }
        }
        if (1 === \count($sides)) {
            return reset($sides);
        }
        if (null === $wanted) {
            $screens = array_values(array_filter($sides, static fn (ProductSide $s) => $s->isAirtime()));
            if (1 === \count($screens)) {
                return $screens[0];
            }
        }

        return null;
    }

    /**
     * @throws ClientCardException
     */
    private function client(string $name, OccupancyReport $report): User
    {
        if (null === $this->clients) {
            $this->clients = [];
            $clients = $this->users->findClients();
            usort($clients, static fn (User $a, User $b) => $a->getId() <=> $b->getId());
            foreach ($clients as $client) {
                $this->remember($client);
            }
        }

        $client = $this->clients['='.self::exactKey($name)] ?? $this->clients['~'.self::looseKey($name)] ?? null;
        if (null === $client) {
            $client = $this->clientCards->create($name);
            $this->entityManager->flush();
            $this->remember($client);
            $report->clientsCreated[] = $name;
        }

        return $client;
    }

    private function remember(User $client): void
    {
        $title = $client->getClientTitle();
        $this->clients['='.self::exactKey($title)] ??= $client;
        if ('' !== ($loose = self::looseKey($title))) {
            $this->clients['~'.$loose] ??= $client;
        }
    }

    /** "Leznova Clinic бронь" => "Leznova Clinic" */
    private static function clientName(string $text): string
    {
        return trim((string) preg_replace('/\s+бронь$/iu', '', trim($text)));
    }

    /** "Экран/ Борсоева сторона А" => "А" */
    private static function titleSide(string $title): ?string
    {
        return preg_match('/сторона\s+(\p{L}\d?)(?!\p{L})/iu', $title, $m) ? $m[1] : null;
    }

    private static function sideKey(string $name, bool $loose): string
    {
        $key = str_replace(' ', '', mb_strtoupper($name));
        $letters = $loose ? self::SIDE_LETTERS : array_diff_key(self::SIDE_LETTERS, ['Б' => true]);

        return strtr($key, $letters);
    }

    private static function schemeKey(string $number): string
    {
        return str_replace(' ', '', mb_strtolower($number));
    }

    /**
     * Distinctive words of a title or an address: four letters or more, lower case.
     *
     * @return list<string>
     */
    private static function words(string $text): array
    {
        $words = preg_split('/[^\p{L}]+/u', str_replace('ё', 'е', mb_strtolower($text)), -1, \PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_diff(array_filter($words, static fn (string $w) => mb_strlen($w) >= 4), self::TITLE_NOISE)));
    }

    private static function exactKey(string $name): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', str_replace('ё', 'е', mb_strtolower($name))));
    }

    /** Without the legal form, quotes and a note in brackets: "ООО "Диагрупп"" => "диагрупп" */
    private static function looseKey(string $name): string
    {
        $key = str_replace('ё', 'е', mb_strtolower($name));
        $key = (string) preg_replace(['/\([^)]*\)/u', '/["«»“”„\']/u', '/(?<![\p{L}\d])(ооо|оао|зао|пао|ао|ип|сз)(?![\p{L}\d])/u'], ' ', $key);

        return trim((string) preg_replace('/\s+/u', ' ', $key));
    }
}
