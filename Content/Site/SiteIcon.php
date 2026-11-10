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

namespace TheliaCMS\Content\Site;

use Psr\Log\LoggerInterface;
use Symfony\UX\Icons\IconRendererInterface;
use TheliaCMS\Media\Icon\KnownIcons;

/**
 * `cms_icon()` of the sandbox of the components of the site: draws the icon an
 * icon prop holds (`cms:<name>`, or the name of an icon of the theme).
 *
 * Only a name that is known is drawn, by the chain of ux-icons (the same
 * sources as `ux_icon()`, which the sandbox does not offer). Anything else,
 * a name taken away since the page was written included, draws nothing and is
 * logged: a page does not break for a missing icon.
 */
final readonly class SiteIcon
{
    /** The attributes a component may give; the others are dropped. */
    public const array ATTRIBUTES = ['class', 'aria-label', 'aria-hidden', 'width', 'height', 'role'];

    public function __construct(
        private KnownIcons $known,
        private ?IconRendererInterface $renderer = null,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @param array<array-key, mixed> $attributes
     */
    public function render(mixed $reference, array $attributes = []): string
    {
        if (!\is_string($reference) || '' === $reference) {
            return '';
        }

        if (!$this->known->isKnown($reference)) {
            $this->logger?->warning('A component draws an icon that does not exist.', ['icon' => $reference]);

            return '';
        }

        if (null === $this->renderer) {
            return '';
        }

        try {
            return $this->renderer->renderIcon($reference, self::allowed($attributes));
        } catch (\Throwable $exception) {
            $this->logger?->warning('An icon of a component cannot be drawn.', ['icon' => $reference, 'exception' => $exception]);

            return '';
        }
    }

    /**
     * @param array<array-key, mixed> $attributes
     *
     * @return array<string, string|bool|int|float>
     */
    private static function allowed(array $attributes): array
    {
        $kept = [];

        foreach ($attributes as $name => $value) {
            if (!\in_array($name, self::ATTRIBUTES, true) || '' === $value || !\is_scalar($value)) {
                continue;
            }

            $kept[$name] = $value;
        }

        return $kept;
    }
}
