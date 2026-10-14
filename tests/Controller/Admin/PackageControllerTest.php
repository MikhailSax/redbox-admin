<?php

namespace App\Tests\Controller\Admin;

use App\Entity\Category;
use App\Entity\Lead;
use App\Entity\LeadItem;
use App\Entity\MediaPlan;
use App\Entity\Package;
use App\Entity\PackageSlot;
use App\Entity\Product;
use App\Entity\ProductSide;
use App\Entity\ProductType;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class PackageControllerTest extends AdminWebTestCase
{
    use ClockSensitiveTrait;

    /** @var list<ProductSide> */
    private array $sides = [];

    protected function setUp(): void
    {
        parent::setUp();
        self::mockTime('2026-10-14 12:00:00');

        $category = (new Category())->setName('Билборд');
        $type = (new ProductType())->setName('Статика');
        $this->em->persist($category);
        $this->em->persist($type);
        foreach (['Борсоева', 'Автомобилистов', 'Терешковой', 'Бурвод'] as $name) {
            $side = (new ProductSide())->setName('Б')->setPrice('25000');
            $this->em->persist((new Product())->setName($name)->setCategory($category)->setProductType($type)->setPrice('25000')->addSide($side));
            $this->sides[] = $side;
        }
        $this->em->flush();
    }

    public function testCreatePackageWithPositions(): void
    {
        $crawler = $this->client->request('GET', '/admin/packages/new');
        self::assertResponseIsSuccessful();
        // a new package starts with one empty position
        self::assertCount(1, $crawler->filter('#package-slots [data-collection-item]'));

        [$values, $uri] = $this->formValues($crawler, 'Создать пакет');
        $values['package_form']['title'] = 'Выгодный';
        $values['package_form']['minMonths'] = '1';
        $values['package_form']['slots'] = [
            0 => ['title' => 'Суперсайт Борсоева', 'quantity' => '1', 'price' => '11250', 'sides' => [(string) $this->sides[0]->getId()]],
            // a position added in the browser from the prototype
            1 => ['title' => 'Щиты', 'quantity' => '3', 'price' => '11250', 'sides' => array_map(static fn (ProductSide $side) => (string) $side->getId(), \array_slice($this->sides, 1))],
        ];
        $this->submit($uri, $values);

        $package = $this->em->getRepository(Package::class)->findOneBy([]);
        self::assertNotNull($package);
        self::assertResponseRedirects('/admin/packages/'.$package->getId().'/edit');
        self::assertSame(['Суперсайт Борсоева', 'Щиты'], $package->getSlots()->map(static fn (PackageSlot $slot) => $slot->getTitle())->toArray());
        self::assertSame(4, $package->getSideCount());
        self::assertSame(45000.0, $package->getFixedMonthlyTotal());

        // the card shows what the website offers for November
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('main aside', 'Набор в месяц');
        // money() puts a non-breaking space between thousands
        self::assertMatchesRegularExpression('/Набор в месяц\s*45\s000/u', $crawler->filter('main aside')->text());

        $this->client->request('GET', '/admin/packages');
        self::assertSelectorTextContains('.data-table', 'Выгодный');
        self::assertSelectorTextContains('.data-table', 'Щиты × 3');
    }

    public function testPositionNeedsAsManySidesAsItTakes(): void
    {
        $crawler = $this->client->request('GET', '/admin/packages/new');
        [$values, $uri] = $this->formValues($crawler, 'Создать пакет');
        $values['package_form']['title'] = 'Максимум';
        $values['package_form']['slots'] = [
            0 => ['title' => 'Экраны 6×3', 'quantity' => '4', 'price' => '14800', 'sides' => [(string) $this->sides[0]->getId()]],
        ];
        $this->submit($uri, $values);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#package-slots', 'Выберите не меньше 4 сторон');
        self::assertSame(0, $this->em->getRepository(Package::class)->count([]));

        // and a package without positions is not a package
        $values['package_form']['slots'] = [];
        $this->submit($uri, $values);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#package-form', 'Добавьте хотя бы одну позицию');
    }

    public function testMediaPlanFromAPackageRequestKeepsThePackagePrices(): void
    {
        $package = (new Package())->setTitle('Специальный')->setStartsAt(new \DateTimeImmutable('2026-10-01'));
        $package->addSlot((new PackageSlot())->setTitle('Щит')->setQuantity(1)->addSide($this->sides[0]));
        $package->addSlot((new PackageSlot())->setTitle('Экран в подарок')->setQuantity(1)->setPrice('0')->addSide($this->sides[1]));
        $this->em->persist($package);

        $lead = (new Lead())->setContactName('Иван')->setPhone('+7 900 111-22-33')->setPackage($package);
        foreach ([[$this->sides[0], '25000.00', 'Щит'], [$this->sides[1], '0.00', 'Экран в подарок']] as [$side, $price, $slot]) {
            $lead->addItem((new LeadItem($side, $side->getProduct()->getName(), 'Б', new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable('2026-12-31'), monthlyPrice: $price))->setPackageSlotTitle($slot));
        }
        $this->em->persist($lead);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/admin/leads/'.$lead->getId());
        self::assertSelectorTextContains('.page-subtitle', 'пакет «Специальный»');
        self::assertSelectorTextContains('.data-table', 'в подарок');

        $this->submitPostForm($crawler, 'form[action$="/media-plan"]');
        $plan = $this->em->getRepository(MediaPlan::class)->findOneBy([]);
        self::assertResponseRedirects('/admin/media-plans/'.$plan->getId());

        $this->em->clear();
        $plan = $this->em->find(MediaPlan::class, $plan->getId());
        self::assertSame(2, $plan->getMonths());
        $prices = [];
        foreach ($plan->getItems() as $item) {
            $prices[$item->getProduct()->getName()] = [$item->getMonthlyPrice(), $item->getPromotionTitle(), $item->isPackagePrice()];
        }
        self::assertSame([
            'Борсоева' => [25000.0, 'Пакет «Специальный»', true],
            'Автомобилистов' => [0.0, 'Пакет «Специальный»', true],
        ], $prices);
    }

    public function testToggleAndDelete(): void
    {
        $package = (new Package())->setTitle('Выгодный')->setStartsAt(new \DateTimeImmutable('2026-10-01'));
        $package->addSlot((new PackageSlot())->setTitle('Щит')->setQuantity(1)->addSide($this->sides[0]));
        $this->em->persist($package);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/admin/packages');
        $this->submitPostForm($crawler, 'form[action$="/toggle"]');
        self::assertResponseRedirects('/admin/packages');
        $this->em->clear();
        self::assertFalse($this->em->find(Package::class, $package->getId())->isActive());

        $crawler = $this->client->request('GET', '/admin/packages');
        $this->submitPostForm($crawler, 'form[action$="/delete"]');
        self::assertSame(0, $this->em->getRepository(Package::class)->count([]));
        // the sides stay
        self::assertSame(4, $this->em->getRepository(ProductSide::class)->count([]));
    }
}
