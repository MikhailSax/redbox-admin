<?php

namespace App\Twig;

use App\Entity\Product;
use App\Entity\Promotion;
use App\Repository\BookingRepository;
use App\Repository\LeadRepository;
use App\Repository\PaymentRepository;
use App\Service\PromotionResolver;
use Symfony\Component\Clock\ClockInterface;
use Twig\Attribute\AsTwigFilter;
use Twig\Attribute\AsTwigFunction;

/**
 * Small helpers for the admin layout.
 */
class AdminExtension
{
    public function __construct(
        private readonly BookingRepository $bookings,
        private readonly LeadRepository $leads,
        private readonly PaymentRepository $payments,
        private readonly ClockInterface $clock,
        private readonly PromotionResolver $promotions,
    ) {
    }

    /**
     * Badges of the sidebar: new requests from the website, holds waiting for payment, overdue client payments.
     *
     * @return array{leads: int, holds: int, overduePayments: int}
     */
    #[AsTwigFunction('admin_counters')]
    public function counters(): array
    {
        return [
            'leads' => $this->leads->countNew(),
            'holds' => $this->bookings->countLiveHolds($this->clock->now()),
            'overduePayments' => $this->payments->countOverdue($this->clock->now()),
        ];
    }

    /**
     * "45 000 ₽", "45 000,50 ₽": kopecks only when there are some.
     */
    #[AsTwigFilter('money')]
    public static function money(string|float|int|null $amount): string
    {
        if (null === $amount || '' === $amount) {
            return '—';
        }
        $amount = round((float) $amount, 2);

        return number_format($amount, fmod($amount, 1.0) !== 0.0 ? 2 : 0, ',', "\u{00A0}")."\u{00A0}₽";
    }

    /**
     * Running promotions for a structure: every one ($publicOnly = false) or only those any client gets.
     *
     * @return list<Promotion>
     */
    #[AsTwigFunction('promotions_for')]
    public function promotionsFor(Product $product, bool $publicOnly = false): array
    {
        return $publicOnly ? $this->promotions->publicFor($product) : $this->promotions->allFor($product);
    }

    /**
     * Escapes $text and wraps case-insensitive matches of $query in <mark class="hl">.
     */
    #[AsTwigFilter('highlight', isSafe: ['html'])]
    public static function highlight(?string $text, ?string $query): string
    {
        $escaped = htmlspecialchars((string) $text, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
        $query = trim((string) $query);
        if ('' === $query) {
            return $escaped;
        }

        $needle = preg_quote(htmlspecialchars($query, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8'), '/');

        return preg_replace('/'.$needle.'/iu', '<mark class="hl">$0</mark>', $escaped) ?? $escaped;
    }

    /**
     * "ИП" for "Иван Петров", "MA" for "maria@redbox.local".
     */
    #[AsTwigFilter('initials')]
    public static function initials(?string $name): string
    {
        $words = preg_split('/[\s@._-]+/u', trim((string) $name), -1, \PREG_SPLIT_NO_EMPTY) ?: ['?'];

        return mb_strtoupper(implode('', array_map(static fn (string $w) => mb_substr($w, 0, 1), \array_slice($words, 0, 2))));
    }
}
