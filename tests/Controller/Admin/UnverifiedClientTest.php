<?php

namespace App\Tests\Controller\Admin;

use App\Entity\Category;
use App\Entity\ClientDocument;
use App\Entity\District;
use App\Entity\Lead;
use App\Entity\LeadItem;
use App\Entity\MediaPlan;
use App\Entity\Payment;
use App\Entity\Product;
use App\Entity\ProductSide;
use App\Entity\ProductType;
use App\Entity\User;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * An account that signed up on the website with an unconfirmed e-mail gets no documents, plans or payments in the CRM,
 * until the client confirms the address or a manager does it after checking with the client.
 */
final class UnverifiedClientTest extends AdminWebTestCase
{
    private User $stranger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stranger = $this->createUser('ivan@romashka.ru', User::ROLE_CLIENT)->setCompany('ООО «Ромашка»')->setEmailVerifiedAt(null);
        $this->em->flush();
    }

    public function testNoDocumentsForAnUnconfirmedAccountUntilTheManagerConfirmsIt(): void
    {
        $crawler = $this->client->request('GET', '/admin/clients/'.$this->stranger->getId());
        self::assertSelectorTextContains('main', 'Почта не подтверждена');
        self::assertCount(0, $crawler->selectButton('Загрузить'));

        // a hand-made request is refused too
        $this->client->request('POST', '/admin/clients/'.$this->stranger->getId().'/documents', [], ['client_document_form' => ['files' => [$this->makeImage('invoice.png')]]]);
        self::assertResponseRedirects();
        self::assertSame(0, $this->em->getRepository(ClientDocument::class)->count([]));
        $this->client->followRedirect();
        self::assertSelectorTextContains('[role=alert]', 'не подтвердил почту');

        $this->submitPostForm($crawler, 'form[action$="/verify-email"]');
        self::assertResponseRedirects();
        $this->em->clear();
        self::assertTrue($this->em->find(User::class, $this->stranger->getId())->isEmailVerified());

        $crawler = $this->client->request('GET', '/admin/clients/'.$this->stranger->getId());
        self::assertSelectorTextNotContains('main', 'Почта не подтверждена');
        self::assertCount(1, $crawler->selectButton('Загрузить'));
    }

    public function testPlansAndPaymentsAreNotTiedToAnUnconfirmedAccount(): void
    {
        $crawler = $this->client->request('GET', '/admin/media-plans/new');
        self::assertStringNotContainsString('ООО «Ромашка»', $crawler->filter('select[name="media_plan_form[client]"]')->text(''));

        $validator = static::getContainer()->get(ValidatorInterface::class);
        $plan = (new MediaPlan())->setTitle('Осень')->setClient($this->stranger)->setStartMonth(new \DateTimeImmutable('2026-10-01'));
        $payment = (new Payment())->setClient($this->stranger)->setTitle('Октябрь')->setAmount('1000')->setDueDate(new \DateTimeImmutable('2026-10-01'));
        foreach ([$plan, $payment] as $entity) {
            $violations = $validator->validate($entity);
            self::assertSame(['client'], array_values(array_unique(array_map(static fn ($v) => $v->getPropertyPath(), iterator_to_array($violations)))), $entity::class);
        }
    }

    public function testMediaPlanFromTheRequestOfAnUnconfirmedAccountIsNotTiedToIt(): void
    {
        $side = (new ProductSide())->setName('А');
        $product = (new Product())->setName('Щит на Ленина')->setCategory((new Category())->setName('Билборд'))->setProductType((new ProductType())->setName('Статика'))
            ->setDistrict((new District())->setName('Центр'))->setPrice('20000')->setLatitude('51.8')->setLongitude('107.6')->addSide($side);
        $lead = (new Lead())->setContactName('Иван')->setPhone('+7 900')->setClient($this->stranger)
            ->addItem(new LeadItem($side, 'Щит на Ленина', 'А', new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-31')));
        foreach ([$product->getCategory(), $product->getProductType(), $product->getDistrict(), $product, $lead] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();

        $crawler = $this->client->request('GET', '/admin/leads/'.$lead->getId());
        $this->submitPostForm($crawler, 'form[action$="/media-plan"]');
        self::assertResponseRedirects();

        $plan = $this->em->getRepository(MediaPlan::class)->findOneBy([]);
        self::assertNull($plan->getClient());
        self::assertStringContainsString('ООО «Ромашка»', $plan->getClientName()); // the name for the PDF stays
    }
}
