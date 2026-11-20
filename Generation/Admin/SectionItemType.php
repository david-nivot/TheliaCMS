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

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * One item of a list prop — a question, a figure, a testimonial — with the
 * fields of its own props.
 */
final class SectionItemType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $sectionForms = $options['builder'];
        \assert($sectionForms instanceof SectionFormBuilder);

        $sectionForms->addProps($builder, $options['props'], $options['files']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setRequired(['props', 'builder'])
            ->setAllowedTypes('props', 'array')
            ->setAllowedTypes('builder', SectionFormBuilder::class)
            ->setDefaults([
                'translation_domain' => 'theliacms',
                'csrf_protection' => false,
                'files' => true,
            ])
            ->setAllowedTypes('files', 'bool');
    }

    public function getBlockPrefix(): string
    {
        return 'cms_section_item';
    }
}
