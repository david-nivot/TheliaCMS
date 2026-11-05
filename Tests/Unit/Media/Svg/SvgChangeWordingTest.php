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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;
use TheliaCMS\Media\Svg\SvgChangeWording;
use TheliaLibrary\Service\Svg\SvgChange;
use TheliaLibrary\Service\Svg\SvgChangeKind;
use TheliaLibrary\Service\Svg\UnreadableSvgReason;

/**
 * The translator here only fills the placeholders: what is asserted is the
 * key chosen and the name it is given, the catalogues are checked apart.
 */
final class SvgChangeWordingTest extends TestCase
{
    /**
     * @return iterable<string, array{SvgChange, string}>
     */
    public static function changes(): iterable
    {
        yield 'event handler' => [new SvgChange(SvgChangeKind::RemovedAttribute, 'onload'), 'Attribute onload removed: event handler.'];
        yield 'event handler in capitals' => [new SvgChange(SvgChangeKind::RemovedAttribute, 'onClick'), 'Attribute onClick removed: event handler.'];
        yield 'editor attribute' => [new SvgChange(SvgChangeKind::RemovedAttribute, 'sodipodi:docname'), 'Attribute sodipodi:docname removed: editor markup.'];
        yield 'editor namespace declaration' => [new SvgChange(SvgChangeKind::RemovedAttribute, 'xmlns:inkscape'), 'Attribute xmlns:inkscape removed: editor markup.'];
        yield 'other attribute' => [new SvgChange(SvgChangeKind::RemovedAttribute, 'href'), 'Attribute href removed: not allowed in an image.'];
        yield 'a standard prefix is not an editor' => [new SvgChange(SvgChangeKind::RemovedAttribute, 'xlink:href'), 'Attribute xlink:href removed: not allowed in an image.'];
        yield 'script' => [new SvgChange(SvgChangeKind::RemovedElement, 'script'), 'Element <script> removed: script.'];
        yield 'foreignObject' => [new SvgChange(SvgChangeKind::RemovedElement, 'foreignObject'), 'Element <foreignObject> removed: embedded HTML content.'];
        yield 'animate' => [new SvgChange(SvgChangeKind::RemovedElement, 'animate'), 'Element <animate> removed: animation.'];
        yield 'set' => [new SvgChange(SvgChangeKind::RemovedElement, 'set'), 'Element <set> removed: animation.'];
        yield 'animateTransform' => [new SvgChange(SvgChangeKind::RemovedElement, 'animateTransform'), 'Element <animateTransform> removed: animation.'];
        yield 'animateMotion' => [new SvgChange(SvgChangeKind::RemovedElement, 'animateMotion'), 'Element <animateMotion> removed: animation.'];
        yield 'editor element' => [new SvgChange(SvgChangeKind::RemovedElement, 'sodipodi:namedview'), 'Element <sodipodi:namedview> removed: editor markup.'];
        yield 'other prefixed element' => [new SvgChange(SvgChangeKind::RemovedElement, 'i:pgf'), 'Element <i:pgf> removed: editor markup.'];
        yield 'processing instruction' => [new SvgChange(SvgChangeKind::RemovedElement, '?php'), 'Processing instruction <?php ?> removed.'];
        yield 'other element' => [new SvgChange(SvgChangeKind::RemovedElement, 'iframe'), 'Element <iframe> removed: not allowed in an image.'];
        yield 'external reference' => [new SvgChange(SvgChangeKind::ExternalReference, 'use'), 'External reference removed from <use>.'];
        yield 'doctype' => [new SvgChange(SvgChangeKind::RemovedElement, '!DOCTYPE'), 'DOCTYPE declaration removed.'];
        yield 'removed comments' => [new SvgChange(SvgChangeKind::RemovedComment, '!--'), 'Comments removed.'];
        yield 'added namespace' => [new SvgChange(SvgChangeKind::AddedNamespace, 'http://www.w3.org/2000/svg'), 'SVG namespace added: without it the file would not be displayed.'];
        yield 'added viewBox' => [new SvgChange(SvgChangeKind::AddedViewBox, '0 0 24 24'), 'viewBox added: 0 0 24 24.'];
    }

    #[DataProvider('changes')]
    public function testEachChangeIsWorded(SvgChange $change, string $expected): void
    {
        self::assertSame([$expected], $this->sentences([$change]));
    }

    public function testEveryKindOfChangeHasASentence(): void
    {
        foreach (SvgChangeKind::cases() as $kind) {
            $sentences = $this->sentences([new SvgChange($kind, 'subject')]);

            self::assertCount(1, $sentences, $kind->value);
            self::assertStringNotContainsString('%', $sentences[0], $kind->value.': a placeholder was left unfilled.');
            self::assertNotSame('', $sentences[0], $kind->value);
        }
    }

    public function testTheSameChangeManyTimesIsOneSentenceAndItsCount(): void
    {
        $sentences = $this->sentences([
            new SvgChange(SvgChangeKind::RemovedAttribute, 'onclick'),
            new SvgChange(SvgChangeKind::RemovedElement, 'script'),
            new SvgChange(SvgChangeKind::RemovedAttribute, 'onclick'),
            new SvgChange(SvgChangeKind::RemovedAttribute, 'onclick'),
        ]);

        self::assertSame(['Attribute onclick removed: event handler. ×3', 'Element <script> removed: script.'], $sentences);
    }

    public function testWhatTheCleaningAddsIsToldFromWhatItTakesOut(): void
    {
        $entries = $this->wording()->entries([
            new SvgChange(SvgChangeKind::RemovedElement, 'script'),
            new SvgChange(SvgChangeKind::AddedViewBox, '0 0 24 24'),
            new SvgChange(SvgChangeKind::AddedNamespace, 'http://www.w3.org/2000/svg'),
            new SvgChange(SvgChangeKind::RemovedComment, '!--'),
        ]);

        self::assertSame([false, true, true, false], array_column($entries, 'added'));
        self::assertSame('Element <script> removed: script.', $entries[0]['text']);
    }

    public function testNoChangeIsNoSentence(): void
    {
        self::assertSame([], $this->sentences([]));
    }

    public function testEveryReasonAFileCannotBeReadHasItsOwnRefusal(): void
    {
        $refusals = [];

        foreach (UnreadableSvgReason::cases() as $reason) {
            $refusals[$reason->value] = $this->wording()->refusal($reason);
        }

        self::assertCount(\count(UnreadableSvgReason::cases()), array_unique($refusals), 'No two reasons share a message.');
        self::assertStringContainsString('internal subset', $refusals['doctype']);
        self::assertStringContainsString('entities', $refusals['entity']);
        self::assertStringContainsString('UTF-8', $refusals['not_utf8']);
        self::assertStringContainsString('valid XML', $refusals['malformed_xml']);
        self::assertStringContainsString('root element', $refusals['not_svg_root']);
        self::assertStringContainsString('5 MB', $refusals['too_large']);
        self::assertStringContainsString('viewBox', $refusals['no_dimensions']);
    }

    private function wording(): SvgChangeWording
    {
        return new SvgChangeWording(new class implements TranslatorInterface {
            public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
            {
                return strtr($id, $parameters);
            }

            public function getLocale(): string
            {
                return 'en_US';
            }
        });
    }

    /**
     * @param list<SvgChange> $changes
     *
     * @return list<string>
     */
    private function sentences(array $changes): array
    {
        return array_column($this->wording()->entries($changes), 'text');
    }
}
