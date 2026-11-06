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
use TheliaCMS\Catalogue\ItemContentRepository;
use TheliaCMS\Content\Document;
use TheliaCMS\Content\Editing\ContentWriter;
use TheliaCMS\Content\Editing\PublishedRendering;
use TheliaCMS\Http\CachePurgerInterface;
use TheliaCMS\Http\CacheTags;
use TheliaCMS\Media\CmsMediaLibrary;
use TheliaCMS\Model\CmsPageContent;
use TheliaCMS\Model\CmsPageContentQuery;
use TheliaCMS\Model\CmsPageRevisionQuery;
use TheliaCMS\Page\Admin\CmsPageWriter;
use TheliaCMS\Page\RenderPublishedCommand;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;
use TheliaLibrary\Model\LibraryImage;

/**
 * What is online, published before a change of the module, is rendered again
 * from its published document: the one column that changes is the published
 * HTML.
 */
final class RenderPublishedCommandTest extends CmsIntegrationTestCase
{
    public function testStaleOnlineHtmlIsRenderedAgainAndNothingElseMoves(): void
    {
        $page = $this->createPage('Online since a while', published: true);
        $pageId = (int) $page->getId();
        $publishedHtml = $this->contentOf($pageId)->getPublishedHtml();

        // Published by an older module, then edited: the draft is another document.
        $this->contentOf($pageId)
            ->setPublishedHtml('<p>rendered by an older module</p>')
            ->setDraftDocument(Document::richText('<h2>A draft in progress</h2>')->toJson())
            ->setDraftHtml('<p>draft html</p>')
            ->setUpdatedAt('2020-01-02 03:04:05')
            ->keepUpdateDateUnchanged()
            ->save();
        $before = $this->contentOf($pageId);
        $revisions = CmsPageRevisionQuery::create()->filterByPageId($pageId)->count();
        $updatedAt = $before->getUpdatedAt()?->format('Y-m-d H:i:s');

        self::assertStringContainsString('1 online version(s) would change, 0 unchanged.', $this->render(['--page' => [(string) $pageId], '--dry-run' => true]));
        self::assertSame('<p>rendered by an older module</p>', $this->contentOf($pageId)->getPublishedHtml(), 'A dry run writes nothing.');

        self::assertStringContainsString('1 online version(s) rendered again, 0 unchanged.', $this->render(['--page' => [(string) $pageId]]));

        $after = $this->contentOf($pageId);
        self::assertSame($publishedHtml, $after->getPublishedHtml(), 'It is the rendering of the published document today.');
        self::assertSame($before->getDraftDocument(), $after->getDraftDocument());
        self::assertSame('<p>draft html</p>', $after->getDraftHtml());
        self::assertSame($before->getPublishedDocument(), $after->getPublishedDocument());
        self::assertEquals($before->getPublishedAt(), $after->getPublishedAt(), 'It is not a publication.');
        self::assertSame($updatedAt, $after->getUpdatedAt()?->format('Y-m-d H:i:s'), 'Nor an edit.');
        self::assertSame($revisions, CmsPageRevisionQuery::create()->filterByPageId($pageId)->count(), 'No version is kept.');

        self::assertStringContainsString('0 online version(s) rendered again, 1 unchanged.', $this->render(['--page' => [(string) $pageId]]), 'A page that renders the same is not written again.');
    }

    public function testAPageThatIsNotOnlineIsLeftAlone(): void
    {
        $page = $this->createPage('Still a draft', published: false);
        $pageId = (int) $page->getId();
        $this->contentOf($pageId)->setPublishedHtml('<p>never published</p>')->save();

        $output = $this->render(['--page' => [(string) $pageId]]);

        self::assertSame('<p>never published</p>', $this->contentOf($pageId)->getPublishedHtml());
        self::assertStringNotContainsString('Failed', $output, 'Nothing online is not a failure.');
        self::assertSame(PublishedRendering::NotOnline, $this->writer()->renderPublishedAgain($page, $this->locale()));
    }

    public function testThePurgeIsTheOneOfAPublicationOfAPageAlreadyOnline(): void
    {
        $page = $this->createPage('Purged once', published: true);
        $pageId = (int) $page->getId();
        $this->contentOf($pageId)->setPublishedHtml('<p>old</p>')->save();
        $purger = $this->purger();
        $writer = $this->writerPurgingWith($purger);

        self::assertSame(PublishedRendering::Rendered, $writer->renderPublishedAgain($page, $this->locale(), write: false));
        self::assertSame([], $purger->purged, 'A dry run purges nothing.');

        self::assertSame(PublishedRendering::Rendered, $writer->renderPublishedAgain($page, $this->locale()));
        self::assertContains(CacheTags::page($pageId), $purger->purged);
        $purged = $purger->purged;

        self::assertSame(PublishedRendering::Unchanged, $writer->renderPublishedAgain($page, $this->locale()));
        self::assertSame($purged, $purger->purged, 'Nothing changed, nothing to purge.');
    }

    public function testACatalogueContentIsRenderedAgainToo(): void
    {
        $factory = new FixtureFactory($this->getPropelConnection());
        $product = $factory->product($factory->category(), $factory->taxRule(), $factory->currency(), ['ref' => 'RENDER-PUBLISHED']);
        $id = (int) $product->getId();
        $writer = $this->getService(ContentWriter::class);
        $writer->saveContent($this->itemTarget(CatalogueItemType::Product, $id, $this->locale()), Document::richText('<h2>Questions</h2><p>Online.</p>'));
        $writer->publish($this->itemTarget(CatalogueItemType::Product, $id, $this->locale()));
        $repository = $this->getService(ItemContentRepository::class);
        $rendered = $this->publishedHtmlOf($id);
        $repository->find(CatalogueItemType::Product, $id, $this->locale())?->setPublishedHtml('<p>old</p>')->save();

        $output = $this->render(['--all' => true, '--dry-run' => true]);
        self::assertStringContainsString('product #'.$id, $output);
        self::assertSame('<p>old</p>', $this->publishedHtmlOf($id));

        $this->render(['--all' => true]);

        self::assertSame($rendered, $this->publishedHtmlOf($id));
    }

    /**
     * An image described in the library after its page went online: the page
     * kept the empty text the component printed, which the library no longer
     * calls forgotten. Rendering it again gives it the description.
     */
    public function testAnImageDescribedSinceThePublicationIsGivenItsDescription(): void
    {
        $image = (new LibraryImage())->setFileName(bin2hex(random_bytes(6)).'.jpg')->setWidth(800)->setHeight(600)->setDecorative(0);
        $image->setLocale($this->locale())->setTitle('Image');
        $image->save();
        $this->getService(CmsMediaLibrary::class)->tag($image);

        $page = $this->createPage('Described later', published: false);
        $pageId = (int) $page->getId();
        $pages = $this->getService(CmsPageWriter::class);
        $pages->saveContent($page, $this->locale(), Document::richText(\sprintf('<p>Texte</p><img src="/image-library/%d/full/800,600/0/default.jpg" alt="">', $image->getId())));
        $pages->publish($page, $this->locale());
        self::assertStringNotContainsString('Un fauteuil', (string) $this->contentOf($pageId)->getPublishedHtml());

        $image->setLocale($this->locale())->setAlt('Un fauteuil en rotin')->save();

        self::assertStringContainsString('1 online version(s) would change, 0 unchanged.', $this->render(['--page' => [(string) $pageId], '--dry-run' => true]));

        $this->render(['--page' => [(string) $pageId]]);

        self::assertStringContainsString('alt="Un fauteuil en rotin"', (string) $this->contentOf($pageId)->getPublishedHtml());
    }

    public function testAsksForAPageOrForAllOfThem(): void
    {
        self::assertStringContainsString('--page', $this->render([]));
    }

    private function publishedHtmlOf(int $productId): ?string
    {
        return $this->getService(ItemContentRepository::class)->find(CatalogueItemType::Product, $productId, $this->locale())?->getPublishedHtml();
    }

    /**
     * @return CachePurgerInterface&object{purged: list<string>}
     */
    private function purger(): CachePurgerInterface
    {
        return new class implements CachePurgerInterface {
            /** @var list<string> */
            public array $purged = [];

            public function purge(array $tags): void
            {
                array_push($this->purged, ...$tags);
            }
        };
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function render(array $arguments): string
    {
        $tester = new CommandTester($this->getService(RenderPublishedCommand::class));
        $tester->execute($arguments, ['capture_stderr_separately' => false]);

        return $tester->getDisplay();
    }

    private function contentOf(int $pageId): CmsPageContent
    {
        $content = CmsPageContentQuery::create()->filterByPageId($pageId)->filterByLocale($this->locale())->findOne();

        self::assertNotNull($content);

        return $content;
    }
}
