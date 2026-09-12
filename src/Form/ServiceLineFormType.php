<?php

namespace App\Form;

use App\Dto\ServiceLineInput;
use App\Entity\AdditionalService;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ServiceLineFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('service', EntityType::class, [
                'label' => 'Из справочника',
                'class' => AdditionalService::class,
                'query_builder' => static fn (EntityRepository $r): QueryBuilder => $r->createQueryBuilder('s')
                    ->andWhere('s.active = true')
                    ->orderBy('s.name', 'ASC'),
                'choice_label' => static fn (AdditionalService $s) => \sprintf('%s — %s ₽/%s', $s->getName(), number_format((float) $s->getPrice(), 0, ',', ' '), $s->getUnit()),
                // read by assets/admin/service-line.js to prefill the fields
                'choice_attr' => static fn (AdditionalService $s) => ['data-name' => $s->getName(), 'data-unit' => $s->getUnit(), 'data-price' => $s->getPrice()],
                'placeholder' => 'Своя услуга…',
                'required' => false,
                'attr' => ['data-service-select' => ''],
            ])
            ->add('name', TextType::class, [
                'label' => 'Название',
                'attr' => ['placeholder' => 'Изготовление макета', 'data-service-field' => 'name'],
            ])
            ->add('quantity', NumberType::class, [
                'label' => 'Кол-во',
                'input' => 'string',
                'scale' => 2,
                'html5' => true,
                'attr' => ['step' => 'any', 'min' => 0, 'inputmode' => 'decimal'],
                'invalid_message' => 'Введите количество числом',
            ])
            ->add('unit', TextType::class, [
                'label' => 'Ед.',
                // free text with suggestions (<datalist id="service-units"> in templates/admin/_service_units.html.twig)
                'attr' => ['data-service-field' => 'unit', 'list' => 'service-units', 'autocomplete' => 'off'],
            ])
            ->add('unitPrice', MoneyType::class, [
                'label' => 'Цена за ед.',
                'currency' => 'RUB',
                'input' => 'string',
                'attr' => ['data-service-field' => 'price'],
                'invalid_message' => 'Введите цену числом',
            ]);

        // Without JavaScript: a picked catalog entry fills whatever was left empty
        $builder->addEventListener(FormEvents::SUBMIT, static function (FormEvent $event): void {
            $input = $event->getData();
            if (!$input instanceof ServiceLineInput || null === $input->service) {
                return;
            }
            if (null === $input->name || '' === trim($input->name)) {
                $input->name = $input->service->getName();
                $input->unit = $input->service->getUnit();
            }
            $input->unitPrice ??= $input->service->getPrice();
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ServiceLineInput::class,
        ]);
    }
}
