<?php

namespace App\Form;

use App\Entity\MediaPlan;
use App\Entity\User;
use App\Service\MonthCalendar;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

class MediaPlanFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'Название',
                'attr' => ['placeholder' => 'Осенняя кампания, центр города'],
            ])
            ->add('client', EntityType::class, [
                'label' => 'Клиент из списка',
                'class' => User::class,
                'query_builder' => static fn (EntityRepository $r): QueryBuilder => $r->createQueryBuilder('u')
                    ->andWhere('u.roles LIKE :client')
                    // accounts from the website get plans and payments once their e-mail is confirmed
                    ->andWhere('u.emailVerifiedAt IS NOT NULL')
                    ->setParameter('client', '%'.User::ROLE_CLIENT.'%')
                    ->orderBy('u.company', 'ASC')
                    ->addOrderBy('u.name', 'ASC'),
                'choice_label' => static fn (User $client): string => $client->getClientTitle().($client->getClientType() ? ' · '.$client->getClientType()->label() : ''),
                'required' => false,
                'placeholder' => 'Не выбран',
                'help' => 'Нужен, чтобы составить график платежей. Клиентов с неподтверждённой почтой в списке нет.',
            ]);
        NewClientFields::add($builder);
        // a new client names the plan's client, so "Клиент в PDF" may stay empty (MediaPlan::validateClient())
        $builder->addEventListener(FormEvents::SUBMIT, static function (FormEvent $event): void {
            /** @var MediaPlan $plan */
            $plan = $event->getData();
            $title = trim((string) $event->getForm()->get('newClient')->getData());
            if (null === $plan->getClient() && '' !== $title && null === $plan->getClientName()) {
                $plan->setClientName($title);
            }
        });
        $builder
            ->add('clientName', TextType::class, [
                'label' => 'Клиент в PDF',
                'required' => false,
                'attr' => ['placeholder' => 'ООО «Ромашка»'],
                'help' => 'Пусто — название клиента из списка.',
            ])
            ->add('clientContact', TextType::class, [
                'label' => 'Контакт клиента',
                'required' => false,
                'attr' => ['placeholder' => '+7 900 000-00-00, mail@example.com'],
            ])
            ->add('startMonth', ChoiceType::class, [
                'label' => 'С месяца',
                // month objects as choices, matched by "YYYY-MM" (a loaded plan has a different instance)
                'choices' => MonthCalendar::range($options['now'], 12),
                'choice_value' => static fn (?\DateTimeInterface $month) => $month?->format('Y-m'),
                'choice_label' => static fn (\DateTimeInterface $month) => MonthCalendar::label($month),
                'placeholder' => false,
            ])
            ->add('months', ChoiceType::class, [
                'label' => 'Срок',
                'choices' => array_combine(array_map(static fn (int $n) => $n.' мес.', range(1, 12)), range(1, 12)),
            ])
            ->add('discountPercent', IntegerType::class, [
                'label' => 'Скидка, %',
                'attr' => ['min' => 0, 'max' => 90],
            ])
            ->add('promoCode', TextType::class, [
                'label' => 'Промокод',
                'required' => false,
                'attr' => ['placeholder' => 'WELCOME', 'class' => 'input uppercase', 'autocomplete' => 'off'],
                'help' => 'Открывает акции с этим кодом.',
            ])
            ->add('firstOrder', CheckboxType::class, [
                'label' => 'Первый заказ клиента',
                'required' => false,
                'help' => 'Открывает акции «только на первый заказ».',
            ])
            ->add('comment', TextareaType::class, [
                'label' => 'Комментарий для клиента',
                'required' => false,
                'attr' => ['rows' => 3],
                'help' => 'Попадёт в PDF.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => MediaPlan::class,
        ]);
        $resolver->setRequired('now');
        $resolver->setAllowedTypes('now', \DateTimeInterface::class);
    }
}
