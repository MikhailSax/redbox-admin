<?php

namespace App\Command;

use App\Service\Import\OccupancyImporter;
use App\Service\Import\OccupancyReader;
use App\Service\Import\PlacementPeriod;
use App\Service\MonthCalendar;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Imports bookings from the occupancy spreadsheet of screens and billboards ("Экраны и щиты занятость 29.09.xlsx"):
 *   bin/console app:import:bookings "Экраны и щиты занятость.xlsx" --dry-run
 * The last sheet is read unless --sheet names another. Its name ("октябрь 2026") gives the month that
 * "сентябрь" and "до конца года" are counted from; without a month in the name it is the current one.
 * Structures must be in the CRM already (app:import:address-program); clients not found get a new card.
 * Bookings are made paid: the spreadsheet lists placements agreed with the clients.
 */
#[AsCommand(name: 'app:import:bookings', description: 'Импорт броней из таблицы занятости экранов и щитов (xlsx)')]
final class ImportBookingsCommand
{
    public function __construct(
        private readonly OccupancyReader $reader,
        private readonly OccupancyImporter $importer,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Путь к файлу .xlsx')] string $file,
        #[Option('Лист; по умолчанию последний видимый (текущий месяц)')] ?string $sheet = null,
        #[Option('Только показать, что будет сделано, ничего не сохранять')] bool $dryRun = false,
    ): int {
        if (!is_file($file)) {
            $io->error(\sprintf('Файл не найден: %s', $file));

            return Command::INVALID;
        }

        $sheets = $this->reader->sheetNames($file);
        $sheet ??= end($sheets) ?: null;
        try {
            $blocks = $this->reader->read($file, $sheet);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage().'. Листы в файле: '.implode(', ', $sheets));

            return Command::INVALID;
        }

        $now = $this->clock->now();
        $month = PlacementPeriod::month((string) $sheet);
        $month = null !== $month
            ? MonthCalendar::parse(\sprintf('%04d-%02d', $month[1] ?? (int) $now->format('Y'), $month[0]))
            : MonthCalendar::firstDay($now);

        $rows = array_sum(array_map(static fn ($block) => \count($block->rows), $blocks));
        $io->title(\sprintf('Лист «%s» (%s): таблиц — %d, строк с клиентами — %d', $sheet, MonthCalendar::label($month), \count($blocks), $rows));

        $report = $this->importer->import($blocks, (string) $sheet, $month, $dryRun);

        $io->table(['Броней создано', 'Уже были'], [[$report->created, $report->existing]]);
        if ([] !== $report->clientsCreated) {
            $io->note("Новые карточки клиентов (без ИНН — физ. лица, проверьте и дополните):\n".implode("\n", array_unique($report->clientsCreated)));
        }
        if ([] !== $report->blocksSkipped) {
            $io->warning("Таблицы пропущены — конструкция не найдена:\n".implode("\n", $report->blocksSkipped));
        }
        if ([] !== $report->rowsSkipped) {
            ksort($report->rowsSkipped);
            $io->warning("Строки пропущены:\n".implode("\n", array_map(
                static fn (int $line, string $reason) => \sprintf('строка %d: %s', $line, $reason),
                array_keys($report->rowsSkipped),
                $report->rowsSkipped,
            )));
        }

        $dryRun ? $io->success('Пробный запуск: ничего не сохранено') : $io->success('Импорт броней завершён');

        return Command::SUCCESS;
    }
}
