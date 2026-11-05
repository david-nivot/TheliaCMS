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

use Propel\Runtime\ActiveQuery\Criteria;
use Thelia\Model\Lang;
use Thelia\Model\LangQuery;
use Thelia\Model\RewritingUrlQuery;
use TheliaCMS\Generation\HeaderOutcome;
use TheliaCMS\Generation\PageHeader;
use TheliaCMS\Http\CachePurgerInterface;
use TheliaCMS\Http\CacheTags;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Model\CmsPageQuery;
use TheliaCMS\Page\Admin\PageDraft;
use TheliaCMS\Page\CmsUrlService;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;
use TheliaCMS\TheliaCMS;

/**
 * What a translation writes of the header of a page, in the language it
 * writes: the empty columns only, an address derived from the translated
 * title only where the language has none, and nothing in the other languages.
 */
final class TranslatedHeaderTest extends CmsIntegrationTestCase
{
    private const array ALL = ['title', 'chapo', 'description', 'og_title', 'og_description'];

    public function testOnlyTheEmptyColumnsAreWrittenAndTheOthersAreNamedAsKept(): void
    {
        $other = $this->secondLocale();
        $page = $this->createPage('Nos ateliers');
        $this->in($other, $page)->setChapo('<p>Le résumé de l’éditeur.</p>')->setOgTitle('   ')->save();

        $outcome = $this->writer()->saveTranslatedHeader($page, $other, new PageHeader('Our workshops', '<p>A machine summary.</p>', '<p>Where we work.</p>', 'Our workshops, shared', 'Come and see'));

        $held = $this->in($other, $page);
        self::assertSame(['title', 'description', 'og_title', 'og_description'], $outcome->written, 'A column of blanks is empty.');
        self::assertSame(['chapo'], $outcome->kept);
        self::assertSame('Our workshops', $held->getTitle());
        self::assertSame('<p>Le résumé de l’éditeur.</p>', $held->getChapo(), 'What an editor wrote is never replaced.');
        self::assertSame('<p>Where we work.</p>', $held->getDescription());
        self::assertSame(['Our workshops, shared', 'Come and see'], [$held->getOgTitle(), $held->getOgDescription()]);

        $again = $this->writer()->saveTranslatedHeader($page, $other, new PageHeader('Another title', '<p>Another.</p>', '<p>Other.</p>', 'Other', 'Other'));

        self::assertSame([], $again->written, 'Nothing is empty any more: nothing is written, however often it is asked.');
        self::assertSame(self::ALL, $again->kept);
        self::assertSame('Our workshops', $this->in($other, $page)->getTitle());
    }

    public function testNothingProposedWritesNothing(): void
    {
        $other = $this->secondLocale();
        $page = $this->createPage('Nos ateliers');

        $outcome = $this->writer()->saveTranslatedHeader($page, $other, new PageHeader(description: '   '));

        self::assertTrue($outcome->isEmpty());
        self::assertNull($page->getRewrittenUrl($other));
    }

    public function testTheAddressIsTheSlugOfTheTranslatedTitleUnderTheAddressOfTheParentInThatLanguage(): void
    {
        $other = $this->secondLocale();
        $parent = $this->createPage('Notre société');
        $this->writer()->saveDraft($parent, $other, new PageDraft(title: 'Our company'));
        $child = $this->createPage('Nos ateliers', parent: (int) $parent->getId());

        $outcome = $this->writer()->saveTranslatedHeader($child, $other, new PageHeader('Our workshops'));

        self::assertSame(HeaderOutcome::ADDRESS_WRITTEN, $outcome->address);
        self::assertSame('our-company/our-workshops', $child->getRewrittenUrl($other));
        self::assertSame('our-workshops', $this->getService(CmsUrlService::class)->slugOf($child, $other));
        self::assertSame('notre-societe/nos-ateliers', $child->getRewrittenUrl($this->locale()), 'The other language keeps its address.');
    }

    public function testAnAddressAnotherPageAnswersOnGetsASuffixThatTheSlugDoesNotKeep(): void
    {
        $other = $this->secondLocale();
        $taken = $this->createPage('Mentions des témoins');
        $page = $this->createPage('Gestion des cookies');

        $this->writer()->saveTranslatedHeader($page, $other, new PageHeader('Mentions des témoins'));

        self::assertSame('mentions-des-temoins', $taken->getRewrittenUrl($this->locale()), 'The page that had it keeps it.');
        self::assertSame('mentions-des-temoins-2', $page->getRewrittenUrl($other));
        self::assertSame('mentions-des-temoins', $this->getService(CmsUrlService::class)->slugOf($page, $other), 'The suffix is in the address, not in the slug.');
    }

    public function testALanguageThatHasAnAddressKeepsItEvenWhenItsTitleWasBlank(): void
    {
        $other = $this->secondLocale();
        $page = $this->createPage('Nos ateliers');
        $this->writer()->saveDraft($page, $other, new PageDraft(title: 'Ancien titre'));
        $this->in($other, $page)->setTitle('')->save();
        $rows = $this->rewritingRows($page);
        $page = $this->in($other, $page);

        $outcome = $this->writer()->saveTranslatedHeader($page, $other, new PageHeader('Our workshops'));

        self::assertSame(['title'], $outcome->written);
        self::assertSame('ancien-titre', $page->getRewrittenUrl($other), 'An address that exists is never touched: the page may be online at it.');
        self::assertSame(HeaderOutcome::ADDRESS_KEPT, $outcome->address);
        self::assertSame($rows, $this->rewritingRows($page), 'No redirection is made, no row changes.');
    }

    public function testAFixedAddressBlocksTheDerivationAndAStoredSlugToo(): void
    {
        $other = $this->secondLocale();
        $fixed = $this->createPage('Nos ateliers');
        $this->in($other, $fixed)->setFixedUrl('nos-ateliers-fige.html')->save();
        $slugged = $this->createPage('Notre histoire');
        $this->in($other, $slugged)->setSlug('histoire-choisie')->save();

        $this->writer()->saveTranslatedHeader($fixed, $other, new PageHeader('Our workshops'));
        $this->writer()->saveTranslatedHeader($slugged, $other, new PageHeader('Our story'));

        self::assertSame('Our workshops', $this->in($other, $fixed)->getTitle());
        self::assertNull($fixed->getRewrittenUrl($other), 'A fixed address is typed whole, never derived.');
        self::assertNull($slugged->getRewrittenUrl($other), 'A slug an editor chose is not replaced by one from the title.');
    }

    public function testATitleThatIsAReservedPathIsWrittenWithoutAnAddress(): void
    {
        $other = $this->secondLocale();
        $page = $this->createPage('Chercher');

        $outcome = $this->writer()->saveTranslatedHeader($page, $other, new PageHeader('Search', '<p>Look for something.</p>'));

        self::assertSame(['title', 'chapo'], $outcome->written, 'The texts stay written.');
        self::assertSame(HeaderOutcome::ADDRESS_RESERVED, $outcome->address);
        self::assertSame('Search', $this->in($other, $page)->getTitle());
        self::assertNull($page->getRewrittenUrl($other));
    }

    public function testTheChildrenAlreadyAddressedInThatLanguageFollowTheirParentOnItsFirstAddress(): void
    {
        $other = $this->secondLocale();
        $parent = $this->createPage('Nos ateliers');
        $child = $this->createPage('Poterie', parent: (int) $parent->getId());
        $this->writer()->saveDraft($child, $other, new PageDraft(title: 'Pottery'));
        self::assertSame('pottery', $child->getRewrittenUrl($other), 'Before its parent is translated, the child answers at the root.');

        $outcome = $this->writer()->saveTranslatedHeader($parent, $other, new PageHeader('Our workshops'));

        self::assertSame('our-workshops', $parent->getRewrittenUrl($other));
        self::assertSame('our-workshops/pottery', CmsPageQuery::create()->findPk($child->getId())?->getRewrittenUrl($other));
        self::assertFalse($outcome->parentNotAddressed);
    }

    public function testAParentNotTranslatedYetIsSaid(): void
    {
        $other = $this->secondLocale();
        $parent = $this->createPage('Nos ateliers');
        $child = $this->createPage('Poterie', parent: (int) $parent->getId());

        $outcome = $this->writer()->saveTranslatedHeader($child, $other, new PageHeader('Pottery'));

        self::assertSame('pottery', $child->getRewrittenUrl($other), 'It answers at the root until the parent has its own address.');
        self::assertTrue($outcome->parentNotAddressed);
    }

    public function testTheModelOfAPageTypeIsGivenItsTextsAndNoAddress(): void
    {
        $other = $this->secondLocale();
        $model = $this->createPage('Modèle', published: false);
        $model->setModel(1)->save();

        $outcome = $this->writer()->saveTranslatedHeader($model, $other, new PageHeader('Model', '<p>Summary.</p>'));

        self::assertSame(['title', 'chapo'], $outcome->written);
        self::assertNull($model->getRewrittenUrl($other), 'A model is not a page of the site: it gets no address.');
    }

    public function testTheSummaryAndTheDescriptionAreFilteredAsAnEditedPageIs(): void
    {
        $other = $this->secondLocale();
        $page = $this->createPage('Nos ateliers');

        $this->writer()->saveTranslatedHeader($page, $other, new PageHeader('Our workshops', '<p onclick="x()">Summary.</p><script>alert(1)</script>', '<p>Description.</p><script>alert(2)</script><iframe src="https://example.com"></iframe>'));

        $held = $this->in($other, $page);
        self::assertStringNotContainsString('alert', (string) $held->getChapo());
        self::assertStringNotContainsString('onclick', (string) $held->getChapo());
        self::assertStringContainsString('Summary.', (string) $held->getChapo());
        self::assertStringNotContainsString('alert', (string) $held->getDescription());
        self::assertStringNotContainsString('iframe', (string) $held->getDescription(), 'Nobody from here holds the right to custom code.');
        self::assertStringContainsString('Description.', (string) $held->getDescription());
    }

    public function testTheOtherLanguagesOfThePageAreUntouched(): void
    {
        $other = $this->secondLocale();
        $page = $this->createPage('Nos ateliers');
        $this->in($this->locale(), $page)->setChapo('<p>Résumé.</p>')->save();

        $this->writer()->saveTranslatedHeader($page, $other, new PageHeader('Our workshops', '<p>Summary.</p>', '<p>Description.</p>', 'Share', 'Share it'));

        $source = $this->in($this->locale(), $page);
        self::assertSame(['Nos ateliers', '<p>Résumé.</p>', null], [$source->getTitle(), $source->getChapo(), $source->getOgTitle()]);
        self::assertSame('nos-ateliers', $page->getRewrittenUrl($this->locale()));
    }

    public function testAWrittenTitleDropsThePagesThatShowIt(): void
    {
        $other = $this->secondLocale();
        $purger = new class implements CachePurgerInterface {
            /** @var list<string> */
            public array $purged = [];

            public function purge(array $tags): void
            {
                array_push($this->purged, ...$tags);
            }
        };
        $writer = $this->writerPurgingWith($purger);
        $page = $this->createPage('Nos ateliers');
        $child = $this->createPage('Poterie', parent: (int) $page->getId());

        $writer->saveTranslatedHeader($page, $other, new PageHeader(description: '<p>Only a description.</p>'));
        self::assertContains(CacheTags::page((int) $page->getId()), $purger->purged);
        self::assertNotContains(CacheTags::page((int) $child->getId()), $purger->purged, 'A description is on the page and in the lists only.');

        $purger->purged = [];
        $writer->saveTranslatedHeader($page, $other, new PageHeader('Our workshops'));

        self::assertContains(CacheTags::page((int) $page->getId()), $purger->purged);
        self::assertContains(CacheTags::page((int) $child->getId()), $purger->purged, 'A title and an address appeared: the pages under it name it in their breadcrumb.');
    }

    private function secondLocale(): string
    {
        $other = LangQuery::create()->filterByActive(true)->filterById(Lang::getDefaultLanguage()->getId(), Criteria::NOT_EQUAL)->orderByPosition()->findOne();

        if (null === $other) {
            self::markTestSkipped('The shop has a single active language, so nothing here can be measured.');
        }

        return (string) $other->getLocale();
    }

    private function in(string $locale, CmsPage $page): CmsPage
    {
        $fresh = CmsPageQuery::create()->findPk($page->getId()) ?? throw new \LogicException('The page exists.');

        return $fresh->setLocale($locale);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rewritingRows(CmsPage $page): array
    {
        return array_map(
            static fn ($row): array => $row->toArray(),
            iterator_to_array(RewritingUrlQuery::create()->filterByView(TheliaCMS::PAGE_VIEW)->filterByViewId((string) $page->getId())->orderById()->find(), false),
        );
    }
}
