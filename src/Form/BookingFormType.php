<?php

namespace App\Form;

use App\Dto\BookingRequest;
use App\Entity\Product;
use App\Entity\ProductSide;
use App\Enum\BookingMode;
use App\Service\MonthCalendar;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
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

        $builder
            ->add('side', EntityType::class, [
                'label' => 'Сторона',
                'class' => ProductSide::class,
                'query_builder' => static fn (EntityRepository $r): QueryBuilder => $r->createQueryBuilder('s')
                    ->andWhere('s.product = :product')
                    ->setParameter('product', $product)
                    ->orderBy('s.name', 'ASC'),
                'choice_label' => static fn (ProductSide $side): string => 'Сторона '.$side->getName().($mixed ? ' · '.($side->isAirtime() ? 'эфир' : 'на месяц') : ''),
                'choice_attr' => static fn (ProductSide $side): array => ['data-booking-mode' => $side->getBookingMode()->value],
                'placeholder' => $product->getSides()->count() > 1 ? 'Выберите сторону' : false,
            ])
            ->add('clientName', TextType::class, [
                'label' => 'Клиент',
                'attr' => ['placeholder' => 'ООО «Ромашка»'],
            ])
            ->add('clientPhone', TelType::class, [
                'label' => 'Телефон',
                'attr' => ['placeholder' => '+7 900 000-00-00'],
            ])
            ->add('comment', TextareaType::class, [
                'label' => 'Комментарий',
                'required' => false,
                'attr' => ['rows' => 2],
            ]);

        if ($airtime) {
            // Airtime is sold by days
            $today = \DateTimeImmutable::createFromInterface($options['now'])->format('Y-m-d');
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
            foreach (MonthCalendar::range($options['now'], 12) as $month) {
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
            $builder->add('clipDuration', ChoiceType::class, [
                'label' => 'Длина ролика',
                'choices' => array_combine(
                    array_map(static fn (int $s) => $s.' сек', BookingMode::CLIP_DURATIONS),
                    BookingMode::CLIP_DURATIONS,
                ),
                'expanded' => true,
                'required' => !$mixed,
                'placeholder' => false,
                'help' => \sprintf('Ролики крутятся в петле %d секунд.', BookingMode::LOOP_SECONDS),
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => BookingRequest::class,
        ]);
        $resolver->setRequired(['product', 'now']);
        $resolver->setAllowedTypes('product', Product::class);
        $resolver->setAllowedTypes('now', \DateTimeInterface::class);
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
