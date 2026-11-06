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

namespace TheliaCMS\Tests\Integration\Generation;

use Propel\Runtime\ActiveQuery\Criteria;
use Symfony\Component\DomCrawler\Crawler;
use Thelia\Core\Security\AccessManager;
use Thelia\Domain\Ai\AiGatewayInterface;
use Thelia\Domain\Ai\AiRequest;
use Thelia\Domain\Ai\AiResponse;
use Thelia\Model\Content;
use Thelia\Model\Lang;
use Thelia\Model\LangQuery;
use Thelia\Test\FixtureFactory;
use TheliaCMS\Catalogue\Admin\ContentTargets;
use TheliaCMS\Catalogue\CatalogueItemType;
use TheliaCMS\Content\Document;
use TheliaCMS\Content\Editing\ContentVersions;
use TheliaCMS\Content\Editing\ContentWriter;
use TheliaCMS\Content\Editing\TranslationFreshness;
use TheliaCMS\Content\Editing\TranslationLink;
use TheliaCMS\Content\Section;
use TheliaCMS\Content\SectionAppearance;
use TheliaCMS\Dashboard\ShowcaseStats;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Model\CmsPageContent;
use TheliaCMS\Model\CmsPageContentQuery;
use TheliaCMS\Model\CmsPageRevisionQuery;
use TheliaCMS\Model\Map\CmsPageContentTableMap;
use TheliaCMS\Page\Admin\CmsPageWriter;
use TheliaCMS\Page\Admin\PageDraft;
use TheliaCMS\Page\StalePages;
use TheliaCMS\Security\CmsResources;
use TheliaCMS\Settings\CmsSettings;
use TheliaCMS\Settings\SiteMode;
use TheliaCMS\Tests\Integration\Security\AdminScreenTestCase;
use TheliaCMS\Tests\Support\FakeAiGateway;
use TheliaCMS\TheliaCMS;

/**
 * A language written from another one is followed: the language it was
 * written from and the words of that draft then are kept with it, and the
 * draft of that language is compared with them when somebody looks.
 */
final class StaleTranslationTest extends AdminScreenTestCase
{
    private FakeAiGateway $api;

    private \stdClass $whileTheModelWrites;

    protected function setUp(): void
    {
        parent::setUp();

        $this->whileTheModelWrites = new \stdClass();
        $this->whileTheModelWrites->run = null;
        $this->api = new FakeAiGateway();
        // The queued answers, with a hook run when the model is called: what
        // an editor does to the source while the model writes.
        static::getContainer()->set(AiGatewayInterface::class, new class($this->api, $this->whileTheModelWrites) implements AiGatewayInterface {
            public function __construct(private readonly FakeAiGateway $answers, private readonly \stdClass $hook)
            {
            }

            public function complete(AiRequest $request): AiResponse
            {
                $this->hook->run?->__invoke();

                return $this->answers->complete($request);
            }

            public function stream(AiRequest $request): iterable
            {
                $this->hook->run?->__invoke();

                return $this->answers->stream($request);
            }
        });
    }

    public function testAPageTranslationIsUpToDateUntilTheWordsOfItsSourceChange(): void
    {
        $page = $this->pageWithASource('Nos tarifs');
        $target = $this->otherLanguage();
        self::assertNull($this->pageLink($page, $target->getLocale()), 'Written by hand or not at all, a language follows nothing.');

        $this->translatePage($page, $target);

        $link = $this->pageLink($page, $target->getLocale());
        self::assertInstanceOf(TranslationLink::class, $link);
        self::assertSame($this->locale(), $link->sourceLocale);
        self::assertFalse($link->isStale(), 'Just written, the translation is up to date.');

        // Neither an image, nor the look of a section, nor a move is a word.
        $this->saveSource($page, [$this->photo('https://example.com/b.jpg', appearance: new SectionAppearance('#f8d7d7')), $this->intro('Nos tarifs')]);
        self::assertFalse($this->pageLink($page, $target->getLocale())?->isStale(), 'An image, a color and the order of the sections are not words.');

        $this->saveSource($page, [$this->intro('Nos tarifs, revus'), $this->photo('https://example.com/b.jpg')]);
        $stale = $this->pageLink($page, $target->getLocale());
        self::assertTrue($stale?->isStale(), 'A word of the source changed.');
        self::assertSame(['intro'], $stale->changed);
        self::assertSame([], $stale->removed);

        // Put back as it was written from, the source is what was translated.
        $this->saveSource($page, [$this->intro('Nos tarifs'), $this->photo('https://example.com/a.jpg')]);
        self::assertFalse($this->pageLink($page, $target->getLocale())?->isStale());
    }

    public function testASectionRemovedFromTheSourceIsNamedAsRemoved(): void
    {
        $page = $this->pageWithASource('Nos tarifs');
        $target = $this->otherLanguage();
        $this->translatePage($page, $target);

        $this->saveSource($page, [$this->intro('Nos tarifs')]);

        $link = $this->pageLink($page, $target->getLocale());
        self::assertTrue($link?->isStale());
        self::assertSame([], $link->changed);
        self::assertSame(['photo'], $link->removed);
    }

    public function testAnItemTranslationIsFollowedToo(): void
    {
        $factory = new FixtureFactory($this->getPropelConnection());
        $item = $factory->content($factory->folder(), ['title' => 'Notre méthode', 'locale' => $this->locale()]);
        $id = (int) $item->getId();
        $target = $this->otherLanguage();
        $this->saveItemSource($id, '<h2>Notre méthode</h2>');
        $this->translateItem($item, $target);

        $link = $this->itemLink($id, $target->getLocale());
        self::assertSame($this->locale(), $link?->sourceLocale);
        self::assertFalse($link->isStale());

        $this->saveItemSource($id, '<h2>Notre méthode</h2><p>En trois étapes.</p>');

        $stale = $this->itemLink($id, $target->getLocale());
        self::assertTrue($stale?->isStale());
        self::assertSame(['content'], $stale->changed);

        $this->saveItemSource($id, '<h2>Notre méthode</h2>');
        self::assertFalse($this->itemLink($id, $target->getLocale())?->isStale(), 'The source put back is the one that was translated.');
    }

    public function testASourceEditedWhileTheModelWritesMakesTheTranslationStaleAtOnce(): void
    {
        $page = $this->pageWithASource('Nos tarifs');
        $target = $this->otherLanguage();
        $this->api->answer($this->answerFor('Our prices'));
        $this->whileTheModelWrites->run = fn () => $this->saveSource($page, [$this->intro('Nos tarifs, modifiés pendant l’écriture'), $this->photo('https://example.com/a.jpg')]);

        $this->postTranslation($page, $target);

        $link = $this->pageLink($page, $target->getLocale());
        self::assertTrue($link?->isStale(), 'The translation was written from the source as it was read, not as it is when the answer comes.');
        self::assertSame(['intro'], $link->changed);
    }

    public function testNothingIsFollowedWhenTheSourceHoldsNothingOrIsTheSameLanguage(): void
    {
        $page = $this->createPage('Nos tarifs', published: false);
        $target = $this->otherLanguage();
        $writer = $this->getService(CmsPageWriter::class);
        $writer->saveContent($page, $target->getLocale(), Document::richText('<h2>Our prices</h2>'));
        $freshness = $this->getService(TranslationFreshness::class);
        $targets = $this->getService(ContentTargets::class);

        self::assertFalse($freshness->follow($targets->forPage($page, $target->getLocale()), $targets->forPage($page, $target->getLocale())), 'A language is not the translation of itself.');

        $writer->saveContent($page, $this->locale(), new Document([]));
        self::assertFalse($freshness->follow($targets->forPage($page, $target->getLocale()), $targets->forPage($page, $this->locale())), 'A source that holds nothing is nothing to follow.');
        self::assertFalse($freshness->follow($targets->forPage($page, $target->getLocale()), $targets->forPage($page, 'xx_XX')), 'Neither is a language the page has no row in.');
        self::assertNull($this->pageLink($page, $target->getLocale()));
    }

    public function testAStoredFingerprintOfAnOtherShapeFollowsNothing(): void
    {
        $page = $this->pageWithASource('Nos tarifs');
        $target = $this->otherLanguage();
        $this->getService(CmsPageWriter::class)->saveContent($page, $target->getLocale(), Document::richText('<h2>Our prices</h2>'));
        $content = $this->content($page, $target);
        self::assertNotNull($content);
        // What the fingerprint of the whole draft of an earlier design looked like.
        $content->setTranslatedFrom($this->locale())->setSourceFingerprint(hash('sha256', 'x'))->save();

        self::assertNull($this->pageLink($page, $target->getLocale()), 'A value that is not a map of sections says nothing: the language is not followed.');
    }

    public function testACopyOfAPageFollowsTheSameSourceAsItsOriginal(): void
    {
        $page = $this->pageWithASource('Nos tarifs');
        $target = $this->otherLanguage();
        $this->translatePage($page, $target);

        $copy = $this->getService(CmsPageWriter::class)->duplicate($page, $this->locale(), 'copie');

        $link = $this->pageLink($copy, $target->getLocale());
        self::assertSame($this->locale(), $link?->sourceLocale, 'The copy of a translation is a translation of the copy of its source.');
        self::assertFalse($link->isStale());

        $this->saveSource($copy, [$this->intro('Nos tarifs, la copie'), $this->photo('https://example.com/a.jpg')]);
        self::assertTrue($this->pageLink($copy, $target->getLocale())?->isStale());
        self::assertFalse($this->pageLink($page, $target->getLocale())?->isStale(), 'The original is not touched by an edit of its copy.');
    }

    public function testTheScreenSaysTheSourceChangedAndNamesTheSectionsThatChanged(): void
    {
        $page = $this->pageWithASource('Nos tarifs');
        $target = $this->otherLanguage();
        $this->translatePage($page, $target);

        $screen = $this->screen($this->pageUrl($page, $target));
        self::assertSame(1, $screen->filter('[data-testid="cms-content-translation-ok"]')->count(), 'Just written, the translation is up to date: one line.');
        self::assertSame(0, $screen->filter('[data-testid="cms-content-translation-stale"]')->count());
        self::assertStringContainsString((string) Lang::getDefaultLanguage()->getTitle(), $screen->filter('[data-testid="cms-content-translation-ok"]')->text());

        $this->saveSource($page, [$this->intro('Nos tarifs, revus'), $this->photo('https://example.com/a.jpg'), new Section('plus', 'cms:richtext', ['html' => '<h2>Un ajout</h2>'])]);

        $screen = $this->screen($this->pageUrl($page, $target));
        self::assertSame(0, $screen->filter('[data-testid="cms-content-translation-ok"]')->count());
        self::assertSame(1, $screen->filter('[data-testid="cms-content-translation-stale"]')->count(), 'The words of the source changed since.');
        self::assertStringContainsString((string) Lang::getDefaultLanguage()->getTitle(), $screen->filter('[data-testid="cms-content-translation-stale"]')->text());
        $changed = $screen->filter('[data-testid="cms-content-translation-changed"] li');
        self::assertSame(2, $changed->count(), 'The section that changed, and the one that was added.');
        self::assertStringContainsString('1', $changed->eq(0)->text(), 'A section is named by its position...');
        self::assertStringContainsString('Our prices', $changed->eq(0)->text(), '...and by its title in the language on screen.');
        self::assertSame(1, $screen->filter('[data-testid="cms-content-retranslate"] form')->count(), 'Writing it again is offered.');
        self::assertSame(1, $screen->filter('[data-testid="cms-content-translation-current"] form')->count(), 'So is marking it up to date.');

        $this->saveSource($page, [$this->intro('Nos tarifs')]);
        $removed = $this->screen($this->pageUrl($page, $target))->filter('[data-testid="cms-content-translation-removed"] li');
        self::assertSame(1, $removed->count(), 'A section the source no longer has is named too.');
        self::assertStringContainsString('2', $removed->text());
    }

    public function testWritingItAgainReadsTheSourceAsItIsKeepsTheReplacedDraftAndClearsTheBanner(): void
    {
        $page = $this->pageWithASource('Nos tarifs');
        $target = $this->otherLanguage();
        $this->translatePage($page, $target);
        $this->saveSource($page, [$this->intro('Nos tarifs, revus'), $this->photo('https://example.com/a.jpg')]);

        $this->api->answer($this->answerFor('Our revised prices'));
        $form = $this->screen($this->pageUrl($page, $target))->filter('[data-testid="cms-content-retranslate"] form')->form();
        self::assertSame(302, $this->send('POST', $form->getUri(), $form->getPhpValues()));

        self::assertStringContainsString('Nos tarifs, revus', $this->api->requests[1]->messages[0]->content, 'The current source is what is written from.');
        self::assertStringContainsString('Our revised prices', (string) $this->content($page, $target)?->getDraftHtml());
        self::assertSame(1, CmsPageRevisionQuery::create()->filterByPageId($page->getId())->filterByLocale($target->getLocale())->filterByLabel(ContentVersions::DRAFT_LABEL)->count(), 'The translation it replaces can be put back.');
        $screen = $this->screen($this->pageUrl($page, $target));
        self::assertSame(1, $screen->filter('[data-testid="cms-content-translation-ok"]')->count());
        self::assertSame(0, $screen->filter('[data-testid="cms-content-translation-stale"]')->count());
    }

    public function testMarkingATranslationUpToDateAsksNothingOfTheGeneratorAndLeavesItAsItIs(): void
    {
        $page = $this->pageWithASource('Nos tarifs');
        $target = $this->otherLanguage();
        $this->translatePage($page, $target);
        $before = (string) $this->content($page, $target)?->getDraftHtml();
        $this->saveSource($page, [$this->intro('Nos tarifs, une coquille corrigée'), $this->photo('https://example.com/a.jpg')]);

        $form = $this->screen($this->pageUrl($page, $target))->filter('[data-testid="cms-content-translation-current"] form')->form();
        self::assertSame(302, $this->send('POST', $form->getUri(), $form->getPhpValues()));

        self::assertSame(1, $this->screen($this->pageUrl($page, $target))->filter('[data-testid="cms-content-translation-ok"]')->count());
        self::assertSame($before, (string) $this->content($page, $target)?->getDraftHtml(), 'The translation is left as it is.');
        self::assertCount(1, $this->api->requests, 'Nothing was asked of the generator.');
    }

    public function testMarkingATranslationUpToDateIsNotAnEditOfIt(): void
    {
        $page = $this->pageWithASource('Nos tarifs');
        $target = $this->otherLanguage();
        $this->translatePage($page, $target);
        $this->saveSource($page, [$this->intro('Nos tarifs, revus'), $this->photo('https://example.com/a.jpg')]);
        $content = $this->content($page, $target);
        self::assertNotNull($content);
        $stamp = '2026-01-02 03:04:05';
        $this->getPropelConnection()->exec(\sprintf("UPDATE cms_page_content SET updated_at = '%s' WHERE id = %d", $stamp, $content->getId()));
        // The row is read again, as a request of its own would read it.
        CmsPageContentTableMap::clearInstancePool();

        $form = $this->screen($this->pageUrl($page, $target))->filter('[data-testid="cms-content-translation-current"] form')->form();
        self::assertSame(302, $this->send('POST', $form->getUri(), $form->getPhpValues()));

        self::assertSame($stamp, $this->getPropelConnection()->query(\sprintf('SELECT updated_at FROM cms_page_content WHERE id = %d', $content->getId()))->fetchColumn(), 'A page is "modified since its publication" by its date of update: tying it to a source must not move it.');
    }

    public function testALanguageWrittenByHandIsFollowedOnceTiedToItsSource(): void
    {
        $page = $this->pageWithASource('Nos engagements');
        $target = $this->otherLanguage();
        $this->getService(CmsPageWriter::class)->saveContent($page, $target->getLocale(), Document::richText('<h2>Our commitments</h2>'));

        $screen = $this->screen($this->pageUrl($page, $target));
        self::assertSame(0, $screen->filter('[data-testid="cms-content-translation-ok"], [data-testid="cms-content-translation-stale"]')->count(), 'Written by hand, it is not followed.');

        $form = $screen->filter('[data-testid="cms-content-translation-attach"] form')->form();
        self::assertSame($this->locale(), $form['source']->getValue());
        self::assertSame(302, $this->send('POST', $form->getUri(), $form->getPhpValues()));
        self::assertSame(1, $this->screen($this->pageUrl($page, $target))->filter('[data-testid="cms-content-translation-ok"]')->count());
        self::assertSame(0, $this->screen($this->pageUrl($page, $target))->filter('[data-testid="cms-content-translation-attach"]')->count(), 'Followed, it is no longer offered.');

        $this->saveSource($page, [$this->intro('Nos engagements, tenus'), $this->photo('https://example.com/a.jpg')]);

        self::assertSame(1, $this->screen($this->pageUrl($page, $target))->filter('[data-testid="cms-content-translation-stale"]')->count());
    }

    public function testAnItemMarkedUpToDateAndAnItemWrittenByHandBothWorkOnTheirScreen(): void
    {
        $factory = new FixtureFactory($this->getPropelConnection());
        $item = $factory->content($factory->folder(), ['title' => 'Notre méthode', 'locale' => $this->locale()]);
        $id = (int) $item->getId();
        $target = $this->otherLanguage();
        $this->saveItemSource($id, '<h2>Notre méthode</h2>');
        $this->translateItem($item, $target);
        $this->saveItemSource($id, '<h2>Notre méthode</h2><p>En trois étapes.</p>');

        $screen = $this->screen($this->itemUrl($item, $target));
        self::assertSame(1, $screen->filter('[data-testid="cms-content-translation-stale"]')->count());
        $form = $screen->filter('[data-testid="cms-content-translation-current"] form')->form();
        self::assertSame(302, $this->send('POST', $form->getUri(), $form->getPhpValues()));
        self::assertSame(1, $this->screen($this->itemUrl($item, $target))->filter('[data-testid="cms-content-translation-ok"]')->count());

        $other = $factory->content($factory->folder(), ['title' => 'Notre équipe', 'locale' => $this->locale()]);
        $this->saveItemSource((int) $other->getId(), '<h2>Notre équipe</h2>');
        $this->getService(ContentWriter::class)->saveContent($this->itemTarget(CatalogueItemType::Content, (int) $other->getId(), $target->getLocale()), Document::richText('<h2>Our team</h2>'));

        $attach = $this->screen($this->itemUrl($other, $target))->filter('[data-testid="cms-content-translation-attach"] form')->form();
        self::assertSame(302, $this->send('POST', $attach->getUri(), $attach->getPhpValues()));
        self::assertSame(1, $this->screen($this->itemUrl($other, $target))->filter('[data-testid="cms-content-translation-ok"]')->count());
    }

    public function testTheRightsDecideWhichWayOutOfAStaleTranslationIsOffered(): void
    {
        $page = $this->pageWithASource('Nos tarifs');
        $target = $this->otherLanguage();
        $this->translatePage($page, $target);
        $this->saveSource($page, [$this->intro('Nos tarifs, revus'), $this->photo('https://example.com/a.jpg')]);
        $factory = new FixtureFactory($this->getPropelConnection());

        $this->logInAs($factory->restrictedAdmin([CmsResources::PAGE => [AccessManager::VIEW, AccessManager::UPDATE]]));
        $screen = $this->screen($this->pageUrl($page, $target));
        self::assertNotNull($screen->filter('[data-testid="cms-content-retranslate"] button')->attr('disabled'), 'Without the right to generate, writing it again is shown and cannot be asked.');
        self::assertSame(1, $screen->filter('[data-testid="cms-content-translation-current"] button')->count(), 'Marking it up to date takes the right to change pages, which this profile has.');

        $this->logInAs($factory->restrictedAdmin([CmsResources::PAGE => [AccessManager::VIEW], CmsResources::GENERATE => [AccessManager::UPDATE]]));
        $screen = $this->screen($this->pageUrl($page, $target));
        self::assertNull($screen->filter('[data-testid="cms-content-retranslate"] button')->attr('disabled'));
        self::assertSame(0, $screen->filter('[data-testid="cms-content-translation-current"]')->count(), 'Without the right to change pages, nothing is marked.');
        self::assertSame(1, $screen->filter('[data-testid="cms-content-translation-stale"]')->count(), 'It is still told: looking takes neither right.');
    }

    public function testTheListAndTheDashboardSayWhichPagesAreToBeTranslatedAgain(): void
    {
        $page = $this->pageWithASource('Nos tarifs');
        $target = $this->otherLanguage();
        $this->translatePage($page, $target);

        self::assertSame(0, $this->listOf($target)->filter(\sprintf('[data-testid="cms-page-stale-%d"]', $page->getId()))->count(), 'Up to date, the row says nothing.');
        self::assertSame(0, $this->staleCount($target));

        $this->saveSource($page, [$this->intro('Nos tarifs, revus'), $this->photo('https://example.com/a.jpg')]);

        $badge = $this->listOf($target)->filter(\sprintf('[data-testid="cms-page-stale-%d"]', $page->getId()));
        self::assertSame(1, $badge->count(), 'The list of pages in that language says so on the row.');
        self::assertStringContainsString('/admin/cms/pages/'.$page->getId().'/content', (string) $badge->attr('href'), 'The badge leads to the content screen.');
        self::assertStringContainsString('edit_language_id='.$target->getId(), (string) $badge->attr('href'), 'In the language of the list.');
        self::assertSame(0, $this->listOf(Lang::getDefaultLanguage())->filter(\sprintf('[data-testid="cms-page-stale-%d"]', $page->getId()))->count(), 'The source itself is not out of date.');
        self::assertSame(1, $this->staleCount($target), 'The dashboard counts it.');
        self::assertSame(0, $this->staleCount(Lang::getDefaultLanguage()));

        $block = $this->dashboard()->filter(\sprintf('[data-testid="cms-dashboard-stale-%s"]', $target->getLocale()));
        self::assertSame(1, $block->count());
        self::assertStringContainsString('1 page(s) to translate again', $block->text());
        self::assertStringContainsString('edit_language_id='.$target->getId(), (string) $block->filter('a')->attr('href'), 'The count leads to the list in that language.');

        $this->saveSource($page, [$this->intro('Nos tarifs'), $this->photo('https://example.com/a.jpg')]);
        self::assertSame(0, $this->staleCount($target), 'Put back as it was, it is up to date again.');
        self::assertSame(0, $this->dashboard()->filter(\sprintf('[data-testid="cms-dashboard-stale-%s"]', $target->getLocale()))->count());
    }

    public function testAPageWithBothReasonsShowsBothLabelsEachLeadingToItsScreen(): void
    {
        $page = $this->pageWithASource('Nos horaires');
        $target = $this->otherLanguage();
        $this->translatePage($page, $target);
        $this->saveSource($page, [$this->intro('Nos horaires, revus'), $this->photo('https://example.com/a.jpg')]);
        // No title in that language, whatever the translation wrote.
        $page->setLocale($target->getLocale())->setTitle('')->save();

        $settings = $this->listOf($target)->filter(\sprintf('[data-testid="cms-page-untranslated-%d"]', $page->getId()));
        $content = $this->listOf($target)->filter(\sprintf('[data-testid="cms-page-stale-%d"]', $page->getId()));
        self::assertSame(1, $settings->count(), 'The settings are to translate.');
        self::assertSame(1, $content->count(), 'And the content to translate again: both are said.');
        self::assertSame('Settings to translate', trim($settings->text()));
        self::assertSame('Content to translate again', trim($content->text()));
        self::assertSame(\sprintf('/admin/cms/pages/%d?edit_language_id=%d', $page->getId(), $target->getId()), (string) $settings->attr('href'), 'One leads to the settings.');
        self::assertSame(\sprintf('/admin/cms/pages/%d/content?edit_language_id=%d', $page->getId(), $target->getId()), (string) $content->attr('href'), 'The other to the content.');

        $this->getService(CmsPageWriter::class)->saveDraft($page, $target->getLocale(), new PageDraft(title: 'Our opening hours'));

        self::assertSame(0, $this->listOf($target)->filter(\sprintf('[data-testid="cms-page-untranslated-%d"]', $page->getId()))->count(), 'Named, the settings are no longer to translate.');
        self::assertSame(1, $this->listOf($target)->filter(\sprintf('[data-testid="cms-page-stale-%d"]', $page->getId()))->count(), 'The content still is: one reason does not settle the other.');
    }

    public function testAPageInTheBinOrAModelIsNotCounted(): void
    {
        $target = $this->otherLanguage();
        $binned = $this->pageWithASource('À la corbeille');
        $model = $this->pageWithASource('Modèle');
        $this->translatePage($binned, $target);
        $this->translatePage($model, $target);
        $this->saveSource($binned, [$this->intro('Changé')]);
        $this->saveSource($model, [$this->intro('Changé')]);
        self::assertSame(2, $this->staleCount($target), 'Both are out of date for now.');

        $this->getService(CmsPageWriter::class)->moveToTrash($binned);
        $model->setModel(1)->save();

        self::assertSame(0, $this->staleCount($target), 'A page in the bin and the model of a type are not pages of the site.');
        self::assertSame([], $this->getService(StalePages::class)->ids($target->getLocale(), [(int) $binned->getId(), (int) $model->getId()]));
    }

    // — helpers —

    private function pageWithASource(string $title): CmsPage
    {
        $page = $this->createPage($title, published: false);
        $this->saveSource($page, [$this->intro($title), $this->photo('https://example.com/a.jpg')]);

        return $page;
    }

    /**
     * @param list<Section> $sections
     */
    private function saveSource(CmsPage $page, array $sections): void
    {
        $this->getService(CmsPageWriter::class)->saveContent($page, $this->locale(), new Document($sections));
    }

    private function saveItemSource(int $id, string $html): void
    {
        $this->getService(ContentWriter::class)->saveContent($this->itemTarget(CatalogueItemType::Content, $id, $this->locale()), new Document([new Section('content', 'cms:richtext', ['html' => $html])]));
    }

    private function intro(string $title): Section
    {
        return new Section('intro', 'cms:richtext', ['html' => \sprintf('<h2>%s</h2><p>Du conseil.</p>', $title)]);
    }

    private function photo(string $url, ?SectionAppearance $appearance = null): Section
    {
        return new Section('photo', 'cms:image', ['image' => $url, 'alt' => 'Un atelier'], $appearance ?? new SectionAppearance());
    }

    private function answerFor(string $title): AiResponse
    {
        return FakeAiGateway::response(json_encode(['version' => 1, 'sections' => [
            ['id' => 'intro', 'component' => 'cms:richtext', 'props' => ['html' => \sprintf('<h2>%s</h2><p>Advice.</p>', $title)]],
            ['id' => 'photo', 'component' => 'cms:image', 'props' => ['image' => 'https://example.com/a.jpg', 'alt' => 'A workshop']],
        ]], \JSON_THROW_ON_ERROR));
    }

    private function translatePage(CmsPage $page, Lang $target): void
    {
        $this->api->answer($this->answerFor('Our prices'));
        $this->postTranslation($page, $target);
    }

    private function postTranslation(CmsPage $page, Lang $target): void
    {
        $screen = $this->screen(\sprintf('/admin/cms/pages/%d/content?edit_language_id=%d', $page->getId(), $target->getId()));
        $form = $screen->filter('[data-testid="cms-content-translate"] form')->form();
        self::assertSame(302, $this->send('POST', $form->getUri(), $form->getPhpValues()));
    }

    private function translateItem(Content $item, Lang $target): void
    {
        $this->api->answer(FakeAiGateway::response(json_encode(['version' => 1, 'sections' => [
            ['id' => 'content', 'component' => 'cms:richtext', 'props' => ['html' => '<h2>Our method</h2>']],
        ]], \JSON_THROW_ON_ERROR)));
        $screen = $this->screen(\sprintf('/admin/cms/catalogue/content/%d/content?edit_language_id=%d', $item->getId(), $target->getId()));
        $form = $screen->filter('[data-testid="cms-content-translate"] form')->form();
        self::assertSame(302, $this->send('POST', $form->getUri(), $form->getPhpValues()));
    }

    private function otherLanguage(): Lang
    {
        $other = LangQuery::create()->filterByActive(true)->filterById(Lang::getDefaultLanguage()->getId(), Criteria::NOT_EQUAL)->orderById()->findOne();
        self::assertNotNull($other, 'The shop of the tests has a second active language.');

        return $other;
    }

    private function listOf(Lang $lang): Crawler
    {
        return $this->screen(\sprintf('/admin/cms/pages?edit_language_id=%d', $lang->getId()));
    }

    private function staleCount(Lang $lang): int
    {
        foreach ($this->getService(ShowcaseStats::class)->collect($this->locale())['completeness'] as $row) {
            if ($row['locale'] === $lang->getLocale()) {
                self::assertSame((int) $lang->getId(), $row['language_id']);

                return $row['stale'];
            }
        }

        self::fail('The dashboard has a row for each active language.');
    }

    /**
     * The home of the back office of a showcase site, where the block of the
     * module is shown: only in that mode, so the mode is switched for the
     * request and put back.
     */
    private function dashboard(): Crawler
    {
        $previous = TheliaCMS::getConfigValue(CmsSettings::SITE_MODE);
        TheliaCMS::setConfigValue(CmsSettings::SITE_MODE, SiteMode::Showcase->value);

        try {
            return $this->screen('/admin');
        } finally {
            TheliaCMS::setConfigValue(CmsSettings::SITE_MODE, $previous ?? SiteMode::Commerce->value);
        }
    }

    private function pageUrl(CmsPage $page, Lang $lang): string
    {
        return \sprintf('/admin/cms/pages/%d/content?edit_language_id=%d', $page->getId(), $lang->getId());
    }

    private function itemUrl(Content $item, Lang $lang): string
    {
        return \sprintf('/admin/cms/catalogue/content/%d/content?edit_language_id=%d', $item->getId(), $lang->getId());
    }

    private function pageLink(CmsPage $page, string $locale): ?TranslationLink
    {
        $targets = $this->getService(ContentTargets::class);

        return $this->getService(TranslationFreshness::class)->link($targets->forPage($page, $locale), static fn (string $source) => $targets->forPage($page, $source));
    }

    private function itemLink(int $id, string $locale): ?TranslationLink
    {
        return $this->getService(TranslationFreshness::class)->link(
            $this->itemTarget(CatalogueItemType::Content, $id, $locale),
            fn (string $source) => $this->itemTarget(CatalogueItemType::Content, $id, $source),
        );
    }

    private function content(CmsPage $page, Lang $lang): ?CmsPageContent
    {
        return CmsPageContentQuery::create()->filterByPageId($page->getId())->filterByLocale($lang->getLocale())->findOne();
    }
}
