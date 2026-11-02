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

use TheliaCMS\Http\CachePurgerInterface;
use TheliaCMS\Http\CacheTags;
use TheliaCMS\ImportExport\ImportOptions;
use TheliaCMS\ImportExport\SiteDocument;
use TheliaCMS\ImportExport\SiteImporter;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Model\CmsPageQuery;
use TheliaCMS\Model\CmsTag;
use TheliaCMS\Model\CmsTagQuery;
use TheliaCMS\Model\Map\CmsPageTableMap;
use TheliaCMS\Model\Map\CmsTagTableMap;
use TheliaCMS\Page\PageCard;
use TheliaCMS\Page\PageTagColor;
use TheliaCMS\Page\PageTagExistsException;
use TheliaCMS\Page\PageTagRepository;
use TheliaCMS\Page\PageTagWriter;
use TheliaCMS\Page\PublishedPageRepository;
use TheliaCMS\Partial\Definition\PageListPartial;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;
use TheliaCMS\Twig\CmsExtension;

/**
 * The tag of a page: the list the site keeps, the pages a tag gathers on the
 * front, and the ways a page gets one — the form, a duplicate, an import, a
 * saved template.
 */
final class PageTagTest extends CmsIntegrationTestCase
{
    public function testATagIsAddedOnceWhateverItsSpacingAndCase(): void
    {
        $tag = $this->tagWriter()->add('  Recette   d’été ');

        self::assertSame('Recette d’été', $tag->getName());
        self::assertSame((int) $tag->getId(), (int) $this->tagWriter()->add('recette d’été')->getId(), 'The column ignores case: one tag.');
        self::assertSame(1, CmsTagQuery::create()->filterByName('Recette d’été')->count());
    }

    public function testAnEmptyNameIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->tagWriter()->add('   ');
    }

    public function testTheTagsComeInAlphabeticalOrder(): void
    {
        $this->tagWriter()->add('Zèbre du test');
        $this->tagWriter()->add('Abricot du test');

        $names = array_values($this->tags()->namesById());

        self::assertLessThan(array_search('Zèbre du test', $names, true), array_search('Abricot du test', $names, true));
    }

    public function testRenamingToTheNameOfAnotherTagIsRefused(): void
    {
        $recipe = $this->tagWriter()->add('Recette');
        $this->tagWriter()->add('Actualité');

        $this->expectException(PageTagExistsException::class);

        $this->tagWriter()->rename($recipe, 'actualité');
    }

    public function testATagIsRenamedUnderItsOwnNameWithAnotherCase(): void
    {
        $recipe = $this->tagWriter()->add('recette');

        $this->tagWriter()->rename($recipe, 'Recette');

        self::assertSame('Recette', $this->tags()->find((int) $recipe->getId())?->getName());
    }

    public function testATagIsCreatedInItsColorAndOneTheSiteHasKeepsItsOwn(): void
    {
        $tag = $this->tagWriter()->add('Recette', PageTagColor::of('#007040', '#D8E480'));

        self::assertSame('#007040', $tag->getColor());
        self::assertSame('#d8e480', $tag->getTextColor());
        self::assertSame('#007040', $this->tagWriter()->add('recette', PageTagColor::of('#d40e21'))->getColor(), 'Adding a name the site has changes nothing.');
        self::assertNull($this->tagWriter()->add('Actualité')->getColor());
        self::assertNull($this->tagWriter()->add('Dessert', PageTagColor::of('#f9b000'))->getTextColor(), 'The text is left to the contrast.');
    }

    public function testRecoloringATagDropsItsPagesFromTheSharedCacheUnlessTheColorIsTheSame(): void
    {
        $tag = $this->tagWriter()->add('Recette', PageTagColor::of('#007040'));
        $page = $this->tagged('Gratin de pâtes', $tag);
        $purger = new class implements CachePurgerInterface {
            /** @var list<string> */
            public array $purged = [];

            public function purge(array $tags): void
            {
                array_push($this->purged, ...$tags);
            }
        };
        $writer = $this->purgingWith(PageTagWriter::class, $purger);

        $writer->recolor($tag, PageTagColor::of('#007040'));
        self::assertSame([], $purger->purged, 'The same color changes nothing.');

        $writer->recolor($tag, PageTagColor::of('#007040', '#d8e480'));
        self::assertSame('#d8e480', $this->tags()->find((int) $tag->getId())?->getTextColor(), 'A text of its own is a new color.');
        self::assertSame([CacheTags::partial(PageListPartial::NAME), CacheTags::page((int) $page->getId())], $purger->purged);

        $writer->recolor($tag, null);
        self::assertNull($this->tags()->find((int) $tag->getId())?->getColor());
        self::assertNull($this->tags()->find((int) $tag->getId())?->getTextColor());
    }

    public function testDeletingATagDetachesItsPagesTheBinIncluded(): void
    {
        $tag = $this->tagWriter()->add('Recette');
        $live = $this->tagged('Gratin de pâtes', $tag);
        $binned = $this->tagged('Tarte', $tag);
        $this->writer()->moveToTrash($binned);

        self::assertSame(2, $this->tags()->countPagesOf($tag), 'The bin counts.');

        $detached = $this->tagWriter()->delete($tag);

        self::assertSame(2, $detached);
        self::assertNull($this->tagIdInDatabase($live));
        self::assertNull($this->tagIdInDatabase($binned));
        self::assertNull($this->tags()->findByName('Recette'));
    }

    public function testThePublishedPageCarriesTheNameOfItsTag(): void
    {
        $page = $this->tagged('Gratin de pâtes', $this->tagWriter()->add('Recette'));

        self::assertSame('Recette', $this->published($page)->tag);
        self::assertNull($this->published($this->createPage('Sans étiquette'))->tag);
    }

    public function testThePageAndTheCardsCarryTheCodesOfTheColorsOfTheTag(): void
    {
        $tag = $this->tagWriter()->add('Recette', PageTagColor::of('#dc4a7b', '#ffffff'));
        $page = $this->tagged('Gratin de pâtes', $tag);

        self::assertSame('#dc4a7b', $this->published($page)->tagColor);
        self::assertSame('#ffffff', $this->published($page)->tagTextColor);
        self::assertSame('#ffffff', $this->published($page)->withHtml('<p>resolved</p>')->tagTextColor);
        self::assertSame('#dc4a7b', $this->repository()->withTag('Recette', $this->locale())[0]->tagColor);
        self::assertSame('#ffffff', $this->repository()->withTag('Recette', $this->locale())[0]->tagTextColor);
        $ofType = array_values(array_filter(
            $this->repository()->ofType((string) $page->getPageType(), $this->locale()),
            static fn (PageCard $card): bool => $card->id === (int) $page->getId(),
        ));
        self::assertSame('#dc4a7b', $ofType[0]->tagColor ?? null);
        self::assertSame('#ffffff', $ofType[0]->tagTextColor ?? null);
        self::assertNull($this->published($this->createPage('Sans étiquette'))->tagColor);

        // The text left to the contrast reaches the theme as the black or white chosen.
        $this->getPropelConnection()->exec(\sprintf('UPDATE cms_tag SET text_color = NULL WHERE id = %d', $tag->getId()));
        CmsTagTableMap::clearInstancePool();
        CmsPageTableMap::clearInstancePool();
        self::assertSame('#000000', $this->published($page)->tagTextColor);

        // A name of the former palette reads as the badge it gave.
        $this->getPropelConnection()->exec(\sprintf("UPDATE cms_tag SET color = 'cyan' WHERE id = %d", $tag->getId()));
        CmsTagTableMap::clearInstancePool();
        CmsPageTableMap::clearInstancePool();
        self::assertSame('#0dcaf0', $this->published($page)->tagColor);

        // Anything else written by hand in the table reads as no color.
        $this->getPropelConnection()->exec(\sprintf("UPDATE cms_tag SET color = 'pink' WHERE id = %d", $tag->getId()));
        CmsTagTableMap::clearInstancePool();
        CmsPageTableMap::clearInstancePool();
        self::assertNull($this->published($page)->tagColor);
        self::assertNull($this->published($page)->tagTextColor);
    }

    public function testThePagesOfATagAreTheOnesOnlineInTheLanguage(): void
    {
        $tag = $this->tagWriter()->add('Recette');
        $other = $this->tagWriter()->add('Actualité');
        $online = $this->tagged('Gratin de pâtes', $tag);
        $this->tagged('Actualité du jour', $other);
        $hidden = $this->tagged('Page masquée', $tag);
        $hidden->setVisible(0)->save();
        $this->writer()->moveToTrash($this->tagged('Page en corbeille', $tag));
        $draft = $this->createPage('Brouillon', published: false);
        $draft->setTagId($tag->getId())->save();
        $later = $this->tagged('Page programmée', $tag);
        $later->setPublishAt(new \DateTimeImmutable('+1 day'))->save();
        $unpublished = $this->tagged('Page dépubliée', $tag);
        $this->writer()->unpublish($unpublished, $this->locale());

        $cards = $this->repository()->withTag('recette', $this->locale());

        self::assertSame([(int) $online->getId()], array_map(static fn (PageCard $card): int => $card->id, $cards));
        self::assertSame('Gratin de pâtes', $cards[0]->title);
        self::assertSame('Recette', $cards[0]->tag);
        self::assertSame($online->getUrl($this->locale()), $cards[0]->url);
        self::assertNotNull($cards[0]->publishedAt);
    }

    public function testThePagesOfATagComeMostRecentlyPublishedFirst(): void
    {
        $tag = $this->tagWriter()->add('Recette');
        $first = $this->tagged('Première recette', $tag);
        $second = $this->tagged('Seconde recette', $tag);
        $this->getPropelConnection()->exec(\sprintf("UPDATE cms_page_content SET published_at = '2020-01-01 10:00:00' WHERE page_id = %d", $second->getId()));

        $ids = array_map(static fn (PageCard $card): int => $card->id, $this->repository()->withTag('Recette', $this->locale()));

        self::assertSame([(int) $first->getId(), (int) $second->getId()], $ids);
    }

    public function testATagNobodyHasOrAnEmptyNameGathersNoPage(): void
    {
        self::assertSame([], $this->repository()->withTag('Étiquette inconnue du test', $this->locale()));
        self::assertSame([], $this->repository()->withTag('  ', $this->locale()));
    }

    public function testTheThemeReadsTheTaggedPagesInTheLanguageServed(): void
    {
        $page = $this->tagged('Gratin de pâtes', $this->tagWriter()->add('Recette'));

        $cards = $this->getService(CmsExtension::class)->pagesTagged('Recette');

        self::assertSame([(int) $page->getId()], array_map(static fn (PageCard $card): int => $card->id, $cards));
    }

    public function testADuplicatedPageKeepsItsTag(): void
    {
        $tag = $this->tagWriter()->add('Recette');
        $page = $this->tagged('Gratin de pâtes', $tag);

        $copy = $this->writer()->duplicate($page, $this->locale(), '(copie)');

        self::assertSame((int) $tag->getId(), (int) $copy->getTagId());
    }

    public function testRenamingOrDeletingATagDropsItsPagesFromTheSharedCache(): void
    {
        $tag = $this->tagWriter()->add('Recette');
        $page = $this->tagged('Gratin de pâtes', $tag);
        $purger = new class implements CachePurgerInterface {
            /** @var list<string> */
            public array $purged = [];

            public function purge(array $tags): void
            {
                array_push($this->purged, ...$tags);
            }
        };
        $writer = $this->purgingWith(PageTagWriter::class, $purger);
        $dropped = [CacheTags::partial(PageListPartial::NAME), CacheTags::page((int) $page->getId())];

        $writer->rename($tag, 'Recettes');
        $writer->delete($tag);

        // The lists of pages show the tag of each page, and list the pages of a tag.
        self::assertSame([...$dropped, ...$dropped], $purger->purged);
    }

    public function testTheTagTravelsWithTheExportAndIsCreatedByTheImport(): void
    {
        $page = $this->tagged('Gratin de pâtes', $this->tagWriter()->add('Recette'));
        $document = $this->exportedPage($page);

        self::assertSame('Recette', $document['pages'][0]['tag']);

        $document['pages'][0]['tag'] = 'Étiquette importée';
        $report = $this->getService(SiteImporter::class)->import(SiteDocument::fromArray($document), new ImportOptions(replace: true));

        self::assertSame([], $report->warnings());
        $imported = $this->tags()->findByName('Étiquette importée');
        self::assertInstanceOf(CmsTag::class, $imported);
        self::assertSame((int) $imported->getId(), $this->tagIdInDatabase($page));
    }

    public function testTheColorTravelsWithTheExportAndColorsTheTagsTheImportCreates(): void
    {
        $page = $this->tagged('Gratin de pâtes', $this->tagWriter()->add('Recette', PageTagColor::of('#f9b000', '#9c0337')));
        $document = $this->exportedPage($page);

        self::assertSame('#f9b000', $document['pages'][0]['tag_color']);
        self::assertSame('#9c0337', $document['pages'][0]['tag_text_color']);

        $document['pages'][0]['tag'] = 'Étiquette importée';
        $report = $this->getService(SiteImporter::class)->import(SiteDocument::fromArray($document), new ImportOptions(replace: true));

        self::assertSame([], $report->warnings());
        self::assertSame('#f9b000', $this->tags()->findByName('Étiquette importée')?->getColor());
        self::assertSame('#9c0337', $this->tags()->findByName('Étiquette importée')?->getTextColor());
    }

    public function testATagTheSiteHasKeepsItsColorOnImport(): void
    {
        $page = $this->tagged('Gratin de pâtes', $this->tagWriter()->add('Recette', PageTagColor::of('#f9b000')));
        $document = $this->exportedPage($page);
        $document['pages'][0]['tag_color'] = '#212529';

        $this->getService(SiteImporter::class)->import(SiteDocument::fromArray($document), new ImportOptions(replace: true));

        CmsTagTableMap::clearInstancePool();
        self::assertSame('#f9b000', $this->tags()->findByName('Recette')?->getColor());
    }

    public function testAFileOfTheFormerPaletteImportsTheBadgeItsNameGave(): void
    {
        $page = $this->createPage('Gratin de pâtes');
        $document = $this->exportedPage($page);
        $document['pages'][0]['tag'] = 'Étiquette importée';
        $document['pages'][0]['tag_color'] = 'dark';
        unset($document['pages'][0]['tag_text_color']);

        $report = $this->getService(SiteImporter::class)->import(SiteDocument::fromArray($document), new ImportOptions(replace: true));

        self::assertSame([], $report->warnings());
        self::assertSame('#212529', $this->tags()->findByName('Étiquette importée')?->getColor());
        self::assertSame('#ffffff', $this->tags()->findByName('Étiquette importée')?->getTextColor());
    }

    public function testAnImportedColorThatIsNotACodeIsDroppedWithAWarning(): void
    {
        $page = $this->createPage('Gratin de pâtes');
        $document = $this->exportedPage($page);
        $document['pages'][0]['tag'] = 'Étiquette importée';
        $document['pages'][0]['tag_color'] = 'pink';

        $report = $this->getService(SiteImporter::class)->import(SiteDocument::fromArray($document), new ImportOptions(replace: true));

        $imported = $this->tags()->findByName('Étiquette importée');
        self::assertInstanceOf(CmsTag::class, $imported, 'The tag is kept.');
        self::assertNull($imported->getColor());
        self::assertSame((int) $imported->getId(), $this->tagIdInDatabase($page));
        self::assertCount(1, $report->warnings());
        self::assertStringContainsString('Étiquette importée', $report->warnings()[0]);
    }

    public function testAFileWrittenBeforeTagsImportsPagesWithoutOne(): void
    {
        $page = $this->tagged('Gratin de pâtes', $this->tagWriter()->add('Recette'));
        $document = $this->exportedPage($page);
        unset($document['pages'][0]['tag']);

        $this->getService(SiteImporter::class)->import(SiteDocument::fromArray($document), new ImportOptions(replace: true));

        self::assertNull($this->tagIdInDatabase($page));
    }

    public function testAnImportedTagThatIsNotANameIsLeftOut(): void
    {
        $page = $this->createPage('Gratin de pâtes');
        $document = $this->exportedPage($page);
        $document['pages'][0]['tag'] = ['Recette'];

        $report = $this->getService(SiteImporter::class)->import(SiteDocument::fromArray($document), new ImportOptions(replace: true));

        self::assertNull($this->tagIdInDatabase($page));
        self::assertCount(1, $report->warnings());
    }

    private function tagged(string $title, CmsTag $tag): CmsPage
    {
        $page = $this->createPage($title);
        $page->setTagId($tag->getId())->save();

        return $page;
    }

    private function tagIdInDatabase(CmsPage $page): ?int
    {
        CmsPageTableMap::clearInstancePool();
        $reloaded = CmsPageQuery::create()->findPk($page->getId());
        self::assertNotNull($reloaded);

        return null === $reloaded->getTagId() ? null : (int) $reloaded->getTagId();
    }

    private function published(CmsPage $page): \TheliaCMS\Page\PublishedPage
    {
        $published = $this->repository()->find((int) $page->getId(), $this->locale());
        self::assertNotNull($published);

        return $published;
    }

    private function repository(): PublishedPageRepository
    {
        return $this->getService(PublishedPageRepository::class);
    }

    private function tags(): PageTagRepository
    {
        return $this->getService(PageTagRepository::class);
    }

    private function tagWriter(): PageTagWriter
    {
        return $this->getService(PageTagWriter::class);
    }
}
