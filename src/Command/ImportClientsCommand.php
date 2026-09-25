<?php

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\ClientCardException;
use App\Service\ClientCards;
use Doctrine\ORM\EntityManagerInterface;
use OpenSpout\Reader\XLSX\Reader;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Adds client cards from a spreadsheet of counterparties ("август-сентябрь размещение.xlsx"):
 *   bin/console app:import:clients "клиенты.xlsx" --dry-run
 *
 * The first sheet with a header row holding "Контрагент" is read; the columns "Email", "ИНН", "КПП", "Телефон"
 * are optional and found by their header. The type follows from the ИНН (10 digits — a company, 12 — an ИП,
 * none — a private person). A client already in the CRM (same ИНН, email or name) is not added again: its empty
 * phone, email and КПП are filled in from the file.
 */
#[AsCommand(name: 'app:import:clients', description: 'Импорт клиентов из списка контрагентов (xlsx)')]
final class ImportClientsCommand
{
    /** header text (lower case) => field */
    private const HEADERS = [
        'контрагент' => 'title',
        'клиент' => 'title',
        'email' => 'email',
        'e-mail' => 'email',
        'почта' => 'email',
        'инн' => 'inn',
        'кпп' => 'kpp',
        'телефон' => 'phone',
    ];

    public function __construct(
        private readonly ClientCards $clients,
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Путь к файлу .xlsx')] string $file,
        #[Option('Только показать, что будет сделано, ничего не сохранять')] bool $dryRun = false,
    ): int {
        if (!is_file($file)) {
            $io->error(\sprintf('Файл не найден: %s', $file));

            return Command::INVALID;
        }

        $rows = $this->read($file);
        if ([] === $rows) {
            $io->error('В файле нет листа со столбцом «Контрагент»');

            return Command::INVALID;
        }
        $io->title(\sprintf('Клиентов в файле: %d', \count($rows)));

        $created = $updated = $unchanged = 0;
        $notes = $withoutInn = $failed = [];
        // a dry run does everything in a transaction that is rolled back at the end
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        foreach ($rows as $line => $row) {
            try {
                $client = $this->clients->find($row['title'], $row['inn'], $row['email']);
                if (null === $client) {
                    $client = $this->clients->create($row['title'], $row['phone'], $row['email'], $row['inn'], $row['kpp'], notes: $notes);
                    ++$created;
                    if (null === $client->getInn()) {
                        $withoutInn[] = $row['title'];
                    }
                } elseif ($this->fillIn($client, $row, $notes)) {
                    ++$updated;
                } else {
                    ++$unchanged;
                }
                // the next rows must see this one (the same email or ИНН twice in the file)
                $this->entityManager->flush();
            } catch (ClientCardException $e) {
                $failed[] = \sprintf('строка %d: %s', $line, $e->getMessage());
            }
        }
        $dryRun ? $connection->rollBack() : $connection->commit();

        $io->table(['Добавлено', 'Дополнено', 'Уже были'], [[$created, $updated, $unchanged]]);
        if ([] !== $notes) {
            $io->note($notes);
        }
        if ([] !== $withoutInn) {
            $io->note("Без ИНН — заведены как физ. лица, при необходимости поменяйте тип в карточке:\n".implode("\n", $withoutInn));
        }
        if ([] !== $failed) {
            $io->warning("Не добавлены:\n".implode("\n", $failed));
        }

        $dryRun ? $io->success('Пробный запуск: ничего не сохранено') : $io->success('Импорт клиентов завершён');

        return Command::SUCCESS;
    }

    /**
     * Fills the client's empty phone, email and КПП from the file.
     *
     * @param array{title: string, email: ?string, inn: ?string, kpp: ?string, phone: ?string} $row
     * @param list<string>                                                                      $notes
     */
    private function fillIn(User $client, array $row, array &$notes): bool
    {
        $changed = false;
        if (null === $client->getPhone() && null !== $row['phone']) {
            $client->setPhone(mb_substr($row['phone'], 0, 50));
            $changed = true;
        }
        if (null === $client->getKpp() && null !== $row['kpp'] && null !== $client->getInn() && 10 === \strlen($client->getInn())) {
            $client->setKpp($row['kpp']);
            $changed = true;
        }
        if (null === $client->getEmail() && null !== $row['email']) {
            $email = mb_strtolower($row['email']);
            if (null === $this->users->findOneBy(['email' => $email])) {
                $client->setEmail($email);
                $changed = true;
            } else {
                $notes[] = \sprintf('%s: почта %s уже есть у другого пользователя — не добавлена', $row['title'], $email);
            }
        }

        return $changed;
    }

    /**
     * @return array<int, array{title: string, email: ?string, inn: ?string, kpp: ?string, phone: ?string}> by line
     */
    private function read(string $path): array
    {
        $reader = new Reader();
        $reader->open($path);
        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $columns = null;
                $rows = [];
                foreach ($sheet->getRowIterator() as $line => $row) {
                    $cells = array_map(self::text(...), $row->toArray());
                    if (null === $columns) {
                        $found = [];
                        foreach ($cells as $index => $cell) {
                            $field = self::HEADERS[mb_strtolower((string) $cell)] ?? null;
                            if (null !== $field && !isset($found[$field])) {
                                $found[$field] = $index;
                            }
                        }
                        $columns = isset($found['title']) ? $found : null;
                        continue;
                    }

                    $get = static fn (string $field) => isset($columns[$field]) ? ($cells[$columns[$field]] ?? null) : null;
                    if (null === ($title = $get('title'))) {
                        continue;
                    }
                    $rows[$line] = ['title' => $title, 'email' => $get('email'), 'inn' => self::code($get('inn'), [9 => 10, 11 => 12]), 'kpp' => self::code($get('kpp'), [8 => 9]), 'phone' => $get('phone')];
                }
                if (null !== $columns) {
                    return $rows;
                }
            }

            return [];
        } finally {
            $reader->close();
        }
    }

    /**
     * ИНН / КПП typed as a number lose the leading zero: "0323347497" becomes 323347497. Padded back.
     *
     * @param array<int, int> $pad digits read => digits it should have
     */
    private static function code(?string $value, array $pad): ?string
    {
        if (null === $value || !ctype_digit($value)) {
            return $value;
        }

        return isset($pad[\strlen($value)]) ? str_pad($value, $pad[\strlen($value)], '0', \STR_PAD_LEFT) : $value;
    }

    /** Trimmed text; numbers as digits */
    private static function text(mixed $value): ?string
    {
        if (\is_int($value) || \is_float($value)) {
            $value = number_format((float) $value, 0, '', '');
        } elseif (!\is_string($value)) {
            return null;
        }
        $value = trim((string) preg_replace('/\s+/u', ' ', str_replace("\u{00A0}", ' ', $value)));

        return '' !== $value ? $value : null;
    }
}
