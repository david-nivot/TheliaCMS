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

namespace TheliaCMS\Generation\Admin;

use FlexyBundle\Toolkit\StoryProviderInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use TheliaCMS\Content\Section\SectionStories;

/**
 * Where to see every section the generator can use: the page the module
 * hangs in the toolkit of the Flexy theme. The toolkit answers where the
 * kernel runs in debug or where the theme opens it (THEME_TOOLKIT, the same
 * rule as the controller of the toolkit), and only when the theme is there;
 * elsewhere there is nothing to link to.
 *
 * The page is a story of the toolkit (SectionStories), declared only when
 * the theme brings the stories of Flexy. A theme with a toolkit of its own
 * (a fork of Flexy from before them) answers the route but has no such page,
 * and reading SectionStories there would load an interface that does not
 * exist.
 */
final readonly class ToolkitLink
{
    private bool $storiesAvailable;

    public function __construct(
        private UrlGeneratorInterface $urls,
        #[Autowire('%kernel.debug%')]
        private bool $debug,
        ?bool $storiesAvailable = null,
        #[Autowire('%env(bool:default::THEME_TOOLKIT)%')]
        private bool $themeToolkit = false,
    ) {
        $this->storiesAvailable = $storiesAvailable ?? interface_exists(StoryProviderInterface::class);
    }

    public function url(): ?string
    {
        if (!($this->debug || $this->themeToolkit) || !$this->storiesAvailable) {
            return null;
        }

        try {
            return $this->urls->generate('toolkit_show', ['slug' => SectionStories::ALL_SECTIONS_SLUG]);
        } catch (RouteNotFoundException) {
            return null;
        }
    }
}
