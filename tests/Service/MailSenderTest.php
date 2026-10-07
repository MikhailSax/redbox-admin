<?php

namespace App\Tests\Service;

use App\Service\MailSender;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

final class MailSenderTest extends TestCase
{
    public function testSendsFromMailerFrom(): void
    {
        $mailer = new class implements MailerInterface {
            public ?RawMessage $sent = null;

            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                $this->sent = $message;
            }
        };

        self::assertTrue((new MailSender($mailer, new MemoryLogger(), 'REDBOX <noreply@sibiradm.ru>'))->send((new Email())->to('anna@citypark.ru')->subject('Привет')->text('…')));
        self::assertInstanceOf(Email::class, $mailer->sent);
        self::assertSame('noreply@sibiradm.ru', $mailer->sent->getFrom()[0]->getAddress());
        self::assertSame('REDBOX', $mailer->sent->getFrom()[0]->getName());
    }

    public function testRefusedEmailIsLoggedInsteadOfThrown(): void
    {
        $mailer = new class implements MailerInterface {
            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                throw new TransportException('Connection could not be established with host "ssl://smtp.example:465"');
            }
        };
        $logger = new MemoryLogger();
        $sender = new MailSender($mailer, $logger, 'noreply@sibiradm.ru');

        self::assertFalse($sender->send((new Email())->to('anna@citypark.ru')->subject('Подтвердите почту')->text('…')));
        self::assertSame('error', $logger->records[0][0]);
        self::assertSame('Подтвердите почту', $logger->records[0][2]['subject']);
        self::assertSame('anna@citypark.ru', $logger->records[0][2]['to']);

        $this->expectException(TransportException::class);
        $sender->sendOrFail((new Email())->to('anna@citypark.ru')->text('…'));
    }
}

final class MemoryLogger extends AbstractLogger
{
    /** @var list<array{0: mixed, 1: string, 2: array<string, mixed>}> */
    public array $records = [];

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->records[] = [$level, (string) $message, $context];
    }
}
