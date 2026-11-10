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

namespace TheliaCMS\Tests\Integration\BackOffice;

use Propel\Runtime\ActiveQuery\Criteria;
use Thelia\Domain\Ai\AiGatewayInterface;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Lang;
use Thelia\Model\LangQuery;
use Thelia\Test\FixtureFactory;
use TheliaCMS\Content\Document;
use TheliaCMS\Content\Section;
use TheliaCMS\Content\Site\SiteComponentRepository;
use TheliaCMS\Generation\Ai\AiUnavailability;
use TheliaCMS\Generation\Component\ComponentGenerator;
use TheliaCMS\Generation\Component\ComponentPrompt;
use TheliaCMS\Generation\PageGenerator;
use TheliaCMS\Generation\Text\TextGenerator;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Model\CmsPageContentQuery;
use TheliaCMS\Page\Admin\CmsPageWriter;
use TheliaCMS\Tests\Integration\Security\AdminScreenTestCase;
use TheliaCMS\Tests\Support\ComponentOutcome;
use TheliaCMS\Tests\Support\FakeAiGateway;
use TheliaCMS\Tests\Unit\Content\Site\PriceTable;

/**
 * The back office when the AI of the shop cannot serve the module: every button, link,
 * tab and panel that calls it is gone, the rest of the screens work as they did,
 * and the routes that call the AI keep answering with their own refusal.
 *
 * The database of the tests has no connection and no model, so the availability is
 * the fake of AdminScreenTestCase: available unless the test turns it off. The
 * routes are the exception, called through the real gateway of the core.
 */
final class AiDegradedModeScreenTest extends AdminScreenTestCase
{
    private const string SETTINGS = '/admin/cms/settings';

    private const string NOTICE = '[data-testid="cms-settings-ai-unavailable"]';

    protected function tearDown(): void
    {
        ConfigQuery::resetCache();

        parent::tearDown();
    }

    public function testTheContentScreenOffersEveryEntryOfThePageWhileTheAiIsAvailable(): void
    {
        $page = $this->createPage('Nos services', published: false);

        $screen = $this->screen($this->contentUrl($page));

        self::assertSame(1, $screen->filter('[data-testid="cms-content-brief-open"]')->count(), 'Writing it again from a brief.');
        self::assertSame(1, $screen->filter('[data-testid="cms-content-brief-dialog"] form[data-cms-generate]')->count());
        self::assertSame(1, $screen->filter('[data-testid="cms-content-change"] [data-testid="cms-content-revise"]')->count(), 'Asking for a change.');
        self::assertGreaterThan(0, $screen->filter('[data-testid^="cms-section-rewrite-open-"]')->count(), 'Rewriting a section.');
        self::assertSame(1, $screen->filter('[data-testid="cms-content-add"] [data-testid="cms-content-prototype-request"]')->count(), 'Prototyping a component.');
    }

    public function testWithoutTheAiTheContentScreenIsTheOneOfAPageWrittenByHand(): void
    {
        $page = $this->createPage('Nos services', published: false);
        $this->ai->turnOff();

        $screen = $this->screen($this->contentUrl($page));

        foreach (['cms-content-brief', 'cms-content-brief-open', 'cms-content-brief-dialog', 'cms-content-change', 'cms-content-revise', 'cms-content-prototype-request'] as $testId) {
            self::assertSame(0, $screen->filter(\sprintf('[data-testid="%s"]', $testId))->count(), $testId);
        }

        self::assertSame(0, $screen->filter('[data-testid^="cms-section-rewrite-open-"]')->count(), 'No section is rewritten.');
        self::assertSame(0, $screen->filter('form[data-cms-generate]')->count(), 'No form of the screen calls the generator.');
        self::assertSame(1, $screen->filter('[data-testid="cms-content-sections"]')->count(), 'The sections are still listed.');
        self::assertGreaterThan(0, $screen->filter('[data-cms-edit-open]')->count(), 'And edited by hand.');
        self::assertSame(1, $screen->filter('[data-testid="cms-content-add"]')->count(), 'A section is still added by hand.');
        self::assertSame(1, $screen->filter('[data-testid="cms-content-publish"]')->count(), 'And the draft published.');
    }

    public function testAnEmptyPageOffersTheBriefOnlyWhileTheAiIsAvailable(): void
    {
        $page = $this->createPage('Vide', published: false);
        CmsPageContentQuery::create()->filterByPageId($page->getId())->delete();

        $screen = $this->screen($this->contentUrl($page));
        self::assertSame(1, $screen->filter('[data-testid="cms-content-brief"] [data-testid="cms-content-generate"]')->count());

        $this->ai->turnOff();
        $screen = $this->screen($this->contentUrl($page));

        self::assertSame(0, $screen->filter('[data-testid="cms-content-brief"]')->count(), 'No brief to write.');
        self::assertSame(0, $screen->filter('[data-testid="cms-content-generate"]')->count());
        self::assertSame(1, $screen->filter('[data-testid="cms-content-empty"]')->count(), 'The empty state stays.');
        self::assertStringNotContainsString('the generator writes a first draft', $screen->filter('[data-testid="cms-content-empty"]')->text(''), 'It does not promise a generator.');
        self::assertSame(1, $screen->filter('[data-testid="cms-content-add-open"]')->count(), 'Adding a section by hand is the way in.');
    }

    public function testAUsageHidesOnlyTheEntriesItServes(): void
    {
        $page = $this->createPage('Nos services', published: false);

        $this->ai->turnOff(AiUnavailability::NoModel, ComponentGenerator::USAGE);
        $screen = $this->screen($this->contentUrl($page));
        self::assertSame(1, $screen->filter('[data-testid="cms-content-brief-open"]')->count(), 'Pages are still written from a brief.');
        self::assertSame(0, $screen->filter('[data-testid="cms-content-prototype-request"]')->count(), 'A component cannot be prototyped.');

        $this->ai->turnOn();
        $this->ai->turnOff(AiUnavailability::UsageOff, PageGenerator::USAGE);
        $screen = $this->screen($this->contentUrl($page));
        self::assertSame(0, $screen->filter('[data-testid="cms-content-brief-open"]')->count());
        self::assertSame(0, $screen->filter('[data-testid="cms-content-revise"]')->count());
        self::assertSame(1, $screen->filter('[data-testid="cms-content-prototype-request"]')->count(), 'Components are still prototyped.');
    }

    public function testAPageNotWrittenInThisLanguageOffersToTranslateItOnlyWhileTheTranslationIsAvailable(): void
    {
        $page = $this->pageWithASource('Nos tarifs');
        $other = $this->otherLanguage();

        self::assertSame(1, $this->screen($this->contentUrl($page, $other))->filter('[data-testid="cms-content-translate"] form')->count());

        $this->ai->turnOff(AiUnavailability::NoModel, PageGenerator::TRANSLATION_USAGE);
        $screen = $this->screen($this->contentUrl($page, $other));

        self::assertSame(0, $screen->filter('[data-testid="cms-content-translate"]')->count());
        self::assertSame(0, $screen->filter('[data-testid="cms-content-translate-btn"]')->count());
        self::assertSame(1, $screen->filter('[data-testid="cms-content-empty"]')->count(), 'The page is empty in this language, to be written by hand.');
        self::assertSame(1, $screen->filter('[data-testid="cms-content-brief"]')->count(), 'The brief is another usage: still offered.');
    }

    public function testATranslationToBeWrittenAgainOffersItOnlyWhileTheTranslationIsAvailable(): void
    {
        $api = new FakeAiGateway();
        static::getContainer()->set(AiGatewayInterface::class, $api);
        $page = $this->pageWithASource('Nos tarifs');
        $other = $this->otherLanguage();

        $api->answer(FakeAiGateway::response(json_encode(['version' => 1, 'sections' => [
            ['id' => 'intro', 'component' => 'cms:richtext', 'props' => ['html' => '<h2>Our prices</h2><p>Advice.</p>']],
        ]], \JSON_THROW_ON_ERROR)));
        $form = $this->screen($this->contentUrl($page, $other))->filter('[data-testid="cms-content-translate"] form')->form();
        self::assertSame(302, $this->send('POST', $form->getUri(), $form->getPhpValues()));
        $this->getService(CmsPageWriter::class)->saveContent($page, $this->locale(), new Document([new Section('intro', 'cms:richtext', ['html' => '<h2>Nos tarifs, revus</h2><p>Du conseil.</p>'])]));

        $screen = $this->screen($this->contentUrl($page, $other));
        self::assertSame(1, $screen->filter('[data-testid="cms-content-translation-stale"]')->count(), 'The source changed since.');
        self::assertSame(1, $screen->filter('[data-testid="cms-content-retranslate"] form')->count());

        $this->ai->turnOff(AiUnavailability::NoModel, PageGenerator::TRANSLATION_USAGE);
        $screen = $this->screen($this->contentUrl($page, $other));

        self::assertSame(1, $screen->filter('[data-testid="cms-content-translation-stale"]')->count(), 'The warning stays: it is what the editor sees.');
        self::assertSame(0, $screen->filter('[data-testid="cms-content-retranslate"]')->count(), 'Writing it again is gone.');
        self::assertSame(1, $screen->filter('[data-testid="cms-content-translation-current"] button')->count(), 'Marking it up to date by hand stays.');
    }

    public function testTheContentScreenOfACatalogueItemFollowsTheSameRule(): void
    {
        $factory = new FixtureFactory($this->getPropelConnection());
        $item = $factory->content($factory->folder(), ['title' => 'Notre méthode', 'locale' => $this->locale()]);
        $url = \sprintf('/admin/cms/catalogue/content/%d/content?edit_language_id=%d', $item->getId(), Lang::getDefaultLanguage()->getId());

        self::assertSame(1, $this->screen($url)->filter('[data-testid="cms-content-brief"]')->count());

        $this->ai->turnOff();
        $screen = $this->screen($url);

        self::assertSame(0, $screen->filter('[data-testid="cms-content-brief"]')->count());
        self::assertSame(0, $screen->filter('form[data-cms-generate]')->count());
        self::assertSame(1, $screen->filter('[data-testid="cms-content-empty"]')->count());
    }

    public function testAPrototypedComponentCannotBeChangedByAskingTheGeneratorWhenTheAiIsOff(): void
    {
        $api = new FakeAiGateway();
        static::getContainer()->set(AiGatewayInterface::class, $api);
        $page = $this->createPage('Nos formules', published: false);
        $api->answer(FakeAiGateway::response(ComponentPrompt::json(PriceTable::component())));
        $generate = \sprintf('/admin/cms/pages/%d/content/component/generate', $page->getId());
        $token = $this->tokenOfTheForm($this->screen($this->contentUrl($page)), $generate);
        self::assertSame(302, $this->send('POST', $generate, ['_token' => $token, 'request' => 'Un tableau de prix.']));
        $candidate = (string) $this->client->getResponse()->headers->get('Location');

        $panel = $this->screen($candidate)->filter('[data-testid="cms-content-prototype"]');
        self::assertSame(1, $panel->filter('[data-testid="cms-content-prototype-change"]')->count());

        $this->ai->turnOff(AiUnavailability::NoModel, ComponentGenerator::USAGE);
        $panel = $this->screen($candidate)->filter('[data-testid="cms-content-prototype"]');

        self::assertSame(0, $panel->filter('[data-testid="cms-content-prototype-change"]')->count(), 'Asking a change is gone.');
        self::assertSame(1, $panel->filter('[data-testid="cms-content-prototype-keep"]')->count(), 'Keeping what was made stays.');
    }

    public function testAPrototypeTheCatalogueAlreadyHasStillSaysSoButOffersNoNewAskWhenTheAiIsOff(): void
    {
        $api = new FakeAiGateway();
        static::getContainer()->set(AiGatewayInterface::class, $api);
        $page = $this->createPage('Nos photos', published: false);
        $api->answer(ComponentOutcome::existing('cms:image', 'cms:image already shows one picture and its caption.'));
        $generate = \sprintf('/admin/cms/pages/%d/content/component/generate', $page->getId());
        $token = $this->tokenOfTheForm($this->screen($this->contentUrl($page)), $generate);
        self::assertSame(302, $this->send('POST', $generate, ['_token' => $token, 'request' => 'Une photo seule.']));
        $candidate = (string) $this->client->getResponse()->headers->get('Location');

        $panel = $this->screen($candidate)->filter('[data-testid="cms-content-prototype"]');
        self::assertSame(1, $panel->filter('[data-testid="cms-content-prototype-existing"]')->count());
        self::assertSame(1, $panel->filter('[data-testid="cms-content-prototype-again"]')->count());

        $this->ai->turnOff(AiUnavailability::NoModel, ComponentGenerator::USAGE);
        $panel = $this->screen($candidate)->filter('[data-testid="cms-content-prototype"]');

        self::assertSame(1, $panel->filter('[data-testid="cms-content-prototype-existing"]')->count(), 'The component that does it is still named.');
        self::assertSame(0, $panel->filter('[data-testid="cms-content-prototype-again"]')->count(), 'Asking again is gone.');
    }

    public function testTheComponentsListOffersNewComponentOnlyWhileTheAiIsAvailable(): void
    {
        self::assertSame(1, $this->screen('/admin/cms/components')->filter('[data-testid="cms-components-new"]')->count());

        $this->ai->turnOff(AiUnavailability::NoModel, ComponentGenerator::USAGE);
        $screen = $this->screen('/admin/cms/components');

        self::assertSame(0, $screen->filter('[data-testid="cms-components-new"]')->count());
        self::assertGreaterThan(0, $screen->filter('table')->count(), 'The list of the components is still there.');
    }

    public function testTheNewComponentScreenSaysItIsUnavailableInsteadOfOfferingARequest(): void
    {
        $this->ai->turnOff(AiUnavailability::Disabled);

        $screen = $this->screen('/admin/cms/components/site/new');

        self::assertSame(0, $screen->filter('[data-testid="cms-site-component-request"]')->count());
        self::assertSame(1, $screen->filter('[data-testid="cms-site-component-unavailable"]')->count());
    }

    public function testAKeptComponentIsChangedByTheGeneratorOnlyWhileTheAiIsAvailable(): void
    {
        $this->getService(SiteComponentRepository::class)->save(PriceTable::component(), SiteComponentRepository::STATUS_READY);
        $url = '/admin/cms/components/site/PriceTable';

        self::assertSame(1, $this->screen($url)->filter('[data-testid="cms-site-component-change"]')->count());

        $this->ai->turnOff(AiUnavailability::NoModel, ComponentGenerator::USAGE);

        self::assertSame(0, $this->screen($url)->filter('[data-testid="cms-site-component-change"]')->count());
    }

    public function testACandidateIsChangedByTheGeneratorOnlyWhileTheAiIsAvailable(): void
    {
        $api = new FakeAiGateway();
        static::getContainer()->set(AiGatewayInterface::class, $api);
        $api->answer(FakeAiGateway::response(ComponentPrompt::json(PriceTable::component())));
        $token = $this->tokenOfTheForm($this->screen('/admin/cms/components/site/new'), '/admin/cms/components/site/generate');
        self::assertSame(302, $this->send('POST', '/admin/cms/components/site/generate', ['_token' => $token, 'request' => 'Un tableau de prix.']));
        $candidate = (string) $this->client->getResponse()->headers->get('Location');

        self::assertSame(1, $this->screen($candidate)->filter('[data-testid="cms-site-component-change"]')->count());

        $this->ai->turnOff(AiUnavailability::NoModel, ComponentGenerator::USAGE);
        $screen = $this->screen($candidate);

        self::assertSame(0, $screen->filter('[data-testid="cms-site-component-change"]')->count());
        self::assertSame(1, $screen->filter('[data-testid="cms-site-component-keep"]')->count(), 'Keeping it stays.');
    }

    public function testTheButtonThatWritesATextFromABriefIsNotAddedToTheScreensOfTheFieldsWhenTheTextUsageIsOff(): void
    {
        $page = $this->createPage('Atelier de céramique');
        $url = \sprintf('/admin/cms/pages/%d', $page->getId());

        self::assertSame(1, $this->screen($url)->filter('script[data-cms-text-brief-config]')->count());

        $this->ai->turnOff(AiUnavailability::NoModel, TextGenerator::USAGE);
        $screen = $this->screen($url);

        self::assertSame(0, $screen->filter('script[data-cms-text-brief-config]')->count());
        self::assertSame(0, $screen->filter('script[src*="rich-text-brief.js"]')->count());

        $category = (new FixtureFactory($this->getPropelConnection()))->category();
        self::assertSame(0, $this->screen(\sprintf('/admin/categories/update?category_id=%d', $category->getId()))->filter('script[data-cms-text-brief-config]')->count(), 'Nor the screens of the catalogue.');
    }

    public function testTheSettingsScreenSaysWhyTheAiIsNotAvailableAndWhereToSetItUp(): void
    {
        self::assertSame(0, $this->screen(self::SETTINGS)->filter(self::NOTICE)->count(), 'Nothing to say while everything works.');

        $this->ai->turnOff(AiUnavailability::Disabled);
        $line = $this->screen(self::SETTINGS)->filter(self::NOTICE);

        self::assertSame(1, $line->count());
        self::assertStringContainsString('turned off for the whole shop', $line->text(''));
        self::assertStringContainsString('AI settings of Thelia', $line->text(''));
        self::assertSame(1, $this->screen(self::SETTINGS)->filter('[data-testid="cms-settings-generator"] '.self::NOTICE)->count(), 'In the card of the generator.');
    }

    public function testTheSettingsLineNamesTheUsagesWithoutAModelAndNoOther(): void
    {
        $this->ai->turnOff(AiUnavailability::NoModel, PageGenerator::USAGE, ComponentGenerator::USAGE);

        $text = $this->screen(self::SETTINGS)->filter(self::NOTICE)->text('');

        self::assertStringContainsString('No connection or model set up for: writing pages from a brief, making components', $text);
        self::assertStringNotContainsString('translating pages', $text);
        self::assertStringNotContainsString('writing texts from a brief', $text);
    }

    public function testOnlyTheSettingsScreenCarriesTheLine(): void
    {
        $this->ai->turnOff();
        $page = $this->createPage('Nos services', published: false);

        foreach (['/admin/cms/pages', $this->contentUrl($page), '/admin/cms/components', '/admin/cms/skills'] as $url) {
            self::assertSame(0, $this->screen($url)->filter(self::NOTICE)->count(), $url);
        }
    }

    /**
     * A route is never guarded by the hiding: asked by a script or a stale tab while the AI
     * is off, it answers with its own message and not with a 500. The core is real
     * here (no fake gateway), and its switch is off.
     */
    public function testTheBriefRouteAnswersTheMessageOfTheGeneratorWhenTheAiIsOff(): void
    {
        $page = $this->createPage('Refusée', published: false);
        $form = $this->screen($this->contentUrl($page))->filter('[data-testid="cms-content-brief-dialog"] form[data-cms-generate]')->form();
        $form['cms_brief[text]'] = 'Une page qui présente nos services.';
        $this->switchTheAiOffInTheCore();

        $this->client->request('POST', $form->getUri(), $form->getPhpValues(), [], [...self::XHR, 'HTTP_ACCEPT' => 'text/event-stream']);
        $events = self::events((string) $this->client->getInternalResponse()->getContent());

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame('error', end($events)['event'] ?? null);
        self::assertStringContainsString('The generator is turned off, or no AI connection and model is set up for it', end($events)['data']['message']);

        self::assertSame(302, $this->send('POST', $form->getUri(), $form->getPhpValues()), 'Without the script, the screen comes back with the outcome.');
    }

    public function testTheTextRouteAnswersWithAnErrorAndNeverA500WhenTheAiIsOff(): void
    {
        $server = [...self::XHR, 'HTTP_X_CSRF_TOKEN' => $this->tokenOfTheScripts($this->screen('/admin/cms/pages'))];
        $this->switchTheAiOffInTheCore();

        $status = $this->send('POST', '/admin/cms/text/generate', [
            'type' => 'page', 'id' => '0', 'locale' => $this->locale(), 'field' => 'summary', 'title' => 'Atelier', 'brief' => 'Deux phrases sur l’atelier.',
        ], $server);

        self::assertSame(503, $status);
        self::assertStringContainsString('The generator is turned off', (string) json_decode((string) $this->client->getResponse()->getContent(), true)['error']);
    }

    public function testTheComponentRouteAnswersWithoutA500WhenTheAiIsOff(): void
    {
        $token = $this->tokenOfTheForm($this->screen('/admin/cms/components/site/new'), '/admin/cms/components/site/generate');
        $this->switchTheAiOffInTheCore();

        self::assertSame(302, $this->send('POST', '/admin/cms/components/site/generate', ['_token' => $token, 'request' => 'Un tableau de prix.']));
        $screen = $this->screen((string) $this->client->getResponse()->headers->get('Location'));

        self::assertStringContainsString('The generator is turned off', $screen->text(''), 'The screen it comes back to says why nothing was made.');
    }

    public function testTheTranslationRouteAnswersWithoutA500WhenTheAiIsOff(): void
    {
        $page = $this->pageWithASource('Nos tarifs');
        $other = $this->otherLanguage();
        $form = $this->screen($this->contentUrl($page, $other))->filter('[data-testid="cms-content-translate"] form')->form();
        $this->switchTheAiOffInTheCore();

        self::assertSame(302, $this->send('POST', $form->getUri(), $form->getPhpValues()));
        self::assertSame(1, $this->screen($this->contentUrl($page, $other))->filter('[data-testid="cms-content-brief"]')->count(), 'The screen it comes back to opens.');
    }

    private function switchTheAiOffInTheCore(): void
    {
        ConfigQuery::write('ai_enabled', '0');
        ConfigQuery::resetCache();
    }

    private function contentUrl(CmsPage $page, ?Lang $lang = null): string
    {
        return \sprintf('/admin/cms/pages/%d/content?edit_language_id=%d', $page->getId(), ($lang ?? Lang::getDefaultLanguage())->getId());
    }

    private function pageWithASource(string $title): CmsPage
    {
        $page = $this->createPage($title, published: false);
        $this->getService(CmsPageWriter::class)->saveContent($page, $this->locale(), new Document([
            new Section('intro', 'cms:richtext', ['html' => \sprintf('<h2>%s</h2><p>Du conseil.</p>', $title)]),
        ]));

        return $page;
    }

    private function otherLanguage(): Lang
    {
        $other = LangQuery::create()->filterByActive(true)->filterById(Lang::getDefaultLanguage()->getId(), Criteria::NOT_EQUAL)->orderById()->findOne();
        self::assertNotNull($other, 'The shop of the tests has a second active language.');

        return $other;
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
