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
use TheliaCMS\Media\Svg\Optimization\FigmaClipRemover;
use TheliaCMS\Media\Svg\Optimization\GeometryEnvelope;
use TheliaCMS\Media\Svg\Optimization\SvgOptimizationLevel;
use TheliaCMS\Media\Svg\Optimization\SvgOptimizationLevels;
use TheliaCMS\Media\Svg\Optimization\SvgOptimizer;
use TheliaCMS\Media\Svg\Optimization\SvgReferences;
use TheliaCMS\Media\Svg\Optimization\UnreferencedIdRemover;
use TheliaCMS\Media\Svg\SvgPreparation;
use TheliaLibrary\Exception\UnreadableSvgException;
use TheliaLibrary\Service\Svg\SanitizedSvg;
use TheliaLibrary\Service\Svg\SvgChange;
use TheliaLibrary\Service\Svg\SvgChangeKind;
use TheliaLibrary\Service\Svg\SvgSanitizerInterface;
use TheliaLibrary\Service\Svg\UnreadableSvgReason;

final class SvgPreparationTest extends TestCase
{
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
            new SvgOptimizer(new FigmaClipRemover(new GeometryEnvelope(), $references), new UnreferencedIdRemover($references)),
            new SvgOptimizationLevels($levels),
            logger: $logger,
        );
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
