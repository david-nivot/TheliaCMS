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
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Thelia\Core\HttpFoundation\Session\Session as TheliaSession;
use Thelia\Core\Security\AccessManager;
use Thelia\Domain\Ai\AiFinishReason;
use Thelia\Domain\Ai\AiGatewayInterface;
use Thelia\Domain\Ai\AiToolCall;
use Thelia\Domain\Ai\Exception\AiProviderUnavailableException;
use Thelia\Model\Lang;
use Thelia\Model\LangQuery;
use Thelia\Model\ModuleConfigQuery;
use Thelia\Test\FixtureFactory;
use TheliaCMS\Content\Document;
use TheliaCMS\Content\Editing\ContentVersions;
use TheliaCMS\Content\Section;
use TheliaCMS\Content\SectionAppearance;
use TheliaCMS\Generation\Brief;
use TheliaCMS\Generation\GenerationLog;
use TheliaCMS\Generation\GenerationRequest;
use TheliaCMS\Generation\GenerationTarget;
use TheliaCMS\Generation\GeneratorSettings;
use TheliaCMS\Generation\Tool\ReadUrlTool;
use TheliaCMS\Model\CmsPageContentQuery;
use TheliaCMS\Model\CmsPageGenerationQuery;
use TheliaCMS\Model\CmsPageQuery;
use TheliaCMS\Model\CmsPageRevisionQuery;
use TheliaCMS\Page\Admin\CmsPageWriter;
use TheliaCMS\Security\CmsResources;
use TheliaCMS\Tests\Integration\Security\AdminScreenTestCase;
use TheliaCMS\Tests\Support\FakeAiGateway;

/**
 * The content screen of a page, against a recorded API: the brief becomes
 * the draft, the outcome is read back from the ledger, a change keeps the
 * sections it does not concern, and publishing puts the draft online.
 */
final class ContentScreenTest extends AdminScreenTestCase
{
    private FakeAiGateway $api;

    private \stdClass $editedLanguages;

    protected function setUp(): void
    {
        parent::setUp();

        $this->api = new FakeAiGateway();
        static::getContainer()->set(AiGatewayInterface::class, $this->api);

        $this->editedLanguages = new \stdClass();
        $this->editedLanguages->id = 0;
        $editedLanguages = $this->editedLanguages;
        $this->getService(EventDispatcherInterface::class)->addListener(KernelEvents::RESPONSE, static function (ResponseEvent $event) use ($editedLanguages): void {
            $session = $event->getRequest()->hasSession(true) ? $event->getRequest()->getSession() : null;

            if ($event->isMainRequest() && $session instanceof TheliaSession) {
                $editedLanguages->id = $session->getAdminEditionLang()->getId();
            }
        });
    }

    protected function tearDown(): void
    {
        $settings = $this->getService(GeneratorSettings::class);
        $settings->save($settings->effort(), $settings->maxTokens(), webFetch: false);
        ModuleConfigQuery::resetConfigCache();

        parent::tearDown();
    }

    public function testTheScreenOffersABriefToAPageWithoutContent(): void
    {
        $page = $this->createPage('Nos services', published: false);
        CmsPageContentQuery::create()->filterByPageId($page->getId())->delete();

        $screen = $this->screen($this->url($page->getId()));

        self::assertSame(1, $screen->filter('[data-testid="cms-page-content"]')->count());
        self::assertSame(1, $screen->filter('[data-testid="cms-content-empty"]')->count(), 'A page without content shows the empty state instead of a preview.');
        self::assertSame(0, $screen->filter('[data-testid="cms-content-frame"]')->count());
        self::assertSame(0, $screen->filter('[data-testid="cms-content-sections"]')->count());
        self::assertSame(1, $screen->filter('[data-testid="cms-content-brief"] form input[name="_token"]')->count(), 'The brief form carries the token.');
        self::assertSame(0, $screen->filter('[data-testid="cms-content-brief-dialog"]')->count(), 'Without a brief, the form is in the screen, the first thing to fill.');
        self::assertNotNull($screen->filter('[data-testid="cms-content-publish"] button')->attr('disabled'), 'Nothing to publish yet.');
    }

    public function testTheDraftABriefReplacesIsKeptAndCanBePutBack(): void
    {
        $page = $this->createPage('Nos services', published: false);
        $url = $this->url($page->getId());
        $writer = $this->getService(CmsPageWriter::class);
        $writer->saveContent($page, $this->locale(), Document::richText('<h2>Avant</h2><p>Écrit à la main.</p>'));

        self::assertSame(0, $this->screen($url)->filter('[data-testid="cms-content-previous-drafts"]')->count(), 'Nothing replaced yet.');

        $this->api->answer(FakeAiGateway::response(self::document([
            ['id' => 'intro', 'component' => 'cms:richtext', 'props' => ['html' => '<h2>Après</h2><p>Écrit par le générateur.</p>']],
        ])));
        $form = $this->screen($url)->filter('[data-testid="cms-content-brief"] form')->form();
        $form['cms_brief[text]'] = 'Une page qui présente nos services.';
        self::assertSame(302, $this->send('POST', $form->getUri(), $form->getPhpValues()));

        $screen = $this->screen($url);
        $drafts = $screen->filter('[data-testid="cms-content-previous-drafts"] form[data-cms-restore]');
        self::assertCount(1, $drafts, 'The draft the brief replaced is kept.');
        self::assertStringContainsString('Après', (string) $this->draftHtml($page->getId()));

        $restore = $drafts->first()->form();
        self::assertSame(302, $this->send('POST', $restore->getUri(), $restore->getPhpValues()));

        self::assertStringContainsString('Écrit à la main.', (string) $this->draftHtml($page->getId()), 'The previous draft is the draft again.');
        self::assertCount(2, $this->screen($url)->filter('[data-testid="cms-content-previous-drafts"] form[data-cms-restore]'), 'The draft it replaced is kept in turn.');
        self::assertSame(0, CmsPageRevisionQuery::create()->filterByPageId($page->getId())->filterByLabel(null, Criteria::ISNULL)->count(), 'A kept draft is no publication.');

        self::assertSame(302, $this->send('POST', $restore->getUri(), ['revision' => 999999999] + $restore->getPhpValues()));
        self::assertStringContainsString('Écrit à la main.', (string) $this->draftHtml($page->getId()), 'An unknown revision changes nothing.');
    }

    private function draftHtml(int $pageId): ?string
    {
        return CmsPageContentQuery::create()->filterByPageId($pageId)->filterByLocale($this->locale())->findOne()?->getDraftHtml();
    }

    public function testABriefWritesTheDraftAndTheScreenShowsTheOutcome(): void
    {
        $page = $this->createPage('Nos services', published: false);
        $url = $this->url($page->getId());

        $this->api->answer(FakeAiGateway::response(self::document([
            ['id' => 'hero', 'component' => 'cms:richtext', 'props' => ['html' => '<h2>Nos services</h2><p>Ce que nous faisons.</p>']],
            ['id' => 'faq', 'component' => 'cms:richtext', 'props' => ['html' => '<h2>Questions</h2><p>Sur devis.</p>']],
        ])));

        $form = $this->screen($url)->filter('[data-testid="cms-content-brief"] form')->form();
        $form['cms_brief[text]'] = 'Une page qui présente nos services de conseil.';
        $form['cms_brief[tone]'] = 'direct';

        self::assertSame(302, $this->send('POST', $form->getUri(), $form->getPhpValues()));

        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertMatchesRegularExpression('#/admin/cms/pages/\d+/content\?.*done=\d+#', $location, 'The screen is reloaded with the row of the ledger.');

        $content = CmsPageContentQuery::create()->filterByPageId($page->getId())->filterByLocale($this->locale())->findOne();
        self::assertStringContainsString('"html":"<h2>Nos services</h2>', (string) $content?->getDraftDocument(), 'The draft is the document the model wrote.');
        self::assertStringContainsString('Nos services', (string) $content?->getDraftHtml());
        self::assertNull($content?->getPublishedHtml(), 'Nothing went online.');
        self::assertStringContainsString('Tone asked for: direct', $this->api->requests[0]->messages[0]->content);

        $screen = $this->screen($location);

        self::assertSame(1, $screen->filter('[data-testid="cms-builder-flash-success"]')->count(), 'The outcome is read from the ledger and shown.');
        self::assertStringContainsString('The draft is written', $screen->filter('[data-testid="cms-builder-flash-success"]')->text());
        self::assertSame(1, $screen->filter('[data-testid="cms-content-frame"]')->count(), 'The draft is previewed in the theme.');
        self::assertSame(['hero', 'faq'], $screen->filter('[data-testid="cms-content-sections"] li[data-section]')->each(static fn (Crawler $item): string => (string) $item->attr('data-section')));
        self::assertStringContainsString('Nos services', $screen->filter('[data-testid="cms-content-sections"] li[data-section]')->first()->text(), 'A section is quoted by its title.');
        self::assertSame(1, $screen->filter('[data-testid="cms-content-history"] tbody tr')->count(), 'The history lists the call.');
        self::assertSame(2, $screen->filter('form[data-cms-generate] input[name$="[section]"]')->count(), 'Every section has its rewrite form.');
        self::assertSame(1, $screen->filter('dialog#cms-section-rewrite-hero form[data-cms-generate]')->count(), 'The rewrite form of a section is in a dialog.');
        self::assertSame('cms-section-rewrite-hero', $screen->filter('[data-testid="cms-section-rewrite-open-hero"]')->attr('commandfor'), 'Its button opens that dialog.');
        self::assertSame('Une page qui présente nos services de conseil.', $screen->filter('[data-testid="cms-content-brief-dialog"] textarea')->text(), 'The brief is back in its form, in a dialog, to be corrected rather than typed again.');
        self::assertSame('Une page qui présente nos services de conseil.', $screen->filter('[data-testid="cms-content-brief-recall"]')->text(), 'The screen recalls the brief in a few lines.');
        self::assertStringContainsString('direct', $screen->filter('[data-testid="cms-content-brief"] .cms-content__brief-meta')->text());
        self::assertSame('cms-content-brief-dialog', $screen->filter('[data-testid="cms-content-brief-open"]')->attr('commandfor'));
        self::assertSame('direct', $screen->filter('[data-testid="cms-content-brief"] input[name="cms_brief[tone]"]')->attr('value'));
        self::assertSame('{"text":"Une page qui présente nos services de conseil.","tone":"direct","audience":null}', CmsPageGenerationQuery::create()->filterByTargetId($page->getId())->findOne()?->getBrief());
    }

    public function testTheHistoryListsTheWebPagesACallRead(): void
    {
        $page = $this->createPage('Notre histoire', published: false);
        $url = $this->url($page->getId());
        $this->readingOn();

        $this->api
            ->answer(FakeAiGateway::response(toolCalls: [
                new AiToolCall('call_1', 'read_url', ['url' => 'https://example.com/a-propos']),
                new AiToolCall('call_2', 'read_url', ['url' => 'https://example.com/prive']),
            ]))
            ->answer(FakeAiGateway::response(self::document([['id' => 'content', 'component' => 'cms:richtext', 'props' => ['html' => '<p>Lyon, 1987.</p>']]])));
        static::getContainer()->set(ReadUrlTool::class, new ReadUrlTool(new MockHttpClient(static fn (string $method, string $address): MockResponse => str_ends_with($address, '/prive')
            ? new MockResponse('', ['http_code' => 404])
            : new MockResponse('Fondée en 1987 à Lyon.', ['response_headers' => ['Content-Type' => 'text/plain']]))));

        $form = $this->screen($url)->filter('[data-testid="cms-content-brief"] form')->form();
        $form['cms_brief[text]'] = 'Résume https://example.com/a-propos et https://example.com/prive.';

        self::assertSame(302, $this->send('POST', $form->getUri(), $form->getPhpValues()));
        self::assertStringContainsString('Fondée en 1987 à Lyon.', $this->api->requests[1]->untrustedContents[0]->content, 'The page read reaches the model as data.');

        $pages = $this->screen((string) $this->client->getResponse()->headers->get('Location'))->filter('[data-testid="cms-content-history-pages"]');

        self::assertSame(1, $pages->count(), 'The call lists the pages it read.');
        self::assertStringContainsString('2 web page(s) read', $pages->filter('summary')->text());
        self::assertSame(2, $pages->filter('li')->count());
        self::assertStringContainsString('https://example.com/a-propos', $pages->filter('li')->eq(0)->text());
        self::assertStringContainsString('not counted', $pages->filter('li')->eq(0)->text(), 'The tokens of a page are in the log of the core, not counted here.');
        self::assertStringContainsString('not read (The page could not be reached)', $pages->filter('li')->eq(1)->text());
    }

    public function testAnUnknownPageDoesNotChangeTheLanguageBeingEdited(): void
    {
        $page = $this->createPage('Nos services', published: false);
        $current = Lang::getDefaultLanguage();
        $other = LangQuery::create()->filterByActive(true)->filterById($current->getId(), Criteria::NOT_EQUAL)->orderById()->findOne();
        self::assertNotNull($other, 'The shop of the tests has a second active language.');
        $path = '/admin/cms/pages/%d/content?edit_language_id=%d';

        $this->screen(\sprintf($path, $page->getId(), $current->getId()));
        self::assertSame($current->getId(), $this->editedLanguageId());

        $this->screen(\sprintf($path, $page->getId(), $other->getId()));
        self::assertSame($other->getId(), $this->editedLanguageId(), 'On a page that exists, the request changes the language being edited.');

        $this->screen(\sprintf($path, $page->getId(), $current->getId()));
        self::assertSame(404, $this->send('GET', \sprintf($path, 999999999, $other->getId())));
        self::assertSame($current->getId(), $this->editedLanguageId(), 'On a page that does not exist, it does not.');
    }

    public function testALanguageWithoutContentIsWrittenFromAnotherOne(): void
    {
        $page = $this->createPage('Nos services', published: false);
        $source = Lang::getDefaultLanguage();
        $target = LangQuery::create()->filterByActive(1)->filterById($source->getId(), Criteria::NOT_EQUAL)->orderById()->findOne();
        self::assertNotNull($target, 'The shop of the tests has a second active language.');
        $url = \sprintf('/admin/cms/pages/%d/content?edit_language_id=%d', $page->getId(), $target->getId());
        $this->api->answer(FakeAiGateway::response(self::document([['id' => 'content', 'component' => 'cms:richtext', 'props' => ['html' => '<h2>Translated</h2><p>Written for the test.</p>']]])));

        $screen = $this->screen($url);

        self::assertSame(0, $screen->filter('[data-testid="cms-content-empty"]')->count(), 'A page written in another language is not announced as blank.');
        self::assertSame(1, $screen->filter('[data-testid="cms-content-translate"]')->count());
        self::assertSame($source->getLocale(), $screen->filter('[data-testid="cms-content-translate"] option[selected]')->attr('value'), 'The first language of the shop that holds a draft is picked.');
        self::assertStringContainsString((string) $source->getTitle(), $screen->filter('[data-testid="cms-content-translate-btn"]')->text());

        $form = $screen->filter('[data-testid="cms-content-translate"] form')->form();
        self::assertSame(302, $this->send('POST', $form->getUri(), $form->getPhpValues()));

        $prompt = $this->api->requests[0]->messages[0]->content;
        self::assertStringContainsString(\sprintf('The document the page holds in %s (`%s`)', $source->getTitle(), $source->getLocale()), $prompt);
        self::assertStringContainsString('<h2>Nos services</h2><p>Written for the test.</p>', $prompt, 'The draft of the other language is what the model writes from.');
        self::assertStringContainsString(\sprintf('Write this document in %s (`%s`)', $target->getTitle(), $target->getLocale()), $prompt);

        $content = CmsPageContentQuery::create()->filterByPageId($page->getId())->filterByLocale($target->getLocale())->findOne();
        self::assertStringContainsString('Translated', (string) $content?->getDraftHtml(), 'The new draft is stored on the row of its language.');
        self::assertStringContainsString('Nos services', (string) CmsPageContentQuery::create()->filterByPageId($page->getId())->filterByLocale($source->getLocale())->findOne()?->getDraftHtml(), 'The source draft is untouched.');
        self::assertSame('translation', CmsPageGenerationQuery::create()->filterByTargetId($page->getId())->filterByLocale($target->getLocale())->findOne()?->getKind());
        self::assertSame(0, $this->screen($url)->filter('[data-testid="cms-content-translate"]')->count(), 'Once written, the offer is gone.');
    }

    public function testATranslationKeepsTheAppearanceOfTheSource(): void
    {
        $page = $this->createPage('Nos produits', published: false);
        $this->getService(CmsPageWriter::class)->saveContent($page, $this->locale(), new Document([
            new Section('content', 'cms:richtext', ['html' => '<h2>Nos produits</h2>'], new SectionAppearance(background: '#f8d7d7', padding: 'medium')),
        ]));
        $source = Lang::getDefaultLanguage();
        $target = LangQuery::create()->filterByActive(1)->filterById($source->getId(), Criteria::NOT_EQUAL)->orderById()->findOne();
        self::assertNotNull($target);
        // The model drops the background and changes the room: the source wins.
        $this->api->answer(FakeAiGateway::response(self::document([['id' => 'content', 'component' => 'cms:richtext', 'props' => ['html' => '<h2>Our products</h2>'], 'appearance' => ['padding' => 'large']]])));

        $form = $this->screen(\sprintf('/admin/cms/pages/%d/content?edit_language_id=%d', $page->getId(), $target->getId()))->filter('[data-testid="cms-content-translate"] form')->form();
        self::assertSame(302, $this->send('POST', $form->getUri(), $form->getPhpValues()));

        $content = CmsPageContentQuery::create()->filterByPageId($page->getId())->filterByLocale($target->getLocale())->findOne();
        $translated = Document::fromJson($content?->getDraftDocument())?->section('content');
        self::assertSame('<h2>Our products</h2>', $translated?->props['html']);
        self::assertSame(['background' => '#f8d7d7', 'padding' => 'medium'], $translated?->appearance->toArray());
    }

    public function testATranslationWrittenOverADraftKeepsItAndMarksTheNewOneAsTheModelsWork(): void
    {
        $page = $this->createPage('Nos tarifs', published: false);
        $target = LangQuery::create()->filterByActive(true)->filterById(Lang::getDefaultLanguage()->getId(), Criteria::NOT_EQUAL)->orderById()->findOne();
        self::assertNotNull($target);
        $this->getService(CmsPageWriter::class)->saveContent($page, $target->getLocale(), Document::richText('<h1>Our prices</h1><p>By hand.</p>'));
        $this->api->answer(FakeAiGateway::response(
            self::document([['id' => 'content', 'component' => 'cms:richtext', 'props' => ['html' => '<h1>Our prices</h1><p>By the model.</p>']]]),
            provenance: FakeAiGateway::provenance(),
        ));

        $screen = $this->screen(\sprintf('/admin/cms/pages/%d/content?edit_language_id=%d', $page->getId(), $target->getId()));
        $token = $this->tokenOfTheForm($screen, \sprintf('/admin/cms/pages/%d/content/generate', $page->getId()));

        self::assertSame(302, $this->send('POST', \sprintf('/admin/cms/pages/%d/content/translate?edit_language_id=%d', $page->getId(), $target->getId()), ['_token' => $token, 'cms_translate' => ['source' => $this->locale()]]));

        $content = CmsPageContentQuery::create()->filterByPageId($page->getId())->filterByLocale($target->getLocale())->findOne();
        self::assertStringContainsString('By the model.', (string) $content?->getDraftHtml());
        self::assertNotNull($content?->getDraftProvenance(), 'The translation carries the mark of the call.');
        self::assertSame(1, CmsPageRevisionQuery::create()->filterByPageId($page->getId())->filterByLocale($target->getLocale())->filterByLabel(ContentVersions::DRAFT_LABEL)->count(), 'The draft the translation replaces is kept.');
        self::assertSame(0, CmsPageRevisionQuery::create()->filterByPageId($page->getId())->filterByLocale($this->locale())->filterByLabel(ContentVersions::DRAFT_LABEL)->count(), 'The source keeps nothing: it was not replaced.');
    }

    public function testTheOutcomeOfAnotherPageIsNotShown(): void
    {
        $page = $this->createPage('Mine', published: false);
        $other = $this->createPage('Other', published: false);
        $this->api->answer(FakeAiGateway::response(self::document([['id' => 'content', 'component' => 'cms:richtext', 'props' => ['html' => '<h2>Other</h2>']]])));

        $form = $this->screen($this->url($other->getId()))->filter('[data-testid="cms-content-brief"] form')->form();
        $form['cms_brief[text]'] = 'Something.';
        $this->send('POST', $form->getUri(), $form->getPhpValues());
        $row = CmsPageGenerationQuery::create()->filterByTargetId($other->getId())->findOne();

        $screen = $this->screen($this->url($page->getId()).'&done='.$row?->getId());

        self::assertSame(0, $screen->filter('.cms-toast')->count());
    }

    public function testTheSearchTitleAndDescriptionOfABriefAreWrittenOnlyWhereThePageHasNone(): void
    {
        $page = $this->createPage('Nos services', published: false);
        $other = $this->createPage('Notre atelier', published: false);
        $locale = $this->locale();
        $page->setLocale($locale)->setMetaTitle('Titre de l\'éditeur')->save();
        $document = [['id' => 'hero', 'component' => 'cms:richtext', 'props' => ['html' => '<h2>Services</h2>']]];
        $answer = static fn (): string => json_encode([...json_decode(self::document($document), true, 16, \JSON_THROW_ON_ERROR), 'meta_title' => 'Titre proposé', 'meta_description' => 'Description proposée.'], \JSON_THROW_ON_ERROR);

        foreach ([$page, $other] as $target) {
            $this->api->answer(FakeAiGateway::response($answer()));
            $form = $this->screen($this->url($target->getId()))->filter('[data-testid="cms-content-brief"] form')->form();
            $form['cms_brief[text]'] = 'Une page de services.';
            self::assertSame(302, $this->send('POST', $form->getUri(), $form->getPhpValues()));
        }

        $kept = CmsPageQuery::create()->findPk($page->getId())->setLocale($locale);
        $written = CmsPageQuery::create()->findPk($other->getId())->setLocale($locale);

        self::assertSame('Titre de l\'éditeur', $kept->getMetaTitle(), 'What an editor wrote is never replaced.');
        self::assertSame('Description proposée.', $kept->getMetaDescription(), 'The empty one is filled.');
        self::assertSame(['Titre proposé', 'Description proposée.'], [$written->getMetaTitle(), $written->getMetaDescription()]);
    }

    public function testAScriptReadsTheProgressAsAStreamOfEvents(): void
    {
        $page = $this->createPage('Streamed', published: false);
        $this->api
            ->answer(FakeAiGateway::response(toolCalls: [new AiToolCall('toolu_1', 'search_pages', ['query' => ''])]))
            ->answer(FakeAiGateway::response(self::document([['id' => 'content', 'component' => 'cms:richtext', 'props' => ['html' => '<h2>Streamed</h2><p>Done.</p>']]])));

        $form = $this->screen($this->url($page->getId()))->filter('[data-testid="cms-content-brief"] form')->form();
        $form['cms_brief[text]'] = 'A streamed page.';

        $this->client->request('POST', $form->getUri(), $form->getPhpValues(), [], [...self::XHR, 'HTTP_ACCEPT' => 'text/event-stream']);
        $response = $this->client->getResponse();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('text/event-stream', (string) $response->headers->get('Content-Type'));
        self::assertSame('no', $response->headers->get('X-Accel-Buffering'));

        // The content of a stream is what the callback wrote, which the
        // browser of the test captured; the response itself holds none.
        $events = self::events((string) $this->client->getInternalResponse()->getContent());

        self::assertSame(['start', 'turn', 'turn', 'done'], array_values(array_filter(array_column($events, 'event'), static fn (string $name): bool => 'delta' !== $name)), 'The deltas of the text aside, every step is announced.');
        self::assertSame(['search_pages'], $events[1]['data']['tools'], 'The tools of a turn are named.');
        self::assertMatchesRegularExpression('#/content\?.*done=\d+$#', $events[3]['data']['redirect'], 'The last event says where to go.');
        self::assertStringContainsString('<p>Done.</p>', (string) CmsPageContentQuery::create()->filterByPageId($page->getId())->findOne()?->getDraftHtml(), 'The draft was saved before the last event.');
    }

    public function testAFailureIsAnEventAndARowOfTheLedger(): void
    {
        $page = $this->createPage('Refused', published: false);
        $this->api->answer(FakeAiGateway::response(finishReason: AiFinishReason::ContentFilter));

        $form = $this->screen($this->url($page->getId()))->filter('[data-testid="cms-content-brief"] form')->form();
        $form['cms_brief[text]'] = 'Something the model declines.';

        $this->client->request('POST', $form->getUri(), $form->getPhpValues(), [], [...self::XHR, 'HTTP_ACCEPT' => 'text/event-stream']);
        $events = self::events((string) $this->client->getInternalResponse()->getContent());

        self::assertSame(['start', 'error'], array_column($events, 'event'), 'A refusal is no turn: the model wrote nothing.');
        self::assertStringContainsString('declined', $events[1]['data']['message']);

        $screen = $this->screen($events[1]['data']['redirect']);
        self::assertSame(1, $screen->filter('[data-testid="cms-builder-flash-warning"]')->count(), 'A refusal is a warning, with the figures of the call.');
        self::assertSame('false', $screen->filter('[data-testid="cms-builder-flash-warning"]')->attr('data-cms-toast-permanent-value'), 'A warning without details goes away by itself.');
        self::assertStringContainsString('<h2>Refused</h2>', (string) CmsPageContentQuery::create()->filterByPageId($page->getId())->findOne()?->getDraftHtml(), 'The draft was left as it was.');
    }

    public function testTheHistoryLineSaysWhatTheCallNeeded(): void
    {
        $page = $this->createPage('Details', published: false);
        $unknown = self::document([['id' => 'x', 'component' => 'Cms:Nothing', 'props' => []]]);
        $this->api
            ->answer(FakeAiGateway::response(toolCalls: [new AiToolCall('toolu_1', 'search_pages', ['query' => 'a']), new AiToolCall('toolu_2', 'search_pages', ['query' => 'b'])]))
            ->answer(FakeAiGateway::response($unknown))
            ->answer(FakeAiGateway::response(self::document([['id' => 'content', 'component' => 'cms:richtext', 'props' => ['html' => '<h2>Details</h2>']]])));

        $form = $this->screen($this->url($page->getId()))->filter('[data-testid="cms-content-brief"] form')->form();
        $form['cms_brief[text]'] = 'A page with details.';
        $this->send('POST', $form->getUri(), $form->getPhpValues());

        $line = $this->screen($this->url($page->getId()))->filter('[data-testid="cms-content-history-details"]');

        self::assertSame(1, $line->count());
        self::assertMatchesRegularExpression('/^1 correction\(s\), search_pages ×2, effort \w+$/', trim($line->text()));
    }

    public function testARunningCallAndAnInterruptedOneHaveTheirOwnLabelsInTheHistory(): void
    {
        $page = $this->createPage('Labels', published: false);
        $log = $this->getService(GenerationLog::class);
        $target = GenerationTarget::page($page, $this->locale());
        $request = GenerationRequest::fromBrief($target, $this->locale(), new Brief('A brief.'));
        $log->begin($request, 'prompt');
        $dead = $log->begin($request, 'prompt');
        CmsPageGenerationQuery::create()->filterById($dead)->update(['UpdatedAt' => date('Y-m-d H:i:s', time() - 3600)]);

        $badges = $this->screen($this->url($page->getId()))->filter('[data-testid="cms-content-history"] tbody .badge')->each(static fn (Crawler $badge): string => trim($badge->text()));

        self::assertSame(['Interrupted', 'Running'], $badges, 'The latest call first: the one that died, then the one still running.');
    }

    public function testACutAnswerAskedAgainIsAnEventOfTheStream(): void
    {
        $page = $this->createPage('Cut', published: false);
        $this->api
            ->cutAfter(['{"version"'], new AiProviderUnavailableException('anthropic-main', true, 'overloaded_error'))
            ->answer(FakeAiGateway::response(self::document([['id' => 'content', 'component' => 'cms:richtext', 'props' => ['html' => '<h2>Cut</h2><p>Done.</p>']]])));

        $form = $this->screen($this->url($page->getId()))->filter('[data-testid="cms-content-brief"] form')->form();
        $form['cms_brief[text]'] = 'A page cut once.';

        $this->client->request('POST', $form->getUri(), $form->getPhpValues(), [], [...self::XHR, 'HTTP_ACCEPT' => 'text/event-stream']);
        $events = self::events((string) $this->client->getInternalResponse()->getContent());
        $names = array_values(array_filter(array_column($events, 'event'), static fn (string $name): bool => 'delta' !== $name));

        self::assertSame(['start', 'cut', 'turn', 'done'], $names, 'The screen says the answer was cut and is asked again.');
        self::assertStringContainsString('cut half way', $events[array_search('cut', array_column($events, 'event'), true)]['data']['message']);
    }

    public function testAChangeKeepsTheSectionsItDoesNotConcern(): void
    {
        $page = $this->createPage('Revised', published: false);
        $this->api->answer(FakeAiGateway::response(self::document([['id' => 'content', 'component' => 'cms:richtext', 'props' => ['html' => '<h2>Revised</h2><p>After.</p>']]])));

        $screen = $this->screen($this->url($page->getId()));
        $form = $screen->filter('[data-testid="cms-content-change"] form')->form();
        $form['cms_change[text]'] = 'Say After instead of Written for the test.';

        self::assertSame(302, $this->send('POST', $form->getUri(), $form->getPhpValues()));
        self::assertStringContainsString('<h2>Revised</h2><p>Written for the test.</p>', $this->api->requests[0]->messages[0]->content, 'The document the page holds is sent with the change.');
        self::assertStringContainsString('<p>After.</p>', (string) CmsPageContentQuery::create()->filterByPageId($page->getId())->findOne()?->getDraftHtml());
        self::assertSame('revision', CmsPageGenerationQuery::create()->filterByTargetId($page->getId())->findOne()?->getKind());
    }

    public function testAChangeAddsColumnsOfLogosOverAShortText(): void
    {
        $page = $this->createPage('Partners', published: false);
        $logos = array_map(static fn (int $id): array => ['image' => \sprintf('https://img.logoipsum.com/%d.svg', $id), 'image_alt' => 'Logoipsum', 'text' => 'Lorem ipsum dolor sit amet.'], [243, 245, 247]);
        $this->readingOn();
        $this->api->answer(FakeAiGateway::response(self::document([
            ['id' => 'content', 'component' => 'cms:richtext', 'props' => ['html' => '<h2>Partners</h2><p>Written for the test.</p>']],
            ['id' => 'logos', 'component' => 'cms:columns', 'props' => json_encode(['title' => '', 'columns' => $logos], \JSON_THROW_ON_ERROR)],
        ])));

        $form = $this->screen($this->url($page->getId()))->filter('[data-testid="cms-content-change"] form')->form();
        $form['cms_change[text]'] = 'Ajoute 3 colonnes avec dans chacune un logo venant de https://logoipsum.com/ et un texte court en lorem ipsum sous l’image';

        self::assertSame(302, $this->send('POST', $form->getUri(), $form->getPhpValues()));
        self::assertStringContainsString('`cms:columns`', (string) $this->api->requests[0]->system, 'The model is offered the columns.');
        self::assertSame('read_url', array_column($this->api->requests[0]->tools, 'name')[array_key_last(array_column($this->api->requests[0]->tools, 'name'))], 'The site the change cites can be read for its logos.');

        $html = (string) CmsPageContentQuery::create()->filterByPageId($page->getId())->findOne()?->getDraftHtml();
        self::assertStringContainsString('<section data-cms-section="logos" class="cms-columns cms-columns--3"', $html);
        self::assertSame(3, substr_count($html, 'src="https://img.logoipsum.com/'), 'The logos of elsewhere are kept by the sanitizer.');
    }

    public function testWhatTheDraftLeavesOutOfTheBriefIsReported(): void
    {
        $page = $this->createPage('Tarte aux pommes', published: false);
        $missing = 'Aucune photo de tarte sortant du four : la médiathèque n’en a pas et le brief ne donne pas d’adresse.';
        $this->api->answer(FakeAiGateway::response(json_encode([
            'version' => 1,
            'sections' => [['id' => 'recette', 'component' => 'cms:richtext', 'props' => json_encode(['html' => '<h2>La recette</h2><p>Written for the test.</p>'], \JSON_THROW_ON_ERROR)]],
            'left_out' => [$missing, ' ', '<b>Aucun moule</b> du catalogue.', 42],
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE)));

        $form = $this->screen($this->url($page->getId()))->filter('[data-testid="cms-content-brief"] form')->form();
        $form['cms_brief[text]'] = 'La recette de la tarte aux pommes avec une photo de la tarte sortant du four.';

        self::assertSame(302, $this->send('POST', $form->getUri(), $form->getPhpValues()));
        self::assertSame(json_encode([$missing, 'Aucun moule du catalogue.'], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES), CmsPageGenerationQuery::create()->filterByTargetId($page->getId())->findOne()?->getLeftOut(), 'Sentences only, read as text.');

        $screen = $this->screen((string) $this->client->getResponse()->headers->get('Location'));
        $warning = $screen->filter('[data-testid="cms-builder-flash-warning"]');

        self::assertSame(1, $warning->count(), 'A draft that leaves part of the brief out comes back as a warning.');
        self::assertStringContainsString('The draft is written', $warning->text());
        self::assertStringContainsString($missing, $warning->text());
        self::assertSame('true', $warning->attr('data-cms-toast-permanent-value'), 'A list of what is missing stays until it is closed.');
        self::assertSame(0, $screen->filter('[data-testid="cms-builder-flash-success"]')->count());
        self::assertStringContainsString('2 thing(s) of the brief left out', $screen->filter('[data-testid="cms-content-history-left-out"] summary')->text(), 'The history keeps it once the notice is gone.');
        self::assertStringContainsString('"left_out":{"type":"array"', json_encode($this->api->requests[0]->outputSchema?->schema, \JSON_THROW_ON_ERROR), 'The model is given where to say it.');
    }

    public function testAChangeAddsAPictureWithItsCaption(): void
    {
        $page = $this->createPage('Apple pie', published: false);
        $this->api->answer(FakeAiGateway::response(self::document([
            ['id' => 'content', 'component' => 'cms:richtext', 'props' => ['html' => '<h2>Apple pie</h2><p>Written for the test.</p>']],
            ['id' => 'photo', 'component' => 'cms:image', 'props' => json_encode(['image' => 'https://example.com/tarte.jpg', 'alt' => 'Une tarte aux pommes dorée', 'caption' => 'Tout juste sortie du four', 'width' => 'narrow'], \JSON_THROW_ON_ERROR)],
        ])));

        $form = $this->screen($this->url($page->getId()))->filter('[data-testid="cms-content-change"] form')->form();
        $form['cms_change[text]'] = 'Ajoute la photo https://example.com/tarte.jpg d’une tarte sortant du four';

        self::assertSame(302, $this->send('POST', $form->getUri(), $form->getPhpValues()));
        self::assertStringContainsString('`cms:image`', (string) $this->api->requests[0]->system, 'The model is offered the image section.');

        $html = (string) CmsPageContentQuery::create()->filterByPageId($page->getId())->findOne()?->getDraftHtml();
        self::assertStringContainsString('<figure data-cms-section="photo" class="cms-image cms-image--narrow"', $html);
        self::assertStringContainsString('src="https://example.com/tarte.jpg"', $html, 'The picture of elsewhere is kept by the sanitizer.');
        self::assertStringContainsString('<figcaption class="cms-image__caption">Tout juste sortie du four</figcaption>', $html);
    }

    public function testOneSectionIsWrittenAgainByItsOwnForm(): void
    {
        $page = $this->createPage('Sectioned', published: false);
        $this->api->answer(FakeAiGateway::response(json_encode(['id' => 'content', 'component' => 'cms:richtext', 'props' => ['html' => '<h2>Sectioned</h2><p>Shorter.</p>']], \JSON_THROW_ON_ERROR)));

        $screen = $this->screen($this->url($page->getId()));
        $form = $screen->filter('[data-testid="cms-content-sections"] li[data-section="content"] form[data-cms-generate]')->form();
        $form['cms_change_content[text]'] = 'Shorter.';

        self::assertSame(302, $this->send('POST', $form->getUri(), $form->getPhpValues()));
        self::assertStringContainsString('Write the section `content` again', $this->api->requests[0]->messages[0]->content);
        self::assertCount(1, $this->api->requests, 'The answer, one section, was taken as it came.');
        $content = CmsPageContentQuery::create()->filterByPageId($page->getId())->findOne();
        self::assertStringContainsString('<p>Shorter.</p>', (string) $content?->getDraftHtml());
        self::assertSame('section', CmsPageGenerationQuery::create()->filterByTargetId($page->getId())->findOne()?->getKind());
    }

    public function testASectionRewrittenOnAColouredBackgroundCarriesItsAppearanceToThePage(): void
    {
        $page = $this->createPage('Styled', published: false);
        $this->api
            // A colour named in words is refused and sent back, then written as a value.
            ->answer(FakeAiGateway::response(json_encode(['id' => 'content', 'component' => 'cms:richtext', 'props' => ['html' => '<h2>Cinq produits</h2>'], 'appearance' => ['background' => 'rouge pastel']], \JSON_THROW_ON_ERROR)))
            ->answer(FakeAiGateway::response(json_encode(['id' => 'content', 'component' => 'cms:richtext', 'props' => ['html' => '<h2>Cinq produits</h2>'], 'appearance' => ['background' => '#f8d7d7', 'padding' => 'medium']], \JSON_THROW_ON_ERROR)));

        $screen = $this->screen($this->url($page->getId()));
        $form = $screen->filter('[data-testid="cms-content-sections"] li[data-section="content"] form[data-cms-generate]')->form();
        $form['cms_change_content[text]'] = 'Sur un fond rouge pastel.';

        self::assertSame(302, $this->send('POST', $form->getUri(), $form->getPhpValues()));

        $system = (string) $this->api->requests[0]->system;
        self::assertStringContainsString('The appearance of a section', $system, 'The model is told what it may set.');
        self::assertStringContainsString('#rrggbb', $this->api->requests[1]->messages[2]->content, 'The colour written in words is sent back to be corrected.');

        $content = CmsPageContentQuery::create()->filterByPageId($page->getId())->findOne();
        self::assertSame(['background' => '#f8d7d7', 'padding' => 'medium'], Document::fromJson($content?->getDraftDocument())?->section('content')?->appearance->toArray());
        self::assertStringContainsString('style="background-color:#f8d7d7;padding-block:3rem"', (string) $content?->getDraftHtml(), 'The draft draws the section on its background.');
    }

    public function testAnEmptyBriefIsRefusedBeforeAnyCall(): void
    {
        $page = $this->createPage('Empty brief', published: false);

        $form = $this->screen($this->url($page->getId()))->filter('[data-testid="cms-content-brief"] form')->form();
        $form['cms_brief[text]'] = '   ';

        self::assertSame(302, $this->send('POST', $form->getUri(), $form->getPhpValues()));
        self::assertSame([], $this->api->requests, 'Nothing was sent to the API.');
        self::assertSame(1, $this->screen($this->url($page->getId()))->filter('[data-testid="cms-builder-flash-danger"]')->count());
    }

    public function testPublishingPutsTheDraftOnline(): void
    {
        $page = $this->createPage('Published from the screen', published: false);
        $screen = $this->screen($this->url($page->getId()));
        $form = $screen->filter('[data-testid="cms-content-publish"]')->form();

        self::assertSame(302, $this->send('POST', $form->getUri(), $form->getPhpValues()));
        self::assertStringContainsString('<h2>Published from the screen</h2>', (string) CmsPageContentQuery::create()->filterByPageId($page->getId())->findOne()?->getPublishedHtml());
        self::assertSame(1, $this->screen($this->url($page->getId()))->filter('[data-testid="cms-builder-flash-success"]')->count());
    }

    public function testTheGeneratorIsARightOfItsOwn(): void
    {
        $page = $this->createPage('No right', published: false);
        $this->logInAs((new FixtureFactory($this->getPropelConnection()))->restrictedAdmin([
            CmsResources::PAGE => [AccessManager::VIEW, AccessManager::UPDATE],
        ]));

        $screen = $this->screen($this->url($page->getId()));
        self::assertSame(1, $screen->filter('[data-testid="cms-content-no-right"]')->count());
        self::assertNotNull($screen->filter('[data-testid="cms-content-generate"]')->attr('disabled'), 'The button is off without the right.');
        self::assertSame(0, $screen->filter('[data-testid="cms-content-change"]')->count());

        $token = $this->tokenOfTheScripts($screen);
        self::assertSame(403, $this->send('POST', $this->generateUrl($page->getId()), ['_token' => $token, 'cms_brief' => ['text' => 'Try anyway.']]));
        self::assertSame([], $this->api->requests);

        self::assertSame(302, $this->send('POST', $this->publishUrl($page->getId()), ['_token' => $token]), 'Publishing takes the right to change pages, which this profile has.');
    }

    public function testAPageInTheBinHasNoContentScreen(): void
    {
        $page = $this->createPage('Binned', published: false);
        $page->setDeletedAt(new \DateTimeImmutable())->save();

        self::assertSame(404, $this->send('GET', $this->url($page->getId())));
        self::assertSame(404, $this->send('GET', $this->url(999999999)));
    }

    private function readingOn(): void
    {
        $settings = $this->getService(GeneratorSettings::class);
        $settings->save($settings->effort(), $settings->maxTokens(), webFetch: true);
        ModuleConfigQuery::resetConfigCache();
    }

    /**
     * The language the session holds once the last request is answered, read
     * as the response leaves: the test client keeps no session of its own.
     */
    private function editedLanguageId(): int
    {
        return $this->editedLanguages->id;
    }

    private function url(int $pageId): string
    {
        return \sprintf('/admin/cms/pages/%d/content?edit_language_id=%d', $pageId, Lang::getDefaultLanguage()->getId());
    }

    private function generateUrl(int $pageId): string
    {
        return \sprintf('/admin/cms/pages/%d/content/generate', $pageId);
    }

    private function publishUrl(int $pageId): string
    {
        return \sprintf('/admin/cms/pages/%d/content/publish', $pageId);
    }

    /**
     * @param list<array<string, mixed>> $sections
     */
    private static function document(array $sections): string
    {
        return json_encode(['version' => 1, 'sections' => $sections], \JSON_THROW_ON_ERROR);
    }

    /**
     * @return list<array{event: string, data: array<string, mixed>}>
     */
    private static function events(string $stream): array
    {
        $events = [];

        foreach (array_filter(array_map('trim', explode("\n\n", $stream))) as $chunk) {
            $event = 'message';
            $data = '';

            foreach (explode("\n", $chunk) as $line) {
                if (str_starts_with($line, 'event:')) {
                    $event = trim(substr($line, 6));
                } elseif (str_starts_with($line, 'data:')) {
                    $data .= trim(substr($line, 5));
                }
            }

            $events[] = ['event' => $event, 'data' => json_decode($data, true, 512, \JSON_THROW_ON_ERROR)];
        }

        return $events;
    }
}
