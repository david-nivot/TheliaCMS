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

namespace TheliaCMS\Script\Admin;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Contracts\Translation\TranslatorInterface;
use TheliaCMS\Script\ScriptPlacement;
use TheliaCMS\TheliaCMS;

/**
 * The messages of the constraints are translated here: the validator looks
 * them up in the `validators` domain, where the module has no catalogue.
 */
final class CmsScriptType extends AbstractType
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
                'constraints' => [new NotBlank(message: $this->translate('Give this snippet a name you will recognise in six months.')), new Length(max: 100)],
                'help' => 'Only shown here. "Analytics", "Live chat", "Ads pixel".',
            ])
            ->add('placement', ChoiceType::class, [
                'label' => 'Where it goes',
                'choices' => ScriptPlacement::choices(),
                'choice_translation_domain' => 'theliacms',
            ])
            ->add('consentCategory', TextType::class, [
                'label' => 'Waits for consent to',
                'required' => false,
                'constraints' => [new Length(max: 50)],
                'help' => 'The name of the vendor in your consent platform. Leave empty only for a snippet the site cannot run without: it will load for everyone, straight away.',
            ])
            ->add('content', TextareaType::class, [
                'label' => 'The snippet',
                'required' => false,
                'attr' => ['rows' => 12, 'spellcheck' => 'false', 'class' => 'font-monospace'],
                'help' => 'Paste it as the vendor gave it to you, script tags included.',
            ])
            ->add('note', TextareaType::class, [
                'label' => 'Note',
                'required' => false,
                'constraints' => [new Length(max: 500)],
                'attr' => ['rows' => 2],
                'help' => 'Who asked for it and why, so the next person knows whether it can go.',
            ])
            ->add('active', CheckboxType::class, [
                'label' => 'Live on the site',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['translation_domain' => 'theliacms']);
    }

    private function translate(string $message): string
    {
        return $this->translator->trans($message, [], TheliaCMS::DOMAIN_NAME);
    }
}
