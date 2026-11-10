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
use TheliaCMS\Evaluation\ComponentBench\BenchPreviews;
use TheliaCMS\Evaluation\ComponentBench\ComponentBenchCheckCommand;
use TheliaCMS\Evaluation\ComponentBench\TrialFiles;
use TheliaCMS\Preview\ComponentPreviewLink;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;
use TheliaCMS\Tests\Unit\Content\Site\PriceTable;

/**
 * `thelia_cms:component-bench:check`: the oracle applied to the files of a
 * round, the verdict written into each, the table, and the previews signed
 * again. It calls nothing: the gateway is not even a dependency.
 */
final class ComponentBenchCheckCommandTest extends CmsIntegrationTestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = THELIA_ROOT.'var/tests/component-bench/'.bin2hex(random_bytes(4));
        mkdir($this->directory.'/cases', 0o775, true);
        ConfigQuery::write('url_site', 'https://bench.example');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);

        parent::tearDown();
    }

    public function testTheVerdictIsWrittenIntoEachFileAndTheTableCountsThem(): void
    {
        $this->writeCase('table-prices', ['outcome' => ['component'], 'props' => [['type' => 'items', 'min' => 1, 'with' => ['text|html']]], 'markup' => ['require' => ['plan__price'], 'forbid' => ['<video']]]);
        $this->writeCase('reuse-photo', ['outcome' => ['existing'], 'target' => ['cms:image']]);
        $this->writeCase('compose-all', ['outcome' => ['component']], gap: 'N71');
        $this->writeTrial('table-prices', 1, $this->componentTrial());
        $this->writeTrial('table-prices', 2, ['existing' => 'cms:image', 'outcome' => 'existing', 'component' => null, 'corrections' => 0, 'cost_usd' => 0.02]);
        $this->writeTrial('table-prices', 3, $this->componentTrial(['browser' => ['375' => ['scroll' => true, 'axe' => []]], 'corrections' => 2]));
        $this->writeTrial('reuse-photo', 1, ['existing' => 'cms:image', 'outcome' => 'existing', 'component' => null, 'cost_usd' => 0.02]);
        $this->writeTrial('reuse-photo', 2, ['existing' => 'cms:richtext', 'outcome' => 'existing', 'component' => null, 'cost_usd' => 0.02]);
        $this->writeTrial('compose-all', 1, $this->componentTrial(['duration_ms' => 60000]));

        $tester = $this->check(['--round' => 'round-t']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertTrue(TrialFiles::read($this->path('table-prices', 1))['verdict']['pass']);
        $outcome = TrialFiles::read($this->path('table-prices', 2))['verdict'];
        self::assertFalse($outcome['pass']);
        self::assertFalse($outcome['checks']['outcome']);
        $browser = TrialFiles::read($this->path('table-prices', 3))['verdict'];
        self::assertFalse($browser['pass'], 'The browser block of a trial is taken.');
        self::assertFalse($browser['checks']['browser']);
        self::assertTrue(TrialFiles::read($this->path('reuse-photo', 1))['verdict']['pass']);
        self::assertFalse(TrialFiles::read($this->path('reuse-photo', 2))['verdict']['pass'], 'An existing component the case does not name.');

        $display = $tester->getDisplay();
        self::assertStringContainsString('| table-prices | 3 | 2 component, 1 existing | 1/3 | 2 |', $display);
        self::assertStringContainsString('| reuse-photo | 2 | 2 existing | 1/2 | 0 |', $display);
        self::assertStringContainsString('Cases of a known gap', $display);
        self::assertMatchesRegularExpression('/\| compose-all \(N71\) \| 1 \|/', $display);
        self::assertStringContainsString('6 trials, 3 kept the contract (2 of 5 outside the cases of a known gap). Cost 0.36 USD, duration 1.0 min.', $display);
        self::assertStringContainsString('- reuse-photo #2:', $display, 'What broke the contract is listed.');
    }

    public function testTheMarkdownOptionPrintsTheTableOnly(): void
    {
        $this->writeCase('reuse-photo', ['outcome' => ['existing'], 'target' => ['cms:image']]);
        $this->writeTrial('reuse-photo', 1, ['existing' => 'cms:richtext', 'outcome' => 'existing', 'component' => null]);

        $display = $this->check(['--round' => 'round-t', '--markdown' => true])->getDisplay();

        self::assertStringContainsString('| Case | Trials |', $display);
        self::assertStringNotContainsString('Trials that broke the contract', $display);
    }

    public function testACopyIsCountedAgainstTheTargetsOfTheCase(): void
    {
        $this->writeCase('quote-dark', ['outcome' => ['copy', 'component'], 'target' => ['Site:Quote']]);
        $this->writeTrial('quote-dark', 1, $this->componentTrial(['based_on' => 'Site:Quote']));
        $this->writeTrial('quote-dark', 2, $this->componentTrial(['based_on' => 'Site:Faq']));
        $this->writeTrial('quote-dark', 3, $this->componentTrial());

        $display = $this->check(['--round' => 'round-t', '--markdown' => true])->getDisplay();

        self::assertStringContainsString('| quote-dark | 3 | 1 component, 2 copy | 2/3 | 0 | 1/2 |', $display, 'Two copies, one of the target; the copy of another component fails the case.');
    }

    public function testARoundWithoutFilesIsRefused(): void
    {
        self::assertSame(Command::FAILURE, $this->check(['--round' => 'empty'])->getStatusCode());
        self::assertSame(Command::INVALID, $this->check([])->getStatusCode());
    }

    public function testRefreshingThePreviewsSignsThemAgainAndChecksNothing(): void
    {
        $this->writeCase('table-prices', ['outcome' => ['existing']]);
        $this->writeTrial('table-prices', 1, $this->componentTrial(['ledger_id' => 77, 'preview_url' => 'https://bench.example/old', 'verdict' => null]));
        $this->writeTrial('table-prices', 2, ['outcome' => 'existing', 'existing' => 'cms:image', 'component' => null, 'ledger_id' => 78, 'preview_url' => null, 'verdict' => null]);

        $tester = $this->check(['--round' => 'round-t', '--refresh-previews' => true]);

        $component = TrialFiles::read($this->path('table-prices', 1));
        self::assertMatchesRegularExpression('#^https://bench\.example/cms-component-preview/candidate-77/fr_FR/\d+/[0-9a-f]{64}$#', $component['preview_url']);
        self::assertNull($component['verdict'], 'Nothing was checked.');
        self::assertSame('cms:image', TrialFiles::read($this->path('table-prices', 2))['existing']);
        self::assertNull(TrialFiles::read($this->path('table-prices', 2))['preview_url'], 'An existing component has no preview.');
        self::assertStringContainsString('1 preview address(es) signed again', $tester->getDisplay());
        self::assertStringNotContainsString('| Case |', $tester->getDisplay());
    }

    /**
     * @param array<string, mixed> $options
     */
    private function check(array $options): CommandTester
    {
        $router = static::getContainer()->get('router');
        $link = new ComponentPreviewLink($router, (string) static::getContainer()->getParameter('kernel.secret'));
        $tester = new CommandTester(new ComponentBenchCheckCommand(new BenchPreviews($link)));
        $tester->execute($options + ['--cases' => $this->directory.'/cases', '--out' => $this->directory.'/out']);

        return $tester;
    }

    private function path(string $case, int $run): string
    {
        return $this->directory.'/out/round-t/'.$case.'/'.$run.'.json';
    }

    /**
     * @param array<string, mixed> $oracle
     */
    private function writeCase(string $id, array $oracle, ?string $gap = null): void
    {
        file_put_contents($this->directory.'/cases/'.$id.'.json', json_encode([
            'id' => $id,
            'family' => 'table',
            'locale' => 'fr_FR',
            'request' => 'Un tableau de prix.',
            'oracle' => $oracle + ['target' => [], 'props' => [], 'markup' => ['require' => [], 'forbid' => []], 'css' => ['require' => [], 'forbid' => []]],
        ] + (null === $gap ? [] : ['gap' => $gap]), \JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, mixed> $fields over the file of a trial that made a component
     */
    private function writeTrial(string $case, int $run, array $fields): void
    {
        TrialFiles::write($this->path($case, $run), $fields + ['case' => $case, 'round' => 'round-t', 'run' => $run, 'outcome' => 'component', 'existing' => null, 'based_on' => null, 'error' => null, 'corrections' => 0, 'cost_usd' => 0.1, 'duration_ms' => 0, 'locale' => 'fr_FR', 'ledger_id' => 5, 'verdict' => null]);
    }

    /**
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>
     */
    private function componentTrial(array $fields = []): array
    {
        $component = PriceTable::component();

        return $fields + ['component' => [
            'code' => $component->code,
            'label' => $component->label,
            'description' => $component->description,
            'props' => $component->props,
            'sample' => $component->sample,
            'template' => $component->template,
            'css' => $component->css,
        ]];
    }
}
