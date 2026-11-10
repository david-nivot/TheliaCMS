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

namespace TheliaCMS\Tests\Unit\Skill;

use PHPUnit\Framework\TestCase;
use TheliaCMS\Skill\SkillFiles;

/**
 * A skill file opens with a front matter and the rules follow: what the
 * module ships has to read, and a file without a name or a description is
 * refused rather than sown nameless.
 */
final class SkillFilesTest extends TestCase
{
    public function testAFileIsReadIntoItsFrontMatterAndItsBody(): void
    {
        $parsed = SkillFiles::parse("---\nname: copywriting\ndescription: How to write.\nalways: true\n---\n\n# Writing\n\nShort sentences.\n", 'test.md');

        self::assertSame('copywriting', $parsed['name']);
        self::assertSame('How to write.', $parsed['description']);
        self::assertTrue($parsed['always']);
        self::assertSame("# Writing\n\nShort sentences.", $parsed['body']);
    }

    public function testAlwaysDefaultsToFalse(): void
    {
        self::assertFalse(SkillFiles::parse("---\nname: seo\ndescription: Engines.\n---\nBody", 'test.md')['always']);
    }

    public function testAFileWithoutAFrontMatterIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('does not open with a front matter');

        SkillFiles::parse("# No front matter\n", 'test.md');
    }

    public function testAFileWithoutADescriptionIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must declare a name and a description');

        SkillFiles::parse("---\nname: seo\n---\nBody", 'test.md');
    }

    public function testEveryShippedSkillReads(): void
    {
        $files = glob(\dirname(__DIR__, 3).'/skills/*/SKILL.md') ?: [];

        self::assertCount(9, $files);

        foreach ($files as $file) {
            $parsed = SkillFiles::parse((string) file_get_contents($file), $file);

            self::assertNotSame('', $parsed['body'], $file);
            self::assertSame(basename(\dirname($file)), $parsed['name'], 'The name of a shipped skill is the name of its directory.');
        }
    }
}
