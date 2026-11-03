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
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Image;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Contracts\Translation\TranslatorInterface;
use TheliaCMS\TheliaCMS;

final class CmsMediaType extends AbstractType
{
    /**
     * SVG is deliberately absent: it is a document that can carry script, and
     * an uploaded one becomes stored cross-site scripting.
     */
    public const array ACCEPTED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    /**
     * The ceiling of an uploaded image, in bytes (10 MB, binary: 1024 × 1024 bytes a MB, as `MediaItem` counts) and in pixels
     * (25 megapixels): its header is read on upload, but it is decoded whole
     * every time a variant is drawn, at about eight bytes a pixel.
     */
    public const int MAX_IMAGE_BYTES = 10 * 1024 * 1024;

    public const int MAX_IMAGE_PIXELS = 25_000_000;

    /**
     * Optional: the form is also built without a container.
     */
    public function __construct(
        private readonly ?TranslatorInterface $translator = null,
    ) {
    }

    /**
     * The one constraint of every image the module takes in a form: its
     * formats and its ceiling.
     *
     * The messages are translated here: the validator looks them up in the
     * `validators` domain, where the module has no catalogue. Its `{{ … }}`
     * placeholders are filled in afterwards.
     */
    public static function imageConstraint(?TranslatorInterface $translator = null): Image
    {
        $translate = static fn (string $message): string => null === $translator ? $message : $translator->trans($message, [], TheliaCMS::DOMAIN_NAME);

        return new Image(
            maxSize: self::MAX_IMAGE_BYTES,
            binaryFormat: true,
            maxSizeMessage: $translate('The image is too heavy ({{ size }} {{ suffix }}). It may weigh {{ limit }} {{ suffix }} at most.'),
            mimeTypes: self::ACCEPTED_MIME_TYPES,
            mimeTypesMessage: $translate('Only JPEG, PNG and WebP images can be uploaded.'),
            maxPixels: self::MAX_IMAGE_PIXELS,
            maxPixelsMessage: $translate('The image has too many pixels ({{ pixels }}). It may have {{ max_pixels }} at most.'),
        );
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'Name',
                'required' => false,
                'constraints' => [new Length(max: 255)],
                'help' => 'Only used to find the image again in this library.',
            ])
            ->add('decorative', CheckboxType::class, [
                'label' => 'This image is decorative',
                'required' => false,
                'help' => 'Decorative images are published with an empty alternative text so screen readers skip them. Use it for backgrounds and separators, never for a photo that carries information.',
            ])
            // Declared required so the label says so; the browser is told not
            // to enforce it (the screen renders the form with `novalidate`)
            // because the rule below lifts it for a decorative image.
            ->add('alt', TextType::class, [
                'label' => 'Alternative text',
                'required' => true,
                'constraints' => [new Length(max: 255)],
                'help' => 'What the image shows, for people who cannot see it. Translated per language.',
            ])
            ->add('caption', TextareaType::class, [
                'label' => 'Caption',
                'required' => false,
                'attr' => ['rows' => 2],
                'help' => 'Shown next to the image where a theme displays one. Translated per language.',
            ])
            ->add('tags', TextType::class, [
                'label' => 'Tags',
                'required' => false,
                'help' => 'Comma separated. Tags are how the library is searched until it gets folders.',
            ])
            ->add('file', FileType::class, [
                'label' => 'Replace the file',
                'required' => false,
                'constraints' => [self::imageConstraint($this->translator)],
                'help' => 'The new file keeps the same address, so the pages using this image show it right away.',
            ])
            ->addEventListener(FormEvents::SUBMIT, self::requireAltUnlessDecorative());
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['translation_domain' => 'theliacms']);
    }

    /**
     * Alternative text is mandatory unless the image is declared decorative —
     * and that declaration arrives in the same submission, so the rule cannot
     * be a constraint on the field.
     */
    public static function requireAltUnlessDecorative(): \Closure
    {
        return static function (FormEvent $event): void {
            $data = $event->getData();

            if (true === ($data['decorative'] ?? false)) {
                $data['alt'] = null;
                $event->setData($data);

                return;
            }

            if ('' === trim((string) ($data['alt'] ?? ''))) {
                $event->getForm()->get('alt')->addError(new FormError(
                    'Describe the image, or tick "This image is decorative".'
                ));
            }
        };
    }
}
