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

namespace TheliaCMS\Tests\Integration\Evaluation;

use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Model\ConfigQuery;
use TheliaCMS\Content\SectionCatalog;
use TheliaCMS\Content\Site\SiteComponentRepository;
use TheliaCMS\Content\Site\SiteComponentUsage;
use TheliaCMS\Content\Site\SiteComponentValidator;
use TheliaCMS\Evaluation\ComponentBench\BenchPreviews;
use TheliaCMS\Evaluation\ComponentBench\ComponentBenchGenerators;
use TheliaCMS\Evaluation\ComponentBench\ComponentBenchRunCommand;
use TheliaCMS\Evaluation\ComponentBench\TrialFiles;
use TheliaCMS\Front\BlockStyles;
use TheliaCMS\Generation\Ai\GatewayFailures;
use TheliaCMS\Generation\Component\ComponentPrompt;
use TheliaCMS\Generation\GenerationLog;
use TheliaCMS\Generation\GeneratorSettings;
use TheliaCMS\Generation\ShopContext;
use TheliaCMS\Model\CmsPageGenerationQuery;
use TheliaCMS\Preview\ComponentPreviewLink;
use TheliaCMS\Skill\SkillRepository;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;
use TheliaCMS\Tests\Support\ComponentOutcome;
use TheliaCMS\Tests\Support\FakeAiGateway;
use TheliaCMS\Tests\Unit\Content\Site\PriceTable;

/**
 * `thelia_cms:component-bench:run`, played against the fake gateway: what a
 * trial file holds, the budget, the resumption. No call leaves the machine.
 */
final class ComponentBenchRunCommandTest extends CmsIntegrationTestCase
{
    private FakeAiGateway $api;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->api = new FakeAiGateway();
        $this->directory = THELIA_ROOT.'var/tests/component-bench/'.bin2hex(random_bytes(4));
        mkdir($this->directory.'/cases', 0o775, true);
        ConfigQuery::write('url_site', 'https://bench.example');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);

        parent::tearDown();
    }

    public function testATrialThatMakesAComponentIsWrittenInTheFormOfThePlan(): void
    {
        $this->writeCase('table-prices');
        $this->api->answer(FakeAiGateway::response(ComponentPrompt::json(PriceTable::component())));

        $tester = $this->play(['--round' => 'round-t', '--case' => ['table-prices'], '--runs' => '1']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $trial = TrialFiles::read($this->directory.'/out/round-t/table-prices/1.json');
        $sent = $this->api->requests[0];
        $row = CmsPageGenerationQuery::create()->orderById('desc')->findOne();
        self::assertNotNull($row);

        self::assertSame(['table-prices', 'round-t', 1, 'fr_FR', 'Un tableau de prix de deux à quatre formules.'], [$trial['case'], $trial['round'], $trial['run'], $trial['locale'], $trial['request']]);
        self::assertSame('2.24.0', $trial['module'], 'The version of config/module.xml.');
        self::assertSame(['fake-model', 'high'], [$trial['model'], $trial['effort']]);
        self::assertSame((string) $sent->system, $trial['system']);
        self::assertSame($sent->messages[0]->content, $trial['user']);
        self::assertSame(hash('sha256', $sent->system."\n\n".$sent->messages[0]->content), $trial['prompt_sha256'], 'The hash of the system prompt and the first message of the user.');
        self::assertSame([ComponentPrompt::json(PriceTable::component())], $trial['answers'], 'The raw answer, as the gateway gave it.');
        self::assertSame(['component', null, null], [$trial['outcome'], $trial['existing'], $trial['based_on']]);
        self::assertSame('PriceTable', $trial['code']);
        self::assertSame('cms-site--price-table', $trial['scope_class'], 'The class the browser script aims at, with `.cms-site`.');
        self::assertSame('PriceTable', $trial['component']['code']);
        self::assertSame(PriceTable::TEMPLATE, $trial['component']['template']);
        self::assertSame('Basic', $trial['component']['sample']['plans'][0]['name']);
        self::assertSame([1, 0, 0.001], [$trial['turns'], $trial['corrections'], $trial['cost_usd']]);
        self::assertSame((int) $row->getId(), $trial['ledger_id']);
        self::assertMatchesRegularExpression('#^https://bench\.example/cms-component-preview/candidate-'.$row->getId().'/fr_FR/\d+/[0-9a-f]{64}$#', $trial['preview_url'], 'Absolute, signed.');
        self::assertNull($trial['error']);
        self::assertNull($trial['verdict'], 'The verdict is the work of `check`.');
        self::assertStringContainsString('table-prices #1  component  turns=1', $tester->getDisplay());
        self::assertSame([], glob($this->directory.'/out/round-t/table-prices/*.tmp') ?: [], 'No file left half written.');
    }

    public function testACorrectionIsCountedAndEveryAnswerKept(): void
    {
        $this->writeCase('table-prices');
        $this->api
            ->answer(FakeAiGateway::response('this is not JSON'))
            ->answer(FakeAiGateway::response(ComponentPrompt::json(PriceTable::component())));

        $this->play(['--round' => 'round-t', '--runs' => '1']);

        $trial = TrialFiles::read($this->directory.'/out/round-t/table-prices/1.json');
        self::assertSame(['this is not JSON', ComponentPrompt::json(PriceTable::component())], $trial['answers']);
        self::assertSame([2, 1], [$trial['turns'], $trial['corrections']]);
        self::assertEqualsWithDelta(0.002, $trial['cost_usd'], 0.0000001);
    }

    public function testAnExistingComponentLeavesNoComponentNorPreview(): void
    {
        $this->writeCase('reuse-photo');
        $this->api->answer(ComponentOutcome::existing('cms:image', 'cms:image already shows one picture and its caption.'));

        $this->play(['--round' => 'round-t', '--runs' => '1']);

        $trial = TrialFiles::read($this->directory.'/out/round-t/reuse-photo/1.json');
        self::assertSame(['existing', 'cms:image', null, null, null, null], [$trial['outcome'], $trial['existing'], $trial['based_on'], $trial['component'], $trial['code'], $trial['preview_url']]);
        self::assertSame(1, $trial['turns']);
        self::assertCount(1, $trial['answers']);
        self::assertNull($trial['error']);
    }

    public function testACopyNamesWhatItIsBasedOn(): void
    {
        $this->getService(SiteComponentRepository::class)->save(PriceTable::component(), SiteComponentRepository::STATUS_READY);
        $variant = PriceTable::component();
        $variant = new \TheliaCMS\Content\Site\SiteComponent('PriceTableDark', $variant->label, $variant->description, $variant->props, $variant->sample, $variant->template, $variant->css);
        $this->writeCase('table-dark');
        $this->api
            ->answer(ComponentOutcome::copy('Site:PriceTable', 'A dark look of Site:PriceTable.'))
            ->answer(FakeAiGateway::response(ComponentPrompt::json($variant)));

        $this->play(['--round' => 'round-t', '--runs' => '1']);

        $trial = TrialFiles::read($this->directory.'/out/round-t/table-dark/1.json');
        self::assertSame(['component', 'Site:PriceTable', 'PriceTableDark'], [$trial['outcome'], $trial['based_on'], $trial['code']]);
        self::assertSame([2, 0], [$trial['turns'], $trial['corrections']], 'The second turn is the copy, not a correction.');
        self::assertCount(2, $trial['answers']);
        self::assertNotNull($trial['preview_url']);
    }

    public function testAFailedGenerationIsATrialAndTheRoundGoesOn(): void
    {
        $this->writeCase('table-a');
        $this->writeCase('table-b');
        $this->api
            ->answer(new \RuntimeException('the provider is down'))
            ->answer(FakeAiGateway::response(ComponentPrompt::json(PriceTable::component())));

        $tester = $this->play(['--round' => 'round-t', '--runs' => '1']);

        $failed = TrialFiles::read($this->directory.'/out/round-t/table-a/1.json');
        self::assertNotNull($failed['error']);
        self::assertSame([null, null, null, []], [$failed['outcome'], $failed['component'], $failed['preview_url'], $failed['answers']]);
        self::assertIsInt($failed['ledger_id'], 'The failure is in the ledger too.');
        self::assertStringContainsString('table-a #1  ERROR', $tester->getDisplay());

        $second = TrialFiles::read($this->directory.'/out/round-t/table-b/1.json');
        self::assertSame('component', $second['outcome']);
    }

    public function testAnAnswerTheSiteKeepsRefusingIsATrialThatKeepsItsAnswersAndItsCost(): void
    {
        $this->writeCase('table-prices');

        for ($index = 0; $index < 3; ++$index) {
            $this->api->answer(FakeAiGateway::response('this is not JSON'));
        }

        $this->play(['--round' => 'round-t', '--runs' => '1']);

        $trial = TrialFiles::read($this->directory.'/out/round-t/table-prices/1.json');
        self::assertNotNull($trial['error']);
        self::assertSame(['fake-model', 3, null, null], [$trial['model'], $trial['turns'], $trial['corrections'], $trial['outcome']]);
        self::assertCount(3, $trial['answers']);
        self::assertEqualsWithDelta(0.003, $trial['cost_usd'], 0.0000001, 'A refused answer was paid: the ledger says how much.');
    }

    public function testNoTrialStartsOnceTheCostOfTheRoundReachesTheBudget(): void
    {
        $this->writeCase('table-prices');

        for ($index = 0; $index < 3; ++$index) {
            $this->api->answer(FakeAiGateway::response(ComponentPrompt::json(PriceTable::component())));
        }

        // 0.001 USD a trial: the second starts under the budget, the third does not.
        $tester = $this->play(['--round' => 'round-t', '--runs' => '3', '--budget-usd' => '0.0015']);

        self::assertFileExists($this->directory.'/out/round-t/table-prices/2.json');
        self::assertFileDoesNotExist($this->directory.'/out/round-t/table-prices/3.json');
        self::assertSame(1, $this->api->remaining(), 'The third answer was never asked for.');
        self::assertStringContainsString('Budget reached', $tester->getDisplay());
        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    public function testTheFilesAlreadyWrittenAreNotPlayedAgainAndTheirCostCountsAgainstTheBudget(): void
    {
        $this->writeCase('table-prices');
        $this->writeCase('table-prices-bis');
        $existing = $this->directory.'/out/round-t/table-prices/1.json';
        TrialFiles::write($existing, ['case' => 'table-prices', 'run' => 1, 'cost_usd' => 0.4, 'marker' => 'kept as it was']);
        $this->api->answer(FakeAiGateway::response(ComponentPrompt::json(PriceTable::component())));

        // Resumption: only what is missing is played.
        $this->play(['--round' => 'round-t', '--runs' => '1']);

        self::assertCount(1, $this->api->requests, 'The case that had its file was not asked again.');
        self::assertSame('kept as it was', TrialFiles::read($existing)['marker']);
        self::assertFileExists($this->directory.'/out/round-t/table-prices-bis/1.json');

        // 0.4 USD already in the round (and 0.001 more): a budget of 0.4 stops everything.
        $tester = $this->play(['--round' => 'round-t', '--runs' => '2', '--budget-usd' => '0.4']);

        self::assertCount(1, $this->api->requests);
        self::assertStringContainsString('Budget reached', $tester->getDisplay());
    }

    public function testTheRoundAndTheCasesAreChecked(): void
    {
        $this->writeCase('table-prices');

        self::assertSame(Command::INVALID, $this->play(['--runs' => '1'])->getStatusCode(), '--round is required.');
        self::assertSame(Command::INVALID, $this->play(['--round' => 'round-t', '--case' => ['nope']])->getStatusCode());
        self::assertSame([], $this->api->requests);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function play(array $options): CommandTester
    {
        $router = static::getContainer()->get('router');
        $link = new ComponentPreviewLink($router, (string) static::getContainer()->getParameter('kernel.secret'));
        $command = new ComponentBenchRunCommand(
            $this->api,
            new ComponentBenchGenerators(
                $this->getService(GeneratorSettings::class),
                new ComponentPrompt($this->getService(SkillRepository::class), $this->getService(SectionCatalog::class), $this->getService(ShopContext::class), new BlockStyles()),
                new SiteComponentValidator(),
                $this->getService(SiteComponentRepository::class),
                $this->getService(GenerationLog::class),
                new NullLogger(),
                new GatewayFailures($this->getService(TranslatorInterface::class)),
                new SiteComponentUsage(),
                $this->getService(SectionCatalog::class),
            ),
            $this->getService(GeneratorSettings::class),
            new BenchPreviews($link),
        );
        $tester = new CommandTester($command);
        $tester->execute($options + ['--cases' => $this->directory.'/cases', '--out' => $this->directory.'/out']);

        return $tester;
    }

    private function writeCase(string $id): void
    {
        file_put_contents($this->directory.'/cases/'.$id.'.json', json_encode([
            'id' => $id,
            'family' => 'table',
            'locale' => 'fr_FR',
            'request' => 'Un tableau de prix de deux à quatre formules.',
            'oracle' => ['outcome' => ['component'], 'target' => [], 'props' => [], 'markup' => ['require' => [], 'forbid' => []], 'css' => ['require' => [], 'forbid' => []]],
        ], \JSON_THROW_ON_ERROR));
    }
}
