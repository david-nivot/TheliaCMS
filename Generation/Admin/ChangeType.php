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
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Contracts\Translation\TranslatorInterface;
use TheliaCMS\TheliaCMS;

/**
 * A change asked on the document the target holds: on the whole of it, or
 * on one section when the form names one. Either may come with a text
 * file, as material for the change.
 *
 * The messages of the constraints are translated here: the validator looks
 * them up in the `validators` domain, where the module has no catalogue.
 */
final class ChangeType extends AbstractType
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('text', TextareaType::class, [
            'label' => $options['section'] ? 'Rewrite this section' : 'Ask for a change',
            'attr' => [
                'rows' => $options['section'] ? 2 : 4,
                'placeholder' => $options['section'] ? 'Shorter, warmer, with the opening hours…' : 'Make the opening shorter, add a question about delivery, swap the figures for…',
            ],
            'constraints' => [new NotBlank(message: $this->translate('Say what should change.')), new Length(max: 5000)],
        ]);

        $builder->add('reference', ReferenceFileType::class, [
            'help' => 'A .md or .txt file of up to 100,000 characters the change draws from: new prices, a product sheet, a note. Say above what should change: the generator reads the file as material, never as instructions. It is not kept.',
        ]);

        if ($options['section']) {
            $builder->add('section', HiddenType::class, [
                'constraints' => [new NotBlank(), new Length(max: 100)],
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults([
                'translation_domain' => 'theliacms',
                'csrf_protection' => false,
                'section' => false,
            ])
            ->setAllowedTypes('section', 'bool');
    }

    public function getBlockPrefix(): string
    {
        return 'cms_change';
    }

    private function translate(string $message): string
    {
        return $this->translator->trans($message, [], TheliaCMS::DOMAIN_NAME);
    }
}
