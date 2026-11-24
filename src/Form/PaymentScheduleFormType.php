<?php

namespace App\Form;

use App\Dto\PaymentSchedule;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The payment schedule of a booking or a media plan: for each month of placement, the sum the client pays and
 * its due date, typed by the manager. The months come from the draft (PaymentScheduler); rows are not added.
 */
class PaymentScheduleFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('lines', CollectionType::class, [
            'label' => false,
            'entry_type' => PaymentScheduleLineFormType::class,
            'entry_options' => ['label' => false],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => PaymentSchedule::class,
        ]);
    }
}
