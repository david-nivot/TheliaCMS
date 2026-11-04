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
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Domain\Ai\AiFinishReason;
use Thelia\Domain\Ai\AiGatewayInterface;
use Thelia\Domain\Ai\AiToolCall;
use Thelia\Domain\Ai\AiToolDefinition;
use Thelia\Model\ModuleConfigQuery;
use TheliaCMS\Content\DocumentSchema;
use TheliaCMS\Content\DocumentValidator;
use TheliaCMS\Content\SectionCatalog;
use TheliaCMS\Content\Site\SiteComponentRepository;
use TheliaCMS\Generation\Ai\GatewayFailures;
use TheliaCMS\Generation\Ai\GeneratorToolDefinitions;
use TheliaCMS\Generation\Brief;
use TheliaCMS\Generation\Component\ComponentPrompt;
use TheliaCMS\Generation\GenerationLog;
use TheliaCMS\Generation\GenerationRequest;
use TheliaCMS\Generation\GenerationTarget;
use TheliaCMS\Generation\GeneratorSettings;
use TheliaCMS\Generation\PageGenerator;
use TheliaCMS\Generation\PromptBuilder;
use TheliaCMS\Generation\Tool\GeneratorTools;
use TheliaCMS\Generation\Tool\MakeComponentTool;
use TheliaCMS\Generation\Tool\ReadUrlTool;
use TheliaCMS\Generation\TranslationGraft;
use TheliaCMS\Model\CmsComponentQuery;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Model\CmsPageGenerationQuery;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;
use TheliaCMS\Tests\Support\FakeAiGateway;
use TheliaCMS\Tests\Unit\Content\Site\PriceTable;

/**
 * The generator writing a page makes a component of the site when the
 * catalogue lacks one: a call inside the call, the component kept as a
 * prototype, its name added to the schema the model is constrained to.
 */
final class MakeComponentToolTest extends CmsIntegrationTestCase
{
    private FakeAiGateway $api;

    protected function setUp(): void
    {
        parent::setUp();

        $this->api = new FakeAiGateway();
        static::getContainer()->set(AiGatewayInterface::class, $this->api);
    }

    protected function tearDown(): void
    {
        $settings = $this->getService(GeneratorSettings::class);
        $settings->save($settings->effort(), $settings->maxTokens(), webFetch: false, componentMaking: false);
        ModuleConfigQuery::resetConfigCache();

        parent::tearDown();
    }

    public function testTheToolIsOfferedWhenTheSiteAllowsItAndTheEditorMayMakeComponents(): void
    {
        $page = $this->createPage('Nos formules', published: false);
        $brief = new Brief('Nos trois formules.');
        $document = self::document([['id' => 'content', 'component' => 'cms:richtext', 'props' => ['html' => '<p>Ok.</p>']]]);

        $this->api->answer(FakeAiGateway::response($document));
        $this->generator()->generate(GenerationRequest::fromBrief($this->target($page), $this->locale(), $brief));
        self::assertNotContains(MakeComponentTool::NAME, self::toolNames($this->api->requests[0]->tools), 'Off until the site turns it on.');
        self::assertStringNotContainsString('make_component', $this->api->requests[0]->messages[0]->content);

        $this->makingOn();
        $this->api->answer(FakeAiGateway::response($document));
        $this->generator()->generate(GenerationRequest::fromBrief($this->target($page), $this->locale(), $brief));
        self::assertNotContains(MakeComponentTool::NAME, self::toolNames($this->api->requests[1]->tools), 'The setting alone is not enough: whoever asks has to be allowed to make components.');

        $this->api->answer(FakeAiGateway::response($document));
        $this->generator()->generate(GenerationRequest::fromBrief($this->target($page), $this->locale(), $brief)->withComponentMaking(true));
        self::assertContains(MakeComponentTool::NAME, self::toolNames($this->api->requests[2]->tools));
        self::assertStringContainsString('`make_component`', $this->api->requests[2]->messages[0]->content, 'The model is told when it may make one.');

        $this->api->answer(FakeAiGateway::response($document));
        $otherLocale = 'fr_FR' === $this->locale() ? 'en_US' : 'fr_FR';
        $this->generator()->generate(GenerationRequest::translation($this->target($page), $otherLocale, \TheliaCMS\Content\Document::richText('<p>Source</p>'), $this->locale())->withComponentMaking(true));
        self::assertNotContains(MakeComponentTool::NAME, self::toolNames($this->api->requests[3]->tools), 'A translation keeps the components of its source.');
    }

    public function testAComponentMadeMidwayIsAPrototypeTheSchemaAndTheDocumentMayName(): void
    {
        $this->makingOn();
        $page = $this->createPage('Nos formules', published: false);
        $component = PriceTable::component(css: '.plan { padding: 1rem; }');

        $this->api
            ->answer(FakeAiGateway::response(toolCalls: [new AiToolCall('toolu_1', MakeComponentTool::NAME, ['request' => 'Un tableau de deux à quatre formules, une mise en avant.'])]))
            // The nested call: the component itself.
            ->answer(FakeAiGateway::response(ComponentPrompt::json($component), logId: 77))
            ->answer(FakeAiGateway::response(self::document([
                ['id' => 'intro', 'component' => 'cms:richtext', 'props' => ['html' => '<h1>Nos formules</h1>']],
                ['id' => 'plans', 'component' => 'Site:PriceTable', 'props' => ['title' => 'Trois formules', 'plans' => [['name' => 'Studio', 'price' => '9 €'], ['name' => 'Pro', 'price' => '29 €', 'featured' => true]]]],
            ])));

        $result = $this->generator()->generate(GenerationRequest::fromBrief($this->target($page), $this->locale(), new Brief('Nos trois formules, en tableau.'))->withComponentMaking(true));

        self::assertSame(['cms:richtext', 'Site:PriceTable'], array_map(static fn ($section) => $section->component, $result->document->sections));
        self::assertSame('light', $result->document->sections[1]->props['tone'], 'The document is validated by the component made: its defaults are filled.');
        self::assertSame(2, $result->turns, 'The nested call is not a turn of the page.');

        self::assertCount(3, $this->api->requests);
        $nested = $this->api->requests[1];
        self::assertSame('theliacms.component', $nested->usageId, 'The component is made under its own usage.');
        self::assertStringContainsString('Un tableau de deux à quatre formules', $nested->messages[0]->content);

        $after = $this->api->requests[2];
        self::assertContains('Site:PriceTable', $after->outputSchema?->schema['properties']['sections']['items']['properties']['component']['enum'] ?? [], 'The schema of the next turn names the component made.');
        self::assertNotContains('Site:PriceTable', $this->api->requests[0]->outputSchema?->schema['properties']['sections']['items']['properties']['component']['enum'] ?? [], 'It was not there before.');
        $toolResult = $after->messages[2]->content;
        self::assertStringContainsString('`Site:PriceTable` is made', $toolResult);
        self::assertStringContainsString('plans', $toolResult, 'The sheet of the component is handed back.');

        $repository = $this->getService(SiteComponentRepository::class);
        self::assertSame(SiteComponentRepository::STATUS_DRAFT, $repository->status('PriceTable'), 'Kept as a prototype: offered to nobody else.');
        $catalog = $this->getService(SectionCatalog::class);
        $catalog->reset();
        self::assertNotContains('Site:PriceTable', $catalog->offeredNames());

        $componentId = (int) CmsComponentQuery::create()->findOneByCode('PriceTable')?->getId();
        $componentRow = CmsPageGenerationQuery::create()->filterByTargetType(GenerationTarget::TYPE_COMPONENT)->findOne();
        self::assertSame($componentId, (int) $componentRow?->getTargetId(), 'The nested call is filed under the component.');
        self::assertSame(77, $componentRow?->getLogId());
        self::assertSame('done', CmsPageGenerationQuery::create()->findPk($result->logId)?->getStatus());
    }

    public function testAComponentTheSiteRefusesIsSaidToTheModelAndTheDocumentGoesOnWithout(): void
    {
        $this->makingOn();
        $page = $this->createPage('Nos formules', published: false);

        $this->api
            ->answer(FakeAiGateway::response(toolCalls: [new AiToolCall('toolu_1', MakeComponentTool::NAME, ['request' => 'Quelque chose.'])]))
            ->answer(FakeAiGateway::response(finishReason: AiFinishReason::ContentFilter))
            ->answer(FakeAiGateway::response(self::document([['id' => 'content', 'component' => 'cms:richtext', 'props' => ['html' => '<p>Sans.</p>']]])));

        $result = $this->generator()->generate(GenerationRequest::fromBrief($this->target($page), $this->locale(), new Brief('Quelque chose.'))->withComponentMaking(true));

        self::assertSame(['cms:richtext'], array_map(static fn ($section) => $section->component, $result->document->sections));
        self::assertStringStartsWith('The component could not be made:', $this->api->requests[2]->messages[2]->content);
        self::assertSame(0, CmsComponentQuery::create()->count(), 'Nothing is kept.');
        self::assertSame(1, CmsPageGenerationQuery::create()->filterByTargetType(GenerationTarget::TYPE_COMPONENT)->count(), 'The call that failed is in the ledger all the same.');
    }

    public function testNoMoreThanTwoComponentsAreMadeForOnePage(): void
    {
        $this->makingOn();
        $page = $this->createPage('Nos formules', published: false);
        $second = PriceTable::component();
        $second = new \TheliaCMS\Content\Site\SiteComponent('Timeline', 'Timeline', 'Steps in order.', $second->props, $second->sample, $second->template);

        $this->api
            ->answer(FakeAiGateway::response(toolCalls: [new AiToolCall('toolu_1', MakeComponentTool::NAME, ['request' => 'Un tableau de prix.'])]))
            ->answer(FakeAiGateway::response(ComponentPrompt::json(PriceTable::component())))
            ->answer(FakeAiGateway::response(toolCalls: [new AiToolCall('toolu_2', MakeComponentTool::NAME, ['request' => 'Une frise.'])]))
            ->answer(FakeAiGateway::response(ComponentPrompt::json($second)))
            ->answer(FakeAiGateway::response(toolCalls: [new AiToolCall('toolu_3', MakeComponentTool::NAME, ['request' => 'Encore un.'])]))
            ->answer(FakeAiGateway::response(self::document([['id' => 'content', 'component' => 'cms:richtext', 'props' => ['html' => '<p>Ok.</p>']]])));

        $this->generator()->generate(GenerationRequest::fromBrief($this->target($page), $this->locale(), new Brief('Beaucoup de choses.'))->withComponentMaking(true));

        self::assertCount(6, $this->api->requests, 'The third request makes no call.');
        self::assertStringStartsWith('No more than 2 components', $this->api->requests[5]->messages[6]->content);
        self::assertSame(2, CmsComponentQuery::create()->count());
    }

    public function testTheToolIsNotRunWhenItWasNotOffered(): void
    {
        $page = $this->createPage('Nos formules', published: false);
        $this->api
            ->answer(FakeAiGateway::response(toolCalls: [new AiToolCall('toolu_1', MakeComponentTool::NAME, ['request' => 'Un tableau de prix.'])]))
            ->answer(FakeAiGateway::response(self::document([['id' => 'content', 'component' => 'cms:richtext', 'props' => ['html' => '<p>Ok.</p>']]])));

        $this->generator()->generate(GenerationRequest::fromBrief($this->target($page), $this->locale(), new Brief('Un tableau.')));

        self::assertSame('There is no tool called "make_component".', $this->api->requests[1]->messages[2]->content);
        self::assertCount(2, $this->api->requests, 'No nested call.');
        self::assertSame(0, CmsComponentQuery::create()->count());
    }

    private function makingOn(): void
    {
        $settings = $this->getService(GeneratorSettings::class);
        $settings->save($settings->effort(), $settings->maxTokens(), webFetch: false, componentMaking: true);
        ModuleConfigQuery::resetConfigCache();
    }

    private function generator(): PageGenerator
    {
        return new PageGenerator(
            $this->api,
            $this->getService(GeneratorSettings::class),
            $this->getService(PromptBuilder::class),
            $this->getService(GeneratorTools::class),
            $this->getService(GeneratorToolDefinitions::class),
            new ReadUrlTool(new MockHttpClient(static fn () => throw new \LogicException('No page may be read here.'))),
            $this->getService(MakeComponentTool::class),
            $this->getService(DocumentSchema::class),
            $this->getService(DocumentValidator::class),
            $this->getService(GenerationLog::class),
            new NullLogger(),
            new GatewayFailures($this->getService(TranslatorInterface::class)),
            $this->getService(TranslationGraft::class),
        );
    }

    private function target(CmsPage $page): GenerationTarget
    {
        return GenerationTarget::page($page, $this->locale());
    }

    /**
     * @param list<AiToolDefinition> $tools
     *
     * @return list<string>
     */
    private static function toolNames(array $tools): array
    {
        return array_map(static fn (AiToolDefinition $tool): string => $tool->name, $tools);
    }

    /**
     * @param list<array{id: string, component: string, props: array<string, mixed>}> $sections
     */
    private static function document(array $sections): string
    {
        return json_encode(['version' => 1, 'sections' => $sections], \JSON_THROW_ON_ERROR);
    }
}
