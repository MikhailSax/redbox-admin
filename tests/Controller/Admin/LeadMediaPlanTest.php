<?php

namespace App\Tests\Controller\Admin;

use App\Entity\Category;
use App\Entity\District;
use App\Entity\Lead;
use App\Entity\LeadItem;
use App\Entity\MediaPlan;
use App\Entity\Product;
use App\Entity\ProductSide;
use App\Entity\ProductType;
use App\Entity\User;
use App\Enum\ClientType;

/**
 * A request of a visitor without an account becomes a media plan tied to a client card:
 * the card with the request's ИНН if there is one, otherwise a new card made from the request.
 */
final class LeadMediaPlanTest extends AdminWebTestCase
{
    private ProductSide $side;

    protected function setUp(): void
    {
        parent::setUp();
        $this->side = (new ProductSide())->setName('А');
        $product = (new Product())->setName('Щит на Ленина')->setCategory((new Category())->setName('Билборд'))->setProductType((new ProductType())->setName('Статика'))
            ->setDistrict((new District())->setName('Центр'))->setPrice('20000')->setLatitude('51.8')->setLongitude('107.6')->addSide($this->side);
        foreach ([$product->getCategory(), $product->getProductType(), $product->getDistrict(), $product] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
    }

    public function testGuestRequestGetsAClientCard(): void
    {
        $plan = $this->planFrom($this->lead('ООО «Байкал»', '0326021373'));

        self::assertAnySelectorTextContains('[role=alert]', 'Клиент «ООО «Байкал»» добавлен в базу клиентов из заявки');
        $client = $plan->getClient();
        self::assertNotNull($client);
        self::assertSame([ClientType::Legal, '0326021373', 'Иван', 'ivan@baikal.ru', '030001001'], [$client->getClientType(), $client->getInn(), $client->getName(), $client->getEmail(), $client->getKpp()]);

        // the next request of the same company: the same card
        $second = $this->planFrom($this->lead('Байкал', '0326021373', 'other@baikal.ru'));
        self::assertAnySelectorTextContains('[role=alert]', 'уже был в базе');
        self::assertSame($client->getId(), $second->getClient()->getId());
        self::assertSame(1, $this->em->getRepository(User::class)->count(['inn' => '0326021373']));
    }

    public function testPrivatePersonWithoutRequisites(): void
    {
        $plan = $this->planFrom($this->lead(null, null));

        self::assertSame([ClientType::Individual, 'Иван'], [$plan->getClient()->getClientType(), $plan->getClient()->getName()]);
    }

    private function lead(?string $company, ?string $inn, string $email = 'ivan@baikal.ru'): Lead
    {
        $lead = (new Lead())->setContactName('Иван')->setPhone('+7 900 111-22-33')->setEmail($email)->setCompanyName($company)->setInn($inn)->setKpp(null !== $inn ? '030001001' : null)
            ->addItem(new LeadItem($this->em->find(ProductSide::class, $this->side->getId()), 'Щит на Ленина', 'А', new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-31')));
        $this->em->persist($lead);
        $this->em->flush();

        return $lead;
    }

    private function planFrom(Lead $lead): MediaPlan
    {
        $crawler = $this->client->request('GET', '/admin/leads/'.$lead->getId());
        $this->submitPostForm($crawler, 'form[action$="/media-plan"]');
        self::assertResponseRedirects();
        $this->client->followRedirect();
        $this->em->clear();

        return $this->em->getRepository(Lead::class)->find($lead->getId())->getMediaPlan();
    }
}
