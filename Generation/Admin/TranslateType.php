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
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Contracts\Translation\TranslatorInterface;
use TheliaCMS\TheliaCMS;

/**
 * Which language to write the document from, when the target holds one in
 * another language and nothing in this one.
 *
 * The messages of the constraints are translated here: the validator looks
 * them up in the `validators` domain, where the module has no catalogue.
 */
final class TranslateType extends AbstractType
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('source', ChoiceType::class, [
            'label' => 'Written from',
            'choices' => $options['sources'],
            'constraints' => [new NotBlank(message: $this->translate('Choose the language to write from.'))],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => 'theliacms',
            // The token of the back office is checked by the guard of the
            // section; a second token on the form would be checked by nobody.
            'csrf_protection' => false,
            'sources' => [],
        ]);
        $resolver->setAllowedTypes('sources', 'array');
    }

    public function getBlockPrefix(): string
    {
        return 'cms_translate';
    }

    private function translate(string $message): string
    {
        return $this->translator->trans($message, [], TheliaCMS::DOMAIN_NAME);
    }
}
