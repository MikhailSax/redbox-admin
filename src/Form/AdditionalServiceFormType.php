<?php

namespace App\Form;

use App\Entity\AdditionalService;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class AdditionalServiceFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Название',
                'attr' => ['placeholder' => 'Печать баннера'],
            ])
            ->add('unit', TextType::class, [
                'label' => 'Единица',
                // free text with suggestions (<datalist id="service-units"> in templates/admin/_service_units.html.twig)
                'attr' => ['list' => 'service-units', 'autocomplete' => 'off', 'placeholder' => 'шт, м², час…'],
            ])
            ->add('price', MoneyType::class, [
                'label' => 'Цена для клиента за единицу',
                'currency' => 'RUB',
                'input' => 'string',
                'invalid_message' => 'Введите сумму числом',
            ])
            ->add('costPrice', MoneyType::class, [
                'label' => 'Себестоимость за единицу',
                'currency' => 'RUB',
                'input' => 'string',
                'required' => false,
                'invalid_message' => 'Введите сумму числом',
                'help' => 'Сколько платите типографии или монтажникам — для расчёта маржи. Клиент её не видит.',
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Описание',
                'required' => false,
                'attr' => ['rows' => 3, 'placeholder' => 'Баннер 440 г/м², люверсы по периметру'],
            ])
            ->add('active', CheckboxType::class, [
                'label' => 'Доступна для добавления в медиапланы',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => AdditionalService::class,
        ]);
    }
}
