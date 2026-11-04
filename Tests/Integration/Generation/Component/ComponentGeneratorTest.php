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

namespace TheliaCMS\Tests\Integration\Generation\Component;

use Psr\Log\NullLogger;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Domain\Ai\AiFinishReason;
use Thelia\Domain\Ai\AiMessageRole;
use Thelia\Domain\Ai\AsAiUsage;
use Thelia\Domain\Ai\Exception\AiBudgetExceededException;
use TheliaCMS\Content\SectionCatalog;
use TheliaCMS\Content\Site\SiteComponent;
use TheliaCMS\Content\Site\SiteComponentRepository;
use TheliaCMS\Content\Site\SiteComponentUsage;
use TheliaCMS\Content\Site\SiteComponentValidator;
use TheliaCMS\Front\BlockStyles;
use TheliaCMS\Generation\Ai\GatewayFailures;
use TheliaCMS\Generation\Component\ComponentCandidates;
use TheliaCMS\Generation\Component\ComponentGenerator;
use TheliaCMS\Generation\Component\ComponentPrompt;
use TheliaCMS\Generation\Component\ComponentRequest;
use TheliaCMS\Generation\GenerationException;
use TheliaCMS\Generation\GenerationLog;
use TheliaCMS\Generation\GenerationObserverInterface;
use TheliaCMS\Generation\GenerationRefusedException;
use TheliaCMS\Generation\GenerationTarget;
use TheliaCMS\Generation\GeneratorSettings;
use TheliaCMS\Generation\InvalidGenerationException;
use TheliaCMS\Generation\ShopContext;
use TheliaCMS\Model\CmsComponentQuery;
use TheliaCMS\Model\CmsPageGenerationQuery;
use TheliaCMS\Skill\SkillRepository;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;
use TheliaCMS\Tests\Support\FakeAiGateway;
use TheliaCMS\Tests\Unit\Content\Site\PriceTable;

/**
 * A request becomes a component the site can keep, through the schema of the
 * answer and as many corrections as it needs; every call is in the ledger.
 */
final class ComponentGeneratorTest extends CmsIntegrationTestCase
{
    private FakeAiGateway $api;

    protected function setUp(): void
    {
        parent::setUp();

        $this->api = new FakeAiGateway();
    }

    public function testARequestBecomesAComponentTheSiteCanKeep(): void
    {
        $this->api->answer(self::answer(PriceTable::component()));

        $result = $this->generator()->generate(new ComponentRequest('Un tableau de prix de deux à quatre formules.', $this->locale()));

        self::assertSame('Site:PriceTable', $result->component->name());
        self::assertSame('light', $result->component->sample['tone'], 'What is handed back is what the validator gave.');
        self::assertSame(1, $result->turns);

        $request = $this->api->requests[0];
        self::assertSame('theliacms.component', $request->usageId, 'The call is the usage the module declares.');
        self::assertSame(ComponentPrompt::schema(), $request->outputSchema?->schema, 'The answer is constrained to a component.');
        self::assertSame([], $request->tools, 'Making a component needs no tool of the site.');
        self::assertStringContainsString('var(--cms-color-accent)', (string) $request->system, 'The tokens of the stylesheet are named.');
        self::assertStringContainsString('# Movement and interaction without script', (string) $request->system, 'The skill on motion is read beside the one on accessibility.');
        self::assertStringContainsString('`cms:richtext`', $request->messages[0]->content, 'The model is told what the site already has.');
        self::assertStringContainsString('Un tableau de prix', $request->messages[0]->content);
        self::assertSame(AiMessageRole::User, $request->messages[0]->role);
        self::assertSame('fake-model', $result->model);
        self::assertSame(10, $result->usage->inputTokens);
        self::assertSame(0.001, $result->costUsd, 'The cost is the one the gateway measured.');

        $row = CmsPageGenerationQuery::create()->findPk($result->logId);
        self::assertNotNull($row);
        self::assertSame([GenerationTarget::TYPE_COMPONENT, 0, ComponentRequest::KIND_NEW, 'done'], [$row->getTargetType(), (int) $row->getTargetId(), $row->getKind(), $row->getStatus()]);
        self::assertStringContainsString('"code": "PriceTable"', (string) $row->getDocument());
        self::assertNull($this->getService(SiteComponentRepository::class)->find('PriceTable'), 'Nothing is kept before the editor says so.');
    }

    public function testASkillTheSiteSwitchedOffIsNotRead(): void
    {
        $skills = $this->getService(SkillRepository::class);

        foreach (['accessibility', 'motion'] as $code) {
            $skill = $skills->find($code);
            self::assertNotNull($skill);
            $skills->save($code, $skill->body, false);
        }

        $this->api->answer(self::answer(PriceTable::component()));
        $this->generator()->generate(new ComponentRequest('Un tableau de prix de deux à quatre formules.', $this->locale()));

        $system = (string) $this->api->requests[0]->system;
        self::assertStringNotContainsString('# Accessibility', $system, 'A skill switched off under CMS > Skills is read by no generator.');
        self::assertStringNotContainsString('# Movement and interaction without script', $system);
        self::assertStringContainsString('# Stylesheet', $system, 'The rules of the site are still sent.');
    }

    public function testTheMarkOfTheCoreFollowsTheComponentFromTheCallToTheComponentKept(): void
    {
        $this->api->answer(FakeAiGateway::response(ComponentPrompt::json(PriceTable::component()), provenance: FakeAiGateway::provenance('theliacms.component')));

        $result = $this->generator()->generate(new ComponentRequest('Un tableau de prix.', $this->locale()));

        self::assertSame('theliacms.component', $result->provenance?->usageId);
        self::assertStringContainsString('"usage":"theliacms.component"', (string) CmsPageGenerationQuery::create()->findPk($result->logId)?->getProvenance(), 'The row of the ledger keeps the mark of its call.');

        $candidate = (new ComponentCandidates())->find($result->logId);
        self::assertNotNull($candidate?->component?->provenance, 'The candidate read back from the ledger still carries it.');

        $repository = $this->getService(SiteComponentRepository::class);
        $repository->save((new SiteComponentValidator())->validate($candidate->component), SiteComponentRepository::STATUS_READY);

        self::assertSame($candidate->component->provenance, $repository->find('PriceTable')?->provenance, 'Kept, the component is marked as a generated one.');
        self::assertStringContainsString('"model":"fake-model"', (string) CmsComponentQuery::create()->findOneByCode('PriceTable')?->getProvenance());

        $byHand = new SiteComponent('PriceTable', 'Prix', 'Un tableau de prix.', $candidate->component->props, $candidate->component->sample, $candidate->component->template);
        $repository->save($byHand, SiteComponentRepository::STATUS_READY, 'PriceTable');
        self::assertSame($candidate->component->provenance, $repository->find('PriceTable')?->provenance, 'A save without a mark leaves the mark the component has.');
    }

    public function testAComponentTheSiteRefusesGoesBackWithEveryProblemAndTheCorrectionIsKept(): void
    {
        $this->api
            ->answer(self::answer(PriceTable::component('<section><h2>Nos formules</h2>{{ props.title|raw }}</section>')))
            ->answer(self::answer(PriceTable::component()));

        $result = $this->generator()->generate(new ComponentRequest('Un tableau de prix.', $this->locale()));

        self::assertSame(2, $result->turns);
        $correction = $this->api->requests[1]->messages[2]->content;
        self::assertStringContainsString('cannot be kept', $correction);
        self::assertStringContainsString('"raw"', $correction);
        self::assertSame(AiMessageRole::Assistant, $this->api->requests[1]->messages[1]->role, 'The answer refused goes back with the problems.');
    }

    public function testANewComponentTakesAFreeCodeAndAChangeKeepsItsOwn(): void
    {
        $repository = $this->getService(SiteComponentRepository::class);
        $repository->save((new SiteComponentValidator())->validate(PriceTable::component()), SiteComponentRepository::STATUS_READY);
        $current = $repository->find('PriceTable');
        self::assertNotNull($current);
        $renamed = PriceTable::component();
        $renamed = new SiteComponent('PriceGrid', $renamed->label, $renamed->description, $renamed->props, $renamed->sample, $renamed->template);

        $this->api->answer(self::answer(PriceTable::component()))->answer(self::answer($renamed));
        $made = $this->generator()->generate(new ComponentRequest('Un tableau de prix.', $this->locale()));

        self::assertSame('PriceGrid', $made->component->code);
        self::assertStringContainsString('"PriceTable" is already', $this->api->requests[1]->messages[2]->content);

        $this->api->answer(self::answer($renamed))->answer(self::answer(PriceTable::component(css: '.plan--featured { border: 2px solid var(--cms-color-accent); }')));
        $id = (int) CmsComponentQuery::create()->findOneByCode('PriceTable')?->getId();
        $changed = $this->generator()->generate(new ComponentRequest('Mets la formule mise en avant en valeur.', $this->locale(), $current, $id));

        self::assertSame('PriceTable', $changed->component->code);
        self::assertStringContainsString('stays "PriceTable"', $this->api->requests[3]->messages[2]->content);
        self::assertStringContainsString('"code": "PriceTable"', $this->api->requests[2]->messages[0]->content, 'The component to change is sent with the request.');
        self::assertSame([$id, ComponentRequest::KIND_CHANGE], [(int) CmsPageGenerationQuery::create()->findPk($changed->logId)?->getTargetId(), CmsPageGenerationQuery::create()->findPk($changed->logId)?->getKind()]);
    }

    public function testAChangeThatBreaksThePagesUsingTheComponentGoesBackNamingThem(): void
    {
        $repository = $this->getService(SiteComponentRepository::class);
        $repository->save((new SiteComponentValidator())->validate(PriceTable::component()), SiteComponentRepository::STATUS_READY);
        $current = $repository->find('PriceTable');
        self::assertNotNull($current);
        $page = $this->createPage('Nos tarifs', published: false);
        $this->writer()->saveContent($page, $this->locale(), new \TheliaCMS\Content\Document([new \TheliaCMS\Content\Section('plans', 'Site:PriceTable', ['plans' => [['name' => 'Studio', 'price' => '40 €']]])]));

        $this->api->answer(self::answer(PriceTable::renamingThePlanName()))->answer(self::answer(PriceTable::component(css: '.plan { padding: 1rem; }')));
        $result = $this->generator()->generate(new ComponentRequest('Renomme le nom des formules en titre.', $this->locale(), $current, (int) CmsComponentQuery::create()->findOneByCode('PriceTable')?->getId()));

        self::assertSame(2, $result->turns);
        $correction = $this->api->requests[1]->messages[2]->content;
        self::assertStringContainsString('"Nos tarifs"', $correction, 'The page the version would break is named.');
        self::assertStringContainsString('"plans"', $correction);
        self::assertSame('.plan { padding: 1rem; }', $result->component->css);
    }

    public function testAComponentRefusedThreeTimesIsAnErrorInTheLedger(): void
    {
        $broken = self::answer(PriceTable::component('{% include "base.html.twig" %}'));
        $this->api->answer($broken)->answer($broken)->answer($broken);

        try {
            $this->generator()->generate(new ComponentRequest('Un tableau de prix.', $this->locale()));
            self::fail('A component that never holds is an error.');
        } catch (InvalidGenerationException $exception) {
            self::assertCount(3, $this->api->requests);
            self::assertSame('invalid', CmsPageGenerationQuery::create()->findPk($exception->logId)?->getStatus());
        }
    }

    public function testARefusalOfTheModelIsRecorded(): void
    {
        $this->api->answer(FakeAiGateway::response(finishReason: AiFinishReason::ContentFilter));

        try {
            $this->generator()->generate(new ComponentRequest('Something the model declines.', $this->locale()));
            self::fail('A refusal is no component.');
        } catch (GenerationRefusedException $exception) {
            $row = CmsPageGenerationQuery::create()->findPk($exception->logId);
            self::assertNotNull($row);
            self::assertSame(['refused', 'content_filter'], [$row->getStatus(), $row->getStopReason()]);
        }
    }

    public function testAnAnswerCutByTheOutputLimitIsAnErrorInTheLedgerAndIsNotCorrected(): void
    {
        $this->api->answer(FakeAiGateway::response('{"code": "PriceTa', finishReason: AiFinishReason::MaxTokens, outputTokens: $this->getService(GeneratorSettings::class)->maxTokens()));

        try {
            $this->generator()->generate(new ComponentRequest('Un tableau de prix.', $this->locale()));
            self::fail('A cut answer is no component.');
        } catch (GenerationException $exception) {
            self::assertStringContainsString('was cut at', $exception->getMessage());
            self::assertCount(1, $this->api->requests, 'A cut answer is not sent back for correction.');
            self::assertSame('failed', CmsPageGenerationQuery::create()->findPk($exception->logId)?->getStatus());
        }
    }

    public function testTheTextOfTheStreamIsToldToTheObserverAsItArrives(): void
    {
        $json = ComponentPrompt::json(PriceTable::component());
        $this->api->streamed([substr($json, 0, 10), substr($json, 10)], FakeAiGateway::response($json));
        $observer = new class implements GenerationObserverInterface {
            /** @var list<int> */
            public array $characters = [];

            public function onTurn(int $turn, array $tools): void
            {
            }

            public function onText(int $characters): void
            {
                $this->characters[] = $characters;
            }

            public function onCorrection(int $round, array $problems): void
            {
            }
        };

        $this->generator()->generate(new ComponentRequest('Un tableau de prix.', $this->locale()), $observer);

        self::assertSame([10, mb_strlen($json)], $observer->characters);
    }

    public function testTheGatewayRefusingTheCallIsAnErrorInTheLedger(): void
    {
        $this->api->answer(new AiBudgetExceededException('theliacms.component'));

        try {
            $this->generator()->generate(new ComponentRequest('Un tableau de prix.', $this->locale()));
            self::fail('A call the gateway refuses makes no component.');
        } catch (GenerationException $exception) {
            self::assertStringContainsString('The AI budget of the site is spent for now', $exception->getMessage());
            self::assertSame('failed', CmsPageGenerationQuery::create()->findPk($exception->logId)?->getStatus());
        }
    }

    public function testTheUsageIsDeclaredOnTheGenerator(): void
    {
        $attributes = (new \ReflectionClass(ComponentGenerator::class))->getAttributes(AsAiUsage::class);

        self::assertCount(1, $attributes);
        $usage = $attributes[0]->newInstance();
        self::assertSame(['theliacms.component', 'admin', 'advanced', 'generative'], [$usage->id, $usage->audience->value, $usage->tier->value, $usage->transparency->value]);
    }

    private static function answer(SiteComponent $component): \Thelia\Domain\Ai\AiResponse
    {
        return FakeAiGateway::response(ComponentPrompt::json($component));
    }

    private function generator(): ComponentGenerator
    {
        return new ComponentGenerator(
            $this->api,
            $this->getService(GeneratorSettings::class),
            new ComponentPrompt($this->getService(SkillRepository::class), $this->getService(SectionCatalog::class), $this->getService(ShopContext::class), new BlockStyles()),
            new SiteComponentValidator(),
            $this->getService(SiteComponentRepository::class),
            $this->getService(GenerationLog::class),
            new NullLogger(),
            new GatewayFailures($this->getService(TranslatorInterface::class)),
            new SiteComponentUsage(),
        );
    }
}
