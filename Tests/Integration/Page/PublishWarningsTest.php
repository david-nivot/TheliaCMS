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
use Thelia\Test\FixtureFactory;
use TheliaCMS\Catalogue\CatalogueItemType;
use TheliaCMS\Content\Document;
use TheliaCMS\Content\Editing\ContentWriter;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Model\CmsPageContentQuery;
use TheliaCMS\Page\Admin\CmsPageWriter;
use TheliaCMS\Page\Admin\HeadingStructureException;
use TheliaCMS\Page\PublishPagesCommand;
use TheliaCMS\Settings\CmsSettings;
use TheliaCMS\Tests\Integration\BuildsItemTargets;
use TheliaCMS\Tests\Integration\Security\AdminScreenTestCase;
use TheliaCMS\TheliaCMS;

/**
 * In `warn` mode a page that goes online with a broken heading structure is
 * published, and its author is told, on every path that publishes: the
 * warnings come from the HTML that went online, not from the draft.
 */
final class PublishWarningsTest extends AdminScreenTestCase
{
    use BuildsItemTargets;

    private const string BROKEN = '<h2>Un</h2><h4>Saut</h4>';

    public function testThePageWriterReturnsTheWarningsOfWhatWentOnline(): void
    {
        $page = $this->brokenPage();

        $this->withHeadingMode('warn', function () use ($page): void {
            $warnings = $this->pages()->publish($page, $this->locale());

            self::assertCount(1, $warnings);
            self::assertStringContainsString('level', $warnings[0]);
        });

        self::assertNotNull($this->pageContent($page)->getPublishedAt());
    }

    public function testACleanPageReturnsNoWarning(): void
    {
        $page = $this->createPage('Propre', published: false);

        $this->withHeadingMode('warn', function () use ($page): void {
            self::assertSame([], $this->pages()->publish($page, $this->locale()));
        });
    }

    public function testTheBlockModeStillRefusesInsteadOfReturning(): void
    {
        $page = $this->brokenPage();

        $this->withHeadingMode('block', function () use ($page): void {
            $this->expectException(HeadingStructureException::class);

            $this->pages()->publish($page, $this->locale());
        });
    }

    public function testTheContentWriterReturnsTheWarningsOfAnItemToo(): void
    {
        $factory = new FixtureFactory($this->getPropelConnection());
        $content = $factory->content($factory->folder(), ['title' => 'Un contenu', 'locale' => $this->locale()]);
        $target = $this->itemTarget(CatalogueItemType::Content, (int) $content->getId(), $this->locale());
        $writer = $this->getService(ContentWriter::class);
        $writer->saveContent($target, Document::richText(self::BROKEN));

        $this->withHeadingMode('warn', static function () use ($writer, $target): void {
            self::assertCount(1, $writer->publish($target));
        });
    }

    public function testTheSettingsScreenTellsWhatToFixWhenTheSiteOnlyWarns(): void
    {
        $page = $this->brokenPage();
        $this->withHeadingMode('warn', function () use ($page): void {
            $edit = \sprintf('/admin/cms/pages/%d', $page->getId());
            $action = $edit.'/publish';
            $token = $this->tokenOfTheForm($this->screen($edit), $action);

            self::assertSame(302, $this->send('POST', $action, ['_token' => $token]));
        });

        self::assertNotNull($this->pageContent($page)->getPublishedAt(), 'The page went online.');

        $screen = $this->screen(\sprintf('/admin/cms/pages/%d', $page->getId()));
        self::assertGreaterThan(0, $screen->filter('[data-testid="bo-flash-warning"]')->count(), 'The author is told what to fix.');
        self::assertSame(0, $screen->filter('[data-testid="bo-flash-danger"]')->count());
    }

    public function testTheContentScreenWarnsAboutWhatWentOnlineAndNotAboutTheStoredDraftHtml(): void
    {
        $page = $this->brokenPage();
        // The draft HTML is computed when the draft is saved: a stale one reads clean.
        $this->pageContent($page)->setDraftHtml('<h2>Un</h2><h3>Propre</h3>')->save();

        $this->withHeadingMode('warn', function () use ($page): void {
            $base = \sprintf('/admin/cms/pages/%d/content', $page->getId());
            $token = $this->tokenOfTheScripts($this->screen($base));

            self::assertSame(302, $this->send('POST', $base.'/publish', ['_token' => $token]));
        });

        $screen = $this->screen(\sprintf('/admin/cms/pages/%d/content', $page->getId()));
        self::assertGreaterThan(0, $screen->filter('[data-testid="cms-builder-flash-warning"]')->count(), 'The warning comes from the HTML that went online.');
    }

    public function testTheCommandListsTheWarningsOfWhatItPublished(): void
    {
        $page = $this->brokenPage();
        $pageId = (int) $page->getId();

        $this->withHeadingMode('warn', function () use ($pageId): void {
            $output = $this->runCommand(['--page' => [(string) $pageId]]);

            self::assertStringContainsString('Warnings', $output);
            self::assertStringContainsString(\sprintf('#%d %s: ', $pageId, $this->locale()), $output);
            self::assertStringContainsString('1 page/locale pair(s) published', $output);
        });

        self::assertNotNull(CmsPageContentQuery::create()->filterByPageId($pageId)->findOne()?->getPublishedAt());
    }

    public function testADryRunListsTheWarningsToo(): void
    {
        $page = $this->brokenPage();
        $pageId = (int) $page->getId();

        $this->withHeadingMode('warn', function () use ($pageId): void {
            $output = $this->runCommand(['--page' => [(string) $pageId], '--dry-run' => true]);

            self::assertStringContainsString('Warnings', $output);
            self::assertStringContainsString('1 page/locale pair(s) would be published', $output);
        });
    }

    public function testACleanPageGivesNoWarningsSection(): void
    {
        $page = $this->createPage('Propre', published: false);

        $this->withHeadingMode('warn', function () use ($page): void {
            self::assertStringNotContainsString('Warnings', $this->runCommand(['--page' => [(string) $page->getId()]]));
        });
    }

    private function brokenPage(): CmsPage
    {
        $page = $this->createPage('Titres', published: false);
        $this->pages()->saveContent($page, $this->locale(), Document::richText(self::BROKEN));

        return $page;
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

    /**
     * @param array<string, mixed> $arguments
     */
    private function runCommand(array $arguments): string
    {
        $tester = new CommandTester($this->getService(PublishPagesCommand::class));
        $tester->execute($arguments, ['capture_stderr_separately' => false]);

        return $tester->getDisplay();
    }

    private function withHeadingMode(string $mode, callable $run): void
    {
        $previous = TheliaCMS::getConfigValue(CmsSettings::HEADING_CHECK_MODE, 'warn');
        TheliaCMS::setConfigValue(CmsSettings::HEADING_CHECK_MODE, $mode);

        try {
            $run();
        } finally {
            TheliaCMS::setConfigValue(CmsSettings::HEADING_CHECK_MODE, (string) $previous);
        }
    }
}
