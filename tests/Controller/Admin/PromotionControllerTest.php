<?php

namespace App\Tests\Controller\Admin;

use App\Entity\Category;
use App\Entity\MediaPlan;
use App\Entity\MediaPlanItem;
use App\Entity\Product;
use App\Entity\ProductSide;
use App\Entity\ProductType;
use App\Entity\Promotion;
use App\Enum\PromotionDiscountType;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class PromotionControllerTest extends AdminWebTestCase
{
    use ClockSensitiveTrait;

    private Category $category;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        self::mockTime('2026-09-10 12:00:00');

        $this->category = (new Category())->setName('Билборд 6х3');
        $type = (new ProductType())->setName('Статика');
        $this->product = (new Product())->setName('Щит на Ленина')->setCategory($this->category)->setProductType($type)
            ->setPrice('40000')->setLatitude('55.75')->setLongitude('37.62')
            ->addSide((new ProductSide())->setName('A'));

        foreach ([$this->category, $type, $this->product] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
    }

    public function testCreatePromotionForCategoryWithPromoCode(): void
    {
        $crawler = $this->client->request('GET', '/admin/promotions/new');
        self::assertResponseIsSuccessful();

        [$values, $uri] = $this->formValues($crawler, 'Создать акцию');
        self::assertSame('2026-09-10', $values['promotion_form']['startsAt']);
        $values['promotion_form']['title'] = 'Осень −15%';
        $values['promotion_form']['discountType'] = 'percent';
        $values['promotion_form']['discountValue'] = '15';
        $values['promotion_form']['endsAt'] = '2026-10-31';
        $values['promotion_form']['categories'] = [(string) $this->category->getId()];
        $values['promotion_form']['code'] = ' autumn15 ';
        $values['promotion_form']['minMonths'] = '3';
        $this->submit($uri, $values);

        $promotion = $this->em->getRepository(Promotion::class)->findOneBy([]);
        self::assertResponseRedirects('/admin/promotions/'.$promotion->getId().'/edit#main');
        self::assertSame('AUTUMN15', $promotion->getCode());
        self::assertSame(PromotionDiscountType::Percent, $promotion->getDiscountType());
        self::assertSame(3, $promotion->getMinMonths());
        self::assertTrue($promotion->isActive());
        // the kernel reboots between requests, so compare with a freshly loaded structure
        self::assertTrue($promotion->targets($this->em->find(Product::class, $this->product->getId())));

        // the card: tabs, and the summary of what was saved
        $crawler = $this->client->followRedirect();
        self::assertSame(['Скидка', 'На что действует 1', 'Условия 2', 'В медиапланах'], $crawler->filter('[role=tablist] a')->each(static fn ($tab) => preg_replace('/\s+/u', ' ', trim($tab->text()))));
        self::assertSelectorTextContains('main aside', 'Билборд 6х3');
        self::assertSelectorTextContains('main aside', 'Только в медиаплане');

        $this->client->request('GET', '/admin/promotions');
        self::assertSelectorTextContains('.data-table', 'Осень −15%');
        self::assertSelectorTextContains('.data-table', 'AUTUMN15');
        self::assertSelectorTextContains('.data-table', 'Действует');
    }

    public function testValidation(): void
    {
        $this->promotion('Старая', code: 'TAKEN');

        $crawler = $this->client->request('GET', '/admin/promotions/new');
        [$values, $uri] = $this->formValues($crawler, 'Создать акцию');
        $values['promotion_form']['title'] = 'Слишком щедрая';
        $values['promotion_form']['discountValue'] = '95';
        $values['promotion_form']['startsAt'] = '2026-09-10';
        $values['promotion_form']['endsAt'] = '2026-09-01';
        $values['promotion_form']['code'] = 'taken';
        $this->submit($uri, $values);

        self::assertResponseStatusCodeSame(422);
        $page = $this->client->getResponse()->getContent();
        self::assertStringContainsString('Выберите категории или конструкции', $page);
        self::assertStringContainsString('Скидка — не больше 90%', $page);
        self::assertStringContainsString('не может закончиться раньше', $page);
        self::assertStringContainsString('Такой промокод уже есть', $page);
        // every tab with an invalid field is marked, the first of them is open
        $crawler = $this->client->getCrawler();
        self::assertSame(['main', 'targets', 'conditions'], $crawler->filter('[data-tab-error]')->each(static fn ($tab) => $tab->attr('data-tab')));
        self::assertStringNotContainsString('hidden', (string) $crawler->filter('[data-tab-panel="main"]')->attr('class'));
        self::assertStringContainsString('hidden', (string) $crawler->filter('[data-tab-panel="conditions"]')->attr('class'));

        $values['promotion_form']['code'] = 'с пробелом';
        $this->submit($uri, $values);
        self::assertStringContainsString('латинские буквы', $this->client->getResponse()->getContent());
    }

    public function testAllStructuresDropsChosenTargets(): void
    {
        $promotion = $this->promotion('Для щита', products: [$this->product]);

        $crawler = $this->client->request('GET', '/admin/promotions/'.$promotion->getId().'/edit');
        [$values, $uri] = $this->formValues($crawler, 'Сохранить');
        $values['promotion_form']['appliesToAll'] = '1';
        $values['_tab'] = 'targets';
        $this->submit($uri, $values);

        // back to the tab the form was saved from
        self::assertResponseRedirects('/admin/promotions/'.$promotion->getId().'/edit#targets');
        $this->em->clear();
        $promotion = $this->em->find(Promotion::class, $promotion->getId());
        self::assertTrue($promotion->isAppliesToAll());
        self::assertCount(0, $promotion->getProducts());
    }

    public function testStateFilterToggleAndDelete(): void
    {
        $running = $this->promotion('Идёт сейчас');
        $this->promotion('Будет в октябре', starts: '2026-10-01');

        $crawler = $this->client->request('GET', '/admin/promotions?state=scheduled', server: ['HTTP_X_LIVE_FILTER' => '1']);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('tbody tr'));
        self::assertStringContainsString('Будет в октябре', $crawler->filter('tbody')->text());

        $crawler = $this->client->request('GET', '/admin/promotions');
        $this->submitPostForm($crawler, 'form[action$="/'.$running->getId().'/toggle"]');
        self::assertResponseRedirects('/admin/promotions');
        $this->em->clear();
        self::assertFalse($this->em->find(Promotion::class, $running->getId())->isActive());

        $crawler = $this->client->request('GET', '/admin/promotions');
        $this->submitPostForm($crawler, 'form[action$="/'.$running->getId().'/delete"]');
        self::assertResponseRedirects('/admin/promotions');
        $this->em->clear();
        self::assertNull($this->em->find(Promotion::class, $running->getId()));
    }

    public function testBadgesOnStructures(): void
    {
        $this->promotion('Для всех', value: '10');
        $this->promotion('По коду', value: '20', code: 'SECRET');

        $crawler = $this->client->request('GET', '/admin/products');
        // conditional promotions (promo code, first order) are not advertised in the list
        self::assertSame(['−10%'], $crawler->filter('.data-table .promo-badge')->each(static fn ($badge) => $badge->text()));

        $this->client->request('GET', '/admin/products/'.$this->product->getId().'/edit');
        self::assertSelectorTextContains('main', 'Для всех');
        self::assertSelectorTextContains('main', 'По коду');

        $this->client->request('GET', '/admin/map/data');
        $point = json_decode($this->client->getResponse()->getContent(), true)['points'][0];
        self::assertSame([['label' => '−10%', 'title' => 'Для всех']], $point['promotions']);
    }

    public function testCardShowsMediaPlansAndTogglesInPlace(): void
    {
        $promotion = $this->promotion('Осень');
        $plan = (new MediaPlan())->setTitle('Кампания кафе')->setClientName('Кафе «Лето»')
            ->setStartMonth(new \DateTimeImmutable('2026-10-01'))->setMonths(2);
        $plan->addItem((new MediaPlanItem($this->product->getSides()->first(), '40000'))->applyPromotion($promotion, 36000));
        $this->em->persist($plan);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/admin/promotions/'.$promotion->getId().'/edit');
        self::assertResponseIsSuccessful();
        $usage = $crawler->filter('[data-tab-panel="usage"] tbody tr');
        self::assertCount(1, $usage);
        self::assertStringContainsString('Кампания кафе', $usage->text());
        self::assertStringContainsString('8 000', $usage->text()); // (40 000 − 36 000) × 2 months
        self::assertSelectorTextContains('[data-tab="usage"]', '1');

        $this->submitPostForm($crawler, 'main aside form[action$="/toggle"]');
        self::assertResponseRedirects('/admin/promotions/'.$promotion->getId().'/edit');
        $this->client->followRedirect();
        self::assertSelectorTextContains('h1 + span + span', 'Выключена');
    }

    public function testFoundInCommandPalette(): void
    {
        $this->promotion('Осень в центре', code: 'AUTUMN');

        $this->client->request('GET', '/admin/search?q=autumn');
        $groups = json_decode($this->client->getResponse()->getContent(), true)['groups'];
        $group = array_values(array_filter($groups, static fn (array $g) => 'Акции' === $g['title']))[0];

        self::assertSame('Осень в центре', $group['items'][0]['title']);
        self::assertSame('Акция, действует · промокод AUTUMN', $group['items'][0]['subtitle']);
        self::assertSame(['label' => '−10%', 'tone' => 'promo'], $group['items'][0]['badge']);
    }

    public function testSuperManagerManagesPromotions(): void
    {
        $this->createUser('manager@redbox.local', 'ROLE_SUPER_MANAGER');
        $this->client->loginUser($this->em->getRepository(\App\Entity\User::class)->findOneBy(['email' => 'manager@redbox.local']));

        $this->client->request('GET', '/admin/promotions');
        self::assertResponseIsSuccessful();
    }

    /**
     * @param list<Product> $products
     */
    private function promotion(string $title, string $value = '10', array $products = [], ?string $code = null, string $starts = '2026-09-01'): Promotion
    {
        $promotion = (new Promotion())->setTitle($title)->setDiscountValue($value)->setCode($code)
            ->setStartsAt(new \DateTimeImmutable($starts))->setAppliesToAll([] === $products);
        array_map($promotion->addProduct(...), $products);
        $this->em->persist($promotion);
        $this->em->flush();

        return $promotion;
    }
}
