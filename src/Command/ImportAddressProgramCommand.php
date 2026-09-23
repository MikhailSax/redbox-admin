<?php

namespace App\Command;

use App\Service\Import\AddressProgramImporter;
use App\Service\Import\AddressProgramReader;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Imports structures, sides, sizes, prices (month, two weeks, 3 and 6 months, print) and screen slots
 * from the address programme spreadsheet:
 *   bin/console app:import:address-program "Адресная программа.xlsx" --dry-run
 * Coordinates come from the map links (short Yandex links need access to yandex.ru); photos are downloaded
 * from the photo links and attached to their sides.
 */
#[AsCommand(name: 'app:import:address-program', description: 'Импорт конструкций из адресной программы (xlsx)')]
final class ImportAddressProgramCommand
{
    public function __construct(
        private readonly AddressProgramReader $reader,
        private readonly AddressProgramImporter $importer,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Путь к файлу .xlsx')] string $file,
        #[Option('Лист; по умолчанию последний видимый (свежий прайс)')] ?string $sheet = null,
        #[Option('Только показать, что будет сделано, ничего не сохранять')] bool $dryRun = false,
    ): int {
        if (!is_file($file)) {
            $io->error(\sprintf('Файл не найден: %s', $file));

            return Command::INVALID;
        }

        $sheets = $this->reader->sheetNames($file);
        $sheet ??= end($sheets) ?: null;
        try {
            $rows = $this->reader->read($file, $sheet);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage().'. Листы в файле: '.implode(', ', $sheets));

            return Command::INVALID;
        }

        $io->title(\sprintf('Лист «%s»: сторон в файле — %d', $sheet, \count($rows)));
        $report = $this->importer->import($rows, $dryRun);

        $io->table(['', 'Создано', 'Обновлено'], [
            ['Конструкции', $report->productsCreated, $report->productsUpdated],
            ['Стороны', $report->sidesCreated, $report->sidesUpdated],
        ]);
        if ([] !== $report->merged) {
            $io->note("Конструкции одного адреса объединены в одну:\n".implode("\n", $report->merged));
        }
        if ([] !== $report->sideTypes) {
            $io->text("Тип стороны задан по файлу:\n  ".implode("\n  ", $report->sideTypes));
        }
        $io->text(\sprintf('Координаты из ссылок на карту: %d', $report->coordinatesSet));
        if ([] !== $report->coordinatesFailed) {
            $io->warning("Не удалось получить координаты по ссылке:\n".implode("\n", $report->coordinatesFailed));
        }
        $io->text(\sprintf('Фото сторон %s: %d', $dryRun ? 'к загрузке' : 'загружено', $report->photosAdded));
        if ([] !== $report->photosFailed) {
            $io->warning("Не удалось скачать фото:\n".implode("\n", $report->photosFailed));
        }
        if ([] !== $report->dictionariesCreated) {
            $io->text('Новые записи справочников: '.implode(', ', $report->dictionariesCreated));
        }
        foreach ($report->skipped as $line => $reason) {
            $io->warning(\sprintf('Строка %d пропущена: %s', $line, $reason));
        }
        if ([] !== $report->withoutPrice) {
            $io->note("Без цены (заполните в карточке):\n".implode("\n", $report->withoutPrice));
        }
        if ($report->bookingNotes > 0) {
            $io->note(\sprintf('Колонка «Бронь» не импортируется (для брони нужен клиент): строк с отметкой — %d', $report->bookingNotes));
        }

        $dryRun ? $io->success('Пробный запуск: ничего не сохранено') : $io->success('Импорт завершён');

        return Command::SUCCESS;
    }
}
