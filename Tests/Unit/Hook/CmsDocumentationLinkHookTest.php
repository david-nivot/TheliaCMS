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

namespace TheliaCMS\Tests\Unit\Hook;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheliaCMS\Hook\CmsDocumentationLinkHook;

/**
 * The section of the documentation each screen of the CMS links to.
 */
final class CmsDocumentationLinkHookTest extends TestCase
{
    private const string TEXTS = __DIR__.'/../../../templates/backOffice/default-twig/documentation/';

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function screens(): iterable
    {
        yield 'page list' => ['admin.cms.pages.list', 'pages'];
        yield 'bin' => ['admin.cms.pages.trash', 'trash'];
        yield 'content of a page' => ['admin.cms.pages.content.edit', 'content'];
        yield 'content of a catalogue item' => ['admin.cms.catalogue.content.edit', 'catalogue'];
        yield 'page templates' => ['admin.cms.settings.page_types.list', 'page-templates'];
        yield 'one page template' => ['admin.cms.settings.page_types.edit', 'page-templates'];
        yield 'settings' => ['admin.cms.settings.edit', 'settings'];
        yield 'tags' => ['admin.cms.tags.list', 'tags'];
        yield 'components' => ['admin.cms.components.list', 'components'];
        yield 'new component' => ['admin.cms.components.site.new', 'components'];
        yield 'skills' => ['admin.cms.skills.edit', 'skills'];
        yield 'menus' => ['admin.cms.menus.edit', 'menus'];
        yield 'answers of a form' => ['admin.cms.forms.submissions', 'forms'];
        yield 'media' => ['admin.cms.media.list', 'media'];
        yield 'preparing an SVG' => ['admin.cms.media.svg.show', 'prepare-svg'];
    }

    #[DataProvider('screens')]
    public function testAScreenLinksToTheSectionExplainingIt(string $route, string $section): void
    {
        self::assertSame($section, CmsDocumentationLinkHook::sectionOf($route));
    }

    public function testAScreenNobodyMappedLinksToTheTopOfTheDocumentation(): void
    {
        self::assertNull(CmsDocumentationLinkHook::sectionOf('admin.cms.something-new.list'));
    }

    /**
     * A section or sub-part renamed or removed in one language would leave links landing
     * at the top of the page.
     */
    #[DataProvider('screens')]
    public function testEveryLanguageOfTheDocumentationHasTheSection(string $route, string $section): void
    {
        foreach (['_fr_FR.html.twig', '_en_US.html.twig'] as $text) {
            self::assertStringContainsString(\sprintf(' id="%s">', $section), (string) file_get_contents(self::TEXTS.$text), $text);
        }
    }

    /**
     * A help link lands on its part as the page opens; an image above it
     * that has no size grows once loaded and pushes the part out of sight.
     */
    public function testEveryImageOfTheDocumentationKeepsItsPlaceBeforeItLoads(): void
    {
        foreach (['_fr_FR.html.twig', '_en_US.html.twig'] as $text) {
            preg_match_all('#<img [^>]*>#', (string) file_get_contents(self::TEXTS.$text), $images);
            self::assertNotSame([], $images[0], $text);

            foreach ($images[0] as $image) {
                self::assertSame(1, preg_match("#assets/documentation/([^']+)'#", $image, $file), $image);
                $size = getimagesize(self::TEXTS.'../assets/documentation/'.$file[1]);
                self::assertNotFalse($size, $file[1]);
                self::assertStringContainsString(\sprintf('width="%d" height="%d"', $size[0], $size[1]), $image, $text.': '.$file[1]);
            }
        }
    }
}
