<?php

namespace App\Form;

use App\Entity\PackageSlot;
use App\Entity\ProductSide;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * One position of a package: how many sides, from which, at what price.
 */
class PackageSlotFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'Позиция',
                'attr' => ['placeholder' => 'Экран 6×3 на выбор'],
            ])
            ->add('quantity', IntegerType::class, [
                'label' => 'Сколько сторон',
                'attr' => ['min' => 1, 'max' => PackageSlot::MAX_QUANTITY, 'inputmode' => 'numeric'],
            ])
            ->add('price', NumberType::class, [
                'label' => 'Цена за сторону в месяц, ₽',
                'required' => false,
                'input' => 'string',
                'scale' => 2,
                'attr' => ['inputmode' => 'decimal', 'placeholder' => 'по прайсу'],
                'help' => 'Пусто — по прайсу стороны, 0 — в подарок.',
            ])
            ->add('sides', EntityType::class, [
                'label' => 'Из каких сторон выбирать',
                'class' => ProductSide::class,
                'choice_label' => static fn (ProductSide $side) => \sprintf('%s · сторона %s', $side->getProduct()?->getName(), $side->getName()),
                'choice_attr' => static fn (ProductSide $side) => [
                    'data-hint' => implode(' · ', array_filter([
                        $side->getEffectiveProductType()?->getName(),
                        $side->getProduct()?->getSizeLabel(),
                        $side->getProduct()?->getDistrict()?->getName(),
                        $side->isWorking() ? null : 'не работает',
                    ])),
                ],
                'multiple' => true,
                'expanded' => true,
                'required' => false,
                'query_builder' => static fn (EntityRepository $repository): QueryBuilder => $repository->createQueryBuilder('s')
                    ->addSelect('p', 'd', 't', 'st')
                    ->join('s.product', 'p')
                    ->leftJoin('p.district', 'd')
                    ->leftJoin('p.productType', 't')
                    ->leftJoin('s.productType', 'st')
                    ->orderBy('p.name', 'ASC')
                    ->addOrderBy('s.name', 'ASC'),
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => PackageSlot::class,
        ]);
    }
}
