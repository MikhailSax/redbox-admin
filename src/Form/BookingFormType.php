<?php

namespace App\Form;

use App\Dto\BookingRequest;
use App\Entity\Booking;
use App\Entity\Product;
use App\Entity\ProductSide;
use App\Entity\User;
use App\Enum\BookingMode;
use App\Service\MonthCalendar;
use App\Twig\AdminExtension;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class BookingFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var Product $product */
        $product = $options['product'];

        // Sides may be booked differently (a screen on one, a static poster on another): then both sets of
        // period fields are rendered and the page shows the set of the chosen side (data-booking-mode)
        $airtime = $product->hasAirtimeSides();
        $whole = $product->hasWholeSides();
        if (!$airtime && !$whole) { // no sides yet
            $airtime = (bool) $product->getProductType()?->getBookingMode()->isAirtime();
            $whole = !$airtime;
        }
        $mixed = $airtime && $whole;
        // Changing a booking: its own side stays on offer even out of order, its running month too; services live on its card
        /** @var Booking|null $editing */
        $editing = $options['booking'];

        $builder
            ->add('side', EntityType::class, [
                'label' => 'Сторона',
                'class' => ProductSide::class,
                'query_builder' => static function (EntityRepository $r) use ($product, $editing): QueryBuilder {
                    $qb = $r->createQueryBuilder('s')
                        ->andWhere('s.product = :product')
                        ->setParameter('product', $product)
                        ->orderBy('s.name', 'ASC');
                    // a side out of order takes no new bookings; a booking being changed keeps its own
                    if (null !== $editing) {
                        return $qb->andWhere('s.working = true OR s = :current')->setParameter('current', $editing->getSide());
                    }

                    return $qb->andWhere('s.working = true');
                },
                'choice_label' => static fn (ProductSide $side): string => 'Сторона '.$side->getName().($side->isAirtime() ? \sprintf(' · %s по %d сек', AdminExtension::plural($side->getSlotCount(), 'слот', 'слота', 'слотов'), $side->getSlotSeconds()) : ($mixed ? ' · на месяц' : '')),
                'choice_attr' => static fn (ProductSide $side): array => ['data-booking-mode' => $side->getBookingMode()->value],
                'placeholder' => $product->getSides()->filter(static fn (ProductSide $side) => $side->isWorking())->count() > 1 ? 'Выберите сторону' : false,
            ])
            ->add('client', EntityType::class, [
                'label' => 'Клиент',
                'class' => User::class,
                'query_builder' => static fn (EntityRepository $r): QueryBuilder => $r->createQueryBuilder('u')
                    ->andWhere('u.roles LIKE :client')
                    // accounts from the website are booked for once their e-mail is confirmed
                    ->andWhere('u.emailVerifiedAt IS NOT NULL')
                    ->setParameter('client', '%'.User::ROLE_CLIENT.'%')
                    ->orderBy('u.company', 'ASC')
                    ->addOrderBy('u.name', 'ASC'),
                'choice_label' => static fn (User $client): string => $client->getClientTitle().($client->getClientType() ? ' · '.$client->getClientType()->label() : ''),
                'choice_attr' => static fn (User $client): array => ['data-contact' => $client->getName(), 'data-phone' => $client->getPhone() ?? ''],
                'placeholder' => 'Выберите клиента',
                'attr' => ['data-booking-client' => ''],
                'required' => false,
                'help' => 'Конструкция закрепляется за карточкой клиента. Нет в списке — добавьте нового ниже.',
            ]);
        NewClientFields::add($builder, mapTitle: true);
        $builder
            ->add('clientName', TextType::class, [
                'label' => 'Контактное лицо',
                'required' => false,
                'attr' => ['placeholder' => 'Иван Петров'],
                'help' => 'Пусто — название клиента из карточки.',
            ])
            ->add('clientPhone', TelType::class, [
                'label' => 'Телефон',
                'required' => false,
                'attr' => ['placeholder' => '+7 900 000-00-00'],
                'help' => 'Пусто — телефон из карточки клиента.',
            ])
            ->add('comment', TextareaType::class, [
                'label' => 'Комментарий',
                'required' => false,
                'attr' => ['rows' => 2],
            ])
            ->add('soldPrice', MoneyType::class, [
                'label' => 'Продано за',
                'currency' => 'RUB',
                'required' => false,
                'invalid_message' => 'Введите сумму числом, например 45000 или 45000,50',
                'attr' => ['placeholder' => 'Например, 45000'],
                'help' => 'Итоговая сумма за весь период, со скидками. Не знаете сейчас — впишите позже в списке броней.',
            ]);

        if (null === $editing) {
            // rows are added and removed by assets/admin/collection.js, a catalog pick prefills one (service-line.js)
            $builder->add('services', CollectionType::class, [
                'label' => false,
                'entry_type' => ServiceLineFormType::class,
                'entry_options' => ['label' => false],
                'allow_add' => true,
                'allow_delete' => true,
                'prototype' => true,
            ]);
        }

        if ($airtime) {
            // Airtime is sold by days, two weeks at least; a running booking keeps its first day in the past
            $today = \DateTimeImmutable::createFromInterface($options['now'])->format('Y-m-d');
            if (null !== $editing && $editing->getStartDate()->format('Y-m-d') < $today) {
                $today = $editing->getStartDate()->format('Y-m-d');
            }
            $builder
                ->add('startDate', DateType::class, [
                    'label' => 'С',
                    'widget' => 'single_text',
                    'input' => 'datetime_immutable',
                    'required' => !$mixed,
                    'attr' => ['min' => $today],
                ])
                ->add('endDate', DateType::class, [
                    'label' => 'По (включительно)',
                    'widget' => 'single_text',
                    'input' => 'datetime_immutable',
                    'required' => !$mixed,
                    'attr' => ['min' => $today],
                ]);
        }

        if ($whole) {
            // Whole sides are sold by calendar months
            $months = [];
            // a running booking: from its first month on
            $first = null !== $editing && $editing->getStartDate() < MonthCalendar::firstDay($options['now']) ? $editing->getStartDate() : $options['now'];
            $count = 11 + \count(MonthCalendar::between(MonthCalendar::firstDay($first), MonthCalendar::firstDay($options['now'])));
            foreach (MonthCalendar::range($first, $count) as $month) {
                $months[MonthCalendar::label($month)] = $month->format('Y-m');
            }
            $builder
                ->add('startMonth', ChoiceType::class, [
                    'label' => 'С месяца',
                    'choices' => $months,
                ])
                ->add('months', ChoiceType::class, [
                    'label' => 'Срок',
                    'choices' => array_combine(
                        array_map(static fn (int $n) => $n.' '.self::monthWord($n), range(1, 12)),
                        range(1, 12),
                    ),
                ]);
        }

        if ($airtime) {
            $slotCounts = $product->getSides()->filter(static fn (ProductSide $side) => $side->isAirtime())->map(static fn (ProductSide $side) => $side->getSlotCount())->getValues();
            $builder->add('slots', IntegerType::class, [
                'label' => 'Слотов',
                'required' => !$mixed,
                'attr' => ['min' => 1, 'max' => [] !== $slotCounts ? max($slotCounts) : BookingMode::DEFAULT_SLOT_COUNT],
                'help' => 'Сколько слотов блока берёт клиент — обычно 1. Экран занят, когда выкуплено всё время блока.',
            ]);

            // A 10-second slot is sold whole or by halves: two clients of 5 seconds share it
            $durations = [];
            foreach ($product->getSides() as $side) {
                if ($side->isAirtime()) {
                    $durations = array_merge($durations, $side->getSlotSecondsChoices());
                }
            }
            $durations = array_values(array_unique($durations));
            rsort($durations);
            if (\count($durations) > 1) {
                $builder->add('slotSeconds', ChoiceType::class, [
                    'label' => 'Секунд в каждом слоте',
                    'choices' => array_combine(array_map(static fn (int $s) => $s.' сек', $durations), $durations),
                    'placeholder' => 'Весь слот',
                    'required' => false,
                    'help' => 'Слот 10 сек можно продать целиком или половину — 5 сек; вторая половина остаётся свободной для другого клиента.',
                ]);
            }
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => BookingRequest::class,
            // the booking being changed; null for a new one
            'booking' => null,
        ]);
        $resolver->setRequired(['product', 'now']);
        $resolver->setAllowedTypes('product', Product::class);
        $resolver->setAllowedTypes('now', \DateTimeInterface::class);
        $resolver->setAllowedTypes('booking', ['null', Booking::class]);
    }

    private static function monthWord(int $n): string
    {
        return match (true) {
            1 === $n % 10 && 11 !== $n % 100 => 'месяц',
            \in_array($n % 10, [2, 3, 4], true) && !\in_array($n % 100, [12, 13, 14], true) => 'месяца',
            default => 'месяцев',
        };
    }
}
