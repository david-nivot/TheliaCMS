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

use Propel\Runtime\ActiveQuery\Criteria;
use Thelia\Domain\Ai\AiGatewayInterface;
use TheliaCMS\Content\PropSchema;
use TheliaCMS\Content\SectionCatalog;
use TheliaCMS\Content\Site\SiteComponent;
use TheliaCMS\Content\Site\SiteComponentCallers;
use TheliaCMS\Content\Site\SiteComponentRepository;
use TheliaCMS\Content\Site\SiteComponentValidator;
use TheliaCMS\Content\Site\SitePropReader;
use TheliaCMS\Generation\Component\ComponentPrompt;
use TheliaCMS\Generation\Component\ComponentRequest;
use TheliaCMS\ImportExport\ImportOptions;
use TheliaCMS\ImportExport\SiteDocument;
use TheliaCMS\ImportExport\SiteExporter;
use TheliaCMS\ImportExport\SiteImporter;
use TheliaCMS\Model\CmsComponentQuery;
use TheliaCMS\Preview\ComponentPreviewLink;
use TheliaCMS\Tests\Integration\Security\AdminScreenTestCase;
use TheliaCMS\Tests\Support\FakeAiGateway;

/**
 * A component of the site that draws another with `cms_component()`, wired
 * as the site runs it (ADR 15).
 */
final class ComponentCompositionTest extends AdminScreenTestCase
{
    public function testThePreviewDrawsTheComponentInsideWithItsStylesheet(): void
    {
        // Asked first: once a screen of the back office was drawn, the kernel
        // of the test keeps its templates ahead of the theme's.
        $this->keepBoth();
        $this->getService(SectionCatalog::class)->reset();
        $preview = $this->getService(ComponentPreviewLink::class)->component('ComposedRow', 'en_US');
        $this->client->request('GET', (string) parse_url($preview, \PHP_URL_PATH));
        $page = (string) $this->client->getResponse()->getContent();

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('<div class="cms-site cms-site--composed-quote"><blockquote class="quote">Less is more.</blockquote></div>', $page);
        self::assertStringContainsString('.cms-site--composed-quote .quote', $page, 'The stylesheet of the component drawn inside is printed with the one of the caller.');
    }

    public function testTheCatalogueDrawsTheComponentInsideTheOneThatCallsIt(): void
    {
        $this->keepBoth();
        $this->getService(SectionCatalog::class)->reset();
        $row = $this->getService(SectionCatalog::class)->find('Site:ComposedRow');

        self::assertNotNull($row);
        self::assertSame(
            '<div class="cms-site cms-site--composed-row"><div class="row"><h2>Our motto</h2><div class="cms-site cms-site--composed-quote"><blockquote class="quote">Less is more.</blockquote></div></div></div>',
            $row->render(['heading' => 'Our motto', 'motto' => 'Less is more.'], $this->locale()),
        );
    }

    /**
     * A heading of the site (ADR 17): kept with the component that draws it,
     * drawn in place at the level the caller gives, and the component stays
     * one that another draws.
     */
    public function testAComponentDrawsAHeadingOfTheSiteAndAnotherStillDrawsIt(): void
    {
        $repository = $this->getService(SiteComponentRepository::class);
        $repository->save($this->getService(SiteComponentValidator::class)->validate(new SiteComponent(
            'TitledQuote',
            'Titled quote',
            'A quote under its heading.',
            [['name' => 'heading', 'type' => 'text', 'label' => 'Heading'], ['name' => 'said', 'type' => 'text', 'label' => 'Said']],
            ['heading' => 'Notre devise', 'said' => 'Less is more.'],
            '<figure>{{ cms_component("cms:heading", {text: props.heading, level: "h3"}) }}<blockquote>{{ props.said }}</blockquote></figure>',
        )), SiteComponentRepository::STATUS_READY);
        $repository->save($this->getService(SiteComponentValidator::class)->validate(new SiteComponent(
            'QuoteRow',
            'Quote row',
            'The titled quote in a row.',
            [['name' => 'heading', 'type' => 'text', 'label' => 'Heading'], ['name' => 'said', 'type' => 'text', 'label' => 'Said']],
            ['heading' => 'Notre devise', 'said' => 'Less is more.'],
            '<div class="row">{{ cms_component("TitledQuote", {heading: props.heading, said: props.said}) }}</div>',
        )), SiteComponentRepository::STATUS_READY);
        $catalog = $this->getService(SectionCatalog::class);
        $catalog->reset();

        self::assertSame(
            '<div class="cms-site cms-site--quote-row"><div class="row"><div class="cms-site cms-site--titled-quote"><figure><div class="cms-heading"><h3 class="cms-heading__text">Notre devise</h3></div><blockquote>Less is more.</blockquote></figure></div></div></div>',
            $catalog->find('Site:QuoteRow')?->render(['heading' => 'Notre devise', 'said' => 'Less is more.'], $this->locale()),
        );

        $lines = explode("\n", $this->getService(ComponentPrompt::class)->userMessage(new ComponentRequest('Un titre.', $this->locale())));
        $after = static fn (string $name): string => $lines[(int) array_search(true, array_map(static fn (string $line): bool => str_starts_with($line, '- `'.$name.'`'), $lines), true) + 1];

        self::assertSame("  Drawable: `cms_component('cms:heading', {…})`, props `text` (text, required), `level` (one of `h2`, `h3`, `h4`), `style` (one of `level`, `h1`, `h2`, `h3`, `h4`, `h5`, `h6`).", $after('cms:heading'), 'On Flexy, the styles of its toolkit (ADR 18).');
        self::assertStringContainsString('Drawable', $after('Site:TitledQuote'), 'Drawing a heading keeps it drawable.');
    }

    public function testAComponentAnotherDrawsIsNotDeletedAndItsSheetNamesTheCaller(): void
    {
        $this->keepBoth();

        $screen = $this->screen('/admin/cms/components/Site:ComposedQuote');
        self::assertStringContainsString('Composed row', $screen->filter('[data-testid="cms-site-component-callers"]')->text());
        self::assertSame(1, $screen->filter('[data-testid="cms-site-component-drawn"]')->count());
        self::assertSame(0, $screen->filter('[data-testid="cms-site-component-delete"]')->count());

        self::assertSame(302, $this->send('POST', '/admin/cms/components/site/ComposedQuote/delete', ['_token' => $this->tokenOfTheScripts($screen)]));
        self::assertNotNull($this->getService(SiteComponentRepository::class)->find('ComposedQuote'), 'A component another draws stays.');
    }

    public function testAVersionThatNoLongerTakesWhatACallerPassesIsNamedAsABreakage(): void
    {
        $this->keepBoth();
        $version = new SiteComponent('ComposedQuote', 'Composed quote', 'A short quote.', [['name' => 'words', 'type' => 'text', 'label' => 'Words']], ['words' => 'x'], '<blockquote class="quote">{{ props.words }}</blockquote>');

        self::assertSame(
            ['The component ComposedRow draws this one, and would draw it wrong. Line 1: it passes "said", which this version no longer declares.'],
            $this->getService(SiteComponentCallers::class)->breakages($version, $this->schemaOf($version)),
        );
        self::assertSame([], $this->getService(SiteComponentCallers::class)->breakages($this->quote(), $this->schemaOf($this->quote())));
    }

    public function testAnImportBringsTheComponentDrawnInsideBeforeTheOneThatDrawsIt(): void
    {
        $this->keepBoth();
        $json = json_decode(json_encode($this->getService(SiteExporter::class)->export(), \JSON_THROW_ON_ERROR), true);
        self::assertSame(['ComposedQuote', 'ComposedRow'], array_column($json['components'], 'code'));
        // The caller first in the file, as a file written by hand may put it.
        $json['components'] = array_reverse($json['components']);
        CmsComponentQuery::create()->filterByCode(['ComposedQuote', 'ComposedRow'], Criteria::IN)->delete();

        $report = $this->getService(SiteImporter::class)->import(SiteDocument::fromArray($json), new ImportOptions());

        self::assertSame([], $report->warnings());
        self::assertSame(2, $report->createdCounts()['components'] ?? 0);
    }

    public function testTheGeneratorIsToldWhichComponentsItMayDrawAndWithWhatProps(): void
    {
        $this->keepBoth();
        $this->getService(SiteComponentRepository::class)->save(new SiteComponent('ComposedDraft', 'Composed draft', 'A prototype.', [['name' => 'said', 'type' => 'text', 'label' => 'Said']], ['said' => 'x'], '<p>{{ props.said }}</p>'), SiteComponentRepository::STATUS_DRAFT);
        $this->getService(SectionCatalog::class)->reset();
        $prompt = $this->getService(ComponentPrompt::class);

        $message = $prompt->userMessage(new ComponentRequest('Une citation à côté d’un titre.', $this->locale()));
        $lines = explode("\n", $message);
        $after = static fn (string $name): string => $lines[(int) array_search(true, array_map(static fn (string $line): bool => str_starts_with($line, '- `'.$name.'`'), $lines), true) + 1];

        self::assertSame("  Drawable: `cms_component('ComposedQuote', {…})`, props `said` (text).", $after('Site:ComposedQuote'));
        self::assertStringNotContainsString('Drawable', $after('Site:ComposedRow'), 'A component that draws another is drawn by none.');
        self::assertStringNotContainsString('Drawable', $after('Site:ComposedDraft'), 'A prototype is drawn by none.');
        self::assertStringContainsString("cms_component('Carousel', {slides: props.photos, title: props.heading, columns: 3})", implode("\n", array_column($prompt->system(), 'text')));
    }

    public function testAGeneratedComponentThatDrawsAKeptOneEndsOnACandidateThatDrawsBoth(): void
    {
        $this->keepBoth();
        $api = new FakeAiGateway();
        static::getContainer()->set(AiGatewayInterface::class, $api);
        $api->answer(FakeAiGateway::response(ComponentPrompt::json(new SiteComponent(
            'QuoteBand',
            'Bandeau citation',
            'Un titre au-dessus de la devise du site.',
            [['name' => 'heading', 'type' => 'text', 'label' => 'Titre'], ['name' => 'motto', 'type' => 'text', 'label' => 'Devise']],
            ['heading' => 'Notre devise', 'motto' => 'Moins, mais mieux.'],
            '<section class="band"><h2>{{ props.heading }}</h2>{{ cms_component(\'ComposedQuote\', {said: props.motto}) }}</section>',
            '.band { padding: 2rem; }',
        ))));

        $token = $this->tokenOfTheForm($this->screen('/admin/cms/components/site/new'), '/admin/cms/components/site/generate');
        self::assertSame(302, $this->send('POST', '/admin/cms/components/site/generate', ['_token' => $token, 'request' => 'Notre devise sous un titre.']));
        $screen = $this->screen((string) $this->client->getResponse()->headers->get('Location'));

        self::assertSame('done', $screen->filter('[data-testid="cms-site-component-outcome"]')->attr('data-status'));
        self::assertSame(1, $screen->filter('[data-testid="cms-site-component-keep"]')->count());

        $this->client->request('GET', (string) $screen->filter('[data-testid="cms-site-component-frame"]')->attr('src'));
        $page = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('<div class="cms-site cms-site--composed-quote"><blockquote class="quote">Moins, mais mieux.</blockquote></div>', $page);
        self::assertStringContainsString('.cms-site--composed-quote .quote', $page);
    }

    private function keepBoth(): void
    {
        $repository = $this->getService(SiteComponentRepository::class);
        $repository->save($this->getService(SiteComponentValidator::class)->validate($this->quote()), SiteComponentRepository::STATUS_READY);
        $repository->save($this->getService(SiteComponentValidator::class)->validate(new SiteComponent(
            'ComposedRow',
            'Composed row',
            'A heading next to the motto of the site.',
            [['name' => 'heading', 'type' => 'text', 'label' => 'Heading'], ['name' => 'motto', 'type' => 'text', 'label' => 'Motto']],
            ['heading' => 'Our motto', 'motto' => 'Less is more.'],
            '<div class="row"><h2>{{ props.heading }}</h2>{{ cms_component("ComposedQuote", {said: props.motto}) }}</div>',
            '.row { display: flex; }',
        )), SiteComponentRepository::STATUS_READY);
    }

    private function quote(): SiteComponent
    {
        return new SiteComponent('ComposedQuote', 'Composed quote', 'A short quote.', [['name' => 'said', 'type' => 'text', 'label' => 'Said']], ['said' => 'Less is more.'], '<blockquote class="quote">{{ props.said }}</blockquote>', '.quote { font-style: italic; }');
    }

    private function schemaOf(SiteComponent $component): PropSchema
    {
        return (new SitePropReader())->read($component->props);
    }
}
