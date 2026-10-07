<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Every e-mail of the project goes out through here: from MAILER_FROM, right away (no queue: the shared hosting
 * has no worker to empty one), and a broken SMTP never breaks the request that sends it — it is logged instead.
 */
class MailSender
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(MAILER_FROM)%')] private readonly string $from,
    ) {
    }

    /**
     * @return bool false when the SMTP server refused or could not be reached
     */
    public function send(Email $email): bool
    {
        try {
            $this->sendOrFail($email);
        } catch (TransportExceptionInterface $exception) {
            $this->logger->error('E-mail "{subject}" to {to} was not sent: {error}', [
                'subject' => $email->getSubject(),
                'to' => implode(', ', array_map(static fn (Address $to) => $to->getAddress(), $email->getTo())),
                'error' => $exception->getMessage(),
                'exception' => $exception,
            ]);

            return false;
        }

        return true;
    }

    /**
     * Same as send(), but the SMTP error is thrown: for app:mail:test, which is there to show it.
     *
     * @throws TransportExceptionInterface
     */
    public function sendOrFail(Email $email): void
    {
        if ([] === $email->getFrom()) {
            $email->from(Address::create($this->from));
        }
        $this->mailer->send($email);
    }
}
