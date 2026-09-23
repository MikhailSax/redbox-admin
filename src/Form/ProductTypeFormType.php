<?php

namespace App\Form;

use App\Entity\Category;
use App\Entity\ProductType;
use App\Enum\BookingMode;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ProductTypeFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Название',
                'attr' => ['placeholder' => 'Призматрон'],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Описание',
                'required' => false,
                'attr' => ['rows' => 4],
            ])
            ->add('bookingMode', EnumType::class, [
                'label' => 'Как бронируется',
                'class' => BookingMode::class,
                'choice_label' => static fn (BookingMode $mode): string => $mode->label(),
                'expanded' => true,
                'help' => 'Видеоэкраны продаются эфиром: блок делится на слоты (обычно 12 по 5 сек), клиент берёт слоты. Остальные — сторона целиком на месяц.',
            ])
            ->add('categories', EntityType::class, [
                'label' => 'Категории, в которых доступен тип',
                'class' => Category::class,
                'query_builder' => static fn (EntityRepository $r): QueryBuilder => $r->createQueryBuilder('c')->orderBy('c.name', 'ASC'),
                'multiple' => true,
                'expanded' => true,
                'required' => false,
                'by_reference' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ProductType::class,
        ]);
    }
}
