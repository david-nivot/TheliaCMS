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

use Symfony\Component\DomCrawler\Crawler;
use Thelia\Domain\Ai\AiGatewayInterface;
use Thelia\Test\FixtureFactory;
use TheliaCMS\Content\Document;
use TheliaCMS\Content\Editing\ContentVersions;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Model\CmsPageContentQuery;
use TheliaCMS\Model\CmsPageRevisionQuery;
use TheliaCMS\Model\Map\CmsPageContentTableMap;
use TheliaCMS\Model\Map\CmsPageTableMap;
use TheliaCMS\Page\Admin\CmsPageWriter;
use TheliaCMS\Tests\Integration\Security\AdminScreenTestCase;
use TheliaCMS\Tests\Support\FakeAiGateway;

/**
 * Two editors on one draft: the screens post the version they read, a write
 * made on a version that moved is refused with who changed it and when, and
 * a screen opened on a draft someone else changed within the hour says so.
 */
final class ConcurrentEditScreenTest extends AdminScreenTestCase
{
    private FakeAiGateway $api;

    protected function setUp(): void
    {
        parent::setUp();

        $this->api = new FakeAiGateway();
        static::getContainer()->set(AiGatewayInterface::class, $this->api);
    }

    public function testASectionRemovedFromAStaleScreenIsRefusedAndTheOtherEditorsDraftKept(): void
    {
        $page = $this->createPage('Deux rédacteurs', published: false);
        $screen = $this->screen($this->contentUrl($page));
        $section = $this->firstSection($page);
        $other = $this->editorNamed('Marie', 'Durand');
        $this->savedBy($page, $other, '<h2>Version de Marie</h2>');

        $status = $this->send('POST', $this->actionUrl($page, 'remove'), [
            '_token' => $this->tokenOfTheForm($screen, $this->actionUrl($page, 'remove')),
            'section' => $section,
            'draft_version' => $this->postedVersion($screen),
        ]);

        self::assertSame(409, $status);
        self::assertStringContainsString('Your change was not saved: Marie Durand changed this content', (string) $this->client->getResponse()->getContent());
        self::assertStringContainsString('Version de Marie', (string) $this->content($page)->getDraftHtml());
    }

    public function testAWriteOfTheScreenThatPostsNoVersionIsRefused(): void
    {
        $page = $this->createPage('Sans version', published: false);
        $screen = $this->screen($this->contentUrl($page));

        self::assertSame(409, $this->send('POST', $this->actionUrl($page, 'remove'), [
            '_token' => $this->tokenOfTheForm($screen, $this->actionUrl($page, 'remove')),
            'section' => $this->firstSection($page),
        ]));
        self::assertNotNull(Document::fromJson($this->content($page)->getDraftDocument())?->section($this->firstSection($page)));
    }

    public function testTheVersionTheScreenReadGoesThroughAndEveryWritingFormCarriesIt(): void
    {
        $page = $this->createPage('Une version', published: false);
        $screen = $this->screen($this->contentUrl($page));
        $version = $this->postedVersion($screen);

        // A page of one section offers no move: the arrows come with a second one.
        foreach (['remove', 'duplicate', 'add', 'publish', 'generate', 'revise'] as $action) {
            $forms = $screen->filter('form')->reduce(fn (Crawler $form): bool => parse_url((string) $form->attr('action'), \PHP_URL_PATH) === $this->actionUrl($page, $action));
            self::assertGreaterThan(0, $forms->count(), \sprintf('No form posts to %s.', $action));
            $forms->each(static fn (Crawler $form) => self::assertSame($version, (string) $form->filter('input[name="draft_version"]')->attr('value'), \sprintf('A form posting to %s carries another version.', $action)));
        }

        self::assertSame($version, (string) $screen->filter('[data-cms-content]')->attr('data-cms-draft-version'));
        self::assertSame(302, $this->send('POST', $this->actionUrl($page, 'remove'), [
            '_token' => $this->tokenOfTheForm($screen, $this->actionUrl($page, 'remove')),
            'section' => $this->firstSection($page),
            'draft_version' => $version,
        ]));
    }

    public function testTheContentScreenNamesAnotherEditorWhoChangedTheDraftWithinTheHour(): void
    {
        $page = $this->createPage('Bandeau', published: false);
        $other = $this->editorNamed('Marie', 'Durand');
        $this->savedBy($page, $other, '<h2>Version de Marie</h2>');

        $banner = $this->screen($this->contentUrl($page))->filter('[data-testid="cms-draft-notice"]');

        self::assertSame(1, $banner->count());
        self::assertStringContainsString('Changed by Marie Durand', $banner->text());

        $this->getPropelConnection()->exec(\sprintf('UPDATE cms_page_content SET updated_at = DATE_SUB(NOW(), INTERVAL 2 HOUR) WHERE page_id = %d', $page->getId()));
        CmsPageContentTableMap::clearInstancePool();
        self::assertSame(0, $this->screen($this->contentUrl($page))->filter('[data-testid="cms-draft-notice"]')->count(), 'A change older than the hour is not worth a banner.');
    }

    public function testNoBannerForTheEditorsOwnChanges(): void
    {
        $page = $this->createPage('Le mien', published: false);
        $screen = $this->screen($this->contentUrl($page));
        // A change made through the screen: its author is the editor reading it next.
        self::assertSame(302, $this->send('POST', $this->actionUrl($page, 'duplicate'), [
            '_token' => $this->tokenOfTheForm($screen, $this->actionUrl($page, 'duplicate')),
            'section' => $this->firstSection($page),
            'draft_version' => $this->postedVersion($screen),
        ]));
        self::assertNotNull($this->content($page)->getDraftUpdatedBy());

        self::assertSame(0, $this->screen($this->contentUrl($page))->filter('[data-testid="cms-draft-notice"]')->count());
    }

    public function testTheSettingsScreenNamesTheOtherEditorAndRefusesToPublishADraftItsPublisherDidNotRead(): void
    {
        $page = $this->createPage('Réglages', published: false);
        $settingsUrl = \sprintf('/admin/cms/pages/%d', $page->getId());
        $publishUrl = \sprintf('/admin/cms/pages/%d/publish', $page->getId());
        $screen = $this->screen($settingsUrl);
        $form = $screen->filter('#cms-page-publish-form');
        $other = $this->editorNamed('Marie', 'Durand');
        $this->savedBy($page, $other, '<h2>Jamais relue</h2>');

        $this->send('POST', $publishUrl, [
            '_token' => (string) $form->filter('input[name="_token"]')->attr('value'),
            'draft_version' => (string) $form->filter('input[name="draft_version"]')->attr('value'),
        ]);

        self::assertNull($this->content($page)->getPublishedDocument(), 'A draft its publisher did not read went online.');

        $after = $this->screen($settingsUrl);
        self::assertStringContainsString('Marie Durand', $after->filter('[data-testid="cms-draft-notice"]')->text());

        // Read again, the draft goes online.
        $form = $after->filter('#cms-page-publish-form');
        $this->send('POST', $publishUrl, [
            '_token' => (string) $form->filter('input[name="_token"]')->attr('value'),
            'draft_version' => (string) $form->filter('input[name="draft_version"]')->attr('value'),
        ]);
        self::assertStringContainsString('Jamais relue', (string) $this->content($page)->getPublishedHtml());
    }

    public function testAStaleScreenPaysForNoGeneration(): void
    {
        $page = $this->createPage('Génération', published: false);
        $screen = $this->screen($this->contentUrl($page));
        $read = $this->postedVersion($screen);
        $this->savedBy($page, $this->editorNamed('Marie', 'Durand'), '<h2>Version de Marie</h2>');

        $this->send('POST', $this->actionUrl($page, 'generate'), [
            '_token' => $this->tokenOfTheForm($screen, $this->actionUrl($page, 'generate')),
            'cms_brief' => ['text' => 'Une page sur nos tarifs.'],
            'draft_version' => $read,
        ], self::SAME_SITE + ['HTTP_ACCEPT' => 'text/event-stream']);

        self::assertStringContainsString('Your change was not saved', (string) $this->client->getInternalResponse()->getContent());
        self::assertSame([], $this->api->requests, 'The model was called for a draft that moved before the call.');
    }

    public function testAnAnswerWhoseDraftMovedDuringTheCallIsKeptAmongThePreviousDrafts(): void
    {
        $page = $this->createPage('Pendant l’appel', published: false);
        $screen = $this->screen($this->contentUrl($page));
        $other = $this->editorNamed('Marie', 'Durand');
        $this->api->answer(FakeAiGateway::response(json_encode(['version' => 1, 'sections' => [['id' => 'content', 'component' => 'cms:richtext', 'props' => ['html' => '<h2>Écrit par le modèle</h2>']]]], \JSON_THROW_ON_ERROR), provenance: FakeAiGateway::provenance()))
            ->meanwhile(fn () => $this->savedBy($page, $other, '<h2>Version de Marie</h2>'));
        $kept = $this->keptDrafts($page);

        $this->send('POST', $this->actionUrl($page, 'generate'), [
            '_token' => $this->tokenOfTheForm($screen, $this->actionUrl($page, 'generate')),
            'cms_brief' => ['text' => 'Une page sur nos tarifs.'],
            'draft_version' => $this->postedVersion($screen),
        ], self::SAME_SITE + ['HTTP_ACCEPT' => 'text/event-stream']);

        self::assertStringContainsString('kept among the previous drafts', (string) $this->client->getInternalResponse()->getContent());
        self::assertStringContainsString('Version de Marie', (string) $this->content($page)->getDraftHtml(), 'The answer replaced a draft nobody asked it to.');
        self::assertSame($kept + 1, $this->keptDrafts($page), 'The answer of the model is lost.');
    }

    private function contentUrl(CmsPage $page): string
    {
        return \sprintf('/admin/cms/pages/%d/content', $page->getId());
    }

    private function actionUrl(CmsPage $page, string $action): string
    {
        return \sprintf('/admin/cms/pages/%d/content/%s', $page->getId(), $action);
    }

    private function postedVersion(Crawler $screen): string
    {
        $field = $screen->filter('input[name="draft_version"]');
        self::assertGreaterThan(0, $field->count(), 'The screen posts no version.');

        return (string) $field->first()->attr('value');
    }

    private function firstSection(CmsPage $page): string
    {
        $document = Document::fromJson($this->content($page)->getDraftDocument());
        self::assertNotNull($document);

        return $document->sections[0]->id;
    }

    private function editorNamed(string $firstname, string $lastname): \Thelia\Model\Admin
    {
        return (new FixtureFactory($this->getPropelConnection()))->admin(['firstname' => $firstname, 'lastname' => $lastname, 'login' => 'concurrent-'.bin2hex(random_bytes(4))]);
    }

    /**
     * A save by another editor, as the writer stores it, while this one's screen is open.
     */
    private function savedBy(CmsPage $page, \Thelia\Model\Admin $editor, string $html): void
    {
        $writer = $this->getService(CmsPageWriter::class);
        $writer->saveContent($page, $this->locale(), Document::richText($html));
        $this->getPropelConnection()->exec(\sprintf("UPDATE cms_page_content SET draft_updated_by = %d WHERE page_id = %d AND locale = '%s'", $editor->getId(), $page->getId(), $this->locale()));
        $this->getPropelConnection()->exec(\sprintf('UPDATE cms_page SET updated_by = %d WHERE id = %d', $editor->getId(), $page->getId()));
        // A screen reads both rows afresh; this process keeps them in memory.
        CmsPageContentTableMap::clearInstancePool();
        CmsPageTableMap::clearInstancePool();
    }

    private function keptDrafts(CmsPage $page): int
    {
        return CmsPageRevisionQuery::create()->filterByPageId($page->getId())->filterByLocale($this->locale())->filterByLabel(ContentVersions::DRAFT_LABEL)->count();
    }

    private function content(CmsPage $page): \TheliaCMS\Model\CmsPageContent
    {
        CmsPageContentTableMap::clearInstancePool();
        $content = CmsPageContentQuery::create()->filterByPageId($page->getId())->filterByLocale($this->locale())->findOne();
        self::assertNotNull($content);

        return $content;
    }
}
