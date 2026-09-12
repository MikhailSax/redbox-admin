<?php

namespace App\Form;

use App\Entity\ProductSide;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
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
            ->add('price', MoneyType::class, [
                'label' => 'Цена стороны за месяц',
                'currency' => 'RUB',
                'input' => 'string',
                'required' => false,
                'invalid_message' => 'Введите сумму числом, например 28800',
                'help' => 'Пусто — как у конструкции.',
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
