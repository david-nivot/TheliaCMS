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

use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DomCrawler\Crawler;
use Thelia\Model\Content;
use Thelia\Test\FixtureFactory;
use TheliaCMS\Catalogue\CatalogueItemType;
use TheliaCMS\Content\Document;
use TheliaCMS\Content\Editing\ContentWriter;
use TheliaCMS\Media\CmsMediaLibrary;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Model\CmsPageContentQuery;
use TheliaCMS\Page\Admin\CmsPageWriter;
use TheliaCMS\Page\PublishPagesCommand;
use TheliaCMS\Settings\CmsSettings;
use TheliaCMS\Tests\Integration\BuildsItemTargets;
use TheliaCMS\Tests\Integration\Security\AdminScreenTestCase;
use TheliaCMS\TheliaCMS;
use TheliaLibrary\Model\LibraryImage;

/**
 * The accessibility problems of what goes online reach the author on every
 * path that publishes: the content screen of a page and of an item, the
 * settings screen of a page and the command.
 */
final class PublishAccessibilityScreensTest extends AdminScreenTestCase
{
    use BuildsItemTargets;

    public function testThePageContentScreenWarnsAndStillSaysItIsOnline(): void
    {
        $page = $this->pageHolding($this->image());
        $base = \sprintf('/admin/cms/pages/%d/content', $page->getId());

        $screen = null;
        $this->withMode('warn', function () use ($base, &$screen): void {
            $screen = $this->publishFromTheScripts($base);
        });

        self::assertNotNull($this->pageContent($page)->getPublishedAt());
        self::assertGreaterThan(0, $screen->filter('[data-testid="cms-builder-flash-warning"]')->count());
        self::assertStringContainsString('no description', $screen->filter('[data-testid="cms-builder-flash-warning"]')->text());
        self::assertSame(1, $screen->filter('[data-testid="cms-builder-flash-success"]')->count());
        self::assertSame(0, $screen->filter('[data-testid="cms-builder-flash-danger"]')->count());
    }

    public function testTheItemContentScreenWarnsAndStillSaysItIsOnline(): void
    {
        $content = $this->itemHolding($this->image());
        $base = \sprintf('/admin/cms/catalogue/content/%d/content', $content->getId());

        $screen = null;
        $this->withMode('warn', function () use ($base, &$screen): void {
            $screen = $this->publishFromTheScripts($base);
        });

        self::assertStringContainsString('no description', $screen->filter('[data-testid="cms-builder-flash-warning"]')->text());
        self::assertSame(1, $screen->filter('[data-testid="cms-builder-flash-success"]')->count());
        self::assertSame(0, $screen->filter('[data-testid="cms-builder-flash-danger"]')->count());
    }

    public function testBothContentScreensRefuseInFrenchWithTheWordsOfEachFamily(): void
    {
        $page = $this->pageHolding($this->image());
        $content = $this->itemHolding($this->image());

        $this->withMode('block', function () use ($page, $content): void {
            $pageMessage = $this->notices($this->publishFromTheScripts(\sprintf('/admin/cms/pages/%d/content', $page->getId()), '?lang=fr_FR'));
            self::assertStringContainsString('Une image n’a pas de description.', $pageMessage);
            self::assertStringContainsString('La page n’a pas été publiée : corrigez d’abord les problèmes d’accessibilité.', $pageMessage);

            $itemMessage = $this->notices($this->publishFromTheScripts(\sprintf('/admin/cms/catalogue/content/%d/content', $content->getId()), '?lang=fr_FR'));
            self::assertStringContainsString('Une image n’a pas de description.', $itemMessage);
            self::assertStringContainsString('Le contenu n’a pas été publié : corrigez d’abord les problèmes d’accessibilité.', $itemMessage);
        });

        self::assertNull($this->pageContent($page)->getPublishedAt());
    }

    public function testAnImageDescribedBetweenTheSaveAndThePublicationGivesNoWarning(): void
    {
        $image = $this->image();
        $page = $this->pageHolding($image);
        $image->setLocale($this->locale())->setAlt('Un fauteuil')->save();
        $base = \sprintf('/admin/cms/pages/%d/content', $page->getId());

        $screen = null;
        $this->withMode('warn', function () use ($base, &$screen): void {
            $screen = $this->publishFromTheScripts($base);
        });

        self::assertSame(1, $screen->filter('[data-testid="cms-builder-flash-success"]')->count());
        self::assertSame(0, $screen->filter('[data-testid="cms-builder-flash-warning"]')->count());
    }

    public function testTheSettingsScreenWarnsInWarnModeAndRefusesInBlockMode(): void
    {
        $warned = $this->pageHolding($this->image());
        $this->withMode('warn', fn () => $this->publishFromTheSettings($warned));

        self::assertNotNull($this->pageContent($warned)->getPublishedAt());
        $screen = $this->screen(\sprintf('/admin/cms/pages/%d', $warned->getId()));
        self::assertGreaterThan(0, $screen->filter('[data-testid="bo-flash-warning"]')->count());
        self::assertSame(0, $screen->filter('[data-testid="bo-flash-danger"]')->count());

        $refused = $this->pageHolding($this->image());
        $this->withMode('block', fn () => $this->publishFromTheSettings($refused));

        self::assertNull($this->pageContent($refused)->getPublishedAt());
        $screen = $this->screen(\sprintf('/admin/cms/pages/%d', $refused->getId()));
        self::assertGreaterThan(0, $screen->filter('[data-testid="bo-flash-danger"]')->count());
        self::assertStringContainsString('no description', $screen->filter('[data-testid="bo-flash-danger"]')->text());
        self::assertSame(0, $screen->filter('[data-testid="bo-flash-warning"]')->count());
    }

    public function testTheCommandListsTheWarningsInWarnModeAndRefusesInBlockMode(): void
    {
        $warned = $this->pageHolding($this->image());
        $this->withMode('warn', function () use ($warned): void {
            $output = $this->runCommand(['--page' => [(string) $warned->getId()]]);

            self::assertStringContainsString('Warnings', $output);
            self::assertStringContainsString(\sprintf('#%d %s: ', $warned->getId(), $this->locale()), $output);
            self::assertStringContainsString('no description', $output);
        });
        self::assertNotNull($this->pageContent($warned)->getPublishedAt());

        $refused = $this->pageHolding($this->image());
        $this->withMode('block', function () use ($refused): void {
            $output = $this->runCommand(['--page' => [(string) $refused->getId()]]);

            self::assertStringContainsString('Refused', $output);
            self::assertStringContainsString('no description', $output);
            self::assertStringNotContainsString('Failed', $output);
            self::assertStringContainsString('0 page/locale pair(s) published', $output);
        });
        self::assertNull($this->pageContent($refused)->getPublishedAt());
    }

    public function testADryRunRefusesToo(): void
    {
        $page = $this->pageHolding($this->image());

        $this->withMode('block', function () use ($page): void {
            $output = $this->runCommand(['--page' => [(string) $page->getId()], '--dry-run' => true]);

            self::assertStringContainsString('Refused', $output);
            self::assertStringContainsString('0 page/locale pair(s) would be published', $output);
        });
    }

    /**
     * Posts the publication of a content screen and returns the screen it
     * redirects to: a notice is shown once, so it is read from this one load.
     */
    private function publishFromTheScripts(string $base, string $query = ''): Crawler
    {
        $token = $this->tokenOfTheScripts($this->screen($base.$query));

        self::assertSame(302, $this->send('POST', $base.'/publish', ['_token' => $token]));

        return $this->screen($base.$query);
    }

    private function notices(Crawler $screen): string
    {
        $flash = $screen->filter('[data-testid="cms-builder-flash-danger"]');
        self::assertGreaterThan(0, $flash->count());

        return implode(' | ', $flash->each(static fn (Crawler $notice): string => trim($notice->text())));
    }

    private function publishFromTheSettings(CmsPage $page): void
    {
        $edit = \sprintf('/admin/cms/pages/%d', $page->getId());
        $action = $edit.'/publish';
        $token = $this->tokenOfTheForm($this->screen($edit), $action);

        self::assertSame(302, $this->send('POST', $action, ['_token' => $token]));
    }

    private function pageHolding(LibraryImage $image): CmsPage
    {
        $page = $this->createPage('Image '.$image->getId(), published: false);
        $this->getService(CmsPageWriter::class)->saveContent($page, $this->locale(), Document::richText($this->holding($image)));

        return $page;
    }

    private function itemHolding(LibraryImage $image): Content
    {
        $factory = new FixtureFactory($this->getPropelConnection());
        $content = $factory->content($factory->folder(), ['title' => 'Notre méthode', 'locale' => $this->locale()]);
        $this->getService(ContentWriter::class)->saveContent($this->itemTarget(CatalogueItemType::Content, (int) $content->getId(), $this->locale()), Document::richText($this->holding($image)));

        return $content;
    }

    private function holding(LibraryImage $image): string
    {
        return \sprintf('<h2>Titre</h2><img src="/image-library/%d/full/800,600/0/default.jpg" alt="">', $image->getId());
    }

    private function image(): LibraryImage
    {
        $image = (new LibraryImage())->setFileName(bin2hex(random_bytes(6)).'.jpg')->setWidth(800)->setHeight(600)->setDecorative(0);
        $image->setLocale($this->locale())->setTitle('Image');
        $image->save();
        $this->getService(CmsMediaLibrary::class)->tag($image);

        return $image;
    }

    private function pageContent(CmsPage $page): \TheliaCMS\Model\CmsPageContent
    {
        $content = CmsPageContentQuery::create()->filterByPageId($page->getId())->filterByLocale($this->locale())->findOne();
        self::assertNotNull($content);

        return $content;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function runCommand(array $arguments): string
    {
        $tester = new CommandTester($this->getService(PublishPagesCommand::class));
        $tester->execute($arguments, ['capture_stderr_separately' => false]);

        return $tester->getDisplay();
    }

    private function withMode(string $mode, callable $run): void
    {
        $previous = TheliaCMS::getConfigValue(CmsSettings::ACCESSIBILITY_CHECK_MODE, 'warn');
        TheliaCMS::setConfigValue(CmsSettings::ACCESSIBILITY_CHECK_MODE, $mode);

        try {
            $run();
        } finally {
            TheliaCMS::setConfigValue(CmsSettings::ACCESSIBILITY_CHECK_MODE, (string) $previous);
        }
    }
}
