<?php

namespace App\Form;

use App\Entity\MediaPlan;
use App\Entity\Payment;
use App\Entity\User;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A payment of the calendar: who pays, for what, how much and by when. Paid / unpaid is set by buttons.
 */
class PaymentFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('client', EntityType::class, [
                'label' => 'Клиент',
                'class' => User::class,
                'query_builder' => static fn (EntityRepository $r): QueryBuilder => $r->createQueryBuilder('u')
                    ->andWhere('u.roles LIKE :client')
                    // accounts from the website get plans and payments once their e-mail is confirmed
                    ->andWhere('u.emailVerifiedAt IS NOT NULL')
                    ->setParameter('client', '%'.User::ROLE_CLIENT.'%')
                    ->orderBy('u.company', 'ASC')
                    ->addOrderBy('u.name', 'ASC'),
                'choice_label' => static fn (User $client): string => $client->getClientTitle().($client->getClientType() ? ' · '.$client->getClientType()->label() : ''),
                'placeholder' => 'Выберите клиента',
            ])
            ->add('mediaPlan', EntityType::class, [
                'label' => 'Медиаплан',
                'class' => MediaPlan::class,
                'query_builder' => static fn (EntityRepository $r): QueryBuilder => $r->createQueryBuilder('m')->orderBy('m.id', 'DESC'),
                'choice_label' => static fn (MediaPlan $plan): string => \sprintf('#%d %s · %s', $plan->getId(), $plan->getTitle(), $plan->getClient()?->getClientTitle() ?? $plan->getClientName()),
                'required' => false,
                'placeholder' => 'Без медиаплана',
            ])
            ->add('title', TextType::class, [
                'label' => 'За что',
                'attr' => ['placeholder' => 'Размещение, октябрь 2026'],
            ])
            ->add('amount', MoneyType::class, [
                'label' => 'Сумма',
                'currency' => 'RUB',
                'input' => 'string',
                'invalid_message' => 'Введите сумму числом, например 45000',
            ])
            ->add('dueDate', DateType::class, [
                'label' => 'Оплатить до',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'invalid_message' => 'Укажите дату',
            ])
            ->add('comment', TextareaType::class, [
                'label' => 'Комментарий',
                'required' => false,
                'attr' => ['rows' => 2, 'placeholder' => 'Счёт №125, оплата по безналу'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Payment::class,
        ]);
    }
}
