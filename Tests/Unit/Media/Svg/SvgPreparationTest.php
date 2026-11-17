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

namespace TheliaCMS\Tests\Unit\Media\Svg;

use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Filesystem;
use TheliaCMS\Media\Svg\Color\SvgColoring;
use TheliaCMS\Media\Svg\Color\SvgColorPreset;
use TheliaCMS\Media\Svg\Optimization\FigmaClipRemover;
use TheliaCMS\Media\Svg\Optimization\GeometryEnvelope;
use TheliaCMS\Media\Svg\Optimization\Path\ShorterPathNotation;
use TheliaCMS\Media\Svg\Optimization\SvgOptimizationLevel;
use TheliaCMS\Media\Svg\Optimization\SvgOptimizationLevels;
use TheliaCMS\Media\Svg\Optimization\SvgOptimizer;
use TheliaCMS\Media\Svg\Optimization\SvgReferences;
use TheliaCMS\Media\Svg\Optimization\UnreferencedIdRemover;
use TheliaCMS\Media\Svg\SvgPreparation;
use TheliaCMS\Media\Svg\SvgStageCache;
use TheliaLibrary\Exception\UnreadableSvgException;
use TheliaLibrary\Service\Svg\SanitizedSvg;
use TheliaLibrary\Service\Svg\SvgChange;
use TheliaLibrary\Service\Svg\SvgChangeKind;
use TheliaLibrary\Service\Svg\SvgSanitizerInterface;
use TheliaLibrary\Service\Svg\UnreadableSvgReason;

final class SvgPreparationTest extends TestCase
{
    private string $stagesRoot = '';

    protected function tearDown(): void
    {
        if ('' !== $this->stagesRoot) {
            (new Filesystem())->remove($this->stagesRoot);
        }
    }

    private const string ORIGINAL = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><!-- a long comment that only an editor needs --><path d="M0 0h24v24H0z" onload="x()"/></svg>';

    public function testTheOriginalIsCleanedThenOptimizedThenCleanedAgainAndTheSecondCleaningIsWhatIsShown(): void
    {
        $sanitizer = $this->sanitizer(changes: [new SvgChange(SvgChangeKind::RemovedAttribute, 'onload')]);

        $result = $this->preparation($sanitizer)->prepare(self::ORIGINAL);

        self::assertCount(2, $sanitizer->received);
        self::assertSame(self::ORIGINAL, $sanitizer->received[0]);
        self::assertStringNotContainsString('<!--', $sanitizer->received[1], 'The second cleaning receives the optimized document.');
        self::assertSame('checked:'.$sanitizer->received[1], $result->content, 'What is shown is what the second cleaning returned.');
        self::assertSame(96.0, $result->width);
        self::assertSame(48.0, $result->height);
    }

    public function testTheChangesToldAreThoseOfTheFirstCleaning(): void
    {
        $sanitizer = $this->sanitizer(changes: [new SvgChange(SvgChangeKind::RemovedAttribute, 'onload')], secondChanges: [new SvgChange(SvgChangeKind::RemovedElement, 'second')]);

        $result = $this->preparation($sanitizer)->prepare(self::ORIGINAL);

        self::assertCount(1, $result->changes);
        self::assertSame('onload', $result->changes[0]->subject);
    }

    public function testTheWeightsAndTheGainAreMeasuredOnTheBytes(): void
    {
        $result = $this->preparation($this->sanitizer())->prepare(self::ORIGINAL);

        self::assertSame(\strlen(self::ORIGINAL), $result->originalBytes);
        self::assertSame(\strlen($result->content), $result->resultBytes);
        self::assertSame($result->originalBytes - $result->resultBytes, $result->savedBytes());
        self::assertSame((int) round($result->savedBytes() * 100 / $result->originalBytes), $result->savedPercent());
    }

    public function testTheLevelChosenIsAppliedBetweenTheOptimizationAndTheControl(): void
    {
        $sanitizer = $this->sanitizer();
        $level = $this->level('strong', '<!--strong-->');

        $result = $this->preparation($sanitizer, [$level])->prepare(self::ORIGINAL, 'strong');

        self::assertStringEndsWith('<!--strong-->', $sanitizer->received[1], 'The control cleans what the level produced.');
        self::assertStringEndsWith('<!--strong-->', $result->content);
    }

    public function testNoLevelOrAnUnknownOneChangesNothing(): void
    {
        $levels = [$this->level('strong', '<!--strong-->')];

        $none = $this->preparation($this->sanitizer(), $levels)->prepare(self::ORIGINAL);
        $unknown = $this->preparation($this->sanitizer(), $levels)->prepare(self::ORIGINAL, 'missing');

        self::assertSame($none->content, $unknown->content);
        self::assertStringNotContainsString('strong', $none->content);
    }

    public function testEveryLevelIsAppliedOnItsOwnToTheSameBaseOptimizationComputedOnce(): void
    {
        $sanitizer = $this->sanitizer();
        $preparation = $this->preparation($sanitizer, [$this->level('light', '<!--light-->'), $this->level('strong', '<!--strong-->')]);

        $results = $preparation->prepareLevels(self::ORIGINAL);

        self::assertSame(['light', 'strong'], array_keys($results));
        // One cleaning of the file, then one control per level: the base is not rebuilt.
        self::assertCount(3, $sanitizer->received);
        self::assertSame('checked:'.$sanitizer->received[1], $results['light']->content);
        self::assertSame('checked:'.$sanitizer->received[2], $results['strong']->content);
        self::assertStringEndsWith('<!--light-->', $sanitizer->received[1]);
        self::assertStringEndsWith('<!--strong-->', $sanitizer->received[2], 'Not applied on the result of the other level.');
        self::assertStringNotContainsString('light', $sanitizer->received[2]);
        self::assertSame(
            $this->preparation($this->sanitizer(), [$this->level('light', '<!--light-->')])->prepare(self::ORIGINAL, 'light')->content,
            $results['light']->content,
            'The same result as preparing the level alone.',
        );
        self::assertSame(\strlen(self::ORIGINAL), $results['strong']->originalBytes, 'The gain is counted against the file sent.');
    }

    public function testAChangeOfColorsDoesNotApplyTheLevelNorCleanTheFileAgain(): void
    {
        $calls = new \ArrayObject();
        $sanitizer = $this->sanitizer();
        $preparation = $this->cachedPreparation($sanitizer, [$this->countingLevel('strong', $calls)]);

        $first = $preparation->prepare(self::ORIGINAL, 'strong');
        $second = $preparation->prepare(self::ORIGINAL, 'strong', new SvgColoring(SvgColorPreset::Monochrome));
        $third = $preparation->prepareLevels(self::ORIGINAL, new SvgColoring(SvgColorPreset::Monochrome));

        self::assertCount(1, $calls, 'The level is applied once for the bytes, whatever the colors.');
        // The file is cleaned once; each preparation then runs its own control.
        self::assertCount(1 + 3, $sanitizer->received);
        self::assertSame(self::ORIGINAL, $sanitizer->received[0]);
        self::assertSame($first->changes, $second->changes, 'The changes told are kept with the cleaning.');
        self::assertSame($second->content, $third['strong']->content);
    }

    public function testAnotherFileDoesNotReadTheStagesOfThisOne(): void
    {
        $calls = new \ArrayObject();
        $preparation = $this->cachedPreparation($this->sanitizer(), [$this->countingLevel('strong', $calls)]);

        $preparation->prepare(self::ORIGINAL, 'strong');
        $preparation->prepare(str_replace('24v24', '25v25', self::ORIGINAL), 'strong');

        self::assertCount(2, $calls);
    }

    public function testTheChangesKeptWithTheCleaningAreReturnedAsTheyWere(): void
    {
        $preparation = $this->cachedPreparation($this->sanitizer(changes: [new SvgChange(SvgChangeKind::RemovedAttribute, 'onload')]));

        $preparation->prepare(self::ORIGINAL);
        $again = $preparation->prepare(self::ORIGINAL);

        self::assertCount(1, $again->changes);
        self::assertSame(SvgChangeKind::RemovedAttribute, $again->changes[0]->kind);
        self::assertSame('onload', $again->changes[0]->subject);
    }

    public function testAStageThatCannotBeReadBackIsComputedAgainWithoutAnException(): void
    {
        $change = [new SvgChange(SvgChangeKind::RemovedAttribute, 'onload')];

        foreach ([
            'corrupted JSON' => '[[',
            'not an array' => '"text"',
            'unknown kind' => '[["a_kind_of_another_deploy","onload"]]',
            'wrong shape' => '[["removed_attribute"]]',
            'wrong types' => '[[1,2]]',
            'not a pair' => '["removed_attribute"]',
        ] as $case => $damaged) {
            $sanitizer = $this->sanitizer(changes: $change);
            $preparation = $this->cachedPreparation($sanitizer);
            $preparation->prepare(self::ORIGINAL);
            $files = glob($this->stagesRoot.'/*.changes') ?: [];
            self::assertCount(1, $files, $case);
            file_put_contents($files[0], $damaged);

            $preparation->prepare(self::ORIGINAL);

            self::assertSame(2 + 2, \count($sanitizer->received), $case.': the file is cleaned again, then each preparation runs its control.');
            (new Filesystem())->remove($this->stagesRoot);
        }
    }

    public function testAMissingBaseDoesNotRefreshTheChangesLeftBehind(): void
    {
        $preparation = $this->cachedPreparation($this->sanitizer());
        $preparation->prepare(self::ORIGINAL);
        $base = glob($this->stagesRoot.'/*.base') ?: [];
        $changes = glob($this->stagesRoot.'/*.changes') ?: [];
        self::assertCount(1, $base);
        self::assertCount(1, $changes);
        unlink($base[0]);
        touch($changes[0], time() - 600);
        // A cache that refuses every write: only the read of the stages can touch the file.
        $readOnly = new SvgPreparation(
            $this->sanitizer(),
            new SvgOptimizer(new FigmaClipRemover(new GeometryEnvelope(), new SvgReferences()), new UnreferencedIdRemover(new SvgReferences()), new ShorterPathNotation()),
            new SvgOptimizationLevels([]),
            stages: new SvgStageCache($this->stagesRoot, 0),
        );

        $readOnly->prepare(self::ORIGINAL);

        clearstatcache();
        self::assertLessThan(time() - 300, filemtime($changes[0]), 'The changes of a base that is gone are not kept alive.');
    }

    public function testWithoutLevelsNothingIsPreparedByLevel(): void
    {
        self::assertSame([], $this->preparation($this->sanitizer())->prepareLevels(self::ORIGINAL));
    }

    public function testWhatTheControlRemovesIsLoggedSinceTheEditorIsNotShownIt(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<array{string, array<array-key, mixed>}> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [(string) $level, $context];
            }
        };
        $quiet = clone $logger;
        $sanitizer = $this->sanitizer(secondChanges: [new SvgChange(SvgChangeKind::RemovedElement, 'script')]);

        $this->preparation($sanitizer, logger: $logger)->prepare(self::ORIGINAL);
        $this->preparation($this->sanitizer(), logger: $quiet)->prepare(self::ORIGINAL);

        self::assertCount(1, $logger->records);
        self::assertSame('warning', $logger->records[0][0]);
        self::assertSame(['removed_element: script'], $logger->records[0][1]['changes']);
        self::assertSame([], $quiet->records, 'Nothing removed, nothing logged.');
    }

    public function testAFileTheControlCannotReadIsRefused(): void
    {
        $sanitizer = $this->sanitizer(refuseSecond: UnreadableSvgReason::MalformedXml);

        $this->expectException(UnreadableSvgException::class);

        $this->preparation($sanitizer)->prepare(self::ORIGINAL);
    }

    /**
     * @param list<SvgOptimizationLevel> $levels
     */
    private function preparation(object $sanitizer, array $levels = [], LoggerInterface $logger = new NullLogger()): SvgPreparation
    {
        \assert($sanitizer instanceof SvgSanitizerInterface);
        $references = new SvgReferences();

        return new SvgPreparation(
            $sanitizer,
            new SvgOptimizer(new FigmaClipRemover(new GeometryEnvelope(), $references), new UnreferencedIdRemover($references), new ShorterPathNotation()),
            new SvgOptimizationLevels($levels),
            logger: $logger,
        );
    }

    /**
     * @param list<SvgOptimizationLevel> $levels
     */
    private function cachedPreparation(object $sanitizer, array $levels = []): SvgPreparation
    {
        \assert($sanitizer instanceof SvgSanitizerInterface);
        $references = new SvgReferences();

        return new SvgPreparation(
            $sanitizer,
            new SvgOptimizer(new FigmaClipRemover(new GeometryEnvelope(), $references), new UnreferencedIdRemover($references), new ShorterPathNotation()),
            new SvgOptimizationLevels($levels),
            stages: new SvgStageCache($this->stagesRoot = sys_get_temp_dir().'/cms-stages-'.bin2hex(random_bytes(6))),
        );
    }

    /**
     * @param \ArrayObject<int, string> $calls
     */
    private function countingLevel(string $name, \ArrayObject $calls): SvgOptimizationLevel
    {
        return new class($name, $calls) implements SvgOptimizationLevel {
            /**
             * @param \ArrayObject<int, string> $calls
             */
            public function __construct(private readonly string $name, private readonly \ArrayObject $calls)
            {
            }

            public function name(): string
            {
                return $this->name;
            }

            public function label(): string
            {
                return 'Level '.$this->name;
            }

            public function changesRendering(): bool
            {
                return true;
            }

            public function apply(string $svg): string
            {
                $this->calls[] = $svg;

                return $svg.'<!--'.$this->name.'-->';
            }
        };
    }

    /**
     * A double that cleans nothing: the first call returns its input, the
     * second marks what it received so the test sees which document it was.
     *
     * @param list<SvgChange> $changes
     * @param list<SvgChange> $secondChanges
     */
    private function sanitizer(array $changes = [], array $secondChanges = [], ?UnreadableSvgReason $refuseSecond = null): object
    {
        return new class($changes, $secondChanges, $refuseSecond) implements SvgSanitizerInterface {
            /** @var list<string> */
            public array $received = [];

            /**
             * @param list<SvgChange> $changes
             * @param list<SvgChange> $secondChanges
             */
            public function __construct(private readonly array $changes, private readonly array $secondChanges, private readonly ?UnreadableSvgReason $refuseSecond)
            {
            }

            public function sanitize(string $svg): SanitizedSvg
            {
                $this->received[] = $svg;

                if (1 === \count($this->received)) {
                    return new SanitizedSvg($svg, $this->changes, 24.0, 24.0);
                }

                if (null !== $this->refuseSecond) {
                    throw new UnreadableSvgException($this->refuseSecond);
                }

                return new SanitizedSvg('checked:'.$svg, $this->secondChanges, 96.0, 48.0);
            }
        };
    }

    private function level(string $name, string $suffix): SvgOptimizationLevel
    {
        return new class($name, $suffix) implements SvgOptimizationLevel {
            public function __construct(private readonly string $name, private readonly string $suffix)
            {
            }

            public function name(): string
            {
                return $this->name;
            }

            public function label(): string
            {
                return 'Level '.$this->name;
            }

            public function changesRendering(): bool
            {
                return false;
            }

            public function apply(string $svg): string
            {
                return $svg.$this->suffix;
            }
        };
    }
}
