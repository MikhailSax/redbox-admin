<?php

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class UserFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $passwordConstraints = [new Assert\Length(min: 8, max: 4096, minMessage: 'Пароль должен быть не короче {{ limit }} символов')];
        if ($options['require_password']) {
            $passwordConstraints[] = new Assert\NotBlank(message: 'Задайте пароль');
        }

        $builder
            ->add('name', TextType::class, [
                'label' => 'Имя',
                'attr' => ['placeholder' => 'Иван Петров'],
            ])
            ->add('email', EmailType::class, [
                'label' => 'Email (логин)',
                'attr' => ['autocomplete' => 'off'],
            ])
            ->add('role', ChoiceType::class, [
                'label' => 'Роль',
                'choices' => array_flip(User::ROLE_LABELS),
                'expanded' => true,
                'constraints' => [new Assert\NotBlank(message: 'Выберите роль')],
                'disabled' => !$options['can_change_role'],
                'help' => $options['can_change_role'] ? 'Администратор дополнительно управляет пользователями.' : 'Свою роль изменить нельзя.',
            ])
            // Hashed by the controller; empty on edit means "keep the current password".
            ->add('plainPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'mapped' => false,
                'required' => $options['require_password'],
                'first_options' => [
                    'label' => $options['require_password'] ? 'Пароль' : 'Новый пароль',
                    'help' => $options['require_password'] ? null : 'Оставьте пустым, чтобы не менять.',
                    'attr' => ['autocomplete' => 'new-password'],
                ],
                'second_options' => [
                    'label' => 'Повторите пароль',
                    'attr' => ['autocomplete' => 'new-password'],
                ],
                'invalid_message' => 'Пароли не совпадают',
                'constraints' => $passwordConstraints,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
            'require_password' => true,
            'can_change_role' => true,
        ]);
        $resolver->setAllowedTypes('require_password', 'bool');
        $resolver->setAllowedTypes('can_change_role', 'bool');
    }
}
