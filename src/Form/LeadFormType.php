<?php

namespace App\Form;

use App\Entity\Lead;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A request as the client sent it, corrected by a manager: contacts, requisites, payment, the comment
 * and the positions (their periods and slots; a position may be removed, not added — that's for the media plan).
 * Status, assignee and the manager's note stay in the small form on the request's card.
 */
class LeadFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('contactName', TextType::class, [
                'label' => 'Контактное лицо',
            ])
            ->add('phone', TelType::class, [
                'label' => 'Телефон',
                'attr' => ['placeholder' => '+7 900 000-00-00'],
            ])
            ->add('email', EmailType::class, [
                'label' => 'Email',
                'required' => false,
            ])
            ->add('companyName', TextType::class, [
                'label' => 'Организация',
                'required' => false,
                'help' => 'Пусто — частное лицо.',
            ])
            ->add('inn', TextType::class, [
                'label' => 'ИНН',
                'required' => false,
                'attr' => ['inputmode' => 'numeric'],
            ])
            ->add('kpp', TextType::class, [
                'label' => 'КПП',
                'required' => false,
                'attr' => ['inputmode' => 'numeric'],
            ])
            ->add('paymentType', ChoiceType::class, [
                'label' => 'Оплата',
                'required' => false,
                'placeholder' => 'Не указана',
                'choices' => [
                    'Предоплата' => Lead::PAYMENT_PREPAY,
                    'Постоплата' => Lead::PAYMENT_POSTPAY,
                ],
            ])
            ->add('comment', TextareaType::class, [
                'label' => 'Комментарий клиента',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            // rows are removed by assets/admin/collection.js; by_reference off, so Lead::removeItem() drops them
            ->add('items', CollectionType::class, [
                'label' => false,
                'entry_type' => LeadItemFormType::class,
                'entry_options' => ['label' => false],
                'allow_delete' => true,
                'by_reference' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Lead::class,
        ]);
    }
}
