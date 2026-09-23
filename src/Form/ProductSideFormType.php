<?php

namespace App\Form;

use App\Entity\ProductSide;
use App\Entity\ProductType;
use App\Enum\BookingMode;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class ProductSideFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Сторона',
                'attr' => ['placeholder' => 'A'],
            ])
            ->add('productType', EntityType::class, [
                'label' => 'Тип стороны',
                'class' => ProductType::class,
                'query_builder' => static fn (EntityRepository $r): QueryBuilder => $r->createQueryBuilder('t')->orderBy('t.name', 'ASC'),
                'choice_label' => static fn (ProductType $type): string => $type->getName().($type->getBookingMode()->isAirtime() ? ' · эфир' : ''),
                'choice_attr' => static fn (ProductType $type): array => ['data-airtime' => $type->getBookingMode()->isAirtime() ? '1' : '0'],
                'attr' => ['data-side-type' => ''],
                'required' => false,
                'placeholder' => 'Как у конструкции',
                'help' => 'Если стороны разные: например, с одной стороны видеоэкран, с другой статика.',
            ])
            ->add('price', MoneyType::class, [
                'label' => 'Цена стороны за месяц',
                'currency' => 'RUB',
                'input' => 'string',
                'required' => false,
                'invalid_message' => 'Введите сумму числом, например 28800',
                'help' => 'Пусто — как у конструкции. У экрана — за один слот.',
            ])
            ->add('price2Weeks', MoneyType::class, [
                'label' => 'За 2 недели',
                'currency' => 'RUB',
                'input' => 'string',
                'required' => false,
                'invalid_message' => 'Введите сумму числом, например 28800',
                'help' => 'Цена за 14 дней целиком.',
            ])
            ->add('price3Months', MoneyType::class, [
                'label' => 'В месяц от 3 мес.',
                'currency' => 'RUB',
                'input' => 'string',
                'required' => false,
                'invalid_message' => 'Введите сумму числом, например 28800',
                'help' => 'Пусто — цена за месяц.',
            ])
            ->add('price6Months', MoneyType::class, [
                'label' => 'В месяц от 6 мес.',
                'currency' => 'RUB',
                'input' => 'string',
                'required' => false,
                'invalid_message' => 'Введите сумму числом, например 28800',
                'help' => 'Пусто — как от 3 мес.',
            ])
            ->add('printPrice', MoneyType::class, [
                'label' => 'Печать',
                'currency' => 'RUB',
                'input' => 'string',
                'required' => false,
                'invalid_message' => 'Введите сумму числом, например 28800',
                'help' => 'Изготовление баннера, плёнки, бэклита.',
            ])
            ->add('printNote', TextType::class, [
                'label' => 'Что печатаем',
                'required' => false,
                'attr' => ['placeholder' => 'баннер'],
            ])
            ->add('slotSeconds', ChoiceType::class, [
                'label' => 'Слот',
                'choices' => array_combine(array_map(static fn (int $s) => $s.' сек', BookingMode::SLOT_DURATIONS), BookingMode::SLOT_DURATIONS),
                'empty_data' => (string) BookingMode::DEFAULT_SLOT_SECONDS,
                'help' => 'Длина одного ролика в блоке.',
            ])
            ->add('slotCount', IntegerType::class, [
                'label' => 'Слотов в блоке',
                'attr' => ['min' => 1, 'max' => BookingMode::MAX_SLOT_COUNT],
                'empty_data' => (string) BookingMode::DEFAULT_SLOT_COUNT,
                'help' => 'Экран занят, когда разобраны все.',
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Описание стороны',
                'required' => false,
                'attr' => ['rows' => 2],
            ])
            // Files are turned into ProductSidePhoto rows by SidePhotoStorage after validation.
            ->add('newPhotos', FileType::class, [
                'label' => 'Добавить фото',
                'mapped' => false,
                'required' => false,
                'multiple' => true,
                'attr' => ['accept' => 'image/*'],
                'help' => 'JPG, PNG или WebP, до 10 МБ. Можно выбрать несколько файлов.',
                'constraints' => [
                    new Assert\All([
                        new Assert\Image(maxSize: '10M', mimeTypes: ['image/jpeg', 'image/png', 'image/webp']),
                    ]),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ProductSide::class,
        ]);
    }
}
