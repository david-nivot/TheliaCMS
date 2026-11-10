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

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Thelia\Model\ConfigQuery;
use TheliaCMS\Evaluation\ComponentBench\BenchCase;
use TheliaCMS\Evaluation\ComponentBench\BenchPreviews;
use TheliaCMS\Evaluation\ComponentBench\ComponentBenchGenerators;
use TheliaCMS\Evaluation\ComponentBench\ComponentBenchRunCommand;
use TheliaCMS\Evaluation\ComponentBench\ComponentOracle;
use TheliaCMS\Evaluation\ComponentBench\TrialFiles;
use TheliaCMS\Generation\GeneratorSettings;
use TheliaCMS\ImportExport\ImportOptions;
use TheliaCMS\ImportExport\SiteDocument;
use TheliaCMS\ImportExport\SiteImporter;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;
use TheliaCMS\Tests\Support\FakeAiGateway;

/**
 * Replays the answers a round of the bench recorded (`Tests/Fixtures/component-bench/<round>/`)
 * through the run command and the oracle, for free: each trial must come to the
 * verdict it was recorded with. The browser measures are replayed as they were
 * taken, the browser not being run here.
 *
 * A recording whose prompt no longer matches the one the generator sends today
 * (a skill, the rules of the generator or a case changed since the round) is
 * stale: its answers still go through the code of today and their verdict is
 * still checked, but it no longer says what the model answers. The stale ones
 * are listed by a test of their own, which reports them without failing.
 */
final class ComponentBenchReplayTest extends CmsIntegrationTestCase
{
    /** The name of the shop on the site the rounds were played on: the prompt says it. */
    private const string STORE_NAME = 'Thelia';

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = THELIA_ROOT.'var/tests/component-bench-replay/'.bin2hex(random_bytes(4));
        mkdir($this->directory, 0o775, true);
        ConfigQuery::write('url_site', 'https://bench.example');
        ConfigQuery::write('store_name', self::STORE_NAME);

        // The rounds start from the seed: an `existing` or a `copy` names its components.
        $seed = \dirname(__DIR__, 3).'/docs/evaluation/components/seed.json';
        $this->getService(SiteImporter::class)->import(SiteDocument::fromJson((string) file_get_contents($seed)), new ImportOptions());
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);

        parent::tearDown();
    }

    public function testEveryRecordedTrialComesToTheVerdictItWasRecordedWith(): void
    {
        $recordings = self::recordings();
        self::assertNotEmpty($recordings, 'The recordings of a round are in the fixtures.');

        $differences = [];

        foreach ($recordings as $name => $recording) {
            [$trial, $remaining] = $this->replay($recording);
            $verdict = (new ComponentOracle())->judge(self::cases()[$recording['case']], $trial + ['browser' => $recording['browser'] ?? null]);

            $replayed = ['outcome' => $trial['outcome'], 'existing' => $trial['existing'], 'based_on' => $trial['based_on'], 'pass' => $verdict['pass'], 'checks' => $verdict['checks'], 'answers left' => $remaining];
            $recorded = ['outcome' => $recording['outcome'], 'existing' => $recording['existing'], 'based_on' => $recording['based_on'], 'pass' => $recording['verdict']['pass'], 'checks' => $recording['verdict']['checks'], 'answers left' => 0];

            if ($replayed !== $recorded) {
                $differences[$name] = ['recorded' => $recorded, 'replayed' => $replayed, 'problems' => $verdict['problems'], 'error' => $trial['error']];
            }
        }

        self::assertSame([], $differences, \sprintf('%d of %d recorded trials come to another verdict.', \count($differences), \count($recordings)));
    }

    public function testTheStaleRecordingsAreReportedNotFailed(): void
    {
        $stale = [];

        foreach (self::recordings() as $name => $recording) {
            [$trial] = $this->replay($recording);

            if ($trial['prompt_sha256'] !== $recording['prompt_sha256']) {
                $stale[] = $name;
            }
        }

        if ([] !== $stale) {
            self::markTestIncomplete(\sprintf(
                '%d of %d recordings are stale: the generator sends another prompt than the one they answered (%s). Play a new round to measure the model again.',
                \count($stale),
                \count(self::recordings()),
                implode(', ', \array_slice($stale, 0, 5)).(\count($stale) > 5 ? ', …' : ''),
            ));
        }

        $this->addToAssertionCount(1);
    }

    /**
     * Plays the case of the recording through the run command, the gateway
     * answering what the model answered.
     *
     * @param array<string, mixed> $recording
     *
     * @return array{0: array<string, mixed>, 1: int} the trial file, and the recorded answers the generator did not ask for
     */
    private function replay(array $recording): array
    {
        $api = new FakeAiGateway();

        foreach ($recording['answers'] as $answer) {
            $api->answer(FakeAiGateway::response($answer));
        }

        $out = $this->directory.'/'.$recording['case'].'-'.$recording['run'];
        $command = new ComponentBenchRunCommand(
            $api,
            $this->getService(ComponentBenchGenerators::class),
            $this->getService(GeneratorSettings::class),
            $this->getService(BenchPreviews::class),
        );
        $tester = new CommandTester($command);
        $tester->execute(['--round' => 'replay', '--case' => [$recording['case']], '--runs' => '1', '--out' => $out]);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());

        return [TrialFiles::read($out.'/replay/'.$recording['case'].'/1.json'), $api->remaining()];
    }

    /**
     * @return array<string, array<string, mixed>> by `<case>-<run>`
     */
    private static function recordings(): array
    {
        $recordings = [];

        foreach (glob(\dirname(__DIR__, 2).'/Fixtures/component-bench/*/*.json') ?: [] as $file) {
            $recordings[basename(\dirname($file)).'/'.basename($file, '.json')] = json_decode((string) file_get_contents($file), true, 64, \JSON_THROW_ON_ERROR);
        }

        return $recordings;
    }

    /**
     * @return array<string, BenchCase>
     */
    private static function cases(): array
    {
        static $cases = null;

        return $cases ??= BenchCase::loadAll(ComponentBenchRunCommand::defaultCases());
    }
}
