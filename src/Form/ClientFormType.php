<?php

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A website client's account: the login (email) and password of the personal account, contacts.
 */
class ClientFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('company', TextType::class, [
                'label' => 'Организация',
                'required' => false,
                'attr' => ['placeholder' => 'ООО «Ромашка»'],
            ])
            ->add('name', TextType::class, [
                'label' => 'Контактное лицо',
                'attr' => ['placeholder' => 'Иван Петров'],
            ])
            ->add('email', EmailType::class, [
                'label' => 'Email',
                'help' => 'Логин в личном кабинете на сайте.',
            ])
            ->add('phone', TelType::class, [
                'label' => 'Телефон',
                'required' => false,
                'attr' => ['placeholder' => '+7 900 000-00-00'],
            ])
            // Hashed by the controller; empty keeps the current one (a new client gets a random one until they set their own)
            ->add('plainPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'mapped' => false,
                'required' => false,
                'first_options' => [
                    'label' => 'Пароль от личного кабинета',
                    'help' => 'Необязательно: клиент может задать пароль сам на сайте.',
                    'attr' => ['autocomplete' => 'new-password'],
                ],
                'second_options' => [
                    'label' => 'Повторите пароль',
                    'attr' => ['autocomplete' => 'new-password'],
                ],
                'invalid_message' => 'Пароли не совпадают',
                'constraints' => [new Assert\Length(min: 8, max: 4096, minMessage: 'Пароль — не короче {{ limit }} символов')],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
        ]);
    }
}
