<?php

namespace App\Tests\Controller\Admin;

use App\Entity\Booking;
use App\Entity\Category;
use App\Entity\District;
use App\Entity\MediaPlan;
use App\Entity\MediaPlanItem;
use App\Entity\MediaPlanServiceLine;
use App\Entity\Payment;
use App\Entity\Product;
use App\Entity\ProductSide;
use App\Entity\ProductType;
use App\Entity\User;
use App\Enum\BookingStatus;
use App\Enum\ClientType;
use App\Enum\PaymentStatus;
use App\Service\PaymentException;
use App\Service\PaymentScheduler;
use App\Twig\AdminExtension;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class PaymentControllerTest extends AdminWebTestCase
{
    use ClockSensitiveTrait;

    private MockClock $clock;
    private User $company;
    private User $person;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = self::mockTime('2026-09-10 12:00:00');

        $this->company = $this->createUser('vector@example.com', User::ROLE_CLIENT)->setName('Анна Смирнова')
            ->setClientType(ClientType::Legal)->setCompany('ООО «Вектор»')->setInn('0323123456');
        $this->person = $this->createUser('petr@example.com', User::ROLE_CLIENT)->setName('Пётр Иванов')->setClientType(ClientType::Individual);
        $this->em->flush();
    }

    public function testStatusFollowsTheDates(): void
    {
        $now = new \DateTimeImmutable('2026-09-10 12:00');
        $due = static fn (string $date) => (new Payment())->setDueDate(new \DateTimeImmutable($date));

        self::assertSame(PaymentStatus::Overdue, $due('2026-09-09')->statusAt($now));
        self::assertSame(1, $due('2026-09-09')->daysOverdueAt($now));
        self::assertSame(PaymentStatus::DueSoon, $due('2026-09-10')->statusAt($now)); // due today is not late yet
        self::assertSame(PaymentStatus::DueSoon, $due('2026-09-13')->statusAt($now));
        self::assertSame(PaymentStatus::Upcoming, $due('2026-09-14')->statusAt($now));
        self::assertSame(PaymentStatus::Paid, $due('2026-09-01')->markPaid($now)->statusAt($now));
        self::assertSame(0, $due('2026-09-01')->markPaid($now)->daysOverdueAt($now));
    }

    public function testCalendarShowsWhoHasToPayAndWhen(): void
    {
        $this->payment($this->company, 'Размещение, август', '30000', '2026-08-25');             // overdue, previous month
        $this->payment($this->person, 'Баннер', '4500.50', '2026-09-08');                         // overdue
        $this->payment($this->company, 'Размещение, сентябрь', '30000', '2026-09-12');           // soon
        $this->payment($this->person, 'Размещение', '12000', '2026-09-25');                      // upcoming
        $this->payment($this->company, 'Дизайн', '5000', '2026-09-03')->markPaid(new \DateTimeImmutable('2026-09-02')); // paid
        $this->payment($this->company, 'Октябрь', '30000', '2026-10-01');                         // next month
        $this->em->flush();

        $crawler = $this->client->request('GET', '/admin/payments');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-payment-calendar] h2', 'Сентябрь 2026');

        // the grid: payments on their days, coloured by status; days of the neighbouring months are shown too
        self::assertSame(['overdue'], $crawler->filter('[data-day="2026-09-08"] [data-payment-status]')->each(static fn ($a) => $a->attr('data-payment-status')));
        self::assertSelectorTextContains('[data-day="2026-09-08"]', 'Пётр Иванов');
        self::assertSelectorTextContains('[data-day="2026-09-08"]', AdminExtension::money('4500.50'));
        self::assertSame('soon', $crawler->filter('[data-day="2026-09-12"] [data-payment-status]')->attr('data-payment-status'));
        self::assertSame('upcoming', $crawler->filter('[data-day="2026-09-25"] [data-payment-status]')->attr('data-payment-status'));
        self::assertSame('paid', $crawler->filter('[data-day="2026-09-03"] [data-payment-status]')->attr('data-payment-status'));
        self::assertSame('upcoming', $crawler->filter('[data-day="2026-10-01"] [data-payment-status]')->attr('data-payment-status'));
        self::assertCount(35, $crawler->filter('[data-day]')); // 31 Aug (Mon) … 4 Oct (Sun)

        // totals: overdue of every month, soon, what is left and what came in this month
        $totals = $crawler->filter('[data-payment-totals] a')->each(static fn ($card) => preg_replace('/\s+/u', ' ', trim($card->text())));
        self::assertStringContainsString('Просрочено 2 34 500,50 ₽', $totals[0]);
        self::assertStringContainsString('Скоро срок 1 30 000 ₽', $totals[1]);
        self::assertStringContainsString('Ожидается 3 46 500,50 ₽', $totals[2]);
        self::assertStringContainsString('Оплачено 1 5 000 ₽', $totals[3]);

        // overdue ones of every month, the oldest first
        self::assertSame(['Размещение, август', 'Баннер'], $crawler->filter('#overdue tbody tr td:nth-child(3) div:first-child')->each(static fn ($td) => $td->text()));
        self::assertSelectorTextContains('#overdue tbody tr:first-child', 'на 16 дн.');

        // the sidebar counts overdue payments
        self::assertSame('Платежи2', preg_replace('/\s+/u', '', $crawler->filter('nav a[href="/admin/payments"]')->text()));

        // the month list by status and client type
        $crawler = $this->client->request('GET', '/admin/payments?month=2026-09&status=soon');
        self::assertCount(1, $crawler->filter('#list tbody tr[data-payment]'));
        $crawler = $this->client->request('GET', '/admin/payments?month=2026-09&type=individual');
        self::assertSame(['Баннер', 'Размещение'], $crawler->filter('#list tbody tr[data-payment] td:nth-child(3) div:first-child')->each(static fn ($td) => $td->text()));
        $crawler = $this->client->request('GET', '/admin/payments?month=2026-10&q=0323');
        self::assertSame(['Октябрь'], $crawler->filter('#list tbody tr[data-payment] td:nth-child(3) div:first-child')->each(static fn ($td) => $td->text()));
    }

    public function testAddEditAndMarkPaid(): void
    {
        $crawler = $this->client->request('GET', '/admin/payments/new?client='.$this->company->getId());
        self::assertResponseIsSuccessful();
        self::assertSame((string) $this->company->getId(), $crawler->filter('select[name="payment_form[client]"] option[selected]')->attr('value'));

        [$values, $uri] = $this->formValues($crawler, 'Добавить в календарь');
        $values['payment_form']['amount'] = '';
        $values['payment_form']['dueDate'] = '';
        $this->submit($uri, $values);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'Укажите, за что платёж');
        self::assertSelectorTextContains('main', 'Укажите сумму');

        $values['payment_form']['title'] = 'Счёт №125';
        $values['payment_form']['amount'] = '45000';
        $values['payment_form']['dueDate'] = '2026-09-20';
        $this->submit($uri, $values);
        self::assertResponseRedirects('/admin/payments?month=2026-09');

        $payment = $this->em->getRepository(Payment::class)->findOneBy([]);
        self::assertSame(['Счёт №125', '45000.00', '2026-09-20'], [$payment->getTitle(), $payment->getAmount(), $payment->getDueDate()->format('Y-m-d')]);
        self::assertSame('me@redbox.local', $payment->getCreatedBy()?->getEmail());

        // the client card lists it and the unpaid sum
        $crawler = $this->client->request('GET', '/admin/clients/'.$this->company->getId());
        self::assertSelectorTextContains('#tab-payments', 'К оплате: '.AdminExtension::money(45000));
        self::assertSelectorTextContains('a[data-tab="payments"]', '1');

        // paid from the client card: back to the card
        $this->submitPostForm($crawler, '#tab-payments form[action="/admin/payments/'.$payment->getId().'/pay"]');
        self::assertResponseRedirects('/admin/clients/'.$this->company->getId().'#payments');
        $this->em->clear();
        $payment = $this->em->find(Payment::class, $payment->getId());
        self::assertTrue($payment->isPaid());
        self::assertSame('2026-09-10', $payment->getPaidAt()->format('Y-m-d'));

        // undone from the calendar
        $crawler = $this->client->request('GET', '/admin/payments');
        $this->submitPostForm($crawler, '#list form[action="/admin/payments/'.$payment->getId().'/unpay"]');
        self::assertResponseRedirects('/admin/payments?month=2026-09');
        $this->em->clear();
        self::assertFalse($this->em->find(Payment::class, $payment->getId())->isPaid());

        // edit the due date, then it is late
        $crawler = $this->client->request('GET', '/admin/payments/'.$payment->getId().'/edit');
        [$values, $uri] = $this->formValues($crawler, 'Сохранить');
        $values['payment_form']['dueDate'] = '2026-09-01';
        $this->submit($uri, $values);
        self::assertResponseRedirects('/admin/payments?month=2026-09');
        $this->client->request('GET', '/admin/payments/'.$payment->getId().'/edit');
        self::assertSelectorTextContains('main [data-payment-status]', 'Просрочен');

        // deleted
        $crawler = $this->client->getCrawler();
        $this->submitPostForm($crawler, 'form[action="/admin/payments/'.$payment->getId().'/delete"]');
        self::assertResponseRedirects('/admin/payments?month=2026-09');
        self::assertSame(0, $this->em->getRepository(Payment::class)->count([]));
    }

    public function testMediaPlanPaymentBelongsToItsClient(): void
    {
        $plan = $this->plan($this->company, months: 1);

        $crawler = $this->client->request('GET', '/admin/payments/new?plan='.$plan->getId());
        [$values, $uri] = $this->formValues($crawler, 'Добавить в календарь');
        self::assertSame((string) $this->company->getId(), $values['payment_form']['client']);
        self::assertSame('Медиаплан «Осень»', $values['payment_form']['title']);

        $values['payment_form']['client'] = (string) $this->person->getId();
        $values['payment_form']['amount'] = '1000';
        $this->submit($uri, $values);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'Медиаплан оформлен на другого клиента — ООО «Вектор»');
    }

    public function testMediaPlanIsSplitIntoMonthlyPayments(): void
    {
        // 3 months × 20 000 ₽ with 10% off = 54 000 ₽ of placement, plus 5 000 ₽ of printing
        $plan = $this->plan(null, months: 3, discount: 10);
        $plan->addServiceLine(new MediaPlanServiceLine('Печать баннера', 'шт', '1', '5000'));
        $this->em->flush();

        // no client yet: nothing to schedule, the page says where to choose one
        $crawler = $this->client->request('GET', '/admin/media-plans/'.$plan->getId());
        self::assertSelectorTextContains('#payments', 'выберите клиента');
        self::assertSelectorNotExists('#payments form[action$="/payments/schedule"]');

        $crawler = $this->client->request('GET', '/admin/media-plans/'.$plan->getId().'/edit');
        [$values, $uri] = $this->formValues($crawler, 'Сохранить');
        $values['media_plan_form']['client'] = (string) $this->company->getId();
        $values['media_plan_form']['clientName'] = '';
        $this->submit($uri, $values);
        self::assertResponseRedirects();
        $this->em->clear();
        self::assertSame('ООО «Вектор»', $this->em->find(MediaPlan::class, $plan->getId())->getClientName()); // the PDF name comes from the client

        $crawler = $this->client->request('GET', '/admin/media-plans/'.$plan->getId());
        $this->submitPostForm($crawler, '#payments form[action$="/payments/schedule"]');
        self::assertResponseRedirects('/admin/media-plans/'.$plan->getId().'#payments');
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('[role=alert]', 'График платежей составлен: 3');

        $payments = $this->em->getRepository(Payment::class)->findBy([], ['dueDate' => 'ASC']);
        self::assertSame(['23000.00', '18000.00', '18000.00'], array_map(static fn (Payment $p) => $p->getAmount(), $payments));
        self::assertSame(['2026-10-01', '2026-11-01', '2026-12-01'], array_map(static fn (Payment $p) => $p->getDueDate()->format('Y-m-d'), $payments));
        self::assertSame('Медиаплан «Осень» — Октябрь 2026 и услуги', $payments[0]->getTitle());
        self::assertSame($this->company->getId(), $payments[1]->getClient()->getId());
        self::assertSelectorTextContains('#payments', sprintf('запланировано %1$s из %1$s', AdminExtension::money(59000)));
        self::assertCount(3, $crawler->filter('#payments tbody tr[data-payment]'));
        self::assertSelectorNotExists('#payments form[action$="/payments/schedule"]'); // once

        // a second schedule is refused
        try {
            static::getContainer()->get(PaymentScheduler::class)->scheduleMediaPlan($this->em->find(MediaPlan::class, $plan->getId()));
            self::fail('Expected PaymentException');
        } catch (PaymentException $e) {
            self::assertStringContainsString('уже есть график платежей', $e->getMessage());
        }
        self::assertSame(3, $this->em->getRepository(Payment::class)->count([]));
    }

    /**
     * Money for a plan has to fix its sides: a hold left as a hold is dropped after 24 hours and the side goes
     * back on sale although the client has paid.
     */
    public function testPaidMediaPlanPaymentFixesItsBookings(): void
    {
        $plan = $this->plan($this->company, months: 1);
        $payment = $this->payment($this->company, 'Октябрь', '20000', '2026-10-01')->setMediaPlan($plan);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/admin/payments?month=2026-10');
        $this->submitPostForm($crawler, '#list form[action="/admin/payments/'.$payment->getId().'/pay"]');
        $this->client->followRedirect();
        self::assertSelectorTextContains('[role=alert]', 'Брони медиаплана закреплены: 0, создано заново: 1');

        // The plan had no bookings at all, so the side is booked and paid in one go
        $this->em->clear();
        $bookings = $this->em->getRepository(Booking::class)->findAll();
        self::assertCount(1, $bookings);
        self::assertSame(BookingStatus::Paid, $bookings[0]->getStatus());
        self::assertNull($bookings[0]->getExpiresAt());
        self::assertSame(['2026-10-01', '2026-10-31'], [$bookings[0]->getStartDate()->format('Y-m-d'), $bookings[0]->getEndDate()->format('Y-m-d')]);
    }

    /** A hold the plan made earlier is confirmed, not booked a second time */
    public function testPaymentConfirmsTheHoldTheMediaPlanAlreadyMade(): void
    {
        $plan = $this->plan($this->company, months: 1);
        $payment = $this->payment($this->company, 'Октябрь', '20000', '2026-10-01')->setMediaPlan($plan);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/admin/media-plans/'.$plan->getId());
        $this->submitPostForm($crawler, 'form[action$="/book"]');
        $this->client->followRedirect();
        self::assertSame(BookingStatus::Hold, $this->em->getRepository(Booking::class)->findAll()[0]->getStatus());

        $crawler = $this->client->request('GET', '/admin/payments?month=2026-10');
        $this->submitPostForm($crawler, '#list form[action="/admin/payments/'.$payment->getId().'/pay"]');
        $this->client->followRedirect();
        self::assertSelectorTextContains('[role=alert]', 'Брони медиаплана закреплены: 1, создано заново: 0');

        $this->em->clear();
        $bookings = $this->em->getRepository(Booking::class)->findAll();
        self::assertCount(1, $bookings);
        self::assertSame(BookingStatus::Paid, $bookings[0]->getStatus());
    }

    public function testDashboardListsUrgentPayments(): void
    {
        $this->payment($this->company, 'Размещение, сентябрь', '30000', '2026-09-05');
        $this->payment($this->person, 'Баннер', '4500', '2026-09-11');
        $this->payment($this->person, 'Октябрь', '12000', '2026-10-15'); // not urgent
        $this->em->flush();

        $crawler = $this->client->request('GET', '/admin');
        self::assertSelectorTextContains('#dashboard-payments', 'Просрочено 1 на '.AdminExtension::money(30000));
        self::assertSelectorTextContains('#dashboard-payments', 'скоро срок: 1 на '.AdminExtension::money(4500));
        self::assertSame(['Размещение, сентябрь', 'Баннер'], $crawler->filter('#dashboard-payments tbody tr[data-payment] td:nth-child(3) div:first-child')->each(static fn ($td) => $td->text()));
    }

    private function payment(User $client, string $title, string $amount, string $due): Payment
    {
        $payment = (new Payment())->setClient($client)->setTitle($title)->setAmount($amount)->setDueDate(new \DateTimeImmutable($due));
        $this->em->persist($payment);

        return $payment;
    }

    private function plan(?User $client, int $months, int $discount = 0): MediaPlan
    {
        $type = (new ProductType())->setName('Статика');
        $category = (new Category())->setName('Билборд');
        $district = (new District())->setName('Центр');
        $side = (new ProductSide())->setName('A');
        $product = (new Product())->setName('Щит на Ленина')->setCategory($category)->setProductType($type)->setDistrict($district)
            ->setPrice('20000')->setLatitude('51.8')->setLongitude('107.6')->addSide($side);
        $plan = (new MediaPlan())->setTitle('Осень')->setClientName($client?->getClientTitle() ?? 'Вектор')->setClient($client)
            ->setStartMonth(new \DateTimeImmutable('2026-10-01'))->setMonths($months)->setDiscountPercent($discount);
        $plan->addItem(new MediaPlanItem($side, '20000'));

        foreach ([$type, $category, $district, $product, $plan] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();

        return $plan;
    }
}
