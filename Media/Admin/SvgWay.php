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

use TheliaCMS\Media\Svg\PreparedSvgStore;

/**
 * Where the editor stands on the way of several SVG files: the file shown, the
 * ones after it, the images stored on the way so far and, when the way
 * replaces the file of an image, which one. It is all in the address, so no
 * session holds it.
 */
final readonly class SvgWay
{
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

        return new self(
            $token,
            $queue,
            $images,
            $replace > 0 ? $replace : null,
            max($total, \count($queue) + 1),
            '' === $level ? null : $level,
            '1' === self::text($query['picker'] ?? ''),
        );
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
