<?php

namespace App\Form;

use App\Entity\Category;
use App\Entity\District;
use App\Entity\Partner;
use App\Entity\Product;
use App\Entity\ProductType;
use App\Helpers\ProductHelper;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ProductFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $byName = static fn (EntityRepository $repository): QueryBuilder => $repository
            ->createQueryBuilder('e')
            ->orderBy('e.name', 'ASC');

        $builder
            ->add('name', TextType::class, [
                'label' => 'Название',
                'attr' => ['placeholder' => 'Билборд, пр. Ленина 12'],
            ])
            ->add('schemeNumber', TextType::class, [
                'label' => 'Номер в схеме',
                'required' => false,
                'attr' => ['placeholder' => '858 или 7/23'],
            ])
            ->add('size', ChoiceType::class, [
                'label' => 'Размер',
                'choices' => ProductHelper::sizeChoices(),
                'placeholder' => 'Не указан',
                'required' => false,
            ])
            ->add('category', EntityType::class, [
                'label' => 'Категория',
                'class' => Category::class,
                'query_builder' => $byName,
                'placeholder' => 'Выберите категорию',
            ])
            ->add('productType', EntityType::class, [
                'label' => 'Тип',
                'class' => ProductType::class,
                'query_builder' => $byName,
                'placeholder' => 'Выберите тип',
                // sides without a type of their own follow it: their slot fields show for a screen (side-airtime.js)
                'choice_attr' => static fn (ProductType $type): array => ['data-airtime' => $type->getBookingMode()->isAirtime() ? '1' : '0'],
                'attr' => ['data-product-type' => ''],
            ])
            ->add('district', EntityType::class, [
                'label' => 'Район',
                'class' => District::class,
                'query_builder' => $byName,
                'placeholder' => 'Выберите район',
            ])
            ->add('price', MoneyType::class, [
                'label' => 'Цена продажи за месяц',
                'currency' => 'RUB',
                'input' => 'string',
                'invalid_message' => 'Введите сумму числом, например 45000',
                'help' => 'За одну сторону; для видеоэкрана — за один слот. Если стороны стоят по-разному, цену стороны, цены за 2 недели, 3 и 6 месяцев — на вкладке «Стороны и фото».',
            ])
            ->add('owner', EntityType::class, [
                'label' => 'Владелец',
                'class' => Partner::class,
                'query_builder' => $byName,
                'placeholder' => 'Своя конструкция',
                'required' => false,
            ])
            ->add('purchasePrice', MoneyType::class, [
                'label' => 'Цена партнёра за месяц',
                'currency' => 'RUB',
                'input' => 'string',
                'required' => false,
                'invalid_message' => 'Введите сумму числом, например 30000',
                'help' => 'Сколько платите партнёру — из неё считается маржа.',
                // shown only while a partner is selected (assets/admin/dependent-fields.js)
                'row_attr' => ['data-visible-when' => 'product_form_owner'],
            ])
            ->add('latitude', NumberType::class, $this->coordinateOptions('Широта', '55.7539303'))
            ->add('longitude', NumberType::class, $this->coordinateOptions('Долгота', '37.6205606'))
            ->add('shortDescription', TextType::class, [
                'label' => 'Краткое описание',
                'required' => false,
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Описание',
                'required' => false,
                'attr' => ['rows' => 5],
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
            ])
            ->add('sides', CollectionType::class, [
                'label' => false,
                'entry_type' => ProductSideFormType::class,
                'entry_options' => ['label' => false],
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'prototype' => true,
            ]);

        // SUBMIT runs after the fields are mapped onto the product and before validation
        $builder->addEventListener(FormEvents::SUBMIT, self::clearPurchasePriceOfOwnStructure(...));
    }

    /**
     * Decimal degrees; html5 renders type="number", which always uses a dot regardless of locale.
     *
     * @return array<string, mixed>
     */
    private function coordinateOptions(string $label, string $example): array
    {
        return [
            'label' => $label,
            'input' => 'string',
            'scale' => 7,
            'html5' => true,
            'attr' => ['step' => 'any', 'placeholder' => $example, 'inputmode' => 'decimal'],
            'invalid_message' => \sprintf('Введите координату числом, например %s', $example),
        ];
    }

    /**
     * An own structure has no partner price: drop a value left over from a previously selected partner.
     */
    private static function clearPurchasePriceOfOwnStructure(FormEvent $event): void
    {
        $product = $event->getData();
        if ($product instanceof Product && $product->isOwn()) {
            $product->setPurchasePrice(null);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Product::class,
        ]);
    }
}
