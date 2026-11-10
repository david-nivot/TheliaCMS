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

namespace TheliaCMS\Content\Pipeline;

use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Visitor\AttributeSanitizer\AttributeSanitizerInterface;
use TheliaCMS\Content\EmbedFacade;
use TheliaCMS\Content\EmbedPlatform;

/**
 * Holds the attributes of a video facade to what the module writes there: a
 * platform of its closed list and an identifier of one of their patterns. The
 * script that builds the player checks them again; a facade that a page
 * writes by hand can name no other video host and no other address.
 */
final readonly class EmbedAttributeSanitizer implements AttributeSanitizerInterface
{
    public function getSupportedElements(): ?array
    {
        return ['div'];
    }

    public function getSupportedAttributes(): array
    {
        return [EmbedFacade::PLATFORM_ATTRIBUTE, EmbedFacade::ID_ATTRIBUTE, EmbedFacade::TITLE_ATTRIBUTE];
    }

    public function sanitizeAttribute(string $element, string $attribute, string $value, HtmlSanitizerConfig $config): ?string
    {
        return match ($attribute) {
            EmbedFacade::PLATFORM_ATTRIBUTE => null !== EmbedPlatform::tryFrom($value) ? $value : null,
            EmbedFacade::ID_ATTRIBUTE => self::isIdentifier($value) ? $value : null,
            default => mb_strlen($value) <= 300 ? $value : null,
        };
    }

    private static function isIdentifier(string $value): bool
    {
        foreach (EmbedPlatform::cases() as $platform) {
            if ($platform->isId($value)) {
                return true;
            }
        }

        return false;
    }
}
