<?php

namespace App\Command;

use App\Service\MailSender;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mime\Email;

/**
 * Checks MAILER_DSN and MAILER_FROM after a deploy: sends one plain e-mail and shows the SMTP server's answer
 * when it is refused (wrong password, closed port, a sender the mailbox may not use).
 */
#[AsCommand(name: 'app:mail:test', description: 'Отправить тестовое письмо, чтобы проверить настройки SMTP')]
final class TestMailCommand
{
    public function __construct(
        private readonly MailSender $mail,
        #[Autowire('%env(MAILER_FROM)%')] private readonly string $from,
    ) {
    }

    public function __invoke(SymfonyStyle $io, #[Argument('Куда отправить')] string $to): int
    {
        $io->writeln(\sprintf('От: %s', $this->from));
        $io->writeln(\sprintf('Кому: %s', $to));

        try {
            $this->mail->sendOrFail((new Email())
                ->to($to)
                ->subject('Проверка почты — REDBOX')
                ->text("Если вы читаете это письмо, SMTP настроен верно: письма о регистрации, заявках и восстановлении пароля будут доходить.\n"));
        } catch (TransportExceptionInterface $exception) {
            $io->error(['Письмо не отправлено.', $exception->getMessage()]);

            return Command::FAILURE;
        }

        $io->success('Письмо принято SMTP-сервером. Проверьте ящик (и папку «Спам»).');

        return Command::SUCCESS;
    }
}
