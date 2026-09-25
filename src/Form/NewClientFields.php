<?php

namespace App\Form;

use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;

/**
 * "…или новый клиент": fields to make a client card on the spot, in a form that otherwise picks one from the list.
 * Unmapped; the controller turns them into a card with ClientCards when no client is picked
 * (an existing card with the same ИНН, email or name is taken instead).
 */
final class NewClientFields
{
    /**
     * @param bool $mapTitle the form's data has a "newClient" property of its own (to validate "a client or a new one")
     */
    public static function add(FormBuilderInterface $builder, bool $mapTitle = false): void
    {
        $builder
            ->add('newClient', TextType::class, [
                'label' => 'Новый клиент',
                'mapped' => $mapTitle,
                'required' => false,
                'attr' => ['placeholder' => 'ООО «Ромашка», ИП Иванов И. И. или ФИО', 'maxlength' => 255],
                'help' => 'Если клиента нет в списке: карточка заведётся сама.',
            ])
            ->add('newClientPhone', TelType::class, [
                'label' => 'Телефон',
                'mapped' => false,
                'required' => false,
                'attr' => ['placeholder' => '+7 900 000-00-00', 'maxlength' => 50],
            ])
            ->add('newClientEmail', EmailType::class, [
                'label' => 'Email',
                'mapped' => false,
                'required' => false,
                'attr' => ['maxlength' => 180],
            ])
            ->add('newClientInn', TextType::class, [
                'label' => 'ИНН',
                'mapped' => false,
                'required' => false,
                'attr' => ['inputmode' => 'numeric', 'maxlength' => 14],
                'help' => '10 цифр — организация, 12 — ИП, пусто — физ. лицо.',
            ]);
    }

    /**
     * @return array{title: string, phone: ?string, email: ?string, inn: ?string}|null null when no new client is typed
     */
    public static function data(FormInterface $form): ?array
    {
        $title = trim((string) $form->get('newClient')->getData());
        if ('' === $title) {
            return null;
        }

        return [
            'title' => $title,
            'phone' => $form->get('newClientPhone')->getData(),
            'email' => $form->get('newClientEmail')->getData(),
            'inn' => $form->get('newClientInn')->getData(),
        ];
    }
}
