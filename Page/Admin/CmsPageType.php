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

namespace TheliaCMS\Page\Admin;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Image;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Contracts\Translation\TranslatorInterface;
use TheliaCMS\Media\Admin\CmsMediaType;
use TheliaCMS\Page\Field\FieldDefinition;
use TheliaCMS\Page\Field\FieldDefinitions;
use TheliaCMS\Page\Field\FieldKind;
use TheliaCMS\Page\PageTypeCode;

final class CmsPageType extends AbstractType
{
    /**
     * Optional: the form is also built without a container, and only the
     * help of the fields of a type reads it.
     */
    public function __construct(
        private readonly ?TranslatorInterface $translator = null,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'Title',
                'constraints' => [new NotBlank(), new Length(max: 255)],
            ])
            ->add('slug', PageAddressType::class, [
                'label' => 'URL slug',
                'help' => 'Leave empty to derive it from the title. Parent pages prefix it automatically.',
                'proposal_url' => $options['address_proposal_url'],
                'page_id' => $options['page_id'],
            ])
            ->add('fixedUrl', TextType::class, [
                'label' => 'Fixed address',
                'required' => false,
                'constraints' => [new Length(max: 255)],
                'help' => 'The whole address, kept as typed whatever the parent page: the address a site taken over had, such as about-us.html. Leave empty for the address built from the parent pages and the slug.',
                // Checked by `assets/page-address.js` while it is typed.
                'attr' => null === $options['fixed_address_check_url'] ? [] : [
                    'data-cms-fixed-address' => '',
                    'data-check-url' => $options['fixed_address_check_url'],
                    'data-page-id' => (string) $options['page_id'],
                    'data-messages' => json_encode([
                        'free' => $this->translate('This address is free.'),
                        'failed' => $this->translate('The address could not be checked.'),
                    ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE),
                ],
            ])
            // `wysiwyg` is the opt-in class of the back-office rich text
            // editor: the same editor as the summary and description of a
            // core content, when one is installed, a plain textarea otherwise.
            ->add('chapo', TextareaType::class, ['label' => 'Summary', 'required' => false, 'attr' => ['rows' => 3, 'class' => 'wysiwyg']])
            ->add('description', TextareaType::class, ['label' => 'Detailed description', 'required' => false, 'attr' => ['rows' => 8, 'class' => 'wysiwyg']])
            ->add('parent', ChoiceType::class, [
                'label' => 'Parent page',
                'choices' => ['None (top level)' => 0] + $options['parent_choices'],
            ])
            // A type is a code and nothing else: shown as it is, never
            // translated. Managed under CMS > Settings > Page types.
            ->add('pageType', ChoiceType::class, [
                'label' => 'Page template',
                'choices' => array_combine($options['page_type_choices'], $options['page_type_choices']),
                'choice_label' => static fn (string $code): string => $options['page_type_labels'][$code] ?? $code,
                'choice_translation_domain' => false,
                // The model of a type stays the model of that type.
                'disabled' => $options['is_model'],
                'help' => 'Picks the template the page is displayed with, and the fields it carries.',
            ])
            // The tags of the site, managed under CMS > Tags: a page carries
            // one of them or none.
            ->add('tag', ChoiceType::class, [
                'label' => 'Tag',
                'required' => false,
                'placeholder' => 'No tag',
                'choices' => array_flip($options['tag_choices']),
                'choice_translation_domain' => false,
                'help' => 'Tags are managed under CMS > Tags.',
            ])
            ->add('visible', ChoiceType::class, [
                'label' => 'Online',
                'choices' => ['Yes' => 1, 'No' => 0],
                'expanded' => true,
                // A model is a reference for new pages, never a page of the site.
                'disabled' => $options['is_model'],
                'help' => $options['is_model'] ? 'The model of a template is never online.' : null,
            ])
            ->add('publishAt', DateTimeType::class, [
                'label' => 'Publish on',
                'required' => false,
                'widget' => 'single_text',
                'help' => 'The page stays invisible until this date.',
            ])
            ->add('unpublishAt', DateTimeType::class, [
                'label' => 'Unpublish on',
                'required' => false,
                'widget' => 'single_text',
            ])
            ->add('metaTitle', TextType::class, ['label' => 'Meta title', 'required' => false])
            ->add('metaDescription', TextareaType::class, ['label' => 'Meta description', 'required' => false, 'attr' => ['rows' => 3]])
            ->add('ogTitle', TextType::class, ['label' => 'Social title', 'required' => false])
            ->add('ogDescription', TextareaType::class, ['label' => 'Social description', 'required' => false, 'attr' => ['rows' => 3]])
            // An id of the media library, picked there by the screen like the
            // image of the page; the controller keeps it only for an image the
            // CMS owns.
            ->add('ogImage', IntegerType::class, [
                'label' => 'Social image',
                'required' => false,
                'help' => 'Shown when the page is shared. Without one, the image of the page.',
                'attr' => array_filter([
                    'min' => 0,
                    'data-cms-image-picker' => $options['image_picker_url'],
                    'data-cms-image-upload' => $options['allow_image_upload'] ? $options['image_upload_url'] : null,
                ]),
            ])
            ->add('twitterCard', ChoiceType::class, [
                'label' => 'Twitter card',
                'required' => false,
                'placeholder' => 'Default',
                'choices' => ['Summary' => 'summary', 'Summary with large image' => 'summary_large_image'],
                'help' => 'Without a choice, the one of the theme.',
            ])
            ->add('canonical', UrlType::class, [
                'label' => 'Canonical URL',
                'required' => false,
                'default_protocol' => 'https',
                'help' => 'Only fill this in to point search engines at another page.',
            ])
            ->add('noindex', ChoiceType::class, [
                'label' => 'Search engine indexing',
                'choices' => ['Index this page' => 0, 'Do not index (noindex)' => 1],
                'expanded' => true,
            ])
            ->add('nofollow', ChoiceType::class, [
                'label' => 'Link following',
                'choices' => ['Follow links' => 0, 'Do not follow links (nofollow)' => 1],
                'expanded' => true,
            ])
            // The id of the image, drawn and picked in the media library by
            // the screen; the controller keeps it only for an image the CMS owns.
            ->add('image', IntegerType::class, [
                'label' => 'Image of the page',
                'required' => false,
                'help' => 'Shown on the cards that present the page elsewhere on the site and when it is shared.',
                'attr' => array_filter([
                    'min' => 0,
                    'data-cms-image-picker' => $options['image_picker_url'],
                    'data-cms-image-upload' => $options['allow_image_upload'] ? $options['image_upload_url'] : null,
                ]),
            ]);

        $this->addFields($builder, $options['field_definitions'], $options['field_locale']);

        // Uploading adds an image to the CMS library, which is the right to
        // create media, not the right to change pages: without it the field is
        // not there, and a request that sends a file anyway is an invalid form.
        if (!$options['allow_image_upload']) {
            return;
        }

        // Wins over the choice above: the file is stored in the CMS library
        // and becomes the image of the page in the same save.
        $builder->add('imageUpload', FileType::class, [
            'label' => 'Or upload a new image',
            'required' => false,
            'constraints' => [new Image(
                mimeTypes: CmsMediaType::ACCEPTED_MIME_TYPES,
                mimeTypesMessage: 'Only JPEG, PNG and WebP images can be uploaded.',
            )],
            'help' => 'It is added to the CMS media library. Describe it there so the page can say what it shows.',
        ]);
    }

    /**
     * One widget per field of the type, named by its code: the values the
     * page holds in the language being edited.
     */
    private function addFields(FormBuilderInterface $builder, FieldDefinitions $definitions, string $locale): void
    {
        $fields = $builder->create('fields', FormType::class, ['label' => false, 'required' => false]);

        foreach ($definitions as $field) {
            [$type, $options] = $this->widgetOf($field);

            $fields->add($field->code, $type, [
                'label' => $field->label($locale),
                // Required to publish, not to save: a draft is written in
                // several sittings, and the writer refuses the publication.
                'required' => false,
                'help' => $this->helpOf($field),
                ...$options,
            ]);
        }

        $builder->add($fields);
    }

    /**
     * @return array{0: class-string, 1: array<string, mixed>}
     */
    private function widgetOf(FieldDefinition $field): array
    {
        return match ($field->kind) {
            FieldKind::Integer, FieldKind::Duration => [IntegerType::class, ['attr' => array_filter(['min' => $field->min, 'max' => $field->max], static fn ($bound): bool => null !== $bound)]],
            FieldKind::Decimal => [NumberType::class, ['scale' => 6, 'html5' => true, 'attr' => ['step' => 'any']]],
            FieldKind::Text => [TextType::class, []],
            FieldKind::LongText => [TextareaType::class, ['attr' => ['rows' => 4]]],
            FieldKind::Boolean => [CheckboxType::class, ['required' => false]],
            FieldKind::Choice => [ChoiceType::class, ['choices' => array_combine($field->choices, $field->choices), 'placeholder' => '—', 'choice_translation_domain' => false]],
            FieldKind::Date => [DateType::class, ['widget' => 'single_text', 'input' => 'string']],
        };
    }

    private function helpOf(FieldDefinition $field): ?string
    {
        $notes = array_filter([
            match ($field->kind) {
                FieldKind::Duration => $this->translate('In minutes.'),
                FieldKind::Integer, FieldKind::Decimal => null === $field->unit ? null : $this->translate('In %unit%.', ['%unit%' => rtrim($field->unit, '.')]),
                default => null,
            },
            $field->translated ? $this->translate('Written for this language.') : null,
            $field->required ? $this->translate('Needed to publish the page.') : null,
        ]);

        return [] === $notes ? null : implode(' ', $notes);
    }

    /**
     * @param array<string, string> $parameters
     */
    private function translate(string $message, array $parameters = []): string
    {
        return null === $this->translator ? strtr($message, $parameters) : $this->translator->trans($message, $parameters, 'theliacms');
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults([
                'translation_domain' => 'theliacms',
                'parent_choices' => [],
                'image_picker_url' => null,
                'image_upload_url' => null,
                'page_type_choices' => [PageTypeCode::DEFAULT],
                'page_type_labels' => [],
                'tag_choices' => [],
                'allow_image_upload' => false,
                'is_model' => false,
                'field_definitions' => FieldDefinitions::none(),
                'field_locale' => 'en_US',
                // Where the slug field asks for the address it proposes; none,
                // and the field is a plain input.
                'address_proposal_url' => null,
                'fixed_address_check_url' => null,
                'page_id' => 0,
            ])
            ->setAllowedTypes('address_proposal_url', ['null', 'string'])
            ->setAllowedTypes('fixed_address_check_url', ['null', 'string'])
            ->setAllowedTypes('page_id', 'int')
            ->setAllowedTypes('page_type_labels', 'array')
            ->setAllowedTypes('is_model', 'bool')
            ->setAllowedTypes('field_definitions', FieldDefinitions::class)
            ->setAllowedTypes('field_locale', 'string')
            ->setAllowedTypes('parent_choices', 'array')
            ->setAllowedTypes('image_picker_url', ['null', 'string'])
            ->setAllowedTypes('image_upload_url', ['null', 'string'])
            ->setAllowedTypes('page_type_choices', 'array')
            ->setAllowedTypes('tag_choices', 'array')
            ->setAllowedTypes('allow_image_upload', 'bool');
    }
}
