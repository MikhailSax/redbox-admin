<?php

namespace App\Form;

use App\Entity\LeadItem;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * One position of a request on its edit page: the period and, for a screen, the slots.
 * The structure itself stays as the visitor chose it.
 */
class LeadItemFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('startDate', DateType::class, [
                'label' => 'С',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
            ])
            ->add('endDate', DateType::class, [
                'label' => 'По (включительно)',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
            ]);

        // Slots only for a screen's position: a whole side has none
        $builder->addEventListener(FormEvents::PRE_SET_DATA, static function (FormEvent $event): void {
            $item = $event->getData();
            if ($item instanceof LeadItem && null !== $item->getSlots()) {
                $event->getForm()->add('slots', IntegerType::class, [
                    'label' => 'Слотов',
                    'attr' => ['min' => 1],
                ]);
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => LeadItem::class,
        ]);
    }
}
