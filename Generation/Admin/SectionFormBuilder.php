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

namespace TheliaCMS\Generation\Admin;

use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\SecurityContext;
use TheliaCMS\Content\Prop;
use TheliaCMS\Content\PropSchema;
use TheliaCMS\Content\PropType;
use TheliaCMS\Content\Section;
use TheliaCMS\Content\SectionAppearance;
use TheliaCMS\Content\SectionDefinitionInterface;
use TheliaCMS\Media\Admin\CmsMediaType;
use TheliaCMS\Media\Admin\CmsMediaWriter;
use TheliaCMS\Media\CmsMediaLibrary;
use TheliaCMS\Security\CmsResources;
use TheliaLibrary\Model\LibraryImage;

/**
 * A form for the props of one section, read from the schema of its
 * component: an editor corrects a word without paying a generation.
 *
 * Every prop type has its field; a `html` prop is a textarea the Tiptap
 * module upgrades when it is there, and a textarea when it is not. An
 * `image` prop is picked from the media library, replaced by a file that
 * joins the library, or given as the address of a picture kept elsewhere.
 * What the form gives back is props the way the model writes them, so the
 * validator reads them exactly as it reads a generated document.
 */
final readonly class SectionFormBuilder
{
    public const string PREFIX = 'cms_section_';

    /**
     * What the editor of the Tiptap module offers for a html prop (version 1.1.0
     * and later): no image, no HTML source, no preview, and no h1, which is the
     * title of the page. The module ignores both attributes before that version.
     */
    public const string HTML_TOOLBAR = 'undo,redo,|,bold,italic,underline,|,heading,paragraph,|,bulletlist,orderedlist,|,link,unlink';
    public const string HTML_HEADINGS = '2,3,4';

    /** The longest text drawn on half a line of the form: the default length of a text prop, a title. */
    private const int SHORT_TEXT = 255;

    /** The file and the address fields that go with an image prop. */
    public const string FILE_SUFFIX = '__file';
    public const string ADDRESS_SUFFIX = '__url';

    /** The child holding the appearance of the section, a name no prop takes. */
    public const string APPEARANCE = 'cms_appearance';

    /** Names of props whose plain reading would mislead an editor. */
    private const array LABELS = [
        'href' => 'Link',
        'alt' => 'Text of the picture',
        'note' => 'Score',
        'max_note' => 'Highest score',
        'embed_url' => 'Address of the video',
        'file_url' => 'Address of the file',
        'thumbnail_image_id' => 'Poster image',
        'tag' => 'Heading level',
        'container' => 'Width',
    ];

    public function __construct(
        private FormFactoryInterface $forms,
        private CmsMediaLibrary $library,
        private CmsMediaWriter $media,
        private SecurityContext $securityContext,
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
    ) {
    }

    public static function name(string $sectionId): string
    {
        return self::PREFIX.preg_replace('/[^a-z0-9_]/i', '_', $sectionId);
    }

    public function build(SectionDefinitionInterface $definition, Section $section, string $action): FormInterface
    {
        $builder = $this->forms->createNamedBuilder(self::name($section->id), FormType::class, $this->dataFor($definition->schema()->props, $section->props), [
            'action' => $action,
            'translation_domain' => 'theliacms',
            'csrf_protection' => false,
            'allow_extra_fields' => true,
        ]);

        $builder->add('section', HiddenType::class, ['data' => $section->id, 'mapped' => false]);
        $this->addProps($builder, $definition->schema()->props);
        $this->addAppearance($builder, $section->appearance);

        return $builder->getForm();
    }

    /**
     * The appearance of the section, as sent: checked by the appearance
     * itself, which says what it refuses.
     *
     * @return array<string, mixed>
     */
    public static function appearanceFrom(FormInterface $form): array
    {
        $values = $form->has(self::APPEARANCE) ? $form->get(self::APPEARANCE)->getData() : [];

        return \is_array($values) ? $values : [];
    }

    /**
     * Unmapped, under a name no prop takes: the props of a component are
     * the whole data of the form.
     */
    private function addAppearance(FormBuilderInterface $builder, SectionAppearance $appearance): void
    {
        $color = [
            'required' => false,
            'attr' => ['maxlength' => 7, 'pattern' => '#[0-9a-fA-F]{6}', 'data-cms-color' => ''],
        ];
        $paddings = [];

        foreach (array_keys(SectionAppearance::PADDINGS) as $padding) {
            $paddings[ucfirst($padding)] = $padding;
        }

        $group = $builder->create(self::APPEARANCE, FormType::class, [
            'mapped' => false,
            'label' => 'Appearance',
            'data' => $appearance->toArray(),
        ]);
        $group->add('background', TextType::class, array_merge_recursive($color, [
            'label' => 'Background',
            'help' => 'A colour written #rrggbb, for instance #f8d7d7. Empty: the one of the theme.',
            'attr' => ['placeholder' => '#f8d7d7'],
        ]));
        $group->add('text', TextType::class, array_merge_recursive($color, [
            'label' => 'Text colour',
            'help' => 'Written #rrggbb. A heading the component colours itself keeps its colour.',
            'attr' => ['placeholder' => '#1c1f22'],
        ]));
        $group->add('padding', ChoiceType::class, [
            'label' => 'Room above and below',
            'required' => false,
            'placeholder' => "The component's",
            'choices' => $paddings,
        ] + self::asButtons(['small' => 'space-small', 'medium' => 'space-medium', 'large' => 'space-large']));
        $group->add('align', ChoiceType::class, [
            'label' => 'Alignment of the text',
            'required' => false,
            'placeholder' => "The component's",
            'choices' => ['Left' => 'left', 'Center' => 'center', 'Right' => 'right'],
        ] + self::asButtons(['left' => 'bi-text-left', 'center' => 'bi-text-center', 'right' => 'bi-text-right']));

        $builder->add($group);
    }

    /**
     * The props a submitted form holds, in the shape the schema reads. A
     * file sent for an image joins the media library here and the prop
     * takes its id; an address typed for it is kept as it is.
     *
     * @param array<string, mixed> $data
     * @param list<Prop>           $props
     *
     * @return array<string, mixed>
     */
    public function propsFrom(array $data, array $props): array
    {
        $values = [];

        foreach ($props as $prop) {
            $value = $data[$prop->name] ?? null;

            $values[$prop->name] = match ($prop->type) {
                PropType::Image => $this->imageFrom($data, $prop),
                // An empty choice is "none", which the schema spells 0.
                PropType::Reference => (int) ($value ?? 0),
                PropType::Integer => (int) ($value ?? $prop->default ?? 0),
                PropType::Boolean => (bool) $value,
                PropType::Items => $this->itemsFrom($prop, \is_array($value) ? $value : []),
                PropType::Json => $value ?? [],
                default => (string) ($value ?? ''),
            };
        }

        return $values;
    }

    /**
     * The rows of a list as the model writes them: rows of fields, or, for a
     * list of scalars, the value of the one field of each row.
     *
     * @param array<mixed> $rows
     *
     * @return list<mixed>
     */
    private function itemsFrom(Prop $prop, array $rows): array
    {
        $items = [];

        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $item = $this->propsFrom($row, $prop->items);
            $items[] = $prop->scalarItems ? $item['value'] : $item;
        }

        return $items;
    }

    /**
     * The file wins over the address, which wins over the choice: each one
     * is a step further from "keep what is there".
     *
     * @param array<string, mixed> $data
     */
    private function imageFrom(array $data, Prop $prop): int|string
    {
        $file = $data[$prop->name.self::FILE_SUFFIX] ?? null;

        if ($file instanceof UploadedFile && $this->mayUpload()) {
            $stored = $this->media->add([$file], $this->library->locale())->stored;

            if (isset($stored[0]) && $stored[0] instanceof LibraryImage) {
                return (int) $stored[0]->getId();
            }
        }

        $address = trim((string) ($data[$prop->name.self::ADDRESS_SUFFIX] ?? ''));

        if ('' !== $address) {
            // Handed to the schema as typed: an address that is no https
            // address is refused there, with a message the editor reads.
            return $address;
        }

        // An id the screen sent is an image of the library of the CMS, or none.
        $id = (int) ($data[$prop->name] ?? 0);

        return $id > 0 && null !== $this->library->ownedImage($id) ? $id : 0;
    }

    private function mayUpload(): bool
    {
        return $this->securityContext->isGranted(['ADMIN'], [CmsResources::MEDIA], [], [AccessManager::CREATE]);
    }

    /**
     * @param list<Prop> $props
     */
    public function addProps(FormBuilderInterface $builder, array $props): void
    {
        foreach ($props as $prop) {
            $this->addProp($builder, $prop);
        }
    }

    private function addProp(FormBuilderInterface $builder, Prop $prop): void
    {
        $common = [
            'label' => $prop->label,
            'required' => $prop->required,
            'help' => $prop->editorHelp ?? $prop->help,
            // The labels and the help come translated from the catalogue.
            'translation_domain' => false,
        ];

        // A prop of the theme labelled by its bare name gets words of its
        // own, translated like the rest of the screen.
        if ($prop->label === $prop->name || '' === $prop->label) {
            $common['label'] = self::humanize($prop->name);
            $common['label_translation_parameters'] = [];
            $common['translation_domain'] = 'theliacms';
        }

        match ($prop->type) {
            PropType::Html => $builder->add($prop->name, TextareaType::class, $common + [
                'attr' => [
                    'rows' => 8,
                    'class' => 'wysiwyg',
                    'data-cms-html' => '',
                    'data-tiptap-toolbar' => self::HTML_TOOLBAR,
                    'data-tiptap-headings' => self::HTML_HEADINGS,
                ],
            ]),
            // A text of a sentence or more takes the whole line of the form;
            // a short one shares it with its neighbour.
            PropType::Text => $builder->add($prop->name, TextType::class, $common + [
                'attr' => ['maxlength' => $prop->max ?? 255] + (($prop->max ?? 255) > self::SHORT_TEXT ? ['data-cms-long' => ''] : []),
            ]),
            PropType::Url => $builder->add($prop->name, TextType::class, $common + [
                'attr' => ['maxlength' => $prop->max ?? 2048, 'placeholder' => '/contact'],
            ]),
            PropType::Integer => $builder->add($prop->name, IntegerType::class, $common + [
                'attr' => ['min' => $prop->min, 'max' => $prop->max],
            ]),
            PropType::Boolean => $builder->add($prop->name, CheckboxType::class, $common + ['required' => false]),
            PropType::Choice => $builder->add($prop->name, ChoiceType::class, $common + [
                'choices' => array_flip(array_map(strval(...), $prop->choices)),
                'choice_translation_domain' => false,
            ] + ([] === $prop->icons ? [] : ['placeholder' => false]) + self::asButtons($prop->icons)),
            PropType::Image => $this->addImage($builder, $prop, $common),
            PropType::Reference => $builder->add($prop->name, IntegerType::class, $common + [
                'attr' => array_filter([
                    'min' => 0,
                    'data-cms-picker' => $prop->picker,
                    'data-cms-picker-searchable' => $prop->searchable ? '1' : null,
                ]),
            ]),
            PropType::Items => $builder->add($prop->name, CollectionType::class, $common + [
                'entry_type' => SectionItemType::class,
                'entry_options' => ['props' => $prop->items, 'builder' => $this, 'label' => false],
                'allow_add' => true,
                'allow_delete' => true,
                'prototype' => true,
                'attr' => ['data-cms-items' => '', 'data-cms-items-min' => $prop->minItems ?? 0, 'data-cms-items-max' => $prop->maxItems ?? 12],
            ]),
            PropType::Json => $builder->add(
                $builder->create($prop->name, TextareaType::class, $common + ['attr' => ['rows' => 4, 'class' => 'font-monospace']])
                    ->addModelTransformer(new CallbackTransformer(
                        static fn (mixed $value): string => \is_string($value) ? $value : json_encode($value ?? [], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR),
                        static fn (?string $value): mixed => '' === trim((string) $value) ? [] : (json_decode((string) $value, true) ?? $value),
                    )),
            ),
        };
    }

    /**
     * A choice with a pictogram per value is drawn as a row of buttons, the
     * pictogram above the words: a width or an alignment is seen before it
     * is read. Without pictograms, a list. The "none" of an optional choice
     * has no value, and the screen draws it with a pictogram of its own.
     *
     * @param array<string, string> $icons
     *
     * @return array<string, mixed>
     */
    private static function asButtons(array $icons): array
    {
        if ([] === $icons) {
            return [];
        }

        return [
            'expanded' => true,
            'choice_attr' => static fn (mixed $choice, mixed $key, mixed $value): array => ['data-cms-icon' => $icons[(string) $value] ?? ''],
            'attr' => ['data-cms-choice-buttons' => ''],
        ];
    }

    /**
     * `max_note` reads "Highest score", `product_ids` "Products": the name of
     * a prop, said the way an editor would.
     */
    public static function humanize(string $name): string
    {
        $words = array_values(array_filter(explode(' ', strtolower((string) preg_replace(['/([a-z])([A-Z])/', '/[_\-]+/'], ['$1 $2', ' '], $name)))));
        $key = implode('_', $words);

        if (isset(self::LABELS[$key])) {
            return self::LABELS[$key];
        }

        $last = array_pop($words);

        if ('ids' === $last && [] !== $words) {
            $words[\count($words) - 1] .= 's';
        } elseif ('id' !== $last || [] === $words) {
            $words[] = $last;
        }

        return ucfirst(implode(' ', $words));
    }

    /**
     * The picture of a section: a choice in the media library, with the
     * address of each image for the preview of the form; a file to replace
     * it with, for whoever may add to the library; and the address of a
     * picture kept elsewhere.
     *
     * @param array<string, mixed> $common
     */
    private function addImage(FormBuilderInterface $builder, Prop $prop, array $common): void
    {
        // The id of the image: the screen draws the image and opens the
        // picker of the media library on it. A list of every image cannot be
        // read past a few dozen, and lost the current one past the 200
        // newest.
        $builder->add($prop->name, IntegerType::class, $common + [
            'attr' => [
                'min' => 0,
                'data-cms-image-picker' => $this->urls->generate('admin.cms.media.picker'),
            ] + ($this->mayUpload() ? ['data-cms-image-upload' => $this->urls->generate('admin.cms.media.add')] : []),
        ]);

        if ($this->mayUpload()) {
            $builder->add($prop->name.self::FILE_SUFFIX, FileType::class, [
                'label' => 'Replace with a file',
                'required' => false,
                'translation_domain' => 'theliacms',
                'constraints' => [CmsMediaType::imageConstraint($this->translator)],
                'help' => 'The file joins the media library and takes the place of the picture. Describe it there afterwards.',
                'attr' => ['accept' => implode(',', CmsMediaType::ACCEPTED_MIME_TYPES), 'data-cms-image-file' => ''],
            ]);
        }

        $builder->add($prop->name.self::ADDRESS_SUFFIX, TextType::class, [
            'label' => 'Or the address of a picture kept elsewhere',
            'required' => false,
            'translation_domain' => 'theliacms',
            'help' => 'An https address. The picture is shown from there, as it is, and is not resized by the site.',
            'attr' => ['placeholder' => 'https://', 'maxlength' => 2048, 'inputmode' => 'url', 'data-cms-image-url' => ''],
        ]);
    }

    /**
     * The props as the form holds them: the ids of an image or a reference
     * are integers and an absent one is nothing, which the form reads as
     * "no choice". The address of a picture kept elsewhere goes to its own
     * field.
     *
     * @param list<Prop>           $props
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    private function dataFor(array $props, array $values): array
    {
        $data = [];

        foreach ($props as $prop) {
            $value = $values[$prop->name] ?? $prop->default;

            if (PropType::Image === $prop->type && \is_string($value) && PropSchema::isExternalImage($value)) {
                $data[$prop->name] = null;
                $data[$prop->name.self::ADDRESS_SUFFIX] = trim($value);

                continue;
            }

            // A path of the media library is the id of its picture.
            if (PropType::Image === $prop->type && \is_string($value)) {
                $value = PropSchema::libraryImageId($value) ?? $value;
            }

            $data[$prop->name] = match ($prop->type) {
                PropType::Image => null === $value || 0 === (int) $value ? null : (int) $value,
                PropType::Reference => null === $value ? null : (int) $value,
                PropType::Choice => null === $value ? null : (string) $value,
                // A list of scalars is one field per row: each value is wrapped.
                PropType::Items => array_map(
                    fn (mixed $item): array => $this->dataFor($prop->items, $prop->scalarItems && !\is_array($item) ? ['value' => $item] : PropSchema::rowKeys((array) $item)),
                    \is_array($value) ? $value : [],
                ),
                default => $value,
            };
        }

        return $data;
    }
}
