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

namespace TheliaCMS\Tests\Integration\Content;

use Thelia\Domain\Ai\AiProvenance;
use Thelia\Test\FixtureFactory;
use TheliaCMS\Catalogue\CatalogueItemType;
use TheliaCMS\Content\Document;
use TheliaCMS\Content\Editing\ContentWriter;
use TheliaCMS\Content\Editing\StaleDraftException;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Model\CmsPageContent;
use TheliaCMS\Model\CmsPageContentQuery;
use TheliaCMS\Tests\Integration\BuildsItemTargets;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;

/**
 * A draft carries a version moved by every write of its content; a write made
 * on a version that moved since is refused and writes nothing.
 */
final class DraftVersionTest extends CmsIntegrationTestCase
{
    use BuildsItemTargets;

    public function testASaveMadeOnAVersionThatMovedIsRefusedAndTheFirstSaveIsKept(): void
    {
        $page = $this->createPage('Deux onglets', published: false);
        $read = $this->version($page);

        $this->writer()->saveContent($page, $this->locale(), Document::richText('<p>Premier onglet.</p>'), null, $read);

        try {
            $this->writer()->saveContent($page, $this->locale(), Document::richText('<p>Second onglet.</p>'), null, $read);
            self::fail('A save made on the version the first save moved went through.');
        } catch (StaleDraftException $stale) {
            self::assertSame($read, $stale->readVersion);
            self::assertSame($read + 1, $stale->currentVersion);
        }

        self::assertStringContainsString('Premier onglet.', (string) $this->content($page)->getDraftDocument());
        self::assertSame($read + 1, $this->version($page));
    }

    public function testASaveOnTheCurrentVersionGoesThroughAndMovesIt(): void
    {
        $page = $this->createPage('Un seul onglet', published: false);
        $read = $this->version($page);

        $this->writer()->saveContent($page, $this->locale(), Document::richText('<p>Une fois.</p>'), null, $read);
        $this->writer()->saveContent($page, $this->locale(), Document::richText('<p>Deux fois.</p>'), null, $read + 1);

        self::assertStringContainsString('Deux fois.', (string) $this->content($page)->getDraftDocument());
        self::assertSame($read + 2, $this->version($page));
    }

    public function testAWriterThatReadNoVersionWritesAndStillMovesIt(): void
    {
        $page = $this->createPage('Ligne de commande', published: false);
        $read = $this->version($page);

        $this->writer()->saveContent($page, $this->locale(), Document::richText('<p>Importé.</p>'));

        self::assertSame($read + 1, $this->version($page), 'A screen open on the draft before must be refused.');
    }

    public function testARestoreAskedOnAStaleVersionKeepsNothingAndChangesNothing(): void
    {
        $page = $this->createPage('Restauration', published: false);
        $this->writer()->keepDraft($page, $this->locale());
        $kept = $this->writer()->previousDrafts($page, $this->locale());
        self::assertNotSame([], $kept);
        $read = $this->version($page);

        $this->writer()->saveContent($page, $this->locale(), Document::richText('<p>Écrit ailleurs.</p>'));

        try {
            $this->writer()->restoreDraft($page, $this->locale(), $kept[0]['id'], $read);
            self::fail('A restore asked on a stale version went through.');
        } catch (StaleDraftException) {
        }

        self::assertCount(\count($kept), $this->writer()->previousDrafts($page, $this->locale()), 'The refused restore kept the draft it did not replace.');
        self::assertStringContainsString('Écrit ailleurs.', (string) $this->content($page)->getDraftDocument());
    }

    public function testPublishingADraftThePublisherDidNotReadPutsNothingOnline(): void
    {
        $page = $this->createPage('Publication', published: false);
        $read = $this->version($page);

        $this->writer()->saveContent($page, $this->locale(), Document::richText('<p>Jamais relu.</p>'));

        try {
            $this->writer()->publish($page, $this->locale(), $read);
            self::fail('A draft that moved since it was read went online.');
        } catch (StaleDraftException) {
        }

        self::assertNull($this->content($page)->getPublishedDocument());

        $this->writer()->publish($page, $this->locale(), $read + 1);

        self::assertStringContainsString('Jamais relu.', (string) $this->content($page)->getPublishedDocument());
        self::assertSame($read + 1, $this->version($page), 'Publishing does not move the version.');
    }

    public function testTheDraftOfAnItemIsGuardedTheSameWay(): void
    {
        $factory = new FixtureFactory($this->getPropelConnection());
        $id = (int) $factory->product($factory->category(), $factory->taxRule(), $factory->currency(), ['ref' => 'DRAFT-VERSION-'.random_int(1000, 9999)])->getId();
        $target = $this->itemTarget(CatalogueItemType::Product, $id, $this->locale());
        $writer = $this->getService(ContentWriter::class);

        $writer->saveContent($target, Document::richText('<p>Premier.</p>'), null, 0);
        self::assertSame(1, $target->row()->draftVersion());

        $this->expectException(StaleDraftException::class);
        $writer->saveContent($target, Document::richText('<p>Périmé.</p>'), null, 0);
    }

    public function testTheMarkOfTheAiStaysOnADraftThatMovedSinceTheEditorReadIt(): void
    {
        $page = $this->createPage('Marque', published: false);
        $this->writer()->saveContent($page, $this->locale(), Document::richText('<p>Écrit par le modèle.</p>'), new AiProvenance('theliacms.page_generation', 'fake-model', new \DateTimeImmutable('2026-11-19T10:00:00+00:00'), 1));
        $read = $this->version($page);

        $this->writer()->saveContent($page, $this->locale(), Document::richText('<p>Régénéré ailleurs.</p>'), new AiProvenance('theliacms.page_generation', 'fake-model', new \DateTimeImmutable('2026-11-19T10:05:00+00:00'), 2));

        try {
            $this->writer()->removeProvenance($page, $this->locale(), $read);
            self::fail('The mark of a draft the editor never read was removed.');
        } catch (StaleDraftException) {
        }

        self::assertNotNull($this->content($page)->getDraftProvenance());
        self::assertTrue($this->writer()->removeProvenance($page, $this->locale(), $read + 1));
        self::assertSame($read + 1, $this->version($page), 'Removing the mark does not move the version: the text is the same.');
    }

    private function version(CmsPage $page): int
    {
        return (int) $this->getPropelConnection()->query(\sprintf(
            "SELECT draft_version FROM cms_page_content WHERE page_id = %d AND locale = '%s'",
            $page->getId(),
            $this->locale(),
        ))->fetchColumn();
    }

    private function content(CmsPage $page): CmsPageContent
    {
        $content = CmsPageContentQuery::create()->filterByPageId($page->getId())->filterByLocale($this->locale())->findOne();
        self::assertInstanceOf(CmsPageContent::class, $content);

        return $content;
    }
}
