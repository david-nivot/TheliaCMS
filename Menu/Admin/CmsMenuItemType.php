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

namespace TheliaCMS\Menu\Admin;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Contracts\Translation\TranslatorInterface;
use TheliaCMS\Menu\MenuAddress;
use TheliaCMS\Menu\MenuTargetType;
use TheliaCMS\Menu\MenuTree;
use TheliaCMS\TheliaCMS;

/**
 * The messages of the form errors are translated here: the form theme
 * prints them as they are.
 *
 * The target is a number the record picker writes once the editor found it by
 * a few letters of its title: no list of the whole site is sent with the form.
 * Without the script, the number is typed.
 */
final class CmsMenuItemType extends AbstractType
{
    public function __construct(
        private readonly MenuAddress $addresses,
        private readonly MenuTargetCheck $targets,
        private readonly UrlGeneratorInterface $urls,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('targetType', ChoiceType::class, [
                'label' => 'This entry points at',
                'choices' => [
                    MenuTargetType::Page->label() => MenuTargetType::Page->value,
                    MenuTargetType::Content->label() => MenuTargetType::Content->value,
                    MenuTargetType::Folder->label() => MenuTargetType::Folder->value,
                    MenuTargetType::Category->label() => MenuTargetType::Category->value,
                    MenuTargetType::Product->label() => MenuTargetType::Product->value,
                    MenuTargetType::Url->label() => MenuTargetType::Url->value,
                    MenuTargetType::None->label() => MenuTargetType::None->value,
                ],
                'attr' => ['data-cms-menu-target-select' => 'true'],
            ])
            ->add('pageId', IntegerType::class, $this->target(MenuTargetType::Page, 'Page', 'admin.cms.partials.sources.pages', $options['source_locale']))
            ->add('contentId', IntegerType::class, $this->target(MenuTargetType::Content, 'Content', 'admin.cms.partials.sources.contents', $options['source_locale']))
            ->add('folderId', IntegerType::class, $this->target(MenuTargetType::Folder, 'Folder', 'admin.cms.partials.sources.folders.search', $options['source_locale']))
            ->add('categoryId', IntegerType::class, $this->target(MenuTargetType::Category, 'Category', 'admin.cms.partials.sources.categories', $options['source_locale'], ['offline' => 1]))
            ->add('productId', IntegerType::class, $this->target(MenuTargetType::Product, 'Product', 'admin.cms.partials.sources.products', $options['source_locale'], ['offline' => 1], 'Choose in the list, or type a few letters of the title or the start of the reference to narrow it.'))
            // Not a UrlType: a menu entry legitimately points at a path of the
            // site, at an anchor, or at a mailto: address.
            ->add('url', TextType::class, [
                'label' => 'Web address',
                'required' => true,
                'help' => 'A full address such as https://example.org, a path of this site such as /contact, or an anchor such as #prices.',
                'row_attr' => ['data-cms-menu-target-field' => MenuTargetType::Url->value],
            ])
            ->add('label', TextType::class, [
                'label' => 'Label',
                'required' => false,
                'help' => 'Leave empty to show the title of the target.',
                'constraints' => [new Length(max: 255)],
            ])
            ->add('parent', ChoiceType::class, [
                'label' => 'Nested under',
                'choices' => ['Top level' => MenuTree::ROOT] + $options['parent_choices'],
                'help' => 'A menu goes three levels deep at most.',
            ])
            ->add('openNewTab', CheckboxType::class, [
                'label' => 'Open in a new tab',
                'required' => false,
            ])
            ->add('visible', CheckboxType::class, [
                'label' => 'Visible on the site',
                'required' => false,
                'help' => 'Untick to keep the entry here without showing it, with the entries under it. Tick it again to bring it back.',
            ]);

        // The fields that do not apply to the chosen type are hidden, so they
        // cannot be validated one by one: what has to hold is the combination.
        $builder->addEventListener(FormEvents::POST_SUBMIT, $this->validateCombination(...));
    }

    /**
     * Only the field of the chosen kind of target shows from the first paint:
     * the script that switches them runs once the page is loaded, and would
     * show all six fields until then. Without the script, the screen shows
     * them all again (`<noscript>` of the template), as the type is then
     * changed with no field following it.
     */
    public function finishView(FormView $view, FormInterface $form, array $options): void
    {
        $chosen = (string) $view['targetType']->vars['value'];

        foreach ($view as $child) {
            $kind = $child->vars['row_attr']['data-cms-menu-target-field'] ?? null;

            if (null !== $kind) {
                $child->vars['row_attr']['hidden'] = $kind !== $chosen;
            }
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults([
                'translation_domain' => 'theliacms',
                // "One of these four fields, depending on that select" is not
                // something HTML validation can express: it would block the
                // form on the three fields that do not apply. The server
                // decides which one is required, and says so per field.
                'attr' => ['novalidate' => 'novalidate'],
                'parent_choices' => [],
                // The language the entry is edited in: the targets are named
                // in it, as its label is written in it.
                'source_locale' => null,
            ])
            ->setAllowedTypes('parent_choices', 'array')
            ->setAllowedTypes('source_locale', ['null', 'string']);
    }

    /**
     * The options of the field of one kind of target: a number the record
     * picker fills from the searched source, offline records included since a
     * menu may be prepared before its target goes online.
     *
     * @param array<string, int> $query
     *
     * @return array<string, mixed>
     */
    private function target(MenuTargetType $type, string $label, string $source, ?string $locale, array $query = [], string $help = 'Choose in the list, or type a few letters of the title to narrow it.'): array
    {
        return [
            'label' => $label,
            'required' => true,
            'help' => $help,
            'attr' => [
                'min' => 1,
                'data-cms-picker' => $this->urls->generate($source, $query + array_filter(['locale' => $locale])),
                'data-cms-picker-searchable' => '1',
            ],
            'row_attr' => ['data-cms-menu-target-field' => $type->value],
        ];
    }

    private function validateCombination(FormEvent $event): void
    {
        $form = $event->getForm();
        $data = $event->getData();

        if (!\is_array($data)) {
            return;
        }

        $type = MenuTargetType::fromStorage($data['targetType'] ?? null);

        match ($type) {
            MenuTargetType::Page => $this->requireTarget($form, $type, 'pageId', $data, 'Choose the page this entry points at.'),
            MenuTargetType::Content => $this->requireTarget($form, $type, 'contentId', $data, 'Choose the content this entry points at.'),
            MenuTargetType::Folder => $this->requireTarget($form, $type, 'folderId', $data, 'Choose the folder this entry points at.'),
            MenuTargetType::Category => $this->requireTarget($form, $type, 'categoryId', $data, 'Choose the category this entry points at.'),
            MenuTargetType::Product => $this->requireTarget($form, $type, 'productId', $data, 'Choose the product this entry points at.'),
            MenuTargetType::Url => $this->checkAddress($form, $data),
            // A heading has nothing to link to, so its label is all it has.
            MenuTargetType::None => $this->requireField($form, 'label', $data, 'An entry with no link needs a label.'),
        };
    }

    /**
     * The field of the chosen kind holds a record that can be the target: a
     * number not typed, or typed wrong, is refused here rather than by the
     * select that used to list them all.
     *
     * @param array<string, mixed> $data
     */
    private function requireTarget(FormInterface $form, MenuTargetType $type, string $field, array $data, string $message): void
    {
        if ($form->get($field)->getErrors()->count() > 0) {
            return;
        }

        $id = $data[$field] ?? null;

        if (null === $id) {
            $form->get($field)->addError(new FormError($this->translate($message)));

            return;
        }

        $refusal = $this->targets->refusal($type, (int) $id, $this->translator->getLocale());

        if (null !== $refusal) {
            $form->get($field)->addError(new FormError($this->translate($refusal)));
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function requireField(FormInterface $form, string $field, array $data, string $message): void
    {
        if ('' === trim((string) ($data[$field] ?? ''))) {
            $form->get($field)->addError(new FormError($this->translate($message)));
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function checkAddress(FormInterface $form, array $data): void
    {
        $address = trim((string) ($data['url'] ?? ''));

        if ('' === $address) {
            $form->get('url')->addError(new FormError($this->translate('Type the address this entry points at.')));

            return;
        }

        if (null === $this->addresses->normalize($address)) {
            $form->get('url')->addError(new FormError($this->translate('This address cannot be used. Start it with https://, with / for a page of this site, or with # for an anchor.')));
        }
    }

    private function translate(string $message): string
    {
        return $this->translator->trans($message, [], TheliaCMS::DOMAIN_NAME);
    }
}
