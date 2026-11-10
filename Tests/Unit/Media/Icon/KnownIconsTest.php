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

namespace TheliaCMS\Tests\Unit\Media\Icon;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Thelia\Core\Template\TemplateDefinition;
use Thelia\Core\Template\TemplateHelperInterface;
use TheliaCMS\Media\Icon\IconFileFinder;
use TheliaCMS\Media\Icon\KnownIcons;
use TheliaCMS\Media\Icon\ThemeIcons;

/**
 * What a page may draw right now, and what a model is told it may name.
 */
final class KnownIconsTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/theliacms-known-icons-'.bin2hex(random_bytes(6));
        (new Filesystem())->dumpFile($this->directory.'/library/live.svg', '<svg/>');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    public function testAnIconIsKnownWhenTheLibraryHoldsItOrTheThemeDrawsIt(): void
    {
        $known = $this->known(['live'], ['arrow-left', 'set:flag']);

        self::assertTrue($known->isKnown('cms:live'));
        self::assertTrue($known->isKnown('arrow-left'));
        self::assertTrue($known->isKnown('set:flag'));
        self::assertFalse($known->isKnown('cms:gone'), 'A name the library does not hold.');
        self::assertFalse($known->isKnown('live'), 'A name of the library needs its prefix.');
        self::assertFalse($known->isKnown('Not A Name'));
        self::assertFalse($known->isKnown(''));
    }

    public function testTheListingNamesTheLibraryFirstThenTheThemeEachSorted(): void
    {
        $listing = $this->known(['alpha', 'live'], ['star', 'arrow'])->listing();

        self::assertSame('`cms:alpha`, `cms:live`, `arrow`, `star`', $listing);
    }

    public function testAThemeTooLongIsCutAndTheLibraryStaysWhole(): void
    {
        $theme = array_map(static fn (int $number): string => \sprintf('theme-%03d', $number), range(1, 250));
        $listing = $this->known(['live', 'mine'], $theme)->listing();

        self::assertNotNull($listing);
        self::assertStringContainsString('`cms:live`, `cms:mine`', $listing);
        self::assertSame(KnownIcons::LISTED_LIMIT, substr_count($listing, '`') / 2);
        self::assertStringContainsString('`theme-198`', $listing);
        self::assertStringNotContainsString('`theme-199`', $listing);
        self::assertStringContainsString('(and 52 more, not listed', $listing);
    }

    public function testASiteWithoutAnyIconHasNoListing(): void
    {
        self::assertNull($this->known([], [])->listing());
    }

    public function testAShapeThatIsNotAReferenceNeverReachesTheLibraryNorTheDisk(): void
    {
        $asked = new \ArrayObject();
        $finder = new class($asked) implements IconFileFinder {
            /** @param \ArrayObject<int, string> $asked */
            public function __construct(private readonly \ArrayObject $asked)
            {
            }

            public function fileOf(string $name): ?string
            {
                $this->asked->append($name);

                return null;
            }

            public function names(): array
            {
                return [];
            }
        };
        $helper = $this->createMock(TemplateHelperInterface::class);
        $helper->expects(self::never())->method('getActiveFrontTemplate');
        $known = new KnownIcons($finder, new ThemeIcons($helper));

        foreach (['cms:../../etc/passwd', 'cms:UPPER', 'cms:a b', 'cms:'.str_repeat('a', 130), '../x'] as $reference) {
            self::assertFalse($known->isKnown($reference), $reference);
        }

        self::assertCount(0, $asked, 'The library is asked for a well-formed name only.');
    }

    public function testAThemeIconIsReadFromTheDiskOncePerLifeOfTheService(): void
    {
        $known = $this->known([], ['arrow', 'star']);
        self::assertTrue($known->isKnown('arrow'));

        (new Filesystem())->remove($this->directory.'/theme');
        self::assertTrue($known->isKnown('star'), 'The disk is not walked again.');
        self::assertSame('`arrow`, `star`', $known->listing());
    }

    public function testTheThemeIconsAreReadAgainAfterAReset(): void
    {
        $icons = $this->themeIcons(['arrow']);
        self::assertTrue($icons->has('arrow'));

        (new Filesystem())->remove($this->directory.'/theme');
        $icons->reset();

        self::assertFalse($icons->has('arrow'), 'A worker sees the next message as new.');
    }

    /**
     * @param list<string> $library names of the live icons of the library
     * @param list<string> $theme   names of the icons of the theme
     */
    private function known(array $library, array $theme): KnownIcons
    {
        $icons = $this->themeIcons($theme);

        $finder = new class($library, $this->directory.'/library/live.svg') implements IconFileFinder {
            /** @param list<string> $names */
            public function __construct(private readonly array $names, private readonly string $file)
            {
            }

            public function fileOf(string $name): ?string
            {
                return \in_array($name, $this->names, true) ? $this->file : null;
            }

            public function names(): array
            {
                return $this->names;
            }
        };

        return new KnownIcons($finder, $icons);
    }

    /**
     * @param list<string> $theme names of the icons of the theme
     */
    private function themeIcons(array $theme): ThemeIcons
    {
        foreach ($theme as $name) {
            (new Filesystem())->dumpFile($this->directory.'/theme/assets/icons/'.implode('/', explode(':', $name)).'.svg', '<svg/>');
        }

        $active = $this->createStub(TemplateDefinition::class);
        $active->method('getAbsolutePath')->willReturn($this->directory.'/theme');
        $active->method('getParentList')->willReturn([]);
        $helper = $this->createStub(TemplateHelperInterface::class);
        $helper->method('getActiveFrontTemplate')->willReturn($active);

        return new ThemeIcons($helper);
    }
}
