<?php

namespace App\Form;

use App\Entity\Category;
use App\Entity\Product;
use App\Entity\Promotion;
use App\Enum\PromotionDiscountType;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class PromotionFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'Название',
                'attr' => ['placeholder' => 'Осень в центре −15%'],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Описание для клиента',
                'required' => false,
                'attr' => ['rows' => 2, 'placeholder' => 'Скидка на размещение в центральном районе до конца октября'],
                'help' => 'Попадёт в PDF медиаплана.',
            ])
            ->add('discountType', EnumType::class, [
                'label' => 'Скидка',
                'class' => PromotionDiscountType::class,
                'expanded' => true,
                'choice_label' => static fn (PromotionDiscountType $type) => $type->label(),
            ])
            ->add('discountValue', NumberType::class, [
                'label' => 'Размер скидки',
                'input' => 'string',
                'scale' => 2,
                'attr' => ['inputmode' => 'decimal', 'placeholder' => '15'],
                'help' => 'От цены за сторону в месяц.',
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
                'label' => 'Акция включена',
                'required' => false,
            ])
            ->add('appliesToAll', CheckboxType::class, [
                'label' => 'Все конструкции',
                'required' => false,
            ])
            ->add('categories', EntityType::class, [
                'label' => 'Категории',
                'class' => Category::class,
                'choice_label' => 'name',
                'multiple' => true,
                'expanded' => true,
                'required' => false,
                'query_builder' => static fn (EntityRepository $repository): QueryBuilder => $repository->createQueryBuilder('c')->orderBy('c.name', 'ASC'),
            ])
            ->add('products', EntityType::class, [
                'label' => 'Отдельные конструкции',
                'class' => Product::class,
                'choice_label' => 'name',
                'choice_attr' => static fn (Product $product) => [
                    'data-hint' => implode(' · ', array_filter([$product->getCategory()?->getName(), $product->getDistrict()?->getName()])),
                ],
                'multiple' => true,
                'expanded' => true,
                'required' => false,
                'query_builder' => static fn (EntityRepository $repository): QueryBuilder => $repository->createQueryBuilder('p')
                    ->addSelect('c', 'd')
                    ->leftJoin('p.category', 'c')
                    ->leftJoin('p.district', 'd')
                    ->orderBy('p.name', 'ASC'),
            ])
            ->add('code', TextType::class, [
                'label' => 'Промокод',
                'required' => false,
                'attr' => ['placeholder' => 'AUTUMN15', 'class' => 'input uppercase', 'autocomplete' => 'off'],
                'help' => 'Если указан — акция работает только в медиапланах с этим кодом.',
            ])
            ->add('firstOrderOnly', CheckboxType::class, [
                'label' => 'Только на первый заказ клиента',
                'required' => false,
            ])
            ->add('minMonths', ChoiceType::class, [
                'label' => 'Срок размещения',
                'required' => false,
                'placeholder' => 'Любой',
                'choices' => array_combine(array_map(static fn (int $n) => 'от '.$n.' мес.', range(2, 12)), range(2, 12)),
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Promotion::class,
        ]);
    }
}
