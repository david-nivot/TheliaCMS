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

namespace TheliaCMS\Tests\Integration\Kind;

use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Model\AdminLogQuery;
use Thelia\Model\LangQuery;
use TheliaCMS\Content\Document;
use TheliaCMS\Model\CmsPageContent;
use TheliaCMS\Model\CmsPageContentQuery;
use TheliaCMS\Model\CmsPageQuery;
use TheliaCMS\Model\Map\CmsPageContentTableMap;
use TheliaCMS\Model\Map\CmsPageTableMap;
use TheliaCMS\Page\Kind\KindContents;
use TheliaCMS\Security\CmsResources;
use TheliaCMS\Tests\Integration\Security\AdminScreenTestCase;
use TheliaCMS\Tests\Support\GuideKind;
use TheliaCMS\TheliaCMS;
use TheliaCMS\Zone\HeaderZone;

/**
 * The content screen of a content of a kind a module declares, which is the
 * screen of the pages, saying what the kind says: where it goes back to, what
 * publishing does, what is noted at the top, what the log calls it, and what
 * the screens of the pages answer when asked to treat it as a page.
 */
final class KindScreenTest extends AdminScreenTestCase
{
    use DeclaresTestKinds;

    protected function setUp(): void
    {
        parent::setUp();

        $this->declareTestKinds();
    }

    /**
     * Sabotage: make `PageScreenTarget::subject()` read the kind of the
     * hidden page as `null`: the back link, its label, the hint and the notes
     * are those of a page and the first four assertions are red.
     */
    public function testTheScreenSpeaksTheWordsOfTheKind(): void
    {
        $content = $this->kindContent('test_guide', 'Guide des blousons', published: false);
        $id = (int) $content->getId();

        $screen = $this->screen($this->url($id));

        $back = $screen->filter('[data-testid="cms-content-back"]');
        self::assertSame(GuideKind::BACK_LABEL, trim($back->text()));
        self::assertSame(\sprintf('/admin/guides-of-the-test?edit_language_id=%d', $this->languageId()), $back->attr('href'));
        self::assertStringContainsString(\sprintf('Guide #%d read in %s.', $id, $this->locale()), $screen->filter('[data-testid="cms-content-notes"]')->text());
        self::assertSame(GuideKind::PUBLISH_HINT, $screen->filter('[data-testid="cms-content-publish"] button')->attr('title'));
        self::assertSame(1, $screen->filter('[data-testid="cms-content-unpublish"]')->count(), 'It is taken offline from its screen, as the zone is.');
        self::assertSame(0, $screen->filter('[data-testid="cms-page-preview-btn"]')->count(), 'It has no address to preview it at.');
    }

    public function testPublishingFromTheScreenSaysItAndTakingItOfflineComesBackToTheScreen(): void
    {
        $content = $this->kindContent('test_guide', 'Guide à publier', html: '<h2>Tailles</h2><p>Mesurez-vous.</p>', published: false);
        $id = (int) $content->getId();
        $url = $this->url($id);
        $this->forgetTheLog();

        $publish = $this->screen($url)->filter('[data-testid="cms-content-publish"] button')->form();
        self::assertSame(302, $this->send('POST', $publish->getUri(), $publish->getPhpValues()));
        self::assertStringContainsString(GuideKind::ONLINE_MESSAGE, $this->screenContent((string) $this->client->getResponse()->headers->get('Location')), 'The screen says the content is online.');
        CmsPageContentTableMap::clearInstancePool();
        self::assertNotNull($this->content($id)?->getPublishedAt(), 'Online in the language.');

        $unpublish = $this->screen($url)->filter('[data-testid="cms-content-unpublish"]')->form();
        self::assertSame(302, $this->send('POST', $unpublish->getUri(), $unpublish->getPhpValues()));
        self::assertStringEndsWith($url, strtok((string) $this->client->getResponse()->headers->get('Location'), '?') ?: '', 'Back to the content screen: it has no settings screen.');
        CmsPageContentTableMap::clearInstancePool();
        self::assertNull($this->content($id)?->getPublishedAt());

        self::assertSame([
            ['PUBLISH', 'Test guide published in '.$this->locale()],
            ['UNPUBLISH', 'Test guide unpublished in '.$this->locale()],
        ], array_map(static fn (array $entry): array => [$entry[0], str_replace(' (custom code allowed)', '', $entry[1])], $this->entries($id)), 'The log names the kind as it declared.');
    }

    /**
     * Sabotage: change `activityName()` of `HeaderZoneKind` or the format of
     * `KindContentTarget::afterPublished()`: the entries of the zone no longer
     * read `CMS header zone published in …` and this is red.
     */
    public function testTheHeaderZoneStillSpeaksAndLogsAsBefore(): void
    {
        $zone = $this->getService(HeaderZone::class)->ensure();
        $this->writer()->saveContent($zone, $this->locale(), Document::richText('<h2>Soldes</h2><p>Un bandeau.</p>'));
        $id = (int) $zone->getId();
        $this->forgetTheLog();

        $screen = $this->screen($this->url($id));
        self::assertSame($this->say('Back to pages'), trim($screen->filter('[data-testid="cms-content-back"]')->text()));
        self::assertSame(\sprintf('/admin/cms/pages?edit_language_id=%d', $this->languageId()), $screen->filter('[data-testid="cms-content-back"]')->attr('href'));
        self::assertSame($this->say('Publishing puts the draft online in the header of every page of the site, in this language.'), $screen->filter('[data-testid="cms-content-publish"] button')->attr('title'));

        $publish = $screen->filter('[data-testid="cms-content-publish"] button')->form();
        self::assertSame(302, $this->send('POST', $publish->getUri(), $publish->getPhpValues()));
        $unpublish = $this->screen($this->url($id))->filter('[data-testid="cms-content-unpublish"]')->form();
        self::assertSame(302, $this->send('POST', $unpublish->getUri(), $unpublish->getPhpValues()));

        $entries = array_map(static fn (array $entry): array => [$entry[0], str_replace(' (custom code allowed)', '', $entry[1])], $this->entries($id));
        self::assertSame([
            ['PUBLISH', 'CMS header zone published in '.$this->locale()],
            ['UNPUBLISH', 'CMS header zone unpublished in '.$this->locale()],
        ], $entries);
    }

    public function testTheVersionsOfAContentAreKeptAndPutBackFromTheScreen(): void
    {
        $content = $this->kindContent('test_guide', 'Guide versionné', html: '<h2>Avant</h2><p>Première rédaction.</p>', published: false);
        $id = (int) $content->getId();
        $this->writer()->keepDraft($content, $this->locale());
        $this->writer()->saveContent($content, $this->locale(), Document::richText('<h2>Après</h2><p>Seconde rédaction.</p>'));

        $drafts = $this->screen($this->url($id))->filter('[data-testid="cms-content-previous-drafts"] form[data-cms-restore]');
        self::assertCount(1, $drafts, 'The draft that was replaced is offered.');

        $restore = $drafts->first()->form();
        self::assertSame(302, $this->send('POST', $restore->getUri(), $restore->getPhpValues()));

        CmsPageContentTableMap::clearInstancePool();
        self::assertStringContainsString('Première rédaction.', (string) $this->content($id)?->getDraftHtml(), 'It is the draft again.');
    }

    /**
     * Sabotage: drop `->filterByModel(0)` from `CmsPageAdminRepository::trash()`
     * and `countInTrash()`: a binned content shows in the bin and in its badge.
     */
    public function testAContentIsInNoListOfPagesNorInTheBin(): void
    {
        $this->createPage('Page témoin');
        $content = $this->kindContent('test_guide', 'Guide invisible des blousons', published: false);

        $list = (string) $this->screenContent('/admin/cms/pages');
        self::assertStringContainsString('Page témoin', $list, 'The list does show a page.');
        self::assertStringNotContainsString('Guide invisible des blousons', $list);

        $this->getService(KindContents::class)->moveToBin('test_guide', (int) $content->getId());

        $trash = (string) $this->screenContent('/admin/cms/pages/trash');
        self::assertStringNotContainsString('Guide invisible des blousons', $trash, 'The bin of the pages does not list it.');

        $restore = $this->send('POST', \sprintf('/admin/cms/pages/%d/restore', $content->getId()), ['_token' => $this->tokenOfTheScripts($this->screen('/admin/cms/pages'))]);
        self::assertSame(404, $restore, 'It cannot be brought back from the screen of the pages.');
    }

    /**
     * Sabotage: make `CmsPageAdminController::toggleVisibility()` flash the
     * text of the model of a type: the refusal of the kind is red. The same
     * for `deletionRefusal()` on the delete.
     */
    public function testTheScreensOfThePagesRefuseWithTheWordsOfTheKind(): void
    {
        $guide = $this->kindContent('test_guide', 'Guide sans visibilité', published: false);
        $kept = $this->kindContent('test_kept', 'Guide gardé', published: false);
        $token = $this->tokenOfTheScripts($this->screen('/admin/cms/pages'));

        self::assertSame(302, $this->send('POST', \sprintf('/admin/cms/pages/%d/visibility', $guide->getId()), ['_token' => $token]));
        self::assertStringContainsString(GuideKind::NOT_A_PAGE, $this->screenContent((string) $this->client->getResponse()->headers->get('Location')));

        self::assertSame(302, $this->send('POST', \sprintf('/admin/cms/pages/%d/delete', $kept->getId()), ['_token' => $token]));
        self::assertStringContainsString(GuideKind::DELETION_REFUSAL, $this->screenContent((string) $this->client->getResponse()->headers->get('Location')));

        self::assertSame(302, $this->send('POST', \sprintf('/admin/cms/pages/%d/delete', $guide->getId()), ['_token' => $token]));
        self::assertStringContainsString($this->say('This content is deleted from the screens of the module that owns it, never from the pages.'), $this->screenContent((string) $this->client->getResponse()->headers->get('Location')), 'A kind that may be binned is binned by its module, not from the list.');

        CmsPageTableMap::clearInstancePool();
        foreach ([$guide, $kept] as $content) {
            $stored = CmsPageQuery::create()->findPk($content->getId());
            self::assertNull($stored?->getDeletedAt(), 'Not in the bin.');
            self::assertSame(0, $stored?->getVisible(), 'Not visible.');
        }
    }

    /**
     * Sabotage: let `ContentTargetResolver::page()` and
     * `CmsPageAdminController::livePageOrFail()` accept a hidden page whose
     * kind is not declared: the screen answers 200 and the posts 302.
     */
    public function testAContentWhoseKindNoModuleDeclaresIsShownNowhere(): void
    {
        $content = $this->kindContent('test_guide', 'Guide orphelin', published: false);
        $id = (int) $content->getId();
        $token = $this->tokenOfTheScripts($this->screen('/admin/cms/pages'));

        self::assertSame(200, $this->send('GET', $this->url($id)), 'Declared, it opens.');

        // The module that declared the kind is deactivated: the content stays in the database.
        $this->getPropelConnection()->exec(\sprintf("UPDATE cms_page SET content_kind = 'module_gone' WHERE id = %d", $id));
        CmsPageTableMap::clearInstancePool();

        self::assertSame(404, $this->send('GET', $this->url($id)), 'The content screen answers 404.');
        self::assertSame(404, $this->send('POST', $this->url($id).'/publish', ['_token' => $token]));
        self::assertSame(404, $this->send('POST', \sprintf('/admin/cms/pages/%d/unpublish', $id), ['_token' => $token]));
        self::assertSame(404, $this->send('POST', \sprintf('/admin/cms/pages/%d/visibility', $id), ['_token' => $token]));
        self::assertSame(404, $this->send('POST', \sprintf('/admin/cms/pages/%d/delete', $id), ['_token' => $token]));
        self::assertSame(404, $this->send('GET', \sprintf('/admin/cms/pages/%d', $id)), 'Nor does its settings screen open.');
    }

    /**
     * Sabotage: make `PageScreenTarget::subject()` read `statusOf()` for every
     * kind: the published guide, hidden as its page is, reads "Unpublished".
     * Make `edit()` of `CmsPageAdminController` open the settings of a content:
     * the redirection is red.
     */
    public function testTheBadgeReadsTheContentAndTheSettingsScreenSendsToTheContentScreen(): void
    {
        $content = $this->kindContent('test_guide', 'Guide en ligne', published: true);
        $id = (int) $content->getId();

        $badge = $this->screen($this->url($id))->filter('.cms-content__title + .badge')->text();
        self::assertSame($this->say('Published'), trim($badge), 'A published guide reads published.');

        self::assertSame(302, $this->send('GET', \sprintf('/admin/cms/pages/%d', $id)), 'It has no settings screen.');
        self::assertStringContainsString($this->url($id), (string) $this->client->getResponse()->headers->get('Location'));
    }

    /**
     * Sabotage: let `move()`, `duplicate()` and `setAsHome()` of
     * `CmsPageAdminController` read `livePageOrFail()`: the actions answer a
     * redirection and the content is moved, copied or made the home page.
     */
    public function testTheActionsOfTheListRefuseAContentOfAKind(): void
    {
        $content = $this->kindContent('test_guide', 'Guide intouchable', published: false);
        $id = (int) $content->getId();
        $token = $this->tokenOfTheScripts($this->screen('/admin/cms/pages'));
        $copies = CmsPageQuery::create()->count();

        self::assertSame(404, $this->send('POST', \sprintf('/admin/cms/pages/%d/move/up', $id), ['_token' => $token]));
        self::assertSame(404, $this->send('POST', \sprintf('/admin/cms/pages/%d/duplicate', $id), ['_token' => $token]));
        self::assertSame(404, $this->send('POST', \sprintf('/admin/cms/pages/%d/set-as-home', $id), ['_token' => $token]));

        self::assertSame($copies, CmsPageQuery::create()->count(), 'No copy.');
        self::assertNotSame((string) $id, (string) TheliaCMS::getConfigValue('home_page_id', '0'), 'Not the home page.');
    }

    private function url(int $id): string
    {
        return \sprintf('/admin/cms/pages/%d/content', $id);
    }

    private function languageId(): int
    {
        return (int) LangQuery::create()->findOneByLocale($this->locale())?->getId();
    }

    private function say(string $message): string
    {
        return $this->getService(TranslatorInterface::class)->trans($message, [], TheliaCMS::DOMAIN_NAME);
    }

    private function screenContent(string $url): string
    {
        $this->screen($url);

        return (string) $this->client->getResponse()->getContent();
    }

    private function content(int $pageId): ?CmsPageContent
    {
        return CmsPageContentQuery::create()->filterByPageId($pageId)->filterByLocale($this->locale())->findOne();
    }

    private function forgetTheLog(): void
    {
        AdminLogQuery::create()->filterByResource(CmsResources::PAGE)->delete();
    }

    /**
     * @return list<array{string, string}> action, message
     */
    private function entries(int $resourceId): array
    {
        $entries = [];

        foreach (AdminLogQuery::create()->filterByResource(CmsResources::PAGE)->filterByResourceId($resourceId)->orderById()->find() as $entry) {
            if (str_contains((string) $entry->getMessage(), 'content edited in')) {
                continue;
            }

            $entries[] = [(string) $entry->getAction(), (string) $entry->getMessage()];
        }

        return $entries;
    }
}
