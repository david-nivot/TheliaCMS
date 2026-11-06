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

namespace TheliaCMS\Tests\Integration\Page;

use Thelia\Test\FixtureFactory;
use TheliaCMS\Catalogue\Admin\ItemAccessibilityException;
use TheliaCMS\Catalogue\CatalogueItemType;
use TheliaCMS\Content\Document;
use TheliaCMS\Content\Editing\ContentWriter;
use TheliaCMS\Media\CmsMediaLibrary;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Model\CmsPageContentQuery;
use TheliaCMS\Model\CmsPageRevisionQuery;
use TheliaCMS\Page\Admin\AccessibilityException;
use TheliaCMS\Page\Admin\CmsPageWriter;
use TheliaCMS\Page\Admin\HeadingStructureException;
use TheliaCMS\Settings\CmsSettings;
use TheliaCMS\Tests\Integration\BuildsItemTargets;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;
use TheliaCMS\TheliaCMS;
use TheliaLibrary\Model\LibraryImage;

/**
 * A page that goes online with an image nobody described, or a link that
 * says nothing, is published with a warning, or kept offline when the site
 * asked for it. Its own setting: the one of the headings decides nothing here.
 */
final class PublishAccessibilityTest extends CmsIntegrationTestCase
{
    use BuildsItemTargets;

    public function testInBlockModeAnUndescribedLibraryImageKeepsThePageOffline(): void
    {
        $page = $this->pageHolding($this->image());

        $this->withMode('block', function () use ($page): void {
            try {
                $this->pages()->publish($page, $this->locale());
                self::fail('The page should have been refused.');
            } catch (AccessibilityException $exception) {
                self::assertCount(1, $exception->problems);
                self::assertStringContainsString('no description', $exception->problems[0]);
            }
        });

        $content = $this->pageContent($page);
        self::assertNull($content->getPublishedAt());
        self::assertSame(0, CmsPageRevisionQuery::create()->filterByPageId($page->getId())->count(), 'The refusal leaves no revision.');
    }

    public function testInWarnModeThePageGoesOnlineAndTheWriterReturnsTheProblem(): void
    {
        $page = $this->pageHolding($this->image());

        $this->withMode('warn', function () use ($page): void {
            $warnings = $this->pages()->publish($page, $this->locale());

            self::assertCount(1, $warnings);
            self::assertStringContainsString('no description', $warnings[0]);
        });

        self::assertNotNull($this->pageContent($page)->getPublishedAt());
    }

    public function testADecoratedOrDescribedImagePublishesWithNothingToSay(): void
    {
        $this->withMode('block', function (): void {
            foreach ([$this->image(decorative: true), $this->image(alt: 'Un fauteuil')] as $image) {
                self::assertSame([], $this->pages()->publish($this->pageHolding($image), $this->locale()));
            }
        });
    }

    public function testALinkThatSaysNothingIsRefusedToo(): void
    {
        $page = $this->createPage('Liens', published: false);
        $this->pages()->saveContent($page, $this->locale(), Document::richText(\sprintf('<p><a href="/contact">%s</a></p>', str_starts_with($this->locale(), 'fr') ? 'Cliquez ici' : 'Click here')));

        $this->withMode('block', function () use ($page): void {
            $this->expectException(AccessibilityException::class);

            $this->pages()->publish($page, $this->locale());
        });
    }

    public function testTheDryRunRefusesAndWarnsLikeThePublication(): void
    {
        $page = $this->pageHolding($this->image());

        $this->withMode('warn', function () use ($page): void {
            self::assertCount(1, $this->pages()->assertPublishable($page, $this->locale()));
        });

        $this->withMode('block', function () use ($page): void {
            $this->expectException(AccessibilityException::class);

            $this->pages()->assertPublishable($page, $this->locale());
        });

        self::assertNull($this->pageContent($page)->getPublishedAt());
    }

    public function testAnItemIsRefusedInBlockModeAndWarnedInWarnMode(): void
    {
        $factory = new FixtureFactory($this->getPropelConnection());
        $content = $factory->content($factory->folder(), ['title' => 'Un contenu', 'locale' => $this->locale()]);
        $target = fn () => $this->itemTarget(CatalogueItemType::Content, (int) $content->getId(), $this->locale());
        $writer = $this->getService(ContentWriter::class);
        $writer->saveContent($target(), Document::richText($this->holding($this->image())));

        $this->withMode('block', static function () use ($writer, $target): void {
            try {
                $writer->publish($target());
                self::fail('The content should have been refused.');
            } catch (ItemAccessibilityException $exception) {
                self::assertCount(1, $exception->problems);
            }
        });

        $this->withMode('warn', static function () use ($writer, $target): void {
            self::assertCount(1, $writer->publish($target()));
        });
    }

    public function testAnUnknownValueMeansWarn(): void
    {
        $page = $this->pageHolding($this->image());

        $this->withMode('strict', function () use ($page): void {
            self::assertCount(1, $this->pages()->publish($page, $this->locale()));
        });
    }

    public function testTheHeadingSettingAloneDoesNotBlockOnAnImage(): void
    {
        $page = $this->pageHolding($this->image());

        $this->withMode('warn', function () use ($page): void {
            $this->withHeadingMode('block', function () use ($page): void {
                self::assertCount(1, $this->pages()->publish($page, $this->locale()));
            });
        });
    }

    public function testTheAccessibilitySettingAloneDoesNotBlockOnHeadings(): void
    {
        $page = $this->createPage('Titres', published: false);
        $this->pages()->saveContent($page, $this->locale(), Document::richText('<h2>Un</h2><h4>Saut</h4>'));

        $this->withMode('block', function () use ($page): void {
            $this->withHeadingMode('warn', function () use ($page): void {
                self::assertCount(1, $this->pages()->publish($page, $this->locale()));
            });
        });

        $second = $this->createPage('Titres bloqués', published: false);
        $this->pages()->saveContent($second, $this->locale(), Document::richText('<h2>Un</h2><h4>Saut</h4>'));

        $this->withMode('warn', function () use ($second): void {
            $this->withHeadingMode('block', function () use ($second): void {
                $this->expectException(HeadingStructureException::class);

                $this->pages()->publish($second, $this->locale());
            });
        });
    }

    public function testThePublicationIsCheckedOnWhatWentOnlineAndNotOnTheStoredDraftHtml(): void
    {
        $page = $this->pageHolding($this->image());
        // The draft HTML is computed when the draft is saved: a stale one reads clean.
        $this->pageContent($page)->setDraftHtml('<p>Propre</p>')->save();

        $this->withMode('block', function () use ($page): void {
            $this->expectException(AccessibilityException::class);

            $this->pages()->publish($page, $this->locale());
        });
    }

    /**
     * A component of the theme prints the text of its own prop, empty here:
     * what goes online is what the check read, the description of the library.
     */
    public function testAnImageDescribedInTheLibraryGoesOnlineWithItsDescription(): void
    {
        $page = $this->pageHolding($this->image(alt: 'Un fauteuil en rotin'));

        $this->withMode('block', function () use ($page): void {
            self::assertSame([], $this->pages()->publish($page, $this->locale()));
        });

        self::assertStringContainsString('alt="Un fauteuil en rotin"', (string) $this->pageContent($page)->getPublishedHtml());
    }

    public function testAnImageDescribedInAnotherLanguageOnlyGoesOnlineWithoutATextAndIsReported(): void
    {
        $image = $this->image();
        $image->setLocale(str_starts_with($this->locale(), 'fr') ? 'en_US' : 'fr_FR')->setAlt('Described elsewhere')->save();
        $page = $this->pageHolding($image);

        $this->withMode('warn', function () use ($page): void {
            self::assertCount(1, $this->pages()->publish($page, $this->locale()));
        });

        $html = (string) $this->pageContent($page)->getPublishedHtml();
        $document = new \DOMDocument();
        @$document->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        $published = $document->getElementsByTagName('img')->item(0);

        self::assertInstanceOf(\DOMElement::class, $published);
        self::assertTrue($published->hasAttribute('alt'));
        self::assertSame('', $published->getAttribute('alt'));
        self::assertStringNotContainsString('Described elsewhere', $html);
    }

    private function pageHolding(LibraryImage $image): CmsPage
    {
        $page = $this->createPage('Image '.$image->getId(), published: false);
        $this->pages()->saveContent($page, $this->locale(), Document::richText($this->holding($image)));

        return $page;
    }

    private function holding(LibraryImage $image): string
    {
        return \sprintf('<p>Texte</p><img src="/image-library/%d/full/800,600/0/default.jpg" alt="">', $image->getId());
    }

    private function image(?string $alt = null, bool $decorative = false): LibraryImage
    {
        $image = (new LibraryImage())->setFileName(bin2hex(random_bytes(6)).'.jpg')->setWidth(800)->setHeight(600)->setDecorative($decorative ? 1 : 0);
        $image->setLocale($this->locale())->setTitle('Image');

        if (null !== $alt) {
            $image->setAlt($alt);
        }

        $image->save();
        $this->getService(CmsMediaLibrary::class)->tag($image);

        return $image;
    }

    private function pages(): CmsPageWriter
    {
        return $this->getService(CmsPageWriter::class);
    }

    private function pageContent(CmsPage $page): \TheliaCMS\Model\CmsPageContent
    {
        $content = CmsPageContentQuery::create()->filterByPageId($page->getId())->filterByLocale($this->locale())->findOne();
        self::assertNotNull($content);

        return $content;
    }

    private function withMode(string $mode, callable $run): void
    {
        $this->withSetting(CmsSettings::ACCESSIBILITY_CHECK_MODE, $mode, $run);
    }

    private function withHeadingMode(string $mode, callable $run): void
    {
        $this->withSetting(CmsSettings::HEADING_CHECK_MODE, $mode, $run);
    }

    private function withSetting(string $key, string $mode, callable $run): void
    {
        $previous = TheliaCMS::getConfigValue($key, 'warn');
        TheliaCMS::setConfigValue($key, $mode);

        try {
            $run();
        } finally {
            TheliaCMS::setConfigValue($key, (string) $previous);
        }
    }
}
