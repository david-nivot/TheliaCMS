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

namespace TheliaCMS\Front;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Thelia\Core\Hook\Theme\ThemeHookInterface;

/**
 * Links the script of the embedded videos at the bottom of the body of every
 * page, on any theme: it does nothing until a facade is clicked, and is
 * cached for a year under an address that carries its hash.
 */
final readonly class EmbedScriptThemeHook implements ThemeHookInterface
{
    public const string HOOK = 'layout.body.bottom';

    public function __construct(
        private EmbedScript $script,
        private UrlGeneratorInterface $urls,
    ) {
    }

    public function supports(string $hookName): bool
    {
        return self::HOOK === $hookName;
    }

    public function render(string $hookName, array $parameters): string
    {
        if (self::HOOK !== $hookName) {
            return '';
        }

        return \sprintf('<script src="%s" defer></script>', htmlspecialchars($this->urls->generate('cms.embed_script', ['v' => $this->script->version()]), \ENT_QUOTES));
    }
}
