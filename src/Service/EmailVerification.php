<?php

namespace App\Service;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use SymfonyCasts\Bundle\VerifyEmail\Exception\ExpiredSignatureException;
use SymfonyCasts\Bundle\VerifyEmail\Exception\InvalidSignatureException;
use SymfonyCasts\Bundle\VerifyEmail\Exception\VerifyEmailExceptionInterface;
use SymfonyCasts\Bundle\VerifyEmail\VerifyEmailHelperInterface;

/**
 * Confirmation of a website account's e-mail (symfonycasts/verify-email-bundle).
 *
 * The bundle signs a link to the API route; the e-mail carries the same query on the website's /verify-email page,
 * which sends it back to POST /api/v1/auth/verify-email. The signature covers the user id and the e-mail,
 * so a link stops working when the address changes.
 */
class EmailVerification
{
    public const ROUTE = 'api_auth_verify_email';

    /** Query parameters of a confirmation link */
    public const PARAMETERS = ['id', 'expires', 'signature', 'token'];

    public function __construct(
        private readonly VerifyEmailHelperInterface $helper,
        private readonly MailSender $mail,
        private readonly Notifications $notifications,
        private readonly EntityManagerInterface $entityManager,
        private readonly UrlGeneratorInterface $urls,
        private readonly UserRepository $users,
        private readonly ClockInterface $clock,
        #[Autowire('%env(WEBSITE_URL)%')] private readonly string $websiteUrl,
    ) {
    }

    /**
     * @return bool false when the SMTP server did not take the e-mail (it is logged; the client can ask again)
     */
    public function send(User $user): bool
    {
        $signature = $this->helper->generateSignature(self::ROUTE, (string) $user->getId(), (string) $user->getEmail(), ['id' => $user->getId()]);
        $query = (string) parse_url($signature->getSignedUrl(), \PHP_URL_QUERY);

        return $this->mail->send((new TemplatedEmail())
            ->to(new Address((string) $user->getEmail(), (string) $user->getName()))
            ->subject('Подтвердите почту — REDBOX')
            ->htmlTemplate('emails/verify_email.html.twig')
            ->textTemplate('emails/verify_email.txt.twig')
            ->context([
                'name' => $user->getName(),
                'url' => rtrim($this->websiteUrl, '/').'/verify-email?'.$query,
                'hours' => intdiv($signature->getExpiresAt()->getTimestamp() - $this->clock->now()->getTimestamp() + 59, 3600),
            ]));
    }

    /**
     * @param array<string, mixed> $parameters query of the link: id, expires, signature, token
     *
     * @return User the account whose e-mail is now confirmed
     *
     * @throws VerifyEmailExceptionInterface when the link is broken, forged, expired or for an old address
     */
    public function confirm(array $parameters): User
    {
        $user = $this->users->find((int) ($parameters['id'] ?? 0));
        if (!$user instanceof User || !$user->isClient()) {
            throw new InvalidSignatureException();
        }

        // The link was signed for the API host the sign-up came through, which is the host of this request too
        $query = array_intersect_key($parameters, array_flip(self::PARAMETERS));
        $signed = Request::create($this->urls->generate(self::ROUTE, [], UrlGeneratorInterface::ABSOLUTE_URL).'?'.http_build_query($query));
        $this->helper->validateEmailConfirmationFromRequest($signed, (string) $user->getId(), (string) $user->getEmail());

        return $this->markVerified($user);
    }

    /**
     * Confirms the e-mail (by the link, by a password reset, or by a manager who checked it with the client)
     * and saves it; the first time, staff hear about it and the client gets a welcome.
     */
    public function markVerified(User $user): User
    {
        $first = !$user->isEmailVerified();
        $user->markEmailVerified($this->clock->now());
        $this->entityManager->flush();

        if ($first) {
            $this->notifications->clientEmailVerified($user);
        }

        return $user;
    }

    public static function isExpired(VerifyEmailExceptionInterface $exception): bool
    {
        return $exception instanceof ExpiredSignatureException;
    }
}
