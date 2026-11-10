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
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;
use Symfony\Contracts\Translation\TranslatorInterface;
use TheliaCMS\TheliaCMS;

/**
 * The messages of the constraints are translated here: the validator looks
 * them up in the `validators` domain, where the module has no catalogue.
 */
final class CmsMenuType extends AbstractType
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'Name',
                'help' => 'Only shown here, to tell your menus apart.',
                'constraints' => [new NotBlank(), new Length(max: 255)],
            ])
            ->add('code', TextType::class, [
                'label' => 'Code',
                'disabled' => $options['code_locked'],
                'help' => $options['code_locked']
                    ? 'The code belongs to the shared menu: change it there.'
                    : 'What the theme calls this menu by, for example main or footer. Letters, digits, dashes.',
                'constraints' => [
                    new NotBlank(),
                    new Length(max: 50),
                    new Regex(pattern: '/^[a-z0-9][a-z0-9-]*$/', message: $this->translate('Use lowercase letters, digits and dashes only.')),
                ],
            ])
            ->add('visible', CheckboxType::class, [
                'label' => 'Visible on the site',
                'required' => false,
                'help' => 'Untick to show nothing of this menu on the site. Its entries are kept.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['translation_domain' => 'theliacms', 'code_locked' => false]);
        $resolver->setAllowedTypes('code_locked', 'bool');
    }

    private function translate(string $message): string
    {
        return $this->translator->trans($message, [], TheliaCMS::DOMAIN_NAME);
    }
}
