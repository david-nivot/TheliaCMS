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

use TheliaCMS\Media\Svg\Color\SvgColoring;
use TheliaCMS\Media\Svg\PreparedSvgStore;

/**
 * Where the editor stands on the way of several SVG files: the file shown, the
 * ones after it, the images stored on the way so far and, when the way
 * replaces the file of an image, which one, and the usage carried from the file
 * before. It is all in the address, so no session holds it.
 */
final readonly class SvgWay
{
    /** "1": the editor set the colors of this file himself; "0": they follow the usage; absent: they do when none is asked. */
    public const string COLORS_TOUCHED_PARAMETER = 'colors_touched';

    /**
     * @param list<string> $queue  the tokens of the files after this one
     * @param list<int>    $images the images stored on the way so far
     */
    public function __construct(
        public string $token,
        public array $queue,
        public array $images,
        public ?int $replace,
        public int $total,
        public ?string $level,
        // Opened from the image picker of the editor, in a frame: the end of
        // the way hands the image back to it instead of opening a screen.
        public bool $picker = false,
        // The color setting carried from the file before, its main color left out.
        public SvgColoring $coloring = new SvgColoring(),
        // The usage carried from the file before: an icon. Read from the
        // address by the screen, which offers it only where it can be taken.
        public bool $icon = false,
        // The name the editor typed, kept when the screen is asked for a preview.
        public ?string $iconName = null,
        // The editor chose the colors of this file: the usage no longer preselects them.
        public bool $colorsTouched = false,
    ) {
    }

    /**
     * @param array<string, mixed> $query
     */
    public static function fromRequest(array $query, string $token): self
    {
        $queue = array_values(array_filter(
            explode(',', self::text($query['queue'] ?? '')),
            static fn (string $candidate): bool => PreparedSvgStore::isToken($candidate),
        ));
        $images = array_values(array_filter(
            array_map('intval', explode(',', self::text($query['images'] ?? ''))),
            static fn (int $id): bool => $id > 0,
        ));
        $replace = (int) self::text($query['replace'] ?? '');
        $total = (int) self::text($query['of'] ?? '');
        $level = self::text($query['level'] ?? '');
        $iconName = trim(self::text($query['icon_name'] ?? ''));
        $coloring = SvgColoring::fromValues($query);

        return new self(
            $token,
            $queue,
            $images,
            $replace > 0 ? $replace : null,
            max($total, \count($queue) + 1),
            '' === $level ? null : $level,
            '1' === self::text($query['picker'] ?? ''),
            $coloring,
            'icon' === self::text($query['usage'] ?? ''),
            '' === $iconName ? null : $iconName,
            self::colorsTouched($query, $coloring),
        );
    }

    /**
     * Whether the editor set the colors himself: said by the field the forms
     * carry, and, for an address that does not carry it, by colors that differ
     * from the default.
     *
     * @param array<string, mixed> $values the query or the form
     */
    public static function colorsTouched(array $values, SvgColoring $coloring): bool
    {
        return match (self::text($values[self::COLORS_TOUCHED_PARAMETER] ?? '')) {
            '1' => true,
            '0' => false,
            default => !$coloring->changesNothing(),
        };
    }

    /** The place of this file among the files of the sending, from 1. */
    public function position(): int
    {
        return $this->total - \count($this->queue);
    }

    /**
     * The address of this screen, what the form of the file posts to.
     *
     * @return array<string, mixed>
     */
    public function parameters(int $languageId): array
    {
        return SvgPreparationController::parameters([$this->token, ...$this->queue], $this->images, $this->replace, $languageId, $this->total, null, $this->picker);
    }

    /** A value of the address that is not text (an array put there by hand) reads as nothing. */
    private static function text(mixed $value): string
    {
        return \is_string($value) ? $value : '';
    }
}
