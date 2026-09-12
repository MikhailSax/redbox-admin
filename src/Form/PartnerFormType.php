<?php

namespace App\Form;

use App\Entity\Partner;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class PartnerFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Название',
                'attr' => ['placeholder' => 'ООО «Наружка Плюс»'],
            ])
            ->add('inn', TextType::class, [
                'label' => 'ИНН',
                'required' => false,
                'attr' => ['inputmode' => 'numeric', 'placeholder' => '10 или 12 цифр'],
            ])
            ->add('contactName', TextType::class, [
                'label' => 'Контактное лицо',
                'required' => false,
            ])
            ->add('phone', TelType::class, [
                'label' => 'Телефон',
                'required' => false,
            ])
            ->add('email', EmailType::class, [
                'label' => 'Email',
                'required' => false,
            ])
            ->add('comment', TextareaType::class, [
                'label' => 'Комментарий',
                'required' => false,
                'attr' => ['rows' => 3, 'placeholder' => 'Условия работы, сроки оплаты…'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Partner::class,
        ]);
    }
}
