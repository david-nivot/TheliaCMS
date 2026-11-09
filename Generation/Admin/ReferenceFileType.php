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
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;
use TheliaCMS\Generation\ReferenceFile;
use TheliaCMS\Generation\ReferenceFileRefusal;
use TheliaCMS\TheliaCMS;

/**
 * A text file joined to a brief or to a change asked, as material for the
 * page. The file is checked by ReferenceFile once the form is submitted, not
 * by a constraint: one place says why a file is refused, on the screen and
 * in the tests.
 */
final class ReferenceFileType extends AbstractType
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        // The browser refuses a file above the ceiling before sending it,
        // and names the limit that stops it: the module's, or PHP's when
        // it is lower, as the media library does.
        $phpLimit = (int) UploadedFile::getMaxFilesize();
        $moduleLimitPlays = ReferenceFile::MAX_BYTES <= $phpLimit;

        $resolver->setDefaults([
            'label' => 'A file to write from',
            'required' => false,
            'translation_domain' => 'theliacms',
            'attr' => [
                'accept' => implode(',', array_map(static fn (string $extension): string => '.'.$extension, ReferenceFile::EXTENSIONS)),
                'data-max-size' => min(ReferenceFile::MAX_BYTES, $phpLimit),
                'data-max-size-message' => $this->translator->trans(($moduleLimitPlays ? ReferenceFileRefusal::TooLong : ReferenceFileRefusal::TooLarge)->message(), [], TheliaCMS::DOMAIN_NAME),
            ],
        ]);
    }

    public function getParent(): string
    {
        return FileType::class;
    }

    public function getBlockPrefix(): string
    {
        return 'cms_reference_file';
    }
}
