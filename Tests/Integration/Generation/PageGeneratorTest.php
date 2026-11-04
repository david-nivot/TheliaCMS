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

use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Domain\Ai\AiFinishReason;
use Thelia\Domain\Ai\AiMessageRole;
use Thelia\Domain\Ai\AiTier;
use Thelia\Domain\Ai\AiToolCall;
use Thelia\Domain\Ai\AiUsageRegistryInterface;
use Thelia\Domain\Ai\Exception\AiBudgetExceededException;
use Thelia\Domain\Ai\UntrustedSource;
use Thelia\Model\ModuleConfigQuery;
use TheliaCMS\Catalogue\CatalogueItem;
use TheliaCMS\Catalogue\CatalogueItemType;
use TheliaCMS\Content\Document;
use TheliaCMS\Content\DocumentSchema;
use TheliaCMS\Content\DocumentValidator;
use TheliaCMS\Content\ImageSection;
use TheliaCMS\Content\RichTextSection;
use TheliaCMS\Content\Section;
use TheliaCMS\Content\SectionAppearance;
use TheliaCMS\Generation\Ai\GatewayFailures;
use TheliaCMS\Generation\Ai\GeneratorToolDefinitions;
use TheliaCMS\Generation\Brief;
use TheliaCMS\Generation\DocumentChecker;
use TheliaCMS\Generation\GenerationException;
use TheliaCMS\Generation\GenerationLog;
use TheliaCMS\Generation\GenerationObserverInterface;
use TheliaCMS\Generation\GenerationRefusedException;
use TheliaCMS\Generation\GenerationRequest;
use TheliaCMS\Generation\GenerationTarget;
use TheliaCMS\Generation\GeneratorSettings;
use TheliaCMS\Generation\InvalidGenerationException;
use TheliaCMS\Generation\PageGenerator;
use TheliaCMS\Generation\PromptBuilder;
use TheliaCMS\Generation\Tool\GeneratorTools;
use TheliaCMS\Generation\Tool\MakeComponentTool;
use TheliaCMS\Generation\Tool\ReadUrlTool;
use TheliaCMS\Generation\TranslationGraft;
use TheliaCMS\Model\CmsForm;
use TheliaCMS\Model\CmsPageContentQuery;
use TheliaCMS\Model\CmsPageGenerationQuery;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;
use TheliaCMS\Tests\Support\FakeAiGateway;
use TheliaLibrary\Model\LibraryImage;

/**
 * The generator against the gateway of the core, faked: what it sends, what
 * it does with what comes back, and what it writes to the ledger whatever the
 * outcome.
 */
final class PageGeneratorTest extends CmsIntegrationTestCase
{
    private FakeAiGateway $api;

    protected function setUp(): void
    {
        parent::setUp();

        $this->api = new FakeAiGateway();
    }

    protected function tearDown(): void
    {
        $settings = $this->getService(GeneratorSettings::class);
        $settings->save($settings->effort(), $settings->maxTokens(), webFetch: false);
        ModuleConfigQuery::resetConfigCache();

        parent::tearDown();
    }

    public function testTheLedgerKeepsTheCostOfTheCoreAndTheRowOfItsLog(): void
    {
        $page = $this->createPage('Nos services', published: false);
        $locale = $this->locale();

        $this->api
            ->streamed([], FakeAiGateway::response('', toolCalls: [new AiToolCall('toolu_1', 'search_pages', ['query' => 'tarif'])], logId: 4100))
            ->streamed([], FakeAiGateway::response(self::document([
                ['id' => 'hero', 'component' => 'cms:richtext', 'props' => ['html' => '<h2>Nos services</h2>']],
            ]), logId: 4101));

        $result = $this->generator()->generate(GenerationRequest::fromBrief(GenerationTarget::page($page, $locale), $locale, new Brief('Une page qui présente nos services de conseil.')));

        $row = CmsPageGenerationQuery::create()->findPk($result->logId);

        self::assertSame('0.002000', $row?->getCostUsd(), 'The ledger sums what the core announced for each request.');
        self::assertSame(4101, $row->getLogId(), 'The row points at the last request of the call in the log of the core.');
    }

    public function testATranslationRunsUnderAUsageOfItsOwnAtTheStandardTier(): void
    {
        $page = $this->createPage('Nos services', published: false);
        $locale = $this->locale();
        $otherLocale = 'fr_FR' === $locale ? 'en_US' : 'fr_FR';
        $source = Document::richText('<h2>Nos services</h2><p>Du conseil.</p>');
        $answer = self::document([['id' => 'content', 'component' => 'cms:richtext', 'props' => ['html' => '<h2>Our services</h2><p>Advice.</p>']]]);

        $this->api->answer(FakeAiGateway::response($answer));
        $result = $this->generator()->generate(GenerationRequest::translation(GenerationTarget::page($page, $otherLocale), $otherLocale, $source, $locale));

        self::assertSame('<h2>Our services</h2><p>Advice.</p>', $result->document->sections[0]->props['html']);
        self::assertSame(PageGenerator::TRANSLATION_USAGE, $this->api->requests[0]->usageId);

        $this->api->answer(FakeAiGateway::response($answer));
        $this->generator()->generate(GenerationRequest::revision($this->target($page), $locale, $source, new Brief('Plus court.')));
        $this->api->answer(FakeAiGateway::response(self::section(['id' => 'content', 'component' => 'cms:richtext', 'props' => ['html' => '<h2>Our services</h2><p>Advice.</p>']])));
        $this->generator()->generate(GenerationRequest::section($this->target($page), $locale, $source, 'content', new Brief('Plus court.')));

        self::assertSame([PageGenerator::USAGE, PageGenerator::USAGE], [$this->api->requests[1]->usageId, $this->api->requests[2]->usageId], 'A change asked by the editor stays on the usage of pages.');

        $registry = $this->getService(AiUsageRegistryInterface::class);
        self::assertSame(AiTier::Standard, $registry->get(PageGenerator::TRANSLATION_USAGE)->tier, 'The core knows the usage, at the standard tier.');
        self::assertSame(AiTier::Advanced, $registry->get(PageGenerator::USAGE)->tier);
    }

    public function testABriefBecomesADocumentThroughTheToolsAndTheSchema(): void
    {
        $linked = $this->createPage('Nos tarifs');
        $page = $this->createPage('Nos services', published: false);
        $locale = $this->locale();
        $form = (new CmsForm())->setCode('contact-generation');
        $form->setLocale($locale)->setTitle('Nous écrire');
        $form->save();

        $this->api
            ->streamed(['Let me look at the pages of the site.'], FakeAiGateway::response('Let me look at the pages of the site.', toolCalls: [new AiToolCall('toolu_1', 'search_pages', ['query' => 'tarif'])]))
            ->streamed([], FakeAiGateway::response(self::document([
                ['id' => 'hero', 'component' => 'cms:richtext', 'props' => ['html' => '<h2>Nos services</h2><p>Ce que nous faisons. <a href="'.$linked->getUrl($locale).'">Voir les tarifs</a></p>']],
                ['id' => 'contact', 'component' => 'Partial:cms-form', 'props' => ['form' => (string) $form->getId()]],
            ])));

        $result = $this->generator()->generate(GenerationRequest::fromBrief(GenerationTarget::page($page, $locale), $locale, new Brief('Une page qui présente nos services de conseil, avec un lien vers les tarifs.', tone: 'direct')));

        self::assertSame(['hero', 'contact'], array_map(static fn ($section) => $section->id, $result->document->sections));
        self::assertSame((int) $form->getId(), $result->document->sections[1]->props['form'], 'The document comes back normalised by the validator.');
        self::assertSame(2, $result->turns);
        self::assertSame([20, 10], [$result->usage->inputTokens, $result->usage->outputTokens], 'The tokens of the two turns are summed.');
        self::assertSame(0.002, $result->costUsd, 'The cost is the one the gateway announced, summed over the turns.');
        self::assertSame('fake-model', $result->model);

        // What was sent.
        self::assertCount(2, $this->api->requests);
        $first = $this->api->requests[0];
        $settings = $this->getService(GeneratorSettings::class);

        self::assertSame(PageGenerator::USAGE, $first->usageId);
        self::assertSame(['anthropic' => ['effort' => 'high']], $first->providerOptions);
        self::assertSame($settings->maxTokens(), $first->maxOutputTokens);
        self::assertSame('cms_page', $first->outputSchema?->name);
        self::assertSame(['version', 'sections'], $first->outputSchema?->schema['required']);
        self::assertSame($this->getService(DocumentSchema::class)->strict([], false, true), $first->outputSchema->schema, 'A page written from a brief is also asked for its search fields.');
        self::assertStringContainsString('# Skills', (string) $first->system);
        self::assertStringContainsString('## `cms:richtext`', (string) $first->system);
        self::assertContains('search_pages', array_column($first->tools, 'name'));
        self::assertNotContains('read_url', array_column($first->tools, 'name'), 'A brief citing no address offers no reading.');
        self::assertStringContainsString('Une page qui présente nos services', $first->messages[0]->content);
        self::assertStringContainsString('Tone asked for: direct', $first->messages[0]->content);
        self::assertStringContainsString('Title: Nos services', $first->messages[0]->content);

        $second = $this->api->requests[1];

        self::assertSame(AiMessageRole::Assistant, $second->messages[1]->role);
        self::assertSame('toolu_1', $second->messages[1]->toolCalls[0]->id);
        self::assertSame(AiMessageRole::Tool, $second->messages[2]->role);
        self::assertSame('toolu_1', $second->messages[2]->toolCallId);
        self::assertStringContainsString('"title":"Nos tarifs"', $second->messages[2]->content, 'The tool answered with the pages of the site.');

        // The ledger.
        $row = CmsPageGenerationQuery::create()->findPk($result->logId);

        self::assertNotNull($row);
        self::assertSame(GenerationLog::STATUS_DONE, $row->getStatus());
        self::assertSame('brief', $row->getKind());
        self::assertSame(2, $row->getTurns());
        self::assertSame(AiFinishReason::Completed->value, $row->getStopReason());
        self::assertStringContainsString('"component":"cms:richtext"', (string) $row->getDocument());
        self::assertStringContainsString('nos services', (string) $row->getPrompt());
    }

    public function testADocumentTheSiteRefusesGoesBackOnceWithItsProblems(): void
    {
        $page = $this->createPage('Page corrigée', published: false);
        $valid = self::document([['id' => 'hero', 'component' => 'cms:richtext', 'props' => ['html' => '<h2>Bonjour</h2>']]]);

        $this->api
            ->answer(FakeAiGateway::response('{"version":1,"sections":[{"id":"hero","component":"Cms:Nope","props":"{}"}]}'))
            ->answer(FakeAiGateway::response("```json\n".$valid."\n```"));

        $result = $this->generator()->generate(GenerationRequest::fromBrief($this->target($page), $this->locale(), new Brief('Bonjour')));

        self::assertSame('<h2>Bonjour</h2>', $result->document->sections[0]->props['html']);
        self::assertStringContainsString('"Cms:Nope", which is not a component', $this->api->requests[1]->messages[2]->content, 'The problems are sent back for correction.');
        self::assertSame(2, $result->turns);
    }

    public function testPropsEscapedOnceAreReadWithTheirLineBreaksAsSpaces(): void
    {
        $page = $this->createPage('Nos services', published: false);
        $locale = $this->locale();

        // The model wrote the inner JSON with a real line break in a text
        // rather than the `\\n` a string within a string takes.
        $this->api->streamed([], FakeAiGateway::response(json_encode([
            'version' => 1,
            'sections' => [
                ['id' => 'hero', 'component' => 'cms:richtext', 'props' => "{\"html\": \"<h2>Nos services</h2>\n<p>Ce que nous faisons.</p>\"}"],
                ['id' => 'broken', 'component' => 'cms:richtext', 'props' => "{'html': '<p>Pas du JSON</p>'}"],
            ],
        ], \JSON_THROW_ON_ERROR)));
        $this->api->streamed([], FakeAiGateway::response(self::document([
            ['id' => 'hero', 'component' => 'cms:richtext', 'props' => ['html' => "<h2>Nos services</h2>\n<p>Ce que nous faisons.</p>"]],
        ])));

        $result = $this->generator()->generate(GenerationRequest::fromBrief(GenerationTarget::page($page, $locale), $locale, new Brief('Nos services.')));

        self::assertSame(['hero'], array_map(static fn ($section) => $section->id, $result->document->sections), 'The first answer went back for its second section alone.');
        $correction = $this->api->requests[1]->messages[\count($this->api->requests[1]->messages) - 1]->content;
        self::assertStringNotContainsString('Section 1:', $correction, 'The section with a bare line break was read.');
        self::assertStringContainsString('Section 2: the props are not valid JSON: write them as one JSON object in a string', $correction);
    }

    public function testADocumentRefusedAfterTwoCorrectionsIsAnError(): void
    {
        $page = $this->createPage('Page cassée', published: false);
        $broken = '{"version":1,"sections":[{"id":"x","component":"Cms:Nope","props":"{}"}]}';
        $this->api->answer(FakeAiGateway::response($broken))->answer(FakeAiGateway::response($broken))->answer(FakeAiGateway::response($broken));

        try {
            $this->generator()->generate(GenerationRequest::fromBrief($this->target($page), $this->locale(), new Brief('Bonjour')));
            self::fail('The generation should have failed.');
        } catch (InvalidGenerationException $exception) {
            self::assertCount(1, $exception->problems);
        }

        self::assertCount(3, $this->api->requests, 'One answer, two corrections, then the error.');
        self::assertSame(GenerationLog::STATUS_INVALID, $this->lastStatus($page));
    }

    public function testARefusalOfTheModelIsSaidAndLogged(): void
    {
        $page = $this->createPage('Page refusée', published: false);
        $this->api->answer(FakeAiGateway::response(finishReason: AiFinishReason::ContentFilter));

        try {
            $this->generator()->generate(GenerationRequest::fromBrief($this->target($page), $this->locale(), new Brief('Something')));
            self::fail('The generation should have been refused.');
        } catch (GenerationRefusedException $exception) {
            self::assertStringContainsString('declined', $exception->getMessage());
        }

        self::assertSame(GenerationLog::STATUS_REFUSED, $this->lastStatus($page));
        self::assertSame(AiFinishReason::ContentFilter->value, CmsPageGenerationQuery::create()->filterByTargetId($page->getId())->findOne()?->getStopReason());
    }

    public function testAnAnswerCutShortIsAFailureNotAHalfPage(): void
    {
        $page = $this->createPage('Page coupée', published: false);
        $this->api->answer(FakeAiGateway::response('{"version":1,"sections":[', finishReason: AiFinishReason::MaxTokens, outputTokens: $this->getService(GeneratorSettings::class)->maxTokens()));

        try {
            $this->generator()->generate(GenerationRequest::fromBrief($this->target($page), $this->locale(), new Brief('Long')));
            self::fail('The generation should have failed.');
        } catch (GenerationException $exception) {
            self::assertStringContainsString(\sprintf('cut at %d tokens', $this->getService(GeneratorSettings::class)->maxTokens()), $exception->getMessage());
        }

        self::assertSame(GenerationLog::STATUS_FAILED, $this->lastStatus($page));
    }

    public function testARefusalOfTheGatewayIsAFailureAndTheDraftIsLeftAsItIs(): void
    {
        $page = $this->createPage('Page en panne', html: '<h2>Avant</h2>', published: false);
        $this->api->answer(new AiBudgetExceededException(PageGenerator::USAGE));

        try {
            $this->generator()->generate(GenerationRequest::fromBrief($this->target($page), $this->locale(), new Brief('Something')));
            self::fail('The generation should have failed.');
        } catch (GenerationException $exception) {
            self::assertStringContainsString('The AI budget of the site is spent for now', $exception->getMessage());
            self::assertNotNull($exception->logId);
        }

        self::assertSame(GenerationLog::STATUS_FAILED, $this->lastStatus($page));
        self::assertStringContainsString('<h2>Avant</h2>', (string) CmsPageContentQuery::create()->filterByPageId($page->getId())->findOne()?->getDraftHtml(), 'The draft is untouched.');
    }

    public function testAModelThatKeepsCallingToolsStopsAtTheCeiling(): void
    {
        $page = $this->createPage('Page sans fin', published: false);

        for ($turn = 1; $turn <= 12; ++$turn) {
            $this->api->answer(FakeAiGateway::response(toolCalls: [new AiToolCall('call_'.$turn, 'search_pages', ['query' => 'x'])]));
        }

        try {
            $this->generator()->generate(GenerationRequest::fromBrief($this->target($page), $this->locale(), new Brief('Sans fin')));
            self::fail('The generation should have failed.');
        } catch (GenerationException $exception) {
            self::assertStringContainsString('more than 12 turns', $exception->getMessage());
        }

        self::assertCount(12, $this->api->requests);
        self::assertSame(GenerationLog::STATUS_FAILED, $this->lastStatus($page));
    }

    public function testAToolTheRequestDidNotOfferIsNotRun(): void
    {
        $page = $this->createPage('Outil inconnu', published: false);
        $this->api
            ->answer(FakeAiGateway::response(toolCalls: [new AiToolCall('call_1', 'read_url', ['url' => 'http://127.0.0.1/'])]))
            ->answer(FakeAiGateway::response(self::document([['id' => 'content', 'component' => 'cms:richtext', 'props' => ['html' => '<p>Ok.</p>']]])));

        $this->generator(new ReadUrlTool(new MockHttpClient(static fn () => throw new \LogicException('No request may leave.'))))
            ->generate(GenerationRequest::fromBrief($this->target($page), $this->locale(), new Brief('Une page sans adresse.')));

        self::assertSame('There is no tool called "read_url".', $this->api->requests[1]->messages[2]->content, 'A page is read only when the brief cites one and the site allowed it.');
    }

    public function testARevisionSendsTheCurrentDocumentAndAsksForTheWholeOneBack(): void
    {
        $page = $this->createPage('Page revue', html: '<h2>Avant</h2>', published: false);
        $content = CmsPageContentQuery::create()->filterByPageId($page->getId())->filterByLocale($this->locale())->findOne();
        $current = Document::fromJson($content?->getDraftDocument());

        self::assertNotNull($current);

        $this->api->answer(FakeAiGateway::response(self::document([['id' => 'content', 'component' => 'cms:richtext', 'props' => ['html' => '<h2>Après</h2>']]])));

        $result = $this->generator()->generate(GenerationRequest::revision($this->target($page), $this->locale(), $current, new Brief('Change the heading to Après.')));

        self::assertSame('<h2>Après</h2>', $result->document->sections[0]->props['html']);
        self::assertStringContainsString('# The document the page holds today', $this->api->requests[0]->messages[0]->content);
        self::assertStringContainsString('<h2>Avant</h2>', $this->api->requests[0]->messages[0]->content);
        self::assertSame('revision', CmsPageGenerationQuery::create()->filterByTargetId($page->getId())->findOne()?->getKind());
    }

    public function testTheObserverIsToldOfEveryTurnAndOfTheCorrectionAndTheLedgerNamesThePage(): void
    {
        $page = $this->createPage('Réassurance', published: false);
        $locale = $this->locale();

        $unknown = self::document([['id' => 'inner', 'component' => 'Cms:Nothing', 'props' => []]]);
        $valid = self::document([['id' => 'figures', 'component' => 'cms:richtext', 'props' => ['html' => '<h2>En chiffres</h2><p>48 h pour livrer.</p>']]]);

        $this->api
            ->streamed([$unknown], FakeAiGateway::response($unknown))
            ->streamed([substr($valid, 0, 20), substr($valid, 20)], FakeAiGateway::response($valid));

        $observer = new class implements GenerationObserverInterface {
            /** @var list<string> */
            public array $events = [];

            public int $characters = 0;

            public function onTurn(int $turn, array $tools): void
            {
                $this->events[] = \sprintf('turn %d: %s', $turn, implode(',', $tools) ?: 'answer');
            }

            public function onText(int $characters): void
            {
                $this->characters = $characters;
            }

            public function onCorrection(int $round, array $problems): void
            {
                $this->events[] = \sprintf('correction %d', $round);
            }

            public function onCut(): void
            {
            }
        };

        $result = $this->generator()->generate(
            GenerationRequest::fromBrief($this->target($page), $locale, new Brief('Trois chiffres qui rassurent.')),
            $observer,
        );

        self::assertSame('cms:richtext', $result->document->sections[0]->component);
        self::assertSame(['turn 1: answer', 'correction 1', 'turn 2: answer'], $observer->events, 'The observer is told of every turn and of the correction.');
        self::assertSame(mb_strlen($valid), $observer->characters, 'The observer is told of the text as it is streamed.');
        self::assertStringContainsString('Cms:Nothing', $this->api->requests[1]->messages[2]->content, 'The unknown component is sent back as a problem.');

        $row = CmsPageGenerationQuery::create()->filterByTargetType(GenerationTarget::TYPE_PAGE)->filterByTargetId($page->getId())->findOne();
        self::assertNotNull($row, 'The ledger names the page as the target.');
        self::assertSame($result->logId, (int) $row->getId());
        self::assertSame($row->getId(), $this->getService(GenerationLog::class)->find((int) $row->getId(), $this->target($page), $locale)?->getId());
        self::assertNull($this->getService(GenerationLog::class)->find((int) $row->getId(), GenerationTarget::page($this->createPage('Other'), $locale), $locale), 'A row is found for its own target only.');
    }

    public function testABriefCitingAWebPageLetsTheModelReadItAndTheTextGoesBackAsUntrustedData(): void
    {
        $page = $this->createPage('Notre histoire', published: false);
        $locale = $this->locale();
        $this->readingOn();

        $this->api
            ->answer(FakeAiGateway::response(toolCalls: [new AiToolCall('call_1', 'read_url', ['url' => 'https://example.com/a-propos'])]))
            ->answer(FakeAiGateway::response(self::document([['id' => 'content', 'component' => 'cms:richtext', 'props' => ['html' => '<h2>Depuis 1987</h2>']]])));

        $reader = new ReadUrlTool(new MockHttpClient(static fn () => new MockResponse('<html><head><title>x</title><script>evil()</script></head><body><h1>A propos</h1><p>Fondée en 1987 à Lyon.</p></body></html>', ['response_headers' => ['Content-Type' => 'text/html; charset=utf-8']])));
        $result = $this->generator($reader)->generate(GenerationRequest::fromBrief(GenerationTarget::page($page, $locale), $locale, new Brief('Reprends notre histoire depuis https://example.com/a-propos, en plus court.')));

        self::assertSame('<h2>Depuis 1987</h2>', $result->document->sections[0]->props['html']);

        $first = $this->api->requests[0];
        self::assertContains('read_url', array_column($first->tools, 'name'), 'A brief citing a page offers the reading tool.');
        self::assertStringContainsString('`read_url`', $first->messages[0]->content, 'The model is told it may read the pages the brief cites.');
        self::assertSame([], $first->untrustedContents);

        $second = $this->api->requests[1];
        self::assertCount(1, $second->untrustedContents);
        self::assertSame(UntrustedSource::WebPage, $second->untrustedContents[0]->source, 'A page read is data of a third party.');
        self::assertSame('https://example.com/a-propos', $second->untrustedContents[0]->reference);
        self::assertStringContainsString('Fondée en 1987 à Lyon.', $second->untrustedContents[0]->content);
        self::assertStringNotContainsString('evil()', $second->untrustedContents[0]->content, 'Scripts are not text.');
        self::assertStringNotContainsString('Fondée en 1987', $second->messages[2]->content, 'The result of the tool does not carry the page.');

        $row = CmsPageGenerationQuery::create()->findPk($result->logId);
        self::assertNotNull($row);
        self::assertSame([['url' => 'https://example.com/a-propos', 'error' => null, 'tokens' => null]], array_map(static fn ($page) => $page->toArray(), GenerationLog::webPagesOf($row)), 'The ledger keeps the page read.');
    }

    public function testAPageThatCannotBeReadIsRecordedWithItsErrorAndOnlyTheHostsOfTheBriefAreRead(): void
    {
        $page = $this->createPage('Notre histoire', published: false);
        $locale = $this->locale();
        $this->readingOn();
        $requested = [];

        $this->api
            ->answer(FakeAiGateway::response(toolCalls: [
                new AiToolCall('call_1', 'read_url', ['url' => 'https://example.com/prive']),
                new AiToolCall('call_2', 'read_url', ['url' => 'https://elsewhere.example.org/exfiltrate?x=1']),
            ]))
            ->answer(FakeAiGateway::response(self::document([['id' => 'content', 'component' => 'cms:richtext', 'props' => ['html' => '<p>1987.</p>']]])));

        $reader = new ReadUrlTool(new MockHttpClient(static function (string $method, string $url) use (&$requested): MockResponse {
            $requested[] = $url;

            return new MockResponse('', ['http_code' => 404]);
        }));
        $result = $this->generator($reader)->generate(GenerationRequest::fromBrief(GenerationTarget::page($page, $locale), $locale, new Brief('Voir https://example.com/prive.')));

        self::assertSame(['https://example.com/prive'], $requested, 'A host the brief does not cite is not requested.');
        self::assertSame([], $this->api->requests[1]->untrustedContents);
        self::assertStringContainsString('url_not_accessible', $this->api->requests[1]->messages[2]->content);
        self::assertStringContainsString('url_not_allowed', $this->api->requests[1]->messages[3]->content);

        $row = CmsPageGenerationQuery::create()->findPk($result->logId);
        self::assertNotNull($row);
        self::assertSame([
            ['url' => 'https://example.com/prive', 'error' => 'url_not_accessible', 'tokens' => null],
            ['url' => 'https://elsewhere.example.org/exfiltrate?x=1', 'error' => 'url_not_allowed', 'tokens' => null],
        ], array_map(static fn ($page) => $page->toArray(), GenerationLog::webPagesOf($row)));
        self::assertSame(GenerationLog::STATUS_DONE, $row->getStatus());
    }

    public function testOnlyFivePagesAreReadForOneRequest(): void
    {
        $page = $this->createPage('Beaucoup de pages', published: false);
        $this->readingOn();
        $calls = [];

        foreach (range(1, 6) as $number) {
            $calls[] = new AiToolCall('call_'.$number, 'read_url', ['url' => 'https://example.com/p'.$number]);
        }

        $this->api
            ->answer(FakeAiGateway::response(toolCalls: $calls))
            ->answer(FakeAiGateway::response(self::document([['id' => 'content', 'component' => 'cms:richtext', 'props' => ['html' => '<p>Ok.</p>']]])));

        $reader = new ReadUrlTool(new MockHttpClient(static fn () => new MockResponse('Texte.', ['response_headers' => ['Content-Type' => 'text/plain']])));
        $this->generator($reader)->generate(GenerationRequest::fromBrief($this->target($page), $this->locale(), new Brief('Voir https://example.com/p1.')));

        self::assertCount(5, $this->api->requests[1]->untrustedContents);
        self::assertStringContainsString('No more than 5 pages', $this->api->requests[1]->messages[7]->content);
    }

    public function testASiteThatLeftTheReadingOffSendsTheAddressesAsText(): void
    {
        $page = $this->createPage('Notre histoire', published: false);
        $locale = $this->locale();

        self::assertFalse($this->getService(GeneratorSettings::class)->webFetch(), 'The reading of pages is off until the site turns it on.');

        $this->api->answer(FakeAiGateway::response(self::document([['id' => 'content', 'component' => 'cms:richtext', 'props' => ['html' => '<p>1987.</p>']]])));

        $this->generator()->generate(GenerationRequest::fromBrief(GenerationTarget::page($page, $locale), $locale, new Brief('Résume https://example.com/a-propos.')));

        $first = $this->api->requests[0];
        self::assertNotContains('read_url', array_column($first->tools, 'name'));
        self::assertStringNotContainsString('read_url', $first->messages[0]->content);
        self::assertStringContainsString('https://example.com/a-propos', $first->messages[0]->content, 'The brief still says what it says.');
    }

    public function testASectionIsAskedAloneAndGraftedKeepingTheAppearanceOfTheOneItReplaces(): void
    {
        $page = $this->createPage('Nos services', published: false);
        $locale = $this->locale();
        $source = new Document([
            new Section('hero', RichTextSection::NAME, ['html' => '<h2>Bienvenue</h2>']),
            new Section('offer', RichTextSection::NAME, ['html' => '<h2>Offre</h2>'], new SectionAppearance('#112233', align: 'center')),
            new Section('contact', RichTextSection::NAME, ['html' => '<h2>Contact</h2>']),
        ]);

        // Another component, and no appearance: the section keeps its look.
        $this->api->answer(FakeAiGateway::response(self::section([
            'id' => 'offer',
            'component' => ImageSection::NAME,
            'props' => ['image' => 'https://example.com/offer.jpg', 'alt' => 'Une offre'],
        ])));

        $result = $this->generator()->generate(GenerationRequest::section($this->target($page), $locale, $source, 'offer', new Brief('Une image à la place du texte.')));

        self::assertSame(['hero', 'offer', 'contact'], array_map(static fn (Section $section): string => $section->id, $result->document->sections));
        self::assertEquals($source->sections[0], $result->document->sections[0], 'The other sections are the ones of the document.');
        self::assertEquals($source->sections[2], $result->document->sections[2]);
        self::assertSame(ImageSection::NAME, $result->document->sections[1]->component, 'The component of the section may change.');
        self::assertSame('https://example.com/offer.jpg', $result->document->sections[1]->props['image']);
        self::assertSame(['background' => '#112233', 'align' => 'center'], $result->document->sections[1]->appearance->toArray(), 'The appearance of the section replaced is kept.');

        $request = $this->api->requests[0];
        self::assertSame(['id', 'component', 'props', 'appearance'], array_keys($request->outputSchema?->schema['properties'] ?? []), 'The schema is the one of a section, not of a document.');
        self::assertContains(ImageSection::NAME, $request->outputSchema->schema['properties']['component']['enum']);
        self::assertStringContainsString('answer with that section alone, with the id `offer`', $request->messages[0]->content);
        self::assertStringContainsString('# The document the page holds today', $request->messages[0]->content);
        self::assertStringContainsString('<h2>Contact</h2>', $request->messages[0]->content, 'The whole document stays in the context.');
    }

    public function testAnAppearanceTheAnswerSetsReplacesTheOneOfTheSectionReplaced(): void
    {
        $page = $this->createPage('Nos services', published: false);
        $source = new Document([new Section('offer', RichTextSection::NAME, ['html' => '<h2>Offre</h2>'], new SectionAppearance('#112233', align: 'center'))]);

        $this->api->answer(FakeAiGateway::response(self::section(['id' => 'offer', 'component' => RichTextSection::NAME, 'props' => ['html' => '<h2>Offre</h2>'], 'appearance' => ['background' => '#f8d7d7']])));

        $result = $this->generator()->generate(GenerationRequest::section($this->target($page), $this->locale(), $source, 'offer', new Brief('Sur un fond rose.')));

        self::assertSame(['background' => '#f8d7d7'], $result->document->sections[0]->appearance->toArray(), 'A look asked for in the brief is the one of the answer.');
    }

    public function testASectionAnsweredUnderAnotherIdIsSentBack(): void
    {
        $page = $this->createPage('Nos services', published: false);
        $locale = $this->locale();
        $source = new Document([
            new Section('hero', RichTextSection::NAME, ['html' => '<h2>Bienvenue</h2>']),
            new Section('offer', RichTextSection::NAME, ['html' => '<h2>Offre</h2>']),
        ]);

        $this->api
            ->answer(FakeAiGateway::response(self::section(['id' => 'hero', 'component' => RichTextSection::NAME, 'props' => ['html' => '<h2>Autre</h2>']])))
            ->answer(FakeAiGateway::response(self::section(['id' => 'offer', 'component' => RichTextSection::NAME, 'props' => ['html' => '<h2>Offre revue</h2>']])));

        $result = $this->generator()->generate(GenerationRequest::section($this->target($page), $locale, $source, 'offer', new Brief('Plus court.')));

        self::assertSame(2, $result->turns);
        self::assertStringContainsString('The section to write again is "offer": answer with that id, not "hero".', $this->api->requests[1]->messages[2]->content);
        self::assertSame('<h2>Bienvenue</h2>', $result->document->sections[0]->props['html'], 'The section of another id was not taken.');
        self::assertSame('<h2>Offre revue</h2>', $result->document->sections[1]->props['html']);
    }

    public function testTheDocumentGraftedStillGoesThroughTheValidator(): void
    {
        $page = $this->createPage('Nos services', published: false);
        $locale = $this->locale();
        $source = new Document([
            new Section('old', 'Gone:Component', ['x' => 1]),
            new Section('offer', RichTextSection::NAME, ['html' => '<h2>Offre</h2>']),
        ]);

        $this->api->answer(FakeAiGateway::response(self::section(['id' => 'offer', 'component' => RichTextSection::NAME, 'props' => ['html' => '<h2>Offre revue</h2>']])));

        try {
            $this->generator()->generate(GenerationRequest::section($this->target($page), $locale, $source, 'offer', new Brief('Plus court.')));
            self::fail('A document the validator refuses is not handed back.');
        } catch (InvalidGenerationException $exception) {
            self::assertStringContainsString('"Gone:Component", which is not a component', $exception->problems[0]);
        }

        self::assertCount(1, $this->api->requests, 'The problem is not one the model can fix: no correction is paid for.');
    }

    public function testATranslationIsWrittenOntoItsSourceAndADifferenceIsSentBack(): void
    {
        $page = $this->createPage('Nos services', published: false);
        $locale = $this->locale();
        $otherLocale = 'fr_FR' === $locale ? 'en_US' : 'fr_FR';
        $source = new Document([
            new Section('team', ImageSection::NAME, ['image' => 'https://example.com/team.jpg', 'alt' => 'Équipe', 'caption' => 'Notre équipe', 'width' => 'full'], new SectionAppearance('#112233')),
            new Section('intro', RichTextSection::NAME, ['html' => '<h2>Bonjour</h2>']),
        ]);

        $this->api
            // Wrong order and another component: sent back.
            ->answer(FakeAiGateway::response(self::document([
                ['id' => 'intro', 'component' => RichTextSection::NAME, 'props' => ['html' => '<h2>Hello</h2>']],
                ['id' => 'team', 'component' => ImageSection::NAME, 'props' => ['image' => 'https://example.com/team.jpg', 'alt' => 'Team']],
            ])))
            // Right sections, an image and a width the model changed: its texts only are read.
            ->answer(FakeAiGateway::response(self::document([
                ['id' => 'team', 'component' => ImageSection::NAME, 'props' => ['image' => 'https://invented.example/other.jpg', 'alt' => 'Team', 'caption' => 'Our team', 'width' => 'narrow'], 'appearance' => ['background' => '#ffffff']],
                ['id' => 'intro', 'component' => RichTextSection::NAME, 'props' => ['html' => '<h2>Hello</h2>']],
            ])));

        $result = $this->generator()->generate(GenerationRequest::translation(GenerationTarget::page($page, $otherLocale), $otherLocale, $source, $locale));

        self::assertSame(2, $result->turns);
        self::assertStringContainsString('Section 1 must be "team" (cms:image), as in the source, and is "intro" (cms:richtext).', $this->api->requests[1]->messages[2]->content);

        $team = $result->document->sections[0];
        self::assertSame('https://example.com/team.jpg', $team->props['image'], 'An image is the one of the source.');
        self::assertSame('full', $team->props['width']);
        self::assertSame('Our team', $team->props['caption'], 'A text is the one of the answer.');
        self::assertSame('Team', $team->props['alt']);
        self::assertSame('#112233', $team->appearance->background);
        self::assertSame('<h2>Hello</h2>', $result->document->sections[1]->props['html']);
        self::assertStringContainsString('every image, reference, address, figure and choice is taken from the source', $this->api->requests[0]->messages[0]->content);
    }

    public function testAnImageTheLibraryDoesNotHoldGoesBackToTheModelAndOneThatExistsStays(): void
    {
        $page = $this->createPage('Nos services', published: false);
        $locale = $this->locale();
        $image = (new LibraryImage())->setLocale($locale)->setFileName('team-generation.png')->setTitle('Team');
        $image->save();

        $this->api
            ->answer(FakeAiGateway::response(self::document([['id' => 'photo', 'component' => ImageSection::NAME, 'props' => ['image' => 987654, 'alt' => 'Équipe']]])))
            ->answer(FakeAiGateway::response(self::document([['id' => 'photo', 'component' => ImageSection::NAME, 'props' => ['image' => (int) $image->getId(), 'alt' => 'Équipe']]])));

        $result = $this->generator()->generate(GenerationRequest::fromBrief(GenerationTarget::page($page, $locale), $locale, new Brief('Une photo de l\'équipe.')));

        self::assertSame(2, $result->turns);
        self::assertStringContainsString('"image" is the image 987654, which the media library does not hold', $this->api->requests[1]->messages[2]->content);
        self::assertStringContainsString('does not fit the site', $this->api->requests[1]->messages[2]->content);
        self::assertSame((int) $image->getId(), $result->document->sections[0]->props['image']);
        self::assertSame([], $result->leftOut);
        self::assertSame(1, CmsPageGenerationQuery::create()->findPk($result->logId)?->getCorrections());
    }

    public function testWhatTheModelDoesNotMendIsEmptiedAndSaidInsteadOfFailingTheGeneration(): void
    {
        $page = $this->createPage('Nos services', published: false);
        $locale = $this->locale();
        $answer = FakeAiGateway::response(self::document([
            ['id' => 'intro', 'component' => RichTextSection::NAME, 'props' => ['html' => '<h2>Nos services</h2>']],
            ['id' => 'photo', 'component' => ImageSection::NAME, 'props' => ['image' => 987654, 'alt' => 'Équipe']],
            ['id' => 'contact', 'component' => 'Partial:cms-form', 'props' => ['form' => '987654']],
        ]));

        $this->api->answer($answer)->answer($answer)->answer($answer);

        $result = $this->generator()->generate(GenerationRequest::fromBrief(GenerationTarget::page($page, $locale), $locale, new Brief('Des services, une photo, un formulaire.')));

        self::assertCount(3, $this->api->requests, 'The answer, then the two corrections of the limit.');
        self::assertSame(['intro', 'photo'], array_map(static fn (Section $section): string => $section->id, $result->document->sections), 'The section that cannot do without its form is left out.');
        self::assertNull($result->document->sections[1]->props['image'], 'The image the library lacks is emptied.');
        self::assertCount(3, $result->leftOut);
        self::assertStringContainsString('photo', $result->leftOut[0]);
        self::assertStringContainsString('contact', $result->leftOut[2]);

        $row = CmsPageGenerationQuery::create()->findPk($result->logId);
        self::assertSame(GenerationLog::STATUS_DONE, $row?->getStatus());
        self::assertSame(2, $row->getCorrections());
        self::assertSame($result->leftOut, GenerationLog::leftOutOf($row), 'The ledger keeps what the page leaves out.');
    }

    public function testASiteProblemAndAShapeProblemShareTheCountOfCorrections(): void
    {
        $page = $this->createPage('Nos services', published: false);
        $locale = $this->locale();
        $missing = FakeAiGateway::response(self::document([['id' => 'photo', 'component' => ImageSection::NAME, 'props' => ['image' => 987654, 'alt' => 'Équipe']]]));

        $this->api
            ->answer(FakeAiGateway::response('{"version":1,"sections":[{"id":"photo","component":"Cms:Nope","props":"{}"}]}'))
            ->answer($missing)
            ->answer($missing);

        $result = $this->generator()->generate(GenerationRequest::fromBrief(GenerationTarget::page($page, $locale), $locale, new Brief('Une photo.')));

        self::assertCount(3, $this->api->requests, 'One correction for the shape, one for the image, then the image is emptied.');
        self::assertNull($result->document->sections[0]->props['image']);
        self::assertCount(1, $result->leftOut);
        self::assertSame(2, CmsPageGenerationQuery::create()->findPk($result->logId)?->getCorrections());
    }

    public function testHeadingsGoBackToTheModelAndAreSaidWhenItLeavesThemAsTheyAre(): void
    {
        $page = $this->createPage('Nos services', published: false);
        $locale = $this->locale();
        $wrong = FakeAiGateway::response(self::document([['id' => 'intro', 'component' => RichTextSection::NAME, 'props' => ['html' => '<h2>Nos services</h2><h4>Suite</h4>']]]));
        $right = FakeAiGateway::response(self::document([['id' => 'intro', 'component' => RichTextSection::NAME, 'props' => ['html' => '<h2>Nos services</h2><h3>Suite</h3>']]]));

        $this->api->answer($wrong)->answer($right);
        $mended = $this->generator()->generate(GenerationRequest::fromBrief(GenerationTarget::page($page, $locale), $locale, new Brief('Nos services.')));

        self::assertSame(2, $mended->turns, 'A jump of heading level goes back.');
        self::assertStringContainsString('level 2 to level 4', $this->api->requests[1]->messages[2]->content);
        self::assertSame([], $mended->leftOut);

        $this->api->answer($wrong)->answer($wrong)->answer($wrong);
        $left = $this->generator()->generate(GenerationRequest::fromBrief(GenerationTarget::page($page, $locale), $locale, new Brief('Nos services.')));

        self::assertSame('<h2>Nos services</h2><h4>Suite</h4>', $left->document->sections[0]->props['html'], 'A heading is not rewritten by the module: the page keeps it, and the editor is told.');
        self::assertCount(1, $left->leftOut);
        self::assertStringContainsString(\sprintf('%s', 'fr_FR' === $locale ? 'La structure des titres de la page est à revoir' : 'The headings of the page need a look'), $left->leftOut[0]);
    }

    public function testATranslationIsNotSentBackForTheImagesItCopiesFromItsSource(): void
    {
        $page = $this->createPage('Nos services', published: false);
        $locale = $this->locale();
        $otherLocale = 'fr_FR' === $locale ? 'en_US' : 'fr_FR';
        $source = new Document([new Section('photo', ImageSection::NAME, ['image' => 987654, 'alt' => 'Équipe'])]);

        $this->api->answer(FakeAiGateway::response(self::document([['id' => 'photo', 'component' => ImageSection::NAME, 'props' => ['image' => 987654, 'alt' => 'Team']]])));

        $result = $this->generator()->generate(GenerationRequest::translation(GenerationTarget::page($page, $otherLocale), $otherLocale, $source, $locale));

        self::assertSame(1, $result->turns);
        self::assertSame([], $result->leftOut);
        self::assertSame(987654, $result->document->sections[0]->props['image'], 'The translation is the page of the source, a dead image included.');
    }

    public function testASearchTitleAndDescriptionAreAskedForAPageFromABriefAndForATranslationOnly(): void
    {
        $page = $this->createPage('Nos services', published: false);
        $locale = $this->locale();
        $otherLocale = 'fr_FR' === $locale ? 'en_US' : 'fr_FR';
        $source = new Document([new Section('intro', RichTextSection::NAME, ['html' => '<h2>Bonjour</h2>'])]);
        $withMeta = static fn (string $title, string $description): string => json_encode([...json_decode(self::document([['id' => 'intro', 'component' => RichTextSection::NAME, 'props' => ['html' => '<h2>Bonjour</h2>']]]), true, 16, \JSON_THROW_ON_ERROR), 'meta_title' => $title, 'meta_description' => $description], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);

        $this->api->answer(FakeAiGateway::response($withMeta('  Conseil en <b>stratégie</b>  ', "Des services de conseil\npour les PME.")));
        $brief = $this->generator()->generate(GenerationRequest::fromBrief(GenerationTarget::page($page, $locale), $locale, new Brief('Des services.')));

        $request = $this->api->requests[0];
        self::assertContains('meta_title', array_keys($request->outputSchema?->schema['properties'] ?? []));
        self::assertNotContains('meta_title', $request->outputSchema->schema['required'], 'Both are optional.');
        self::assertNotContains('meta_description', $request->outputSchema->schema['required']);
        self::assertStringContainsString('# What search engines show', $request->messages[0]->content);
        self::assertSame(['meta_title' => 'Conseil en stratégie', 'meta_description' => 'Des services de conseil pour les PME.'], $brief->seo, 'Plain text, on one line.');

        $this->api->answer(FakeAiGateway::response($withMeta('Our advice', 'Advice for small companies.')));
        $translation = $this->generator()->generate(GenerationRequest::translation(GenerationTarget::page($page, $otherLocale), $otherLocale, $source, $locale));

        self::assertContains('meta_description', array_keys($this->api->requests[1]->outputSchema?->schema['properties'] ?? []));
        self::assertStringContainsString('written from the translated texts', $this->api->requests[1]->messages[0]->content);
        self::assertSame(['meta_title' => 'Our advice', 'meta_description' => 'Advice for small companies.'], $translation->seo);

        // Not asked, so not read, whatever the model writes.
        $this->api->answer(FakeAiGateway::response($withMeta('Revised', 'Revised page.')));
        $revision = $this->generator()->generate(GenerationRequest::revision($this->target($page), $locale, $source, new Brief('Plus court.')));
        $this->api->answer(FakeAiGateway::response(self::section(['id' => 'intro', 'component' => RichTextSection::NAME, 'props' => ['html' => '<h2>Bonjour</h2>']])));
        $section = $this->generator()->generate(GenerationRequest::section($this->target($page), $locale, $source, 'intro', new Brief('Plus court.')));
        $item = new CatalogueItem(CatalogueItemType::Category, 1, $locale, 'Vernis', null, null, null, null);
        $this->api->answer(FakeAiGateway::response($withMeta('Item', 'Item page.')));
        $forItem = $this->generator()->generate(GenerationRequest::fromBrief(GenerationTarget::item($item), $locale, new Brief('Les vernis.')));

        foreach ([1 => $this->api->requests[2], 2 => $this->api->requests[3], 3 => $this->api->requests[4]] as $request) {
            self::assertNotContains('meta_title', array_keys($request->outputSchema?->schema['properties'] ?? []));
            self::assertStringNotContainsString('What search engines show', $request->messages[0]->content);
        }

        self::assertSame([[], [], []], [$revision->seo, $section->seo, $forItem->seo]);
    }

    public function testATitleOrADescriptionOverItsLimitIsIgnoredWithoutACorrection(): void
    {
        $page = $this->createPage('Nos services', published: false);
        $locale = $this->locale();
        $document = json_decode(self::document([['id' => 'intro', 'component' => RichTextSection::NAME, 'props' => ['html' => '<h2>Bonjour</h2>']]]), true, 16, \JSON_THROW_ON_ERROR);

        $this->api->answer(FakeAiGateway::response(json_encode([...$document, 'meta_title' => str_repeat('é', 61), 'meta_description' => str_repeat('a', 155)], \JSON_THROW_ON_ERROR)));
        $long = $this->generator()->generate(GenerationRequest::fromBrief(GenerationTarget::page($page, $locale), $locale, new Brief('Des services.')));

        self::assertSame(1, $long->turns, 'No correction turn is paid for a title that is too long.');
        self::assertSame(['meta_description' => str_repeat('a', 155)], $long->seo, '155 characters fit, 61 for the title do not.');

        $this->api->answer(FakeAiGateway::response(json_encode([...$document, 'meta_title' => str_repeat('é', 60), 'meta_description' => str_repeat('a', 156)], \JSON_THROW_ON_ERROR)));
        $edge = $this->generator()->generate(GenerationRequest::fromBrief(GenerationTarget::page($page, $locale), $locale, new Brief('Des services.')));

        self::assertSame(['meta_title' => str_repeat('é', 60)], $edge->seo, '60 characters fit, 156 for the description do not.');
        self::assertSame(1, $edge->turns);
    }

    private function readingOn(): void
    {
        $settings = $this->getService(GeneratorSettings::class);
        $settings->save($settings->effort(), $settings->maxTokens(), webFetch: true);
        ModuleConfigQuery::resetConfigCache();
    }

    /**
     * @param array<string, mixed> $section
     */
    private static function section(array $section): string
    {
        return json_encode([...$section, 'props' => json_encode($section['props'], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE)], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param list<array<string, mixed>> $sections
     */
    private static function document(array $sections): string
    {
        return json_encode([
            'version' => 1,
            'sections' => array_map(static fn (array $section): array => [...$section, 'props' => json_encode($section['props'], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE)], $sections),
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
    }

    private function generator(?ReadUrlTool $reader = null): PageGenerator
    {
        return new PageGenerator(
            $this->api,
            $this->getService(GeneratorSettings::class),
            $this->getService(PromptBuilder::class),
            $this->getService(GeneratorTools::class),
            $this->getService(GeneratorToolDefinitions::class),
            $reader ?? new ReadUrlTool(new MockHttpClient(static fn () => throw new \LogicException('No page may be read here.'))),
            $this->getService(MakeComponentTool::class),
            $this->getService(DocumentSchema::class),
            $this->getService(DocumentValidator::class),
            $this->getService(GenerationLog::class),
            new NullLogger(),
            new GatewayFailures($this->getService(TranslatorInterface::class)),
            $this->getService(TranslationGraft::class),
            $this->getService(DocumentChecker::class),
        );
    }

    private function target(\TheliaCMS\Model\CmsPage $page): GenerationTarget
    {
        return GenerationTarget::page($page, $this->locale());
    }

    private function lastStatus(\TheliaCMS\Model\CmsPage $page): ?string
    {
        return CmsPageGenerationQuery::create()->filterByTargetId($page->getId())->orderById(\Propel\Runtime\ActiveQuery\Criteria::DESC)->findOne()?->getStatus();
    }
}
