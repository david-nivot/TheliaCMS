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

namespace TheliaCMS\Tests\Integration\Generation\Text;

use Symfony\Component\DomCrawler\Crawler;
use Thelia\Core\Security\AccessManager;
use Thelia\Domain\Ai\AiFinishReason;
use Thelia\Domain\Ai\AiGatewayInterface;
use Thelia\Test\FixtureFactory;
use TheliaCMS\Generation\GenerationRequest;
use TheliaCMS\Generation\GeneratorSettings;
use TheliaCMS\Model\CmsPageGenerationQuery;
use TheliaCMS\Security\CmsResources;
use TheliaCMS\Tests\Integration\Security\AdminScreenTestCase;
use TheliaCMS\Tests\Support\FakeAiGateway;

/**
 * "Write from a brief" beside the rich-text fields: the screens that offer it,
 * and the route that writes the text without saving it.
 */
final class TextBriefScreenTest extends AdminScreenTestCase
{
    private const string ROUTE = '/admin/cms/text/generate';

    private const string OUTCOME = '/admin/cms/text/outcome';

    private FakeAiGateway $api;

    protected function setUp(): void
    {
        parent::setUp();

        $this->api = new FakeAiGateway();
        static::getContainer()->set(AiGatewayInterface::class, $this->api);
    }

    public function testTheSettingsOfACmsPageOfferTheButtonForItsSummaryAndDescription(): void
    {
        $page = $this->createPage('Atelier de céramique');

        $config = $this->configOf($this->screen(\sprintf('/admin/cms/pages/%d', $page->getId())));

        self::assertSame('page', $config['type']);
        self::assertSame((int) $page->getId(), $config['id'], 'The id of the page is the one of the screen.');
        self::assertSame($this->locale(), $config['locale']);
        self::assertSame('cms_page[title]', $config['title']);
        self::assertSame([['name' => 'cms_page[chapo]', 'kind' => 'summary'], ['name' => 'cms_page[description]', 'kind' => 'description']], $config['fields']);
        self::assertSame(self::ROUTE, parse_url((string) $config['url'], \PHP_URL_PATH));
    }

    public function testACategoryOffersTheButtonForItsThreeTexts(): void
    {
        $category = (new FixtureFactory($this->getPropelConnection()))->category();

        $config = $this->configOf($this->screen(\sprintf('/admin/categories/update?category_id=%d', $category->getId())));

        self::assertSame('category', $config['type']);
        self::assertSame('thelia_category_modification[id]', $config['idField'], 'The id and the language are read from the form.');
        self::assertSame('thelia_category_modification[locale]', $config['localeField']);
        self::assertSame(['summary', 'description', 'conclusion'], array_column($config['fields'], 'kind'));
    }

    public function testWithoutTheRightToGenerateNoScreenOffersTheButton(): void
    {
        $page = $this->createPage('Atelier sans droit');
        $this->logInAs((new FixtureFactory($this->getPropelConnection()))->restrictedAdmin([
            CmsResources::PAGE => [AccessManager::VIEW, AccessManager::UPDATE],
        ]));

        $screen = $this->screen(\sprintf('/admin/cms/pages/%d', $page->getId()));

        self::assertSame(0, $screen->filter('script[data-cms-text-brief-config]')->count());
        self::assertSame(403, $this->send('POST', self::ROUTE, $this->parameters(), $this->withToken($screen)));
        self::assertSame([], $this->api->requests, 'The generator is not called.');
    }

    public function testTheRouteAnswersTheTextAndLogsTheCallWithoutSavingAnything(): void
    {
        $page = $this->createPage('Atelier de tournage');
        $this->api->answer(FakeAiGateway::response('{"html":"<p>Un atelier <strong>ouvert</strong> le samedi.</p>"}'));

        $screen = $this->screen(\sprintf('/admin/cms/pages/%d', $page->getId()));
        $status = $this->send('POST', self::ROUTE, $this->parameters(['id' => (string) $page->getId()]), $this->withToken($screen));
        $answer = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertSame(200, $status);
        self::assertSame('<p>Un atelier <strong>ouvert</strong> le samedi.</p>', $answer['html']);
        self::assertMatchesRegularExpression('/^\d+\.\d{3}$/', $answer['cost']);

        $row = CmsPageGenerationQuery::create()->filterByTargetType('page')->filterByTargetId($page->getId())->filterByKind(GenerationRequest::KIND_TEXT)->findOne();
        self::assertNotNull($row, 'The call is in the ledger of the page.');

        $page->setLocale($this->locale());
        self::assertSame('', (string) $page->getChapo(), 'Nothing is saved: the field takes the text when the form is saved.');
    }

    public function testARefusalOfTheModelAndACutAnswerAreAnsweredWithTheirStatus(): void
    {
        $server = $this->withToken($this->screen('/admin/cms/pages'));

        $this->api->answer(FakeAiGateway::response(finishReason: AiFinishReason::ContentFilter));
        self::assertSame(422, $this->send('POST', self::ROUTE, $this->parameters(), $server), 'The model declined: the editor is told, nothing is retried.');

        $this->api->answer(FakeAiGateway::response('{"html":"<p>Un atel', finishReason: AiFinishReason::MaxTokens, outputTokens: $this->getService(GeneratorSettings::class)->textMaxTokens()));
        self::assertSame(502, $this->send('POST', self::ROUTE, $this->parameters(), $server), 'The answer was cut by the limit.');
        self::assertStringContainsString('cut at', (string) json_decode((string) $this->client->getResponse()->getContent(), true)['error']);
    }

    /**
     * The server writes the text to the end even when the browser stopped
     * waiting: the answer is read back by the id of the call, by the
     * administrator who asked only, instead of being written and billed again.
     */
    public function testAnAnswerIsReadBackByTheIdOfTheCall(): void
    {
        $this->api->answer(FakeAiGateway::response('{"html":"<p>Un atelier ouvert le dimanche.</p>"}'));
        $screen = $this->screen('/admin/cms/pages');
        $server = $this->withToken($screen);
        $id = 'b1a2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d';

        self::assertSame(404, $this->send('POST', self::OUTCOME, ['request' => $id], $server), 'Nothing is kept before the call.');

        $this->send('POST', self::ROUTE, $this->parameters(['request' => $id]), $server);

        self::assertSame(200, $this->send('POST', self::OUTCOME, ['request' => $id], $server));
        self::assertSame('<p>Un atelier ouvert le dimanche.</p>', json_decode((string) $this->client->getResponse()->getContent(), true)['html']);
        self::assertCount(1, $this->api->requests, 'Reading the answer back calls the generator no second time.');

        self::assertSame(404, $this->send('POST', self::OUTCOME, ['request' => 'f0e1d2c3-b4a5-4968-8776-655443322110'], $server), 'Another id has nothing.');

        $this->logInAs((new FixtureFactory($this->getPropelConnection()))->restrictedAdmin([
            CmsResources::PAGE => [AccessManager::VIEW],
            CmsResources::GENERATE => [AccessManager::VIEW, AccessManager::UPDATE],
        ]));
        $other = $this->withToken($this->screen('/admin/cms/pages'));

        self::assertSame(404, $this->send('POST', self::OUTCOME, ['request' => $id], $other), 'Another administrator cannot read it.');
    }

    public function testAnErrorIsReadBackAsItWasAnswered(): void
    {
        $server = $this->withToken($this->screen('/admin/cms/pages'));
        $id = 'c1a2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d';

        $this->send('POST', self::ROUTE, $this->parameters(['brief' => ' ', 'request' => $id]), $server);

        self::assertSame(422, $this->send('POST', self::OUTCOME, ['request' => $id], $server), 'The screen stops looking: the call is over.');
    }

    public function testWhatTheButtonCannotServeIsRefusedBeforeTheGenerator(): void
    {
        $screen = $this->screen('/admin/cms/pages');
        $server = $this->withToken($screen);

        self::assertSame(400, $this->send('POST', self::ROUTE, $this->parameters(['type' => 'order']), $server), 'An object the button does not serve.');
        self::assertSame(400, $this->send('POST', self::ROUTE, $this->parameters(['field' => 'meta_description']), $server), 'A field it does not serve.');
        self::assertSame(400, $this->send('POST', self::ROUTE, $this->parameters(['locale' => 'xx_XX']), $server), 'A language the shop does not have.');
        self::assertSame(422, $this->send('POST', self::ROUTE, $this->parameters(['brief' => '  ']), $server), 'An empty brief.');
        self::assertSame([], $this->api->requests);
    }

    /**
     * @return array<string, mixed>
     */
    private function configOf(Crawler $screen): array
    {
        $node = $screen->filter('script[data-cms-text-brief-config]');

        self::assertSame(1, $node->count(), 'The screen carries the config of the button.');
        self::assertSame(1, $screen->filter('script[src*="rich-text-brief.js"]')->count(), 'And its script.');

        $config = json_decode($node->text(''), true);
        self::assertIsArray($config);

        return $config;
    }

    /**
     * @param array<string, string> $overrides
     *
     * @return array<string, string>
     */
    private function parameters(array $overrides = []): array
    {
        return $overrides + [
            'type' => 'page',
            'id' => '0',
            'locale' => $this->locale(),
            'field' => 'summary',
            'title' => 'Atelier',
            'brief' => 'Deux phrases sur l’atelier.',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function withToken(Crawler $screen): array
    {
        return [...self::XHR, 'HTTP_X_CSRF_TOKEN' => $this->tokenOfTheScripts($screen)];
    }
}
