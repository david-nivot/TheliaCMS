<?php

declare(strict_types=1);

/*
 * This file is part of the Thelia package.
 * http://www.thelia.net
 *
 * (c) OpenStudio <info@thelia.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace TheliaCMS\Form\Admin;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Range;
use Symfony\Component\Validator\Constraints\Regex;
use Symfony\Contracts\Translation\TranslatorInterface;
use TheliaCMS\Form\FieldChoices;
use TheliaCMS\Form\FieldType;
use TheliaCMS\TheliaCMS;

/**
 * One field of a form.
 *
 * Which settings apply depends on the type, and "one of these depending on that
 * select" is not something HTML validation can express — it would block on the
 * fields that do not apply. The form is posted without it and the server says
 * what is missing, field by field.
 *
 * The messages of the constraints and of the form errors are translated
 * here: the validator looks the first up in the `validators` domain, where
 * the module has no catalogue, and the form theme prints the second as they
 * are.
 */
final class CmsFormFieldType extends AbstractType
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $types = [];

        foreach (FieldType::cases() as $type) {
            $types[$type->label()] = $type->value;
        }

        $builder
            ->add('type', ChoiceType::class, [
                'label' => 'Kind of field',
                'choices' => $types,
                'attr' => ['data-cms-field-type-select' => 'true'],
            ])
            ->add('label', TextType::class, [
                'label' => 'Label',
                'help' => 'The question, as the visitor reads it. Translated per language.',
                'constraints' => [new NotBlank(), new Length(max: 255)],
            ])
            ->add('name', TextType::class, [
                'label' => 'Name',
                'help' => 'How this answer is named in the emails and in the export, for example email. Letters, digits, underscores.',
                'constraints' => [
                    new NotBlank(),
                    new Length(max: 50),
                    new Regex(pattern: '/^[a-z][a-z0-9_]*$/', message: $this->translate('Start with a letter, then lowercase letters, digits and underscores.')),
                ],
            ])
            ->add('required', CheckboxType::class, [
                'label' => 'This field has to be filled in',
                'required' => false,
            ])
            ->add('placeholder', TextType::class, [
                'label' => 'Placeholder',
                'required' => false,
                'constraints' => [new Length(max: 255)],
                'help' => 'Faint text inside the field. Never use it instead of a label.',
                'row_attr' => ['data-cms-field-setting' => 'placeholder'],
            ])
            ->add('help', TextareaType::class, [
                'label' => 'Help text',
                'required' => false,
                'attr' => ['rows' => 2],
                'help' => 'Shown under the field.',
            ])
            ->add('choices', TextareaType::class, [
                'label' => 'Answers offered',
                'required' => false,
                'attr' => ['rows' => 5],
                'help' => 'One per line, in the language being edited.',
                'row_attr' => ['data-cms-field-setting' => 'choices'],
            ])
            ->add('rows', IntegerType::class, [
                'label' => 'Height in lines',
                'constraints' => [new Range(min: 2, max: 20)],
                'row_attr' => ['data-cms-field-setting' => 'rows'],
            ]);

        $builder->addEventListener(FormEvents::POST_SUBMIT, $this->checkChoices(...));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => 'theliacms',
            'attr' => ['novalidate' => 'novalidate'],
        ]);
    }

    /**
     * A drop-down with nothing to pick from is a field nobody can answer, and
     * the form would still be accepted with it.
     */
    private function checkChoices(FormEvent $event): void
    {
        $data = $event->getData();

        if (!\is_array($data)) {
            return;
        }

        if (!FieldType::fromStorage($data['type'] ?? null)->hasChoices()) {
            return;
        }

        if ([] === FieldChoices::parse($data['choices'] ?? null)) {
            $event->getForm()->get('choices')->addError(new FormError($this->translate('Write at least one answer, one per line.')));
        }
    }

    private function translate(string $message): string
    {
        return $this->translator->trans($message, [], TheliaCMS::DOMAIN_NAME);
    }
}
