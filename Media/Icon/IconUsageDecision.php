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

namespace TheliaCMS\Media\Icon;

use Symfony\Contracts\Translation\TranslatorInterface;
use TheliaCMS\Locale\TranslatableText;
use TheliaCMS\Media\Svg\Color\SvgColoring;
use TheliaCMS\Media\Svg\Color\SvgColorPreset;
use TheliaCMS\Media\Svg\SvgPreparation;

/**
 * What the usage of an SVG that goes through the preparation screen comes to:
 * an image, or an icon under a name.
 *
 * The usage is offered for a file added to the library, not for one that
 * replaces the file of an image or is chosen from the picker. It is held to
 * what the drawing allows: asked for a file that cannot be an icon, it falls
 * back to an image and the reasons stay on the screen.
 */
final readonly class IconUsageDecision
{
    public function __construct(
        private SvgPreparation $preparation,
        private IconEligibility $eligibility,
        private IconNames $names,
        private IconCitations $citations,
        private TranslatorInterface $translator,
    ) {
    }

    /** The usage is offered only where a file is added: not for a file that replaces an image, nor from the picker. */
    public static function isOffered(?int $replace, bool $picker): bool
    {
        return null === $replace && !$picker;
    }

    /** The usage asked, where it is offered. */
    public static function isAsked(?int $replace, bool $picker, bool $icon): bool
    {
        return $icon && self::isOffered($replace, $picker);
    }

    /**
     * The file prepared for the usage asked and the colors. Until the editor
     * sets the colors himself, the usage chooses them: an icon takes the main
     * color as the color of the text, an image keeps its colors. The drawing
     * is judged fit for an icon as it is before that preset, so the preset
     * never makes fit what was not.
     *
     * @throws \TheliaLibrary\Exception\UnreadableSvgException
     */
    public function prepare(string $original, ?string $level, bool $icon, SvgColoring $asked, bool $touched): IconUsageDraft
    {
        if ($touched) {
            $result = $this->preparation->prepare($original, $level, $asked);

            return new IconUsageDraft($result, $result->content);
        }

        $plain = $this->preparation->prepare($original, $level);

        if (!$icon || !$this->eligibility->isFit($plain->content) || null === $plain->palette->main()) {
            return new IconUsageDraft($plain, $plain->content);
        }

        return new IconUsageDraft($this->preparation->prepare($original, $level, new SvgColoring(SvgColorPreset::Main)), $plain->content);
    }

    /**
     * What the usage block of the screen draws.
     *
     * @param string      $fileName the name of the file sent, from which a name is proposed
     * @param string      $judged   the drawing the icon usage is judged on
     * @param bool        $icon     the usage asked for: it holds only where the drawing can be an icon
     * @param string|null $iconName the name typed; the one drawn from the file name when none was
     * @param string|null $problem  why the answer was not taken, already in the language of the screen
     *
     * @return array<string, mixed>
     */
    public function variables(string $fileName, ?int $replace, bool $picker, string $judged, bool $icon, ?string $iconName, ?string $problem): array
    {
        $replacedIcon = null === $replace ? null : $this->names->nameOf($replace);
        $offered = self::isOffered($replace, $picker);

        if (!$offered && null === $replacedIcon) {
            return IconUsageBlock::absent($problem)->variables();
        }

        $obstacles = array_map(fn (TranslatableText $obstacle): string => $obstacle->trans($this->translator), $this->eligibility->obstacles($judged));
        $chosen = $offered && $icon && [] === $obstacles;
        $name = $iconName ?? IconNameSuggestion::fromFileName($fileName);
        // A preview raises the problem of a name that is taken or invalid at once; one typed nothing is told on the answer.
        $nameProblem = $chosen && null === $problem ? $this->nameProblem($name, false) : null;

        return (new IconUsageBlock(
            offered: $offered,
            replaced: null === $replacedIcon ? null : IconName::prefixed($replacedIcon),
            obstacles: $obstacles,
            chosen: $chosen,
            name: $name,
            error: $problem ?? (null === $nameProblem ? null : $nameProblem->trans($this->translator)),
            citation: null === $replacedIcon ? null : $this->citations->of($replacedIcon),
        ))->variables();
    }

    /**
     * What stops an answer that makes an icon, or replaces the file of one: a
     * drawing a page would not keep whole, or a name that cannot be taken.
     *
     * @param string   $judged  the drawing the icon usage is judged on
     * @param int|null $replace the image whose file the answer replaces
     *
     * @return string|null the reason, in the language of the screen
     */
    public function problem(string $judged, bool $icon, string $name, ?int $replace): ?string
    {
        $replacedIcon = null === $replace ? null : $this->names->nameOf($replace);

        if (!$icon && null === $replacedIcon) {
            return null;
        }

        if (!$this->eligibility->isFit($judged)) {
            return (null === $replacedIcon
                ? TranslatableText::of('This file cannot be added as an icon: see below what a page would not keep. It can still be added as an image.')
                : TranslatableText::of('The image is the icon %s: the new file would not be kept whole in a page, so it does not replace the old one.', IconName::prefixed($replacedIcon)))->trans($this->translator);
        }

        return $icon ? $this->nameProblem($name, true)?->trans($this->translator) : null;
    }

    private function nameProblem(string $name, bool $required): ?TranslatableText
    {
        if ('' === $name && !$required) {
            return null;
        }

        if (!IconName::isValid($name)) {
            return IconName::invalidMessage();
        }

        return $this->names->isTaken($name)
            ? TranslatableText::of('The icon name "%s" is already used by another image.', IconName::prefixed($name))
            : null;
    }
}
