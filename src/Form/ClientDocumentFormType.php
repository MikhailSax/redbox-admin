<?php

namespace App\Form;

use App\Dto\ClientDocumentUpload;
use App\Enum\ClientDocumentType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ClientDocumentFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('type', EnumType::class, [
                'label' => 'Тип',
                'class' => ClientDocumentType::class,
                'choice_label' => static fn (ClientDocumentType $type) => $type->label(),
            ])
            ->add('title', TextType::class, [
                'label' => 'Название',
                'required' => false,
                'attr' => ['placeholder' => 'Договор № 15 от 01.09.2026'],
                'help' => 'Пусто — по имени файла.',
            ])
            ->add('files', FileType::class, [
                'label' => 'Файлы',
                'multiple' => true,
                'attr' => ['accept' => '.pdf,.doc,.docx,.xls,.xlsx,.odt,.ods,.zip,image/*'],
                'help' => 'PDF, Word, Excel, ZIP или фото, до 25 МБ каждый. Можно несколько.',
            ])
            ->add('comment', TextType::class, [
                'label' => 'Комментарий',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ClientDocumentUpload::class,
        ]);
    }
}
