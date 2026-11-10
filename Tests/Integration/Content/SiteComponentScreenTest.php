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

use Symfony\Component\DomCrawler\Crawler;
use Thelia\Core\Security\AccessManager;
use Thelia\Domain\Ai\AiFinishReason;
use Thelia\Domain\Ai\AiGatewayInterface;
use Thelia\Test\FixtureFactory;
use TheliaCMS\Content\Document;
use TheliaCMS\Content\Section;
use TheliaCMS\Content\SectionCatalog;
use TheliaCMS\Content\Site\SiteComponent;
use TheliaCMS\Content\Site\SiteComponentRepository;
use TheliaCMS\Content\Site\SiteComponentValidator;
use TheliaCMS\Generation\Component\ComponentPrompt;
use TheliaCMS\Model\CmsComponentQuery;
use TheliaCMS\Model\CmsPageContentQuery;
use TheliaCMS\Model\CmsPageGenerationQuery;
use TheliaCMS\Page\Admin\CmsPageWriter;
use TheliaCMS\Security\CmsResources;
use TheliaCMS\Tests\Integration\Security\AdminScreenTestCase;
use TheliaCMS\Tests\Support\ComponentOutcome;
use TheliaCMS\Tests\Support\FakeAiGateway;
use TheliaCMS\Tests\Unit\Content\Site\PriceTable;

/**
 * A component made in the back office: asked for, looked at in the theme,
 * changed, kept, then a component like the others on the list.
 */
final class SiteComponentScreenTest extends AdminScreenTestCase
{
    private const string NEW = '/admin/cms/components/site/new';

    private const string GENERATE = '/admin/cms/components/site/generate';

    private FakeAiGateway $api;

    protected function setUp(): void
    {
        parent::setUp();

        $this->api = new FakeAiGateway();
        static::getContainer()->set(AiGatewayInterface::class, $this->api);
    }

    public function testARequestEndsOnACandidateDrawnInTheThemeAndNothingIsKeptYet(): void
    {
        $this->api->answer(self::answer(PriceTable::component(css: '.plan--featured { border: 2px solid var(--cms-color-accent); }')));

        $location = $this->ask('Un tableau de prix de deux à quatre formules.');
        self::assertMatchesRegularExpression('#/admin/cms/components/site/candidates/\d+$#', $location);

        $screen = $this->screen($location);
        self::assertSame('done', $screen->filter('[data-testid="cms-site-component-outcome"]')->attr('data-status'));
        self::assertSame(1, $screen->filter('[data-testid="cms-site-component-keep"]')->count());
        self::assertSame(3, $screen->filter('[data-testid="cms-site-component-props"] tbody tr')->count(), 'Every prop is listed: title, plans, tone.');
        self::assertNull(CmsComponentQuery::create()->findOneByCode('PriceTable'), 'Nothing is kept before the editor says so.');

        $preview = (string) $screen->filter('[data-testid="cms-site-component-frame"]')->attr('src');
        self::assertMatchesRegularExpression('#^/cms-component-preview/candidate-\d+/[a-z]{2}_[A-Z]{2}/\d+/[a-f0-9]{64}$#', $preview);

        $this->client->request('GET', $preview);
        $page = (string) $this->client->getResponse()->getContent();
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('class="cms-site cms-site--price-table"', $page, 'The sample is drawn in the theme.');
        self::assertStringContainsString('.cms-site--price-table .plan--featured', $page, 'Its stylesheet is printed scoped.');
        self::assertSame('noindex, nofollow', $this->client->getResponse()->headers->get('X-Robots-Tag'));
        self::assertSame('no-referrer', $this->client->getResponse()->headers->get('Referrer-Policy'), 'The signed address does not leak through the Referer.');

        $alone = (string) $screen->filter('[data-testid="cms-site-component-preview-modes"] input[value="content"]')->attr('data-url');
        self::assertSame($preview.'?content=1', $alone, 'The preview offers the component alone, as the content screen does.');
        $this->client->request('GET', $alone);
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('class="cms-site cms-site--price-table"', $content);
        self::assertSame(1, (new Crawler($page))->filter('h1')->count(), 'The whole page draws the name of the component as its title.');
        self::assertSame(0, (new Crawler($content))->filter('h1')->count(), 'The component alone does not.');

        $this->client->request('GET', substr($preview, 0, -1).('0' === substr($preview, -1) ? '1' : '0'));
        self::assertSame(404, $this->client->getResponse()->getStatusCode(), 'A preview answers its signature only.');
    }

    public function testAChangeOfACandidateThenKeepingItMakesAComponentOfTheSite(): void
    {
        $this->api
            ->answer(self::answer(PriceTable::component()))
            ->answer(self::answer(PriceTable::component(css: '.plan { padding: 1rem; }')));

        $first = $this->ask('Un tableau de prix.');
        $second = $this->ask('Ajoute de l’espace dans chaque formule.', ['candidate' => self::idOf($first)], $first);

        self::assertStringContainsString('"code": "PriceTable"', $this->api->requests[1]->messages[0]->content, 'The change starts from the candidate.');

        $keep = $this->screen($second)->filter('[data-testid="cms-site-component-keep"]')->form();
        self::assertSame(302, $this->send('POST', $keep->getUri(), $keep->getPhpValues()));
        self::assertStringEndsWith('/admin/cms/components/site/PriceTable', (string) $this->client->getResponse()->headers->get('Location'));

        $kept = $this->getService(SiteComponentRepository::class)->find('PriceTable');
        self::assertSame('.plan { padding: 1rem; }', $kept?->css);
        self::assertSame(SiteComponentRepository::STATUS_READY, $this->getService(SiteComponentRepository::class)->status('PriceTable'));
        $id = (int) CmsComponentQuery::create()->findOneByCode('PriceTable')?->getId();
        self::assertSame($id, (int) CmsPageGenerationQuery::create()->findPk(self::idOf($second))?->getTargetId(), 'The call it came from starts its history.');

        $screen = $this->screen('/admin/cms/components/site/PriceTable');
        self::assertSame(1, $screen->filter('[data-testid="cms-site-component-history"] li a')->count());
        self::assertMatchesRegularExpression('#^/cms-component-preview/component-PriceTable/#', (string) $screen->filter('[data-testid="cms-site-component-frame"]')->attr('src'));

        $list = $this->screen('/admin/cms/components');
        self::assertStringContainsString('Made on this site', $list->filter('[data-testid="cms-component-origin-Site:PriceTable"]')->text());
        self::assertSame(1, $list->filter('[data-testid="cms-component-open-Site:PriceTable"]')->count());

        $this->client->request('GET', '/cms/site-components.css');
        self::assertStringContainsString('.cms-site--price-table .plan {padding: 1rem;}', (string) $this->client->getResponse()->getContent(), 'The site serves the stylesheet of what it kept, scoped.');
    }

    public function testAChangeOfAKeptComponentReplacesItOnceKept(): void
    {
        $repository = $this->getService(SiteComponentRepository::class);
        $repository->save(PriceTable::component(), SiteComponentRepository::STATUS_READY);
        $id = (int) CmsComponentQuery::create()->findOneByCode('PriceTable')?->getId();
        $this->api->answer(self::answer(PriceTable::component(css: '.plan { color: var(--cms-color-text); }')));

        $candidate = $this->ask('Change la couleur du texte.', ['component' => 'PriceTable'], '/admin/cms/components/site/PriceTable');

        self::assertSame($id, (int) CmsPageGenerationQuery::create()->findPk(self::idOf($candidate))?->getTargetId());
        self::assertSame('', $repository->find('PriceTable')?->css, 'The kept version stays until the new one is kept.');

        $keep = $this->screen($candidate)->filter('[data-testid="cms-site-component-keep"]')->form();
        self::assertSame(302, $this->send('POST', $keep->getUri(), $keep->getPhpValues()));
        self::assertSame('.plan { color: var(--cms-color-text); }', $repository->find('PriceTable')?->css);
        self::assertSame(1, CmsComponentQuery::create()->count(), 'The component is replaced, not doubled.');
    }

    public function testAVersionThatBreaksAPageIsNotKeptAndOneThatFitsDrawsTheDraftsAgain(): void
    {
        $repository = $this->getService(SiteComponentRepository::class);
        $repository->save(PriceTable::component(), SiteComponentRepository::STATUS_READY);
        // A candidate written before a page used the component: the check
        // runs again when it is kept, on what the pages hold then.
        $this->api->answer(self::answer(PriceTable::renamingThePlanName()))->answer(self::answer(PriceTable::component(str_replace('class="price-table ', 'class="price-table price-table--v2 ', PriceTable::TEMPLATE))));
        $breaking = $this->ask('Renomme le nom des formules en titre.', ['component' => 'PriceTable'], '/admin/cms/components/site/PriceTable');
        $fitting = $this->ask('Ajoute un commentaire.', ['component' => 'PriceTable'], '/admin/cms/components/site/PriceTable');

        $page = $this->createPage('Nos tarifs', published: false);
        $writer = $this->getService(CmsPageWriter::class);
        $writer->saveContent($page, $this->locale(), new Document([new Section('plans', 'Site:PriceTable', ['plans' => [['name' => 'Studio', 'price' => '40 €']]])]));

        $keep = $this->screen($breaking)->filter('[data-testid="cms-site-component-keep"]')->form();
        self::assertSame(302, $this->send('POST', $keep->getUri(), $keep->getPhpValues()));
        self::assertStringEndsWith('/candidates/'.self::idOf($breaking), (string) $this->client->getResponse()->headers->get('Location'), 'The editor stays on the candidate.');
        self::assertStringContainsString('"Nos tarifs"', $this->screen($breaking)->text(), 'The page it would break is named.');
        self::assertSame(PriceTable::TEMPLATE, $repository->find('PriceTable')?->template, 'The kept version stays.');

        $keep = $this->screen($fitting)->filter('[data-testid="cms-site-component-keep"]')->form();
        self::assertSame(302, $this->send('POST', $keep->getUri(), $keep->getPhpValues()));
        self::assertStringContainsString('price-table--v2', (string) CmsPageContentQuery::create()->filterByPageId($page->getId())->findOne()?->getDraftHtml(), 'The draft is drawn again with the version kept.');
    }

    public function testAComponentIsDeletedOnlyOnceNoPageHoldsIt(): void
    {
        $repository = $this->getService(SiteComponentRepository::class);
        $repository->save(PriceTable::component(), SiteComponentRepository::STATUS_READY);
        $page = $this->createPage('Nos tarifs', published: false);
        $writer = $this->getService(CmsPageWriter::class);
        $writer->saveContent($page, $this->locale(), new Document([new Section('plans', 'Site:PriceTable', ['plans' => [['name' => 'Studio', 'price' => '40 €']]])]));

        $screen = $this->screen('/admin/cms/components/site/PriceTable');
        self::assertSame(1, $screen->filter('[data-testid="cms-site-component-in-use"]')->count());
        self::assertSame(0, $screen->filter('[data-testid="cms-site-component-delete"]')->count());

        $token = $this->tokenOfTheScripts($screen);
        self::assertSame(302, $this->send('POST', '/admin/cms/components/site/PriceTable/delete', ['_token' => $token]));
        self::assertNotNull($repository->find('PriceTable'), 'A component a page holds stays.');

        $writer->saveContent($page, $this->locale(), new Document([new Section('intro', 'cms:richtext', ['html' => '<h2>Tarifs</h2>'])]));
        $delete = $this->screen('/admin/cms/components/site/PriceTable')->filter('[data-testid="cms-site-component-delete"]')->form();
        self::assertSame(302, $this->send('POST', $delete->getUri(), $delete->getPhpValues()));
        self::assertNull($repository->find('PriceTable'));
        self::assertStringEndsWith('/admin/cms/components', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testACopyIsAPrototypeHoldingWhatItsSourceHolds(): void
    {
        $repository = $this->getService(SiteComponentRepository::class);
        // Kept the way a component is kept: through the validator, which fills
        // the example with the defaults of its props.
        $source = (new SiteComponentValidator())->validate(PriceTable::component(css: '.plan { color: var(--cms-color-text); }')->withProvenance('{"generator":"thelia-ai"}'));
        $repository->save($source, SiteComponentRepository::STATUS_READY);

        $form = $this->screen('/admin/cms/components/site/PriceTable')->filter('form[data-testid="cms-site-component-copy"]')->form();
        self::assertSame($source->label, $form['label']->getValue(), 'The label of the source is the one to start from.');
        $form['code'] = 'PriceTableDark';
        $form['label'] = 'Tarifs, version sombre';

        self::assertSame(302, $this->send('POST', $form->getUri(), $form->getPhpValues()));
        self::assertStringEndsWith('/admin/cms/components/site/PriceTableDark', (string) $this->client->getResponse()->headers->get('Location'));

        $copy = $repository->find('PriceTableDark');
        self::assertNotNull($copy);
        self::assertSame('Tarifs, version sombre', $copy->label);
        self::assertSame([$source->description, $source->props, $source->sample, $source->template, $source->css], [$copy->description, $copy->props, $copy->sample, $copy->template, $copy->css]);
        self::assertSame('{"generator":"thelia-ai"}', $copy->provenance, 'A copy of a generated component is still generated content.');
        self::assertSame(SiteComponentRepository::STATUS_DRAFT, $repository->status('PriceTableDark'), 'A prototype: the generator is not offered two identical components.');
        self::assertSame(SiteComponentRepository::STATUS_READY, $repository->status('PriceTable'), 'The source is left as it is.');

        $screen = $this->screen('/admin/cms/components/site/PriceTableDark');
        self::assertSame(1, $screen->filter('[data-testid="bo-flash-success"]')->count());
        self::assertSame(1, $screen->filter('[data-testid="cms-site-component-prototype"]')->count());
        self::assertArrayNotHasKey('Site:PriceTableDark', $this->getService(SectionCatalog::class)->offered(), 'Offered to nobody until it is kept for the whole site.');
    }

    public function testACopyUnderACodeTakenOrMalformedLeavesNothingBehind(): void
    {
        $repository = $this->getService(SiteComponentRepository::class);
        $repository->save(PriceTable::component(), SiteComponentRepository::STATUS_READY);
        $form = $this->screen('/admin/cms/components/site/PriceTable')->filter('form[data-testid="cms-site-component-copy"]')->form();

        foreach (['PriceTable', 'price-table', ''] as $code) {
            $form['code'] = $code;
            self::assertSame(302, $this->send('POST', $form->getUri(), $form->getPhpValues()), $code);
            self::assertStringEndsWith('/admin/cms/components/site/PriceTable', (string) $this->client->getResponse()->headers->get('Location'), 'Back on the source.');
            self::assertSame(1, $this->screen('/admin/cms/components/site/PriceTable')->filter('[data-testid="bo-flash-danger"]')->count(), \sprintf('"%s" is refused, and the editor is told why.', $code));
        }

        self::assertSame(1, CmsComponentQuery::create()->count(), 'Nothing was saved.');
    }

    public function testCopyingAComponentTakesTheRightToMakeOne(): void
    {
        $this->getService(SiteComponentRepository::class)->save(PriceTable::component(), SiteComponentRepository::STATUS_READY);
        $token = $this->tokenOfTheScripts($this->screen('/admin/cms/components/site/PriceTable'));

        $this->logInAs((new FixtureFactory($this->getPropelConnection()))->restrictedAdmin([
            CmsResources::SETTINGS => [AccessManager::VIEW, AccessManager::UPDATE],
        ]));

        self::assertSame(0, $this->screen('/admin/cms/components/site/PriceTable')->filter('form[data-testid="cms-site-component-copy"]')->count());
        self::assertSame(403, $this->send('POST', '/admin/cms/components/site/PriceTable/copy', ['_token' => $token, 'code' => 'PriceTableDark', 'label' => 'Sombre']));
        self::assertSame(1, CmsComponentQuery::create()->count());
    }

    public function testAnExistingComponentIsSaidWithItsReasonAndALinkAndHoldsNoCandidate(): void
    {
        $this->getService(SiteComponentRepository::class)->save(PriceTable::component(), SiteComponentRepository::STATUS_READY);
        $this->getService(SectionCatalog::class)->reset();
        $this->api->answer(ComponentOutcome::existing('Site:PriceTable', 'it already shows plans with their prices.'));

        $screen = $this->screen($this->ask('Un tableau de tarifs.'));

        $existing = $screen->filter('[data-testid="cms-site-component-existing"]');
        self::assertSame(1, $existing->count());
        self::assertStringContainsString('Site:PriceTable', $existing->text());
        self::assertStringContainsString('it already shows plans with their prices.', $existing->text());
        self::assertSame('/admin/cms/components/site/PriceTable', $existing->filter('a')->attr('href'), 'A component of the site has its screen.');
        self::assertSame('done', $screen->filter('[data-testid="cms-site-component-outcome"]')->attr('data-status'));
        self::assertSame(0, $screen->filter('[data-testid="cms-site-component-keep"]')->count(), 'No candidate: nothing to keep.');
        self::assertSame(0, $screen->filter('[data-testid="cms-site-component-frame"]')->count(), 'Nothing to preview.');
        self::assertSame(1, $screen->filter('[data-testid="cms-site-component-request"]')->count(), 'The editor may say what differs.');
        self::assertSame(1, CmsComponentQuery::create()->count());
    }

    public function testAnExistingComponentOfTheCatalogueWithoutAScreenIsNamedWithoutALink(): void
    {
        $this->api->answer(ComponentOutcome::existing('cms:richtext', 'it already holds free text.'));

        $existing = $this->screen($this->ask('Un paragraphe de texte.'))->filter('[data-testid="cms-site-component-existing"]');

        self::assertStringContainsString('cms:richtext', $existing->text());
        self::assertSame(0, $existing->filter('a')->count());
    }

    public function testACopyEndsOnTheNormalCandidateSayingWhatItIsBasedOn(): void
    {
        $this->getService(SiteComponentRepository::class)->save(PriceTable::component(), SiteComponentRepository::STATUS_READY);
        $this->getService(SectionCatalog::class)->reset();
        $variant = PriceTable::component();
        $variant = new SiteComponent('PriceGrid', $variant->label, $variant->description, $variant->props, $variant->sample, $variant->template);
        $this->api
            ->answer(ComponentOutcome::copy('Site:PriceTable', 'a darker look.'))
            ->answer(self::answer($variant));

        $screen = $this->screen($this->ask('Le tableau de prix, en sombre.'));

        self::assertSame(1, $screen->filter('[data-testid="cms-site-component-keep"]')->count(), 'A candidate like any other.');
        self::assertSame(0, $screen->filter('[data-testid="cms-site-component-existing"]')->count());
        $basedOn = $screen->filter('[data-testid="cms-site-component-based-on"]');
        self::assertStringContainsString('Site:PriceTable', $basedOn->text());
        self::assertStringContainsString('a darker look.', $basedOn->text());
        self::assertSame('/admin/cms/components/site/PriceTable', $basedOn->filter('a')->attr('href'));
    }

    public function testTheScriptOfTheScreenIsToldTheSiteAlreadyHasTheComponent(): void
    {
        $this->getService(SiteComponentRepository::class)->save(PriceTable::component(), SiteComponentRepository::STATUS_READY);
        $this->getService(SectionCatalog::class)->reset();
        $this->api->answer(ComponentOutcome::existing('Site:PriceTable', 'it already shows plans.'));
        $token = $this->tokenOfTheForm($this->screen(self::NEW), self::GENERATE);

        $this->client->request('POST', self::GENERATE, ['_token' => $token, 'request' => 'Un tableau de tarifs.'], [], [...self::XHR, 'HTTP_ACCEPT' => 'text/event-stream']);
        $events = array_values(array_filter(self::events((string) $this->client->getInternalResponse()->getContent()), static fn (array $event): bool => 'delta' !== $event['event']));

        self::assertSame(['start', 'turn', 'done'], array_column($events, 'event'));
        self::assertStringContainsString('already has a component', $events[2]['data']['message']);
        self::assertMatchesRegularExpression('#/admin/cms/components/site/candidates/\d+$#', $events[2]['data']['redirect']);
    }

    public function testTheScriptOfTheScreenFollowsTheCallAsItRunsThenGoesToTheCandidate(): void
    {
        $this->api
            ->streamed([str_repeat('x', 400)], self::answer(PriceTable::component('<section><h2>Nos formules</h2>{{ props.title }}</section>')))
            ->streamed([str_repeat('x', 10)], self::answer(PriceTable::component()));
        $screen = $this->screen(self::NEW);
        self::assertStringContainsString('component-generator.js', $screen->html(), 'The screen loads the script that follows the call.');
        $token = $this->tokenOfTheForm($screen, self::GENERATE);

        $this->client->request('POST', self::GENERATE, ['_token' => $token, 'request' => 'Un tableau de prix.'], [], [...self::XHR, 'HTTP_ACCEPT' => 'text/event-stream']);
        $all = self::events((string) $this->client->getInternalResponse()->getContent());
        // The characters written so far come as `delta` events between the steps.
        $events = array_values(array_filter($all, static fn (array $event): bool => 'delta' !== $event['event']));

        self::assertSame(['start', 'turn', 'correction', 'turn', 'done'], array_column($events, 'event'));
        self::assertContains('delta', array_column($all, 'event'), 'The screen sees the model write.');
        self::assertStringContainsString('component', $events[2]['data']['message'], 'The refusal speaks of a component, not of a document.');
        self::assertMatchesRegularExpression('#/admin/cms/components/site/candidates/\d+$#', $events[4]['data']['redirect']);
    }

    public function testACallThatFailsIsShownWithWhatWentWrong(): void
    {
        $this->api->answer(FakeAiGateway::response(finishReason: AiFinishReason::ContentFilter));

        $screen = $this->screen($this->ask('Something the model declines.'));

        self::assertSame('refused', $screen->filter('[data-testid="cms-site-component-outcome"]')->attr('data-status'));
        self::assertSame(0, $screen->filter('[data-testid="cms-site-component-keep"]')->count());
        self::assertSame(1, $screen->filter('[data-testid="cms-site-component-request"]')->count(), 'The editor may ask again.');
    }

    public function testSeeingTheComponentsIsNotTheRightToMakeOne(): void
    {
        $token = $this->tokenOfTheForm($this->screen(self::NEW), self::GENERATE);

        $this->logInAs((new FixtureFactory($this->getPropelConnection()))->restrictedAdmin([
            CmsResources::SETTINGS => [AccessManager::VIEW, AccessManager::UPDATE],
        ]));

        $screen = $this->screen(self::NEW);
        self::assertSame(1, $screen->filter('[data-testid="cms-site-component-not-allowed"]')->count());
        self::assertSame(0, $this->screen('/admin/cms/components')->filter('[data-testid="cms-components-new"]')->count());
        self::assertSame(403, $this->send('POST', self::GENERATE, ['_token' => $token, 'request' => 'Un tableau de prix.']));
        self::assertSame([], $this->api->requests, 'The API is not called.');
    }

    public function testAskingTheGeneratorForAComponentTakesTheRightToUseIt(): void
    {
        $this->api->answer(self::answer(PriceTable::component()));
        $candidate = $this->ask('Un tableau de prix.');
        $this->getService(SiteComponentRepository::class)->save(PriceTable::component(), SiteComponentRepository::STATUS_READY);
        $token = $this->tokenOfTheForm($this->screen(self::NEW), self::GENERATE);

        $this->logInAs((new FixtureFactory($this->getPropelConnection()))->restrictedAdmin([
            CmsResources::SETTINGS => [AccessManager::VIEW, AccessManager::UPDATE],
            CmsResources::CUSTOM_CODE => [AccessManager::VIEW, AccessManager::UPDATE],
        ]));

        self::assertSame(1, $this->screen(self::NEW)->filter('[data-testid="cms-site-component-not-allowed"]')->count());
        self::assertSame(0, $this->screen('/admin/cms/components')->filter('[data-testid="cms-components-new"]')->count());

        $kept = $this->screen('/admin/cms/components/site/PriceTable');
        self::assertSame(0, $kept->filter('[data-testid="cms-site-component-change"]')->count(), 'No change is asked of the generator.');
        self::assertSame(1, $kept->filter('form[data-testid="cms-site-component-copy"]')->count(), 'A copy calls no model: the right to make a component is enough.');

        $screen = $this->screen($candidate);
        self::assertSame(0, $screen->filter('[data-testid="cms-site-component-change"]')->count());
        self::assertSame(1, $screen->filter('[data-testid="cms-site-component-keep"]')->count(), 'Keeping a version calls no model.');

        self::assertSame(403, $this->send('POST', self::GENERATE, ['_token' => $token, 'request' => 'Un tableau de prix.']));
        self::assertCount(1, $this->api->requests, 'Only the call made with the right reached the API.');
    }

    /**
     * Sends a request from the form of a screen and gives where it ended.
     *
     * @param array<string, int|string> $hidden
     */
    private function ask(string $text, array $hidden = [], string $from = self::NEW): string
    {
        $token = $this->tokenOfTheForm($this->screen($from), self::GENERATE);

        self::assertSame(302, $this->send('POST', self::GENERATE, ['_token' => $token, 'request' => $text] + $hidden));

        return (string) $this->client->getResponse()->headers->get('Location');
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

    private static function idOf(string $candidateUrl): int
    {
        return (int) substr($candidateUrl, (int) strrpos($candidateUrl, '/') + 1);
    }

    private static function answer(SiteComponent $component): \Thelia\Domain\Ai\AiResponse
    {
        return FakeAiGateway::response(ComponentPrompt::json($component));
    }
}
