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

namespace TheliaCMS\Tests\Unit\Generation;

use FlexyBundle\Toolkit\StoryProviderInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use TheliaCMS\Generation\Admin\ToolkitLink;

/**
 * The link to the page of every section: there with the Flexy theme where the
 * kernel runs in debug or the theme opens its toolkit (THEME_TOOLKIT),
 * nowhere otherwise, since the toolkit answers nowhere else.
 */
final class ToolkitLinkTest extends TestCase
{
    public function testLinksToThePageOfEverySectionInDebug(): void
    {
        if (!interface_exists(StoryProviderInterface::class)) {
            self::markTestSkipped('The theme has no toolkit: the section stories cannot load.');
        }

        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturnCallback(static fn (string $route, array $parameters): string => '/'.$route.'/'.$parameters['slug']);

        self::assertSame('/toolkit_show/cms-all-sections', (new ToolkitLink($urls, true))->url());
    }

    public function testLinksNowhereOutsideDebug(): void
    {
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturn('/toolkit/cms-all-sections');

        self::assertNull((new ToolkitLink($urls, false, storiesAvailable: true, themeToolkit: false))->url(), 'The toolkit answers 404 outside debug unless the theme opens it.');
    }

    public function testLinksToThePageOfEverySectionWhereTheThemeOpensItsToolkit(): void
    {
        if (!interface_exists(StoryProviderInterface::class)) {
            self::markTestSkipped('The theme has no toolkit: the section stories cannot load.');
        }

        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturn('/toolkit/cms-all-sections');

        self::assertSame(
            '/toolkit/cms-all-sections',
            (new ToolkitLink($urls, false, storiesAvailable: true, themeToolkit: true))->url(),
            'THEME_TOOLKIT opens the toolkit outside debug.',
        );
    }

    public function testLinksNowhereWithoutTheToolkit(): void
    {
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willThrowException(new RouteNotFoundException());

        self::assertNull((new ToolkitLink($urls, true))->url(), 'Another theme has no toolkit.');
    }

    public function testLinksNowhereWhenTheThemeHasNoStories(): void
    {
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturn('/toolkit/cms-all-sections');

        self::assertNull(
            (new ToolkitLink($urls, true, storiesAvailable: false))->url(),
            'A theme with a toolkit of its own but without the stories of Flexy lists no page of sections.',
        );
    }
}
