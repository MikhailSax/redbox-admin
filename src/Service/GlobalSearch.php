<?php

namespace App\Service;

use App\Entity\Booking;
use App\Entity\Lead;
use App\Entity\Category;
use App\Entity\District;
use App\Entity\Partner;
use App\Entity\Product;
use App\Entity\ProductType;
use App\Entity\Promotion;
use App\Entity\User;
use App\Repository\BookingRepository;
use App\Repository\PartnerRepository;
use App\Repository\ProductRepository;
use App\Repository\PromotionRepository;
use App\Service\Availability\AvailabilityResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Search behind the Ctrl/⌘+K command palette: structures, bookings (clients), partners, promotions, dictionaries, users.
 *
 * @phpstan-type Item array{title: string, subtitle: string, url: string, badge?: array{label: string, tone: string}}
 * @phpstan-type Group array{title: string, items: list<Item>}
 */
class GlobalSearch
{
    public const MIN_LENGTH = 2;

    public function __construct(
        private readonly ProductRepository $products,
        private readonly BookingRepository $bookings,
        private readonly PartnerRepository $partners,
        private readonly PromotionRepository $promotions,
        private readonly EntityManagerInterface $entityManager,
        private readonly AvailabilityResolver $availability,
        private readonly UrlGeneratorInterface $urls,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @return list<Group> non-empty groups only
     */
    public function search(string $term, bool $includeUsers): array
    {
        $term = trim($term);
        if (mb_strlen($term) < self::MIN_LENGTH) {
            return [];
        }

        $groups = [
            ['title' => 'Конструкции', 'items' => $this->products($term)],
            ['title' => 'Брони и клиенты', 'items' => $this->bookings($term)],
            ['title' => 'Заявки', 'items' => $this->leads($term)],
            ['title' => 'Клиенты', 'items' => $this->clients($term)],
            ['title' => 'Партнёры', 'items' => $this->partners($term)],
            ['title' => 'Акции', 'items' => $this->promotions($term)],
            ['title' => 'Справочники', 'items' => $this->dictionaries($term)],
        ];
        if ($includeUsers) {
            $groups[] = ['title' => 'Пользователи', 'items' => $this->users($term)];
        }

        return array_values(array_filter($groups, static fn (array $group) => [] !== $group['items']));
    }

    /**
     * @return list<Item>
     */
    private function products(string $term): array
    {
        $products = $this->products->search($term, 6);
        $statuses = $this->availability->forProducts(array_map(static fn (Product $p) => $p->getId(), $products), $this->clock->now());

        return array_map(function (Product $product) use ($statuses) {
            $status = $statuses[$product->getId()]->status();

            return [
                'title' => (string) $product->getName(),
                'subtitle' => implode(' · ', array_filter([$product->getCategory()?->getName(), $product->getProductType()?->getName(), $product->getDistrict()?->getName()])),
                'url' => $this->urls->generate('admin_product_edit', ['id' => $product->getId()]),
                'badge' => null !== $status ? ['label' => $status->label(), 'tone' => $status->value] : null,
            ];
        }, $products);
    }

    /**
     * @return list<Item>
     */
    private function bookings(string $term): array
    {
        $now = $this->clock->now();

        return array_map(function (Booking $booking) use ($now) {
            $status = $booking->getStatusAt($now);

            return [
                'title' => $booking->getClientName().' · '.$booking->getClientPhone(),
                'subtitle' => \sprintf('%s, сторона %s · %s', $booking->getProduct()?->getName(), $booking->getSide()->getName(), MonthCalendar::periodLabel($booking->getStartDate(), $booking->getEndDate())),
                'url' => $this->urls->generate('admin_booking_product', ['id' => $booking->getProduct()?->getId()]),
                'badge' => ['label' => $status->label(), 'tone' => $status->value],
            ];
        }, $this->bookings->findForList(null, $term, 5));
    }

    /**
     * @return list<Item>
     */
    private function leads(string $term): array
    {
        return array_map(fn (Lead $lead) => [
            'title' => \sprintf('Заявка №%d · %s', (int) $lead->getId(), $lead->getClientTitle()),
            'subtitle' => implode(' · ', array_filter([$lead->getPhone(), $lead->getCreatedAt()?->format('d.m.Y')])),
            'url' => $this->urls->generate('admin_lead_show', ['id' => $lead->getId()]),
            'badge' => ['label' => $lead->getStatus()->label(), 'tone' => $lead->getStatus()->tone()],
        ], $this->entityManager->getRepository(Lead::class)->findForList(null, $term, 3));
    }

    /**
     * @return list<Item>
     */
    private function clients(string $term): array
    {
        return array_map(fn (User $client) => [
            'title' => $client->getDisplayName(),
            'subtitle' => implode(' · ', array_filter(['Клиент', $client->getEmail(), $client->getPhone()])),
            'url' => $this->urls->generate('admin_client_show', ['id' => $client->getId()]),
        ], $this->entityManager->getRepository(User::class)->findClients($term, 3));
    }

    /**
     * @return list<Item>
     */
    private function partners(string $term): array
    {
        return array_map(fn (Partner $partner) => [
            'title' => (string) $partner->getName(),
            'subtitle' => implode(' · ', array_filter([$partner->getContactName(), $partner->getPhone(), $partner->getInn() ? 'ИНН '.$partner->getInn() : null])) ?: 'Партнёр',
            'url' => $this->urls->generate('admin_partner_edit', ['id' => $partner->getId()]),
        ], $this->partners->search($term, 3));
    }

    /**
     * @return list<Item>
     */
    private function promotions(string $term): array
    {
        $labels = ['active' => 'действует', 'scheduled' => 'запланирована', 'finished' => 'завершена', 'disabled' => 'выключена'];
        $now = $this->clock->now();

        return array_map(fn (Promotion $promotion) => [
            'title' => (string) $promotion->getTitle(),
            'subtitle' => implode(' · ', array_filter([
                'Акция, '.$labels[$promotion->getStateOn($now)],
                $promotion->getCode() ? 'промокод '.$promotion->getCode() : null,
                $promotion->isFirstOrderOnly() ? 'первый заказ' : null,
            ])),
            'url' => $this->urls->generate('admin_promotion_edit', ['id' => $promotion->getId()]),
            'badge' => ['label' => $promotion->getDiscountLabel(), 'tone' => 'promo'],
        ], \array_slice($this->promotions->findForList($term), 0, 3));
    }

    /**
     * @return list<Item>
     */
    private function dictionaries(string $term): array
    {
        $items = [];
        foreach ([
            [Category::class, 'Категория', 'admin_category_edit'],
            [ProductType::class, 'Тип', 'admin_product_type_edit'],
            [District::class, 'Район', 'admin_district_edit'],
        ] as [$class, $kind, $route]) {
            $found = $this->entityManager->getRepository($class)->createQueryBuilder('e')
                ->andWhere('e.name LIKE :term')
                ->setParameter('term', BookingRepository::like($term))
                ->orderBy('e.name', 'ASC')
                ->setMaxResults(3)
                ->getQuery()
                ->getResult();

            foreach ($found as $entity) {
                $items[] = [
                    'title' => (string) $entity->getName(),
                    'subtitle' => $kind,
                    'url' => $this->urls->generate($route, ['id' => $entity->getId()]),
                ];
            }
        }

        return $items;
    }

    /**
     * @return list<Item>
     */
    private function users(string $term): array
    {
        $users = $this->entityManager->getRepository(User::class)->createQueryBuilder('u')
            ->andWhere('u.name LIKE :term OR u.email LIKE :term')
            ->andWhere('u.roles NOT LIKE :client')
            ->setParameter('client', '%'.User::ROLE_CLIENT.'%')
            ->setParameter('term', BookingRepository::like($term))
            ->orderBy('u.name', 'ASC')
            ->setMaxResults(3)
            ->getQuery()
            ->getResult();

        return array_map(fn (User $user) => [
            'title' => (string) $user->getName(),
            'subtitle' => $user->getEmail().' · '.$user->getRoleLabel(),
            'url' => $this->urls->generate('admin_user_edit', ['id' => $user->getId()]),
        ], $users);
    }
}
