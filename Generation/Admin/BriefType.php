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
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Contracts\Translation\TranslatorInterface;
use TheliaCMS\Generation\ReferenceFile;
use TheliaCMS\Generation\ReferenceFileRefusal;
use TheliaCMS\TheliaCMS;

/**
 * The brief: what the editor wants the page to say, what they know of its
 * tone and audience when they care to say it, and a text file they join as
 * material for the page when they have one.
 *
 * The messages of the constraints are translated here: the validator looks
 * them up in the `validators` domain, where the module has no catalogue.
 * The file is checked by ReferenceFile once the form is submitted, not by a
 * constraint: one place says why a file is refused, on the screen and in
 * the tests.
 */
final class BriefType extends AbstractType
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // The browser refuses a file above the ceiling before sending it,
        // and names the limit that stops it: the module's, or PHP's when
        // it is lower, as the media library does.
        $phpLimit = (int) UploadedFile::getMaxFilesize();
        $moduleLimitPlays = ReferenceFile::MAX_BYTES <= $phpLimit;

        $builder
            ->add('text', TextareaType::class, [
                'label' => 'What should this page say?',
                'attr' => ['rows' => 9, 'placeholder' => 'Who it is for, what it has to say, what the visitor should do next. Facts, figures and names you want in it: the generator invents none.'],
                'constraints' => [new NotBlank(message: $this->translate('Write a brief first.')), new Length(max: 20000)],
            ])
            ->add('reference', FileType::class, [
                'label' => 'A file to write from',
                'required' => false,
                'help' => 'A .md or .txt file of up to 100,000 characters: a product sheet, a page of the old site saved as text, a note. The generator reads it as material for the page, never as instructions. It is not kept with the brief.',
                'attr' => [
                    'accept' => implode(',', array_map(static fn (string $extension): string => '.'.$extension, ReferenceFile::EXTENSIONS)),
                    'data-max-size' => min(ReferenceFile::MAX_BYTES, $phpLimit),
                    'data-max-size-message' => $this->translate(($moduleLimitPlays ? ReferenceFileRefusal::TooLong : ReferenceFileRefusal::TooLarge)->message()),
                ],
            ])
            ->add('tone', TextType::class, [
                'label' => 'Tone',
                'required' => false,
                'attr' => ['placeholder' => 'warm, direct, institutional…'],
                'constraints' => [new Length(max: 200)],
            ])
            ->add('audience', TextType::class, [
                'label' => 'Audience',
                'required' => false,
                'attr' => ['placeholder' => 'families, professionals, first-time buyers…'],
                'constraints' => [new Length(max: 200)],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => 'theliacms',
            // The token of the back office is checked by the guard of the
            // section; a second token on the form would be checked by nobody.
            'csrf_protection' => false,
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'cms_brief';
    }

    private function translate(string $message): string
    {
        return $this->translator->trans($message, [], TheliaCMS::DOMAIN_NAME);
    }
}
