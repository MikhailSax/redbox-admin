<?php

namespace App\Tests\Service;

use App\Entity\Category;
use App\Entity\MediaPlan;
use App\Entity\MediaPlanItem;
use App\Entity\Product;
use App\Entity\ProductSide;
use App\Entity\ProductType;
use App\Entity\Promotion;
use App\Enum\PromotionDiscountType;
use App\Repository\PromotionRepository;
use App\Service\PromotionResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class PromotionResolverTest extends KernelTestCase
{
    use ClockSensitiveTrait;

    private EntityManagerInterface $em;
    private MockClock $clock;
    private Category $billboards;
    private Category $screens;
    private Product $billboard;
    private Product $otherBillboard;
    private Product $screen;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->clock = self::mockTime('2026-09-10 12:00:00');
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        foreach ([MediaPlanItem::class, MediaPlan::class, Promotion::class, ProductSide::class, Product::class, ProductType::class, Category::class] as $class) {
            $this->em->createQuery(\sprintf('DELETE FROM %s e', $class))->execute();
        }

        $this->billboards = (new Category())->setName('Билборд 6х3');
        $this->screens = (new Category())->setName('Видеоэкран');
        $type = (new ProductType())->setName('Статика');
        $this->billboard = (new Product())->setName('Щит на Ленина')->setCategory($this->billboards)->setProductType($type)->setPrice('40000');
        $this->otherBillboard = (new Product())->setName('Щит на Мира')->setCategory($this->billboards)->setProductType($type)->setPrice('30000');
        $this->screen = (new Product())->setName('Экран')->setCategory($this->screens)->setProductType($type)->setPrice('10000');

        foreach ([$this->billboards, $this->screens, $type, $this->billboard, $this->otherBillboard, $this->screen] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
    }

    public function testTargetsCategoriesProductsOrEverything(): void
    {
        $byCategory = $this->promotion('Билборды −10%', '10', categories: [$this->billboards]);
        $byProduct = $this->promotion('Экран −20%', '20', products: [$this->screen]);
        $everything = $this->promotion('Всем −5%', '5', all: true);

        $resolver = $this->resolver();

        self::assertSame([$byCategory, $everything], $this->sorted($resolver->allFor($this->otherBillboard)));
        self::assertSame([$byProduct, $everything], $this->sorted($resolver->allFor($this->screen)));
    }

    public function testOnlyRunningPromotionsCount(): void
    {
        $this->promotion('Выключена', '10', all: true, active: false);
        $this->promotion('Ещё не началась', '10', all: true, starts: '2026-09-11');
        $this->promotion('Закончилась', '10', all: true, starts: '2026-08-01', ends: '2026-09-09');
        $lastDay = $this->promotion('Последний день', '10', all: true, starts: '2026-08-01', ends: '2026-09-10');

        self::assertSame([$lastDay], $this->resolver()->allFor($this->billboard));
    }

    public function testBestPromotionWinsWithoutStacking(): void
    {
        $this->promotion('−10%', '10', all: true);
        $fixed = $this->promotion('−5 000 ₽', '5000', PromotionDiscountType::Fixed, categories: [$this->billboards]);

        // 40 000: −10% = 36 000, −5 000 = 35 000 → fixed wins, and they don't add up
        $best = $this->resolver()->best($this->billboard, 40000, $this->plan());
        self::assertSame($fixed, $best['promotion']);
        self::assertSame(35000.0, $best['price']);

        // a screen gets only −10%: the fixed promotion targets billboards
        self::assertSame(9000.0, $this->resolver()->best($this->screen, 10000, $this->plan())['price']);
    }

    public function testPromoCodeFirstOrderAndLengthConditions(): void
    {
        $code = $this->promotion('По коду', '30', all: true)->setCode('autumn');
        $first = $this->promotion('Первый заказ', '20', all: true)->setFirstOrderOnly(true);
        $long = $this->promotion('От 3 месяцев', '15', all: true)->setMinMonths(3);
        $this->em->flush();

        $resolver = $this->resolver();
        self::assertSame('AUTUMN', $code->getCode());
        self::assertNull($resolver->best($this->billboard, 40000, $this->plan()));
        self::assertSame($long, $resolver->best($this->billboard, 40000, $this->plan(months: 3))['promotion']);
        self::assertSame($first, $resolver->best($this->billboard, 40000, $this->plan(months: 3, firstOrder: true))['promotion']);
        self::assertSame($code, $resolver->best($this->billboard, 40000, $this->plan(firstOrder: true, code: 'Autumn'))['promotion']);
        self::assertSame(28000.0, $resolver->best($this->billboard, 40000, $this->plan(code: 'AUTUMN'))['price']);

        // only unconditional promotions are "public" (badges); length alone doesn't hide one
        self::assertSame([$long], $resolver->publicFor($this->billboard));
        self::assertSame($code, static::getContainer()->get(PromotionRepository::class)->findRunningByCode('autumn', $this->clock->now()));
    }

    public function testDiscountNeverGoesBelowZero(): void
    {
        $promotion = (new Promotion())->setDiscountType(PromotionDiscountType::Fixed)->setDiscountValue('50000');

        self::assertSame(0.0, $promotion->apply(40000));
        self::assertSame('−50 000 ₽', $promotion->getDiscountLabel());
        self::assertSame('−12,5%', (new Promotion())->setDiscountValue('12.50')->getDiscountLabel());
    }

    /**
     * @param list<Category> $categories
     * @param list<Product>  $products
     */
    private function promotion(
        string $title,
        string $value,
        PromotionDiscountType $type = PromotionDiscountType::Percent,
        array $categories = [],
        array $products = [],
        bool $all = false,
        bool $active = true,
        string $starts = '2026-09-01',
        ?string $ends = null,
    ): Promotion {
        $promotion = (new Promotion())->setTitle($title)->setDiscountType($type)->setDiscountValue($value)
            ->setAppliesToAll($all)->setActive($active)
            ->setStartsAt(new \DateTimeImmutable($starts))->setEndsAt(null !== $ends ? new \DateTimeImmutable($ends) : null);
        array_map($promotion->addCategory(...), $categories);
        array_map($promotion->addProduct(...), $products);
        $this->em->persist($promotion);
        $this->em->flush();

        return $promotion;
    }

    private function plan(int $months = 1, bool $firstOrder = false, ?string $code = null): MediaPlan
    {
        return (new MediaPlan())->setMonths($months)->setFirstOrder($firstOrder)->setPromoCode($code);
    }

    /** A fresh resolver: the service keeps the running promotions for the whole request */
    private function resolver(): PromotionResolver
    {
        return new PromotionResolver(static::getContainer()->get(PromotionRepository::class), $this->clock);
    }

    /**
     * @param list<Promotion> $promotions
     *
     * @return list<Promotion>
     */
    private function sorted(array $promotions): array
    {
        usort($promotions, static fn (Promotion $a, Promotion $b) => $a->getId() <=> $b->getId());

        return $promotions;
    }
}
