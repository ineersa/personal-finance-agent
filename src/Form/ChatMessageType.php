<?php

declare(strict_types=1);

namespace App\Form;

use App\Chat\ChatModel;
use App\Chat\ReasoningEffort;
use App\Chat\Workspace;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\Validator\Constraints\Uuid;

final class ChatMessageType extends AbstractType
{
    public function __construct(#[Autowire('%env(CHAT_MODEL)%')] private readonly string $model, #[Autowire('%env(CHAT_REASONING_EFFORT)%')] private readonly string $effort)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('model', EnumType::class, [
                'class' => ChatModel::class,
                'constraints' => [new NotNull()],
                'choice_label' => static fn (ChatModel $choice): string => $choice->label(),
                'choice_attr' => static fn (ChatModel $choice): array => ['data-efforts' => json_encode(array_column($choice->efforts(), 'value'), \JSON_THROW_ON_ERROR)],
                'attr' => ['data-chat-model-target' => 'model', 'data-action' => 'change->chat-model#update'],
            ])
            ->add('effort', EnumType::class, [
                'class' => ReasoningEffort::class,
                'constraints' => [new NotNull()],
                'label' => 'Reasoning level',
                'attr' => ['data-chat-model-target' => 'effort'],
            ])
            ->add('question', TextareaType::class, [
                'label' => 'Your message',
                'label_attr' => ['class' => 'visually-hidden'],
                'attr' => ['rows' => 1, 'placeholder' => 'Ask a question or follow up…', 'data-chat-view-target' => 'input', 'data-action' => 'input->chat-view#resizeInput keydown->chat-view#handleKey'],
                'constraints' => [new NotBlank(message: 'Write a message first.')],
            ])
            ->add('conversation', HiddenType::class, ['required' => false, 'constraints' => [new Uuid()]]);
        $builder->addEventListener(FormEvents::POST_SUBMIT, static function (FormEvent $event): void {
            $form = $event->getForm();
            $model = $form->get('model')->getData();
            $effort = $form->get('effort')->getData();
            if ($model instanceof ChatModel && $effort instanceof ReasoningEffort) {
                try {
                    $model->validateEffort($effort);
                } catch (\DomainException $error) {
                    $form->get('effort')->addError(new FormError($error->getMessage()));
                }
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $model = ChatModel::from($this->model);
        $effort = ReasoningEffort::from($this->effort);
        $model->validateEffort($effort);
        $resolver->setDefaults(['data' => [], 'csrf_token_id' => Workspace::SUBMIT_CSRF, 'allow_extra_fields' => true]);
        $resolver->setNormalizer('data', static function (Options $options, ?array $data) use ($model, $effort): array {
            $data ??= [];
            $data['model'] ??= $model;
            $data['effort'] ??= $effort;

            return $data;
        });
    }

    public function getBlockPrefix(): string
    {
        return '';
    }
}
