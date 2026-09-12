<?php

namespace App\Form;

use App\Entity\Category;
use App\Entity\ProductType;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class CategoryFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Название',
                'attr' => ['placeholder' => 'Билборд 6х3'],
            ])
            ->add('shortDescription', TextType::class, [
                'label' => 'Краткое описание',
                'required' => false,
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Описание',
                'required' => false,
                'attr' => ['rows' => 5],
            ])
            ->add('productTypes', EntityType::class, [
                'label' => 'Доступные типы',
                'class' => ProductType::class,
                'query_builder' => static fn (EntityRepository $r): QueryBuilder => $r->createQueryBuilder('t')->orderBy('t.name', 'ASC'),
                'multiple' => true,
                'expanded' => true,
                'required' => false,
                // inverse side of the relation: go through add/removeProductType()
                'by_reference' => false,
            ])
            // Stored by FileUploader in the controller after validation.
            ->add('imageFile', FileType::class, [
                'label' => 'Изображение',
                'mapped' => false,
                'required' => false,
                'help' => 'JPG, PNG или WebP, до 10 МБ.',
                'constraints' => [
                    new Assert\Image(maxSize: '10M', mimeTypes: ['image/jpeg', 'image/png', 'image/webp']),
                ],
            ])
            ->add('removeImage', CheckboxType::class, [
                'label' => 'Удалить текущее изображение',
                'mapped' => false,
                'required' => false,
            ])
            ->add('seoTitle', TextType::class, [
                'label' => 'SEO title',
                'required' => false,
            ])
            ->add('seoDescription', TextareaType::class, [
                'label' => 'SEO description',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('seoKeywords', TextType::class, [
                'label' => 'SEO keywords',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Category::class,
        ]);
    }
}
