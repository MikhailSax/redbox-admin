<?php

namespace App\Form;

use App\Entity\PhotoReport;
use App\Entity\Product;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class PhotoReportFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'Название',
                'attr' => ['placeholder' => 'Монтаж баннера'],
            ])
            ->add('shotAt', DateType::class, [
                'label' => 'Дата съёмки',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
            ])
            ->add('product', EntityType::class, [
                'label' => 'Конструкция',
                'class' => Product::class,
                'required' => false,
                'placeholder' => 'Не указана',
                'choice_label' => static fn (Product $p) => ($p->getSchemeNumber() ? '№'.$p->getSchemeNumber().' · ' : '').$p->getName(),
                'query_builder' => static fn (EntityRepository $r): QueryBuilder => $r->createQueryBuilder('p')->orderBy('p.name', 'ASC'),
            ])
            // Stored by the controller after validation
            ->add('photos', FileType::class, [
                'label' => 'Фото',
                'mapped' => false,
                'multiple' => true,
                'attr' => ['accept' => 'image/jpeg,image/png,image/webp'],
                'help' => 'JPG, PNG или WebP, до 15 МБ каждое. Можно выбрать сразу несколько.',
                'constraints' => [
                    new Assert\Count(min: 1, minMessage: 'Добавьте хотя бы одно фото'),
                    new Assert\All([new Assert\Image(maxSize: '15M', mimeTypes: ['image/jpeg', 'image/png', 'image/webp'])]),
                ],
            ])
            ->add('comment', TextType::class, [
                'label' => 'Комментарий',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => PhotoReport::class,
        ]);
    }
}
