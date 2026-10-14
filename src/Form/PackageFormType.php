<?php

namespace App\Form;

use App\Entity\Package;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class PackageFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $months = range(1, Package::MAX_MONTHS);

        $builder
            ->add('title', TextType::class, [
                'label' => 'Название',
                'attr' => ['placeholder' => 'Максимум'],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Описание для клиента',
                'required' => false,
                'attr' => ['rows' => 3, 'placeholder' => 'Суперсайт 12×4 и 4 экрана 6×3 на выбор — по 14 800 ₽ за сторону'],
                'help' => 'Показывается на сайте в разделе «Пакетные предложения».',
            ])
            ->add('startsAt', DateType::class, [
                'label' => 'Начало',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
            ])
            ->add('endsAt', DateType::class, [
                'label' => 'Окончание',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'required' => false,
                'help' => 'Включительно. Пусто — бессрочно.',
            ])
            ->add('active', CheckboxType::class, [
                'label' => 'Пакет включён',
                'required' => false,
            ])
            ->add('minMonths', ChoiceType::class, [
                'label' => 'Срок размещения от',
                'choices' => array_combine(array_map(static fn (int $n) => $n.' мес.', $months), $months),
            ])
            ->add('maxMonths', ChoiceType::class, [
                'label' => 'до',
                'required' => false,
                'placeholder' => 'Без ограничения',
                'choices' => array_combine(array_map(static fn (int $n) => $n.' мес.', $months), $months),
            ])
            ->add('position', IntegerType::class, [
                'label' => 'Порядок на сайте',
                'required' => false,
                'help' => 'Меньше — выше.',
            ])
            ->add('slots', CollectionType::class, [
                'label' => false,
                'entry_type' => PackageSlotFormType::class,
                'entry_options' => ['label' => false],
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'prototype' => true,
                'error_bubbling' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Package::class,
        ]);
    }
}
