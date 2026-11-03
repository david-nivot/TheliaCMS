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

namespace TheliaCMS\Media\Admin;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * What the describe queue asks of an image: what it shows, or that it shows
 * nothing a reader needs. The rule is the one of the sheet of the image.
 */
final class MediaDescriptionType extends AbstractType
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('alt', TextType::class, [
                'label' => 'Alternative text',
                'required' => true,
                'constraints' => [new Length(max: 255)],
                'help' => 'What the image shows, for people who cannot see it. Translated per language.',
                'attr' => ['autofocus' => 'autofocus', 'autocomplete' => 'off'],
            ])
            ->add('decorative', CheckboxType::class, [
                'label' => 'This image is decorative',
                'required' => false,
            ])
            ->addEventListener(FormEvents::SUBMIT, CmsMediaType::requireAltUnlessDecorative($this->translator));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['translation_domain' => 'theliacms']);
    }
}
