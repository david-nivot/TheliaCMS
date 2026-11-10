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
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Contracts\Translation\TranslatorInterface;
use TheliaCMS\Menu\MenuAddress;
use TheliaCMS\Menu\MenuTargetType;
use TheliaCMS\Menu\MenuTree;
use TheliaCMS\TheliaCMS;

/**
 * The messages of the form errors are translated here: the form theme
 * prints them as they are.
 */
final class CmsMenuItemType extends AbstractType
{
    public function __construct(
        private readonly MenuAddress $addresses,
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
            ->add('pageId', ChoiceType::class, [
                'label' => 'Page',
                'required' => true,
                'placeholder' => 'Choose a page',
                'choices' => $options['page_choices'],
                'row_attr' => ['data-cms-menu-target-field' => MenuTargetType::Page->value],
            ])
            ->add('contentId', ChoiceType::class, [
                'label' => 'Content',
                'required' => true,
                'placeholder' => 'Choose a content',
                'choices' => $options['content_choices'],
                'row_attr' => ['data-cms-menu-target-field' => MenuTargetType::Content->value],
            ])
            ->add('folderId', ChoiceType::class, [
                'label' => 'Folder',
                'required' => true,
                'placeholder' => 'Choose a folder',
                'choices' => $options['folder_choices'],
                'row_attr' => ['data-cms-menu-target-field' => MenuTargetType::Folder->value],
            ])
            ->add('categoryId', ChoiceType::class, [
                'label' => 'Category',
                'required' => true,
                'placeholder' => 'Choose a category',
                'choices' => $options['category_choices'],
                'row_attr' => ['data-cms-menu-target-field' => MenuTargetType::Category->value],
            ])
            ->add('productId', ChoiceType::class, [
                'label' => 'Product',
                'required' => true,
                'placeholder' => 'Choose a product',
                'help' => 'Products are grouped by their default category. Type the start of a title to reach it in the list.',
                'choices' => $options['product_choices'],
                'row_attr' => ['data-cms-menu-target-field' => MenuTargetType::Product->value],
            ])
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
                'page_choices' => [],
                'content_choices' => [],
                'folder_choices' => [],
                'category_choices' => [],
                'product_choices' => [],
                'parent_choices' => [],
            ])
            ->setAllowedTypes('page_choices', 'array')
            ->setAllowedTypes('content_choices', 'array')
            ->setAllowedTypes('folder_choices', 'array')
            ->setAllowedTypes('category_choices', 'array')
            ->setAllowedTypes('product_choices', 'array')
            ->setAllowedTypes('parent_choices', 'array');
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
            MenuTargetType::Page => $this->requireField($form, 'pageId', $data, 'Choose the page this entry points at.'),
            MenuTargetType::Content => $this->requireField($form, 'contentId', $data, 'Choose the content this entry points at.'),
            MenuTargetType::Folder => $this->requireField($form, 'folderId', $data, 'Choose the folder this entry points at.'),
            MenuTargetType::Category => $this->requireField($form, 'categoryId', $data, 'Choose the category this entry points at.'),
            MenuTargetType::Product => $this->requireField($form, 'productId', $data, 'Choose the product this entry points at.'),
            MenuTargetType::Url => $this->checkAddress($form, $data),
            // A heading has nothing to link to, so its label is all it has.
            MenuTargetType::None => $this->requireField($form, 'label', $data, 'An entry with no link needs a label.'),
        };
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
