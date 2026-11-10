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

namespace TheliaCMS\Tests\Unit\Content\Partial;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\IdentityTranslator;
use TheliaCMS\Content\Partial\PartialSection;
use TheliaCMS\Tests\Unit\Partial\FakeDescribedPartial;
use TheliaCMS\Tests\Unit\Partial\FakePartial;

/**
 * What the section picker and the components screen say about a block of live
 * content: its own words when it has some, the generic sentence otherwise.
 */
final class PartialSectionDescriptionTest extends TestCase
{
    public function testAPartialThatSaysWhatItDoesIsDescribedInItsOwnWords(): void
    {
        $section = new PartialSection(
            new FakeDescribedPartial(new FakePartial('stores'), 'Every store of the network, with a link to its page.'),
            new IdentityTranslator(),
        );

        self::assertSame('Every store of the network, with a link to its page.', $section->description());
    }

    public function testAPartialThatSaysNothingKeepsTheGenericSentence(): void
    {
        $section = new PartialSection(new FakePartial('stores'), new IdentityTranslator());

        self::assertSame('Live content, rendered on every visit: Stores. The page stores its settings only.', $section->description());
    }

    public function testABlankDescriptionFallsBackOnTheGenericSentence(): void
    {
        $section = new PartialSection(new FakeDescribedPartial(new FakePartial('stores'), "  \n"), new IdentityTranslator());

        self::assertSame('Live content, rendered on every visit: Stores. The page stores its settings only.', $section->description());
    }
}
