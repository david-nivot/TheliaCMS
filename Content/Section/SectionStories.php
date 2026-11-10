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

namespace TheliaCMS\Content\Section;

use FlexyBundle\Toolkit\ComponentStatus;
use FlexyBundle\Toolkit\Story;
use FlexyBundle\Toolkit\StoryProviderInterface;

/**
 * Lists the sections a page may be made of in the toolkit of the Flexy theme,
 * on one page.
 *
 * Registered only when Flexy is there (see TheliaCMS::configureServices): on
 * another theme there is no toolkit to list anything in.
 */
final readonly class SectionStories implements StoryProviderInterface
{
    public const string CATEGORY = 'CMS';

    /** The slug the toolkit gives the page that lists every section: the category and the name. */
    public const string ALL_SECTIONS_SLUG = 'cms-all-sections';

    public function stories(): array
    {
        // One page with every section, for whoever writes a brief and wants
        // to know what the generator can use.
        return [
            new Story(
                category: self::CATEGORY,
                name: 'All sections',
                twigPath: '@TheliaCMSModule/front/sections/toolkit/all.html.twig',
                sourcePath: \dirname(__DIR__, 2).'/templates/front/sections/toolkit/all.html.twig',
                status: ComponentStatus::READY,
            ),
            new Story(
                category: self::CATEGORY,
                name: 'CMS icons',
                twigPath: '@TheliaCMSModule/front/sections/toolkit/icons.html.twig',
                sourcePath: \dirname(__DIR__, 2).'/templates/front/sections/toolkit/icons.html.twig',
                status: ComponentStatus::READY,
            ),
        ];
    }
}
