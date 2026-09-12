<?php

namespace App\Command;

use App\Service\BookingManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Scheduler\Attribute\AsPeriodicTask;

/**
 * Goes through the bookings and marks unpaid holds past their 24h deadline as Expired (the rows stay for history).
 * Runs twice a day, at 09:00 and 21:00, via the scheduler worker (bin/console messenger:consume scheduler_default),
 * or manually / from cron.
 *
 * Even if the worker is not running, overdue holds already stop blocking sides:
 * availability checks ignore holds past their deadline. This command only updates their status.
 */
#[AsCommand(name: 'app:booking:expire', description: 'Снять неоплаченные брони, у которых истекли 24 часа')]
#[AsPeriodicTask(frequency: '12 hours', from: '09:00', schedule: 'default')]
final class ExpireBookingsCommand
{
    public function __construct(
        private readonly BookingManager $bookingManager,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $count = $this->bookingManager->expireOverdueHolds();
        $io->success(\sprintf('Снято просроченных броней: %d', $count));

        return Command::SUCCESS;
    }
}
