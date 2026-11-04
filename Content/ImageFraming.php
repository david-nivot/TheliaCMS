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

namespace TheliaCMS\Content;

use Symfony\Contracts\Translation\TranslatorInterface;
use TheliaCMS\Locale\TranslatableText;
use TheliaCMS\TheliaCMS;

/**
 * The framing of the pictures of a component whose markup is not the module's:
 * a component of the theme, a component made from a brief.
 *
 * Such a component prints the address of its pictures itself, so the module
 * cannot put anything on its `<img>`. It gives the component the address of the
 * zone it keeps instead (`ImageSource::framed()`), and reads the choice of the
 * editor from props the component never sees: for a picture `photo`, the
 * proportions `photo__proportions`, the centre `photo__focus_x` and
 * `photo__focus_y`, and `photo__zoom`. A double underscore is a name the
 * components of the theme do not spell, and a picture whose four names a real
 * prop already holds is left unframed.
 *
 * The four props are editor-only (no model reads or writes them) and marked
 * with the editor `framing`, which is how the schema, the form and the drawing
 * tell them from a prop of the component.
 */
final readonly class ImageFraming
{
    public const string EDITOR = 'framing';

    public const string PROPORTIONS = '__proportions';

    public const string FOCUS_X = '__focus_x';

    public const string FOCUS_Y = '__focus_y';

    public const string ZOOM = '__zoom';

    public function __construct(
        private ?TranslatorInterface $translator = null,
    ) {
    }

    /**
     * The props of a component with, right after each picture, the four of its
     * framing: in the rows of a list too. Unchanged when there is no picture.
     *
     * @param list<Prop> $props
     *
     * @return list<Prop>
     */
    public function extend(array $props): array
    {
        $taken = array_flip(array_map(static fn (Prop $prop): string => $prop->name, $props));
        $extended = [];

        foreach ($props as $prop) {
            $extended[] = PropType::Items === $prop->type && !$prop->scalarItems ? $prop->withItems($this->extend($prop->items)) : $prop;

            if (PropType::Image === $prop->type && !$this->collides($prop->name, $taken)) {
                array_push($extended, ...$this->framingOf($prop->name));
            }
        }

        return $extended;
    }

    public static function isFraming(Prop $prop): bool
    {
        return self::EDITOR === $prop->editor;
    }

    /**
     * The name of the picture a framing prop belongs to.
     */
    public static function imageOf(Prop $prop): ?string
    {
        if (!self::isFraming($prop)) {
            return null;
        }

        foreach ([self::PROPORTIONS, self::FOCUS_X, self::FOCUS_Y, self::ZOOM] as $suffix) {
            if (str_ends_with($prop->name, $suffix)) {
                return substr($prop->name, 0, -\strlen($suffix));
            }
        }

        return null;
    }

    /**
     * The values a document holds without the framing props: what a component
     * is given.
     *
     * @param list<Prop>           $schema
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    public static function withoutFraming(array $schema, array $values): array
    {
        foreach ($schema as $prop) {
            if (self::isFraming($prop)) {
                unset($values[$prop->name]);
            }
        }

        return $values;
    }

    /**
     * The address a picture of a component is drawn from, with the framing the
     * values hold for it: the whole picture when the editor set none.
     *
     * @param array<string, mixed> $values the props of the component, or the fields of a row, as stored
     */
    public static function source(mixed $image, string $name, array $values): ?string
    {
        $proportions = $values[$name.self::PROPORTIONS] ?? ImageProportions::ORIGINAL;

        if (!\is_string($proportions) || !(ImageProportions::ORIGINAL === $proportions || ImageProportions::isRatio($proportions))) {
            $proportions = ImageProportions::ORIGINAL;
        }

        return ImageSource::framed(
            $image,
            $proportions,
            max(0, min(100, (int) ($values[$name.self::FOCUS_X] ?? 50))),
            max(0, min(100, (int) ($values[$name.self::FOCUS_Y] ?? 50))),
            max(100, min(400, (int) ($values[$name.self::ZOOM] ?? 100))),
        );
    }

    /**
     * Refuses a zoom that is not on a step of ten, as the other sections do:
     * the zones a picture is served in stay few. The rows of a list too.
     *
     * @param list<Prop>           $schema
     * @param array<string, mixed> $props  as the schema read them
     *
     * @throws InvalidDocumentException
     */
    public static function assertZoomSteps(array $schema, array $props): void
    {
        foreach ($schema as $prop) {
            if (self::isFraming($prop) && str_ends_with($prop->name, self::ZOOM) && 0 !== (int) ($props[$prop->name] ?? 100) % ImageSection::ZOOM_STEP) {
                throw InvalidDocumentException::because([TranslatableText::of('"%s" must be a multiple of %d.', $prop->name, ImageSection::ZOOM_STEP)]);
            }

            if (PropType::Items === $prop->type && !$prop->scalarItems && \is_array($props[$prop->name] ?? null)) {
                foreach (array_values($props[$prop->name]) as $index => $row) {
                    try {
                        self::assertZoomSteps($prop->items, \is_array($row) ? $row : []);
                    } catch (InvalidDocumentException $exception) {
                        throw InvalidDocumentException::because(array_map(static fn (mixed $problem): mixed => TranslatableText::of('"%s" item %d: %s', $prop->name, $index + 1, $problem), $exception->texts));
                    }
                }
            }
        }
    }

    /**
     * @param array<string, int> $taken
     */
    private function collides(string $image, array $taken): bool
    {
        foreach ([self::PROPORTIONS, self::FOCUS_X, self::FOCUS_Y, self::ZOOM] as $suffix) {
            if (isset($taken[$image.$suffix])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<Prop>
     */
    private function framingOf(string $image): array
    {
        return [
            Prop::choice($image.self::PROPORTIONS, $this->trans('Proportions'), [
                ImageProportions::ORIGINAL => $this->trans('Original image'),
                ...array_combine(ImageProportions::ratios(), ImageProportions::ratios()),
            ], ImageProportions::ORIGINAL, icons: [
                ImageProportions::ORIGINAL => 'bi-image',
                ...array_combine(ImageProportions::ratios(), array_map(static fn (string $ratio): string => 'ratio-'.str_replace(':', '-', $ratio), ImageProportions::ratios())),
            ])->forEditor(
                help: $this->trans('The shape the picture is cut to. "Original image" keeps its own; the others crop it around the part you keep. The component keeps its own frame: if it is shaped differently, the theme crops again.'),
                only: true,
                editor: self::EDITOR,
            ),
            Prop::integer($image.self::FOCUS_X, $this->trans('Kept part, horizontal'), 50, 0, 100)
                ->forEditor(help: $this->trans('Where the part of the picture to keep sits, from the left (0) to the right (100) edge.'), only: true, editor: self::EDITOR),
            Prop::integer($image.self::FOCUS_Y, $this->trans('Kept part, vertical'), 50, 0, 100)
                ->forEditor(help: $this->trans('Where the part of the picture to keep sits, from the top (0) to the bottom (100) edge.'), only: true, editor: self::EDITOR),
            Prop::integer($image.self::ZOOM, $this->trans('Zoom'), 100, 100, 400)
                ->forEditor(help: $this->trans('100 shows the whole frame; 200 keeps half of its width and height. By steps of 10.'), only: true, editor: self::EDITOR),
        ];
    }

    private function trans(string $message): string
    {
        return $this->translator?->trans($message, [], TheliaCMS::DOMAIN_NAME) ?? $message;
    }
}
