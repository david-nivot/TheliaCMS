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

namespace TheliaCMS\Settings\Admin;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;
use Symfony\Contracts\Translation\TranslatorInterface;
use TheliaCMS\Page\Field\FieldDefinition;
use TheliaCMS\Page\Field\FieldKind;
use TheliaCMS\Page\Field\InvalidFieldsException;
use TheliaCMS\TheliaCMS;

/**
 * One field of the pages of a type. The settings that apply depend on the
 * kind, so the form is posted whole and FieldDefinition says what does not
 * hold together; the code is fixed once the field exists.
 */
final class PageTypeFieldType extends AbstractType
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $kinds = [];

        foreach (FieldKind::cases() as $kind) {
            $kinds[$kind->label()] = $kind->value;
        }

        $builder
            ->add('kind', ChoiceType::class, [
                'label' => 'Kind of field',
                'choices' => $kinds,
                'attr' => ['data-cms-field-type-select' => 'true'],
            ])
            ->add('label', TextType::class, [
                'label' => 'Label',
                'help' => 'As an editor and a visitor read it, in this language.',
                'constraints' => [new NotBlank(message: $this->translate('Give the field a label.')), new Length(max: 100)],
            ])
            ->add('code', TextType::class, [
                'label' => 'Code',
                'disabled' => $options['existing'],
                'help' => 'What a template reads the value by, as cms_page.fields.code. Lowercase letters, digits and underscores; it cannot change once the field exists.',
                'constraints' => $options['existing'] ? [] : [
                    new NotBlank(message: $this->translate('Give the field a code.')),
                    new Length(max: FieldDefinition::CODE_MAX_LENGTH),
                    new Regex(pattern: FieldDefinition::CODE_PATTERN, message: $this->translate('Start with a lowercase letter, then lowercase letters, digits and underscores, such as "cooking_time".')),
                ],
            ])
            ->add('unit', TextType::class, [
                'label' => 'Unit',
                'required' => false,
                'constraints' => [new Length(max: FieldDefinition::UNIT_MAX_LENGTH)],
                'help' => 'Written after the value, such as "kg" or "people".',
                'row_attr' => ['data-cms-field-setting' => 'unit'],
            ])
            ->add('choices', TextareaType::class, [
                'label' => 'Words offered',
                'required' => false,
                'attr' => ['rows' => 4],
                'help' => 'One per line, shown as they are in every language.',
                'row_attr' => ['data-cms-field-setting' => 'choices'],
            ])
            ->add('min', TextType::class, [
                'label' => 'Minimum',
                'required' => false,
            ])
            ->add('max', TextType::class, [
                'label' => 'Maximum',
                'required' => false,
                'help' => 'For a number, the highest value; for a text, the longest it may be.',
            ])
            ->add('translated', CheckboxType::class, [
                'label' => 'Written again for each language',
                'required' => false,
                'row_attr' => ['data-cms-field-setting' => 'translated'],
            ])
            ->add('required', CheckboxType::class, [
                'label' => 'A page cannot be published without it',
                'required' => false,
            ]);

        $builder->addEventListener(FormEvents::POST_SUBMIT, $this->checkDefinition(...));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => TheliaCMS::DOMAIN_NAME,
            'attr' => ['novalidate' => 'novalidate'],
            'existing' => false,
        ]);
        $resolver->setAllowedTypes('existing', 'bool');
    }

    /**
     * The field as the site would store it, read the way every other way in
     * reads it: what does not hold together is said on the form.
     */
    private function checkDefinition(FormEvent $event): void
    {
        $form = $event->getForm();

        if (!$form->isValid()) {
            return;
        }

        try {
            FieldDefinition::fromArray(self::definition((array) $event->getData(), 'x'));
        } catch (InvalidFieldsException $exception) {
            foreach ($exception->translated($this->translator) as $problem) {
                $form->addError(new FormError($problem));
            }
        }
    }

    /**
     * The definition a submitted form describes, under a code given when the
     * form does not post one.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public static function definition(array $data, ?string $code = null): array
    {
        $kind = FieldKind::tryFrom((string) ($data['kind'] ?? ''));

        return [
            'code' => $code ?? (string) ($data['code'] ?? ''),
            'kind' => (string) ($data['kind'] ?? ''),
            'label' => trim((string) ($data['label'] ?? '')),
            'labels' => ['x' => trim((string) ($data['label'] ?? ''))],
            'unit' => $data['unit'] ?? null,
            'choices' => FieldKind::Choice === $kind ? preg_split('/\R/u', (string) ($data['choices'] ?? '')) : [],
            'translated' => (bool) ($data['translated'] ?? false) && null !== $kind && $kind->mayBeTranslated(),
            'required' => (bool) ($data['required'] ?? false),
            'min' => $data['min'] ?? null,
            'max' => $data['max'] ?? null,
        ];
    }

    private function translate(string $message): string
    {
        return $this->translator->trans($message, [], TheliaCMS::DOMAIN_NAME);
    }
}
