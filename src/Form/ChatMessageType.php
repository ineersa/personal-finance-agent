<?php

declare(strict_types=1);

namespace App\Form;

use App\Chat\Workspace;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Uuid;

final class ChatMessageType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('question', TextareaType::class, [
                'label' => 'Your message',
                'label_attr' => ['class' => 'visually-hidden'],
                'attr' => ['rows' => 1, 'placeholder' => 'Ask a question or follow up…', 'data-chat-view-target' => 'input', 'data-action' => 'input->chat-view#resizeInput keydown->chat-view#handleKey'],
                'constraints' => [new NotBlank(message: 'Write a message first.')],
            ])
            ->add('conversation', HiddenType::class, ['required' => false, 'constraints' => [new Uuid()]]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['csrf_token_id' => Workspace::SUBMIT_CSRF, 'allow_extra_fields' => true]);
    }

    public function getBlockPrefix(): string
    {
        return '';
    }
}
