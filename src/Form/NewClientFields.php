<?php

namespace App\Form;

use App\Enum\ClientType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
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
            ->add('newClientType', EnumType::class, [
                'label' => 'Кто клиент',
                'class' => ClientType::class,
                'choice_label' => static fn (ClientType $type): string => $type->label(),
                'mapped' => false,
                'required' => false,
                'expanded' => true,
                'placeholder' => false,
                'help' => 'Не выбрано — по ИНН: 10 цифр — юр. лицо, 12 — ИП, пусто — физ. лицо.',
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
                'help' => 'У юр. лица 10 цифр, у ИП 12; у физ. лица не нужен.',
            ]);
    }

    /**
     * @return array{title: string, type: ?ClientType, phone: ?string, email: ?string, inn: ?string}|null null when no new client is typed
     */
    public static function data(FormInterface $form): ?array
    {
        $title = trim((string) $form->get('newClient')->getData());
        if ('' === $title) {
            return null;
        }

        return [
            'title' => $title,
            'type' => $form->get('newClientType')->getData(),
            'phone' => $form->get('newClientPhone')->getData(),
            'email' => $form->get('newClientEmail')->getData(),
            'inn' => $form->get('newClientInn')->getData(),
        ];
    }
}
