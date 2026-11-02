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
use Symfony\Component\Form\DataMapperInterface;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ColorType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use TheliaCMS\Page\PageTagColor;
use TheliaCMS\TheliaCMS;

/**
 * The colors of a tag, as one field whose data is a PageTagColor or null.
 *
 * A color input always holds a color, so whether the tag has one, and whether
 * its text follows the contrast, are boxes of their own.
 */
final class PageTagColorType extends AbstractType implements DataMapperInterface
{
    /** What the inputs show for a tag with no color: the grey of its badge. */
    private const string DEFAULT_BACKGROUND = '#6c757d';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('colored', CheckboxType::class, [
                'label' => 'Give the tag a color',
                'required' => false,
                'help' => 'The badge of the tag in the back office. A theme may show it on the site.',
            ])
            ->add('background', ColorType::class, [
                'label' => 'Background',
                'html5' => true,
                'required' => true,
            ])
            ->add('automatic_text', CheckboxType::class, [
                'label' => 'Text in black or white, whichever reads best on the background',
                'required' => false,
            ])
            ->add('text', ColorType::class, [
                'label' => 'Text',
                'html5' => true,
                'required' => true,
                'help' => 'Used when the box above is unticked.',
            ])
            ->setDataMapper($this);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'empty_data' => null,
            // Required so the color inputs, which always hold a color, are not marked optional; the
            // boxes say whether the tag has a color.
            'required' => true,
            'label' => false,
            'translation_domain' => TheliaCMS::DOMAIN_NAME,
        ]);
    }

    public function mapDataToForms(mixed $viewData, \Traversable $forms): void
    {
        $color = $viewData instanceof PageTagColor ? $viewData : null;
        $fields = self::byName($forms);

        $fields['colored']->setData(null !== $color);
        $fields['background']->setData($color->background ?? self::DEFAULT_BACKGROUND);
        $fields['automatic_text']->setData(null === $color?->text);
        $fields['text']->setData($color?->textColor() ?? '#ffffff');
    }

    /**
     * A code the color input refuses leaves no color: the input carries the
     * error and the form is not valid.
     */
    public function mapFormsToData(\Traversable $forms, mixed &$viewData): void
    {
        $fields = self::byName($forms);

        if (true !== $fields['colored']->getData()) {
            $viewData = null;

            return;
        }

        $background = PageTagColor::normalize((string) $fields['background']->getData());
        $text = true === $fields['automatic_text']->getData() ? null : PageTagColor::normalize((string) $fields['text']->getData());
        $textRefused = true !== $fields['automatic_text']->getData() && null === $text;

        $viewData = null === $background || $textRefused ? null : PageTagColor::of($background, $text);
    }

    /**
     * @param \Traversable<FormInterface> $forms
     *
     * @return array<string, FormInterface>
     */
    private static function byName(\Traversable $forms): array
    {
        $fields = [];

        foreach ($forms as $form) {
            $fields[$form->getName()] = $form;
        }

        return $fields;
    }
}
