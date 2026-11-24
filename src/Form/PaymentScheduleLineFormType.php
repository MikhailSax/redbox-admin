<?php

namespace App\Form;

use App\Dto\PaymentScheduleLine;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * One month of the payment schedule: the sum (empty — nothing that month) and the due date.
 * The month itself is fixed by the draft and is not part of the request.
 */
class PaymentScheduleLineFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('amount', MoneyType::class, [
                'label' => 'Сумма',
                'currency' => 'RUB',
                'input' => 'string',
                'required' => false,
                'invalid_message' => 'Введите сумму числом, например 45000',
                'attr' => ['data-schedule-amount' => '', 'placeholder' => '0'],
            ])
            ->add('dueDate', DateType::class, [
                'label' => 'Оплатить до',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'required' => false,
                'invalid_message' => 'Укажите дату',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => PaymentScheduleLine::class,
        ]);
    }
}
