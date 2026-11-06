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

namespace TheliaCMS\Media\Svg;

use Symfony\Contracts\Translation\TranslatorInterface;
use TheliaCMS\Media\Admin\MediaUploadRefusal;
use TheliaCMS\TheliaCMS;
use TheliaLibrary\Service\Svg\SvgChange;
use TheliaLibrary\Service\Svg\SvgChangeKind;
use TheliaLibrary\Service\Svg\UnreadableSvgReason;

/**
 * Puts into words what the cleaning of an SVG did, in the language of the
 * screen: one sentence for what left the file, the reason it left, and a
 * sentence for a file that could not be read at all.
 *
 * The reason comes from the name the library gives: a handler is an attribute
 * starting with `on`, an animation is one of a few elements, and whatever
 * carries a prefix other than the standard ones was put there by an editor.
 */
final readonly class SvgChangeWording
{
    private const array ANIMATION_ELEMENTS = ['animate', 'animatecolor', 'animatemotion', 'animatetransform', 'discard', 'mpath', 'set'];

    private const array STANDARD_PREFIXES = ['xlink', 'xml', 'svg'];

    public function __construct(private TranslatorInterface $translator)
    {
    }

    /**
     * One sentence per different change, in the order they appear (the same
     * change many times is one sentence and its count), each saying whether
     * the cleaning added something to the file (a viewBox, the namespace) or
     * took something out of it.
     *
     * @param list<SvgChange> $changes
     *
     * @return list<array{text: string, added: bool}>
     */
    public function entries(array $changes): array
    {
        $counts = [];
        $firsts = [];

        foreach ($changes as $change) {
            $key = $change->kind->value."\0".$change->subject;
            $counts[$key] = ($counts[$key] ?? 0) + 1;
            $firsts[$key] ??= $change;
        }

        $entries = [];

        foreach ($firsts as $key => $change) {
            $sentence = $this->sentence($change);
            $entries[] = [
                'text' => $counts[$key] > 1 ? $sentence.' ×'.$counts[$key] : $sentence,
                'added' => \in_array($change->kind, [SvgChangeKind::AddedViewBox, SvgChangeKind::AddedNamespace], true),
            ];
        }

        return $entries;
    }

    /**
     * Why a file was refused, as the editor reads it.
     */
    public function refusal(UnreadableSvgReason $reason): string
    {
        return $this->translate(MediaUploadRefusal::forUnreadableSvg($reason)->message());
    }

    private function sentence(SvgChange $change): string
    {
        return match ($change->kind) {
            SvgChangeKind::RemovedElement => $this->removedElement($change->subject),
            SvgChangeKind::RemovedAttribute => $this->removedAttribute($change->subject),
            SvgChangeKind::ExternalReference => $this->translate('External reference removed from %name%.', ['%name%' => '<'.$change->subject.'>']),
            SvgChangeKind::AddedViewBox => $this->translate('viewBox added: %value%.', ['%value%' => $change->subject]),
            SvgChangeKind::RemovedComment => $this->translate('Comments removed.'),
            SvgChangeKind::AddedNamespace => $this->translate('SVG namespace added: without it the file would not be displayed.'),
        };
    }

    private function removedElement(string $name): string
    {
        $parameters = ['%name%' => '<'.$name.'>'];
        $lowercase = strtolower($name);

        // The declaration that opens some exports: not an element, so not worded as one.
        if ('!DOCTYPE' === $name) {
            return $this->translate('DOCTYPE declaration removed.');
        }

        if (str_starts_with($name, '?')) {
            return $this->translate('Processing instruction %name% removed.', ['%name%' => '<'.$name.' ?>']);
        }

        return $this->translate(match (true) {
            'script' === $lowercase => 'Element %name% removed: script.',
            'foreignobject' === $lowercase => 'Element %name% removed: embedded HTML content.',
            \in_array($lowercase, self::ANIMATION_ELEMENTS, true) => 'Element %name% removed: animation.',
            $this->isEditorMarkup($name) => 'Element %name% removed: editor markup.',
            default => 'Element %name% removed: not allowed in an image.',
        }, $parameters);
    }

    private function removedAttribute(string $name): string
    {
        return $this->translate(match (true) {
            1 === preg_match('/^on[a-z]+$/i', $name) => 'Attribute %name% removed: event handler.',
            $this->isEditorMarkup($name) => 'Attribute %name% removed: editor markup.',
            default => 'Attribute %name% removed: not allowed in an image.',
        }, ['%name%' => $name]);
    }

    /**
     * A name with a prefix other than the standard ones: `sodipodi:namedview`,
     * `inkscape:label`, `xmlns:inkscape`, `i:pgf`.
     */
    private function isEditorMarkup(string $name): bool
    {
        $parts = explode(':', $name, 2);

        if (2 !== \count($parts)) {
            return false;
        }

        if ('xmlns' === $parts[0]) {
            return !\in_array($parts[1], self::STANDARD_PREFIXES, true);
        }

        return !\in_array($parts[0], self::STANDARD_PREFIXES, true);
    }

    /**
     * @param array<string, string> $parameters
     */
    private function translate(string $message, array $parameters = []): string
    {
        return $this->translator->trans($message, $parameters, TheliaCMS::DOMAIN_NAME);
    }
}
