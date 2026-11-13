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
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Constraints\Image;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use TheliaCMS\Media\Svg\SvgUpload;
use TheliaCMS\Media\Video\VideoIntake;
use TheliaCMS\TheliaCMS;

final class CmsMediaType extends AbstractType
{
    /**
     * SVG is deliberately absent: it is a document that can carry script, and
     * an uploaded one becomes stored cross-site scripting. The fields that take
     * an image for a page or a section keep this list; the media library has its
     * own, below.
     */
    public const array ACCEPTED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    /**
     * What the media library takes: the same images, and SVG, which never
     * enters it directly. It is cleaned and shown to the editor on its own
     * screen first. The extension is there for the systems that give no type to
     * an SVG file.
     */
    public const array LIBRARY_ACCEPTED_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/svg+xml', '.svg'];

    /**
     * The ceiling of an uploaded image, in bytes (10 MB, binary: 1024 × 1024 bytes a MB, as `MediaItem` counts) and in pixels
     * (25 megapixels): its header is read on upload, but it is decoded whole
     * every time a variant is drawn, at about eight bytes a pixel.
     */
    public const int MAX_IMAGE_BYTES = 10 * 1024 * 1024;

    public const int MAX_IMAGE_PIXELS = 25_000_000;

    /**
     * The videos the library takes: MP4 only, the type finfo reads in the
     * content of the file and never the extension.
     */
    public const array ACCEPTED_VIDEO_MIME_TYPES = ['video/mp4'];

    /**
     * The ceiling of an uploaded video, in bytes (50 MB, binary as the image
     * one). Nothing is decoded, so there is no pixel ceiling: it only bounds
     * the disk and the transfer.
     */
    public const int MAX_VIDEO_BYTES = 50 * 1024 * 1024;

    /**
     * The videos a server with ffmpeg takes besides MP4: what a phone records
     * (QuickTime, `.mov`) and what a browser exports (WebM). They are converted
     * to MP4 in the background.
     */
    public const array RAW_VIDEO_MIME_TYPES = ['video/quicktime', 'video/webm'];

    /**
     * The ceiling of a raw video sent to a server with ffmpeg, in bytes (100 MB,
     * binary): the phone video of a minute, before its conversion shrinks it.
     * The PHP upload limits of the server must stay above it.
     */
    public const int MAX_RAW_VIDEO_BYTES = 100 * 1024 * 1024;

    /**
     * Every type the library list takes in; `$videoTypes` are the video ones
     * the server takes (`VideoIntake::mimeTypes()`).
     *
     * @param list<string> $videoTypes
     *
     * @return list<string>
     */
    public static function acceptedUploadMimeTypes(array $videoTypes = self::ACCEPTED_VIDEO_MIME_TYPES): array
    {
        return [...self::LIBRARY_ACCEPTED_TYPES, ...$videoTypes];
    }

    /**
     * Optional: the form is also built without a container.
     */
    public function __construct(
        private readonly ?TranslatorInterface $translator = null,
        private readonly ?VideoIntake $videoIntake = null,
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

    /**
     * The constraint of the file that replaces an image of the library: an SVG
     * is let through, the library cleans it and the editor sees the result
     * before anything is stored; any other file is held to the constraint of
     * an image.
     */
    public static function replacementConstraint(?TranslatorInterface $translator = null): Callback
    {
        return new Callback(static function (mixed $file, ExecutionContextInterface $context) use ($translator): void {
            if (!$file instanceof UploadedFile || SvgUpload::isSvg($file)) {
                return;
            }

            $context->getValidator()->inContext($context)->validate($file, self::imageConstraint($translator));
        });
    }

    /**
     * The constraint of the file that replaces an icon: an SVG only, which
     * goes through the preparation screen where it is judged fit to be an icon.
     * A JPEG, a PNG or a WebP cannot be drawn by a page as an icon.
     */
    public static function iconReplacementConstraint(?TranslatorInterface $translator = null): Callback
    {
        return new Callback(static function (mixed $file, ExecutionContextInterface $context) use ($translator): void {
            if (!$file instanceof UploadedFile || SvgUpload::isSvg($file)) {
                return;
            }

            $message = 'An icon can only be replaced by an SVG file.';
            $context->buildViolation(null === $translator ? $message : $translator->trans($message, [], TheliaCMS::DOMAIN_NAME))->addViolation();
        });
    }

    /**
     * The constraint of the file that replaces a video: an MP4 under the
     * ceiling of a video, or a phone video under the raw ceiling where the
     * server converts videos (`$intake`). An image is never replaced by a
     * video, nor the reverse: the pages that use the row would publish the
     * wrong element.
     */
    public static function videoConstraint(?TranslatorInterface $translator = null, ?VideoIntake $intake = null): File
    {
        $translate = static fn (string $message): string => null === $translator ? $message : $translator->trans($message, [], TheliaCMS::DOMAIN_NAME);
        $converts = true === $intake?->converts();

        return new File(
            maxSize: $converts ? self::MAX_RAW_VIDEO_BYTES : self::MAX_VIDEO_BYTES,
            binaryFormat: true,
            mimeTypes: $converts ? [...self::ACCEPTED_VIDEO_MIME_TYPES, ...self::RAW_VIDEO_MIME_TYPES] : self::ACCEPTED_VIDEO_MIME_TYPES,
            maxSizeMessage: $translate('The video is too heavy ({{ size }} {{ suffix }}). It may weigh {{ limit }} {{ suffix }} at most.'),
            mimeTypesMessage: $translate($converts ? 'A video can only be replaced by an MP4, MOV or WebM video.' : 'A video can only be replaced by an MP4 video.'),
        );
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $video = true === $options['video'];

        $builder->add('title', TextType::class, [
            'label' => 'Name',
            'required' => false,
            'constraints' => [new Length(max: 255)],
            'help' => $video ? 'Only used to find the video again in this library.' : 'Only used to find the image again in this library.',
        ]);

        // A video has no alternative text, no decorative flag: it is not read
        // by a screen reader as an image is, and the rule below does not apply.
        // Nor does an icon: its text depends on where it is placed, and the
        // component that draws it gives it.
        if (!$video && true !== $options['icon']) {
            $builder
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
                ->addEventListener(FormEvents::SUBMIT, self::requireAltUnlessDecorative($this->translator));
        }

        $builder
            ->add('caption', TextareaType::class, [
                'label' => 'Caption',
                'required' => false,
                'attr' => ['rows' => 2],
                'help' => $video ? 'Shown next to the video where a theme displays one. Translated per language.' : 'Shown next to the image where a theme displays one. Translated per language.',
            ])
            ->add('tags', TextType::class, [
                'label' => 'Tags',
                'required' => false,
                'help' => 'Comma separated. Tags are how the library is searched until it gets folders.',
            ])
            ->add('file', FileType::class, [
                'label' => 'Replace the file',
                'required' => false,
                'constraints' => [match (true) {
                    $video => self::videoConstraint($this->translator, $this->videoIntake),
                    true === $options['icon'] => self::iconReplacementConstraint($this->translator),
                    default => self::replacementConstraint($this->translator),
                }],
                'help' => $video ? 'The new file keeps the same address, so the pages using this video show it right away.' : 'The new file keeps the same address, so the pages using this image show it right away.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['translation_domain' => 'theliacms', 'video' => false, 'icon' => false]);
        $resolver->setAllowedTypes('video', 'bool');
    }

    /**
     * Alternative text is mandatory unless the image is declared decorative —
     * and that declaration arrives in the same submission, so the rule cannot
     * be a constraint on the field. Its message is translated here: the form
     * theme prints a form error as it is.
     */
    public static function requireAltUnlessDecorative(?TranslatorInterface $translator = null): \Closure
    {
        return static function (FormEvent $event) use ($translator): void {
            $data = $event->getData();

            if (true === ($data['decorative'] ?? false)) {
                $data['alt'] = null;
                $event->setData($data);

                return;
            }

            if ('' === trim((string) ($data['alt'] ?? ''))) {
                $message = 'Describe the image, or tick "This image is decorative".';
                $event->getForm()->get('alt')->addError(new FormError(
                    null === $translator ? $message : $translator->trans($message, [], TheliaCMS::DOMAIN_NAME),
                ));
            }
        };
    }
}
