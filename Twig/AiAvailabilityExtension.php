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

namespace TheliaCMS\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * What a screen of the back office asks before it offers a button that calls the AI:
 * `{% if cms_ai_available('theliacms.component') %}`. The usage is the one the
 * button's call runs under (`theliacms.page_generation`, `theliacms.page_translation`,
 * `theliacms.component`, `theliacms.text`).
 *
 * This hides the entry, it does not guard the route: every route that calls the AI
 * keeps answering with its own refusal when the AI cannot serve it.
 *
 * The answer comes from a runtime (AiAvailabilityRuntime), built when a template
 * first asks and not when the environment is built: a page that never asks costs
 * no resolution of the AI settings.
 */
final class AiAvailabilityExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('cms_ai_available', [AiAvailabilityRuntime::class, 'isAvailable']),
        ];
    }
}
