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

namespace TheliaCMS\Tests\Unit\Generation;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Thelia\Core\Template\TemplateDefinition;
use Thelia\Core\Template\TemplateHelperInterface;
use TheliaCMS\Content\Prop;
use TheliaCMS\Content\PropSchema;
use TheliaCMS\Content\SectionCatalog;
use TheliaCMS\Content\SectionDefinitionInterface;
use TheliaCMS\Generation\CatalogSheet;
use TheliaCMS\Media\Icon\IconFileFinder;
use TheliaCMS\Media\Icon\KnownIcons;
use TheliaCMS\Media\Icon\ThemeIcons;

/**
 * The model is told which icons it may name, once for the sheet.
 */
final class CatalogSheetIconsTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/theliacms-sheet-icons-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    public function testTheSheetListsTheIconsOnceWhateverTheNumberOfIconProps(): void
    {
        $text = $this->sheet(['live'], ['arrow'], [
            $this->definition('Test:One', new PropSchema([Prop::icon('mark', 'Mark'), Prop::items('rows', 'Rows', [Prop::icon('glyph', 'Glyph')], 0, 4)])),
            $this->definition('Test:Two', new PropSchema([Prop::icon('badge', 'Badge')])),
        ])->text();

        self::assertSame(1, substr_count($text, '## The icons'));
        self::assertStringContainsString('`cms:live`, `arrow`', $text);
        self::assertStringContainsString('- `mark` (icon name, taken from the list of the icons', $text);
    }

    public function testTheSheetSaysWhatBecomesOfAnUnknownName(): void
    {
        $text = $this->sheet(['live'], ['arrow'], [$this->definition('Test:One', new PropSchema([Prop::icon('mark', 'Mark')]))])->text();

        self::assertStringContainsString('The list may be cut, then it says how many names it leaves out. A name that is not an icon of the site is sent back for correction, then left empty.', $text);
        self::assertStringNotContainsString('is refused', $text);
    }

    public function testASiteWithoutIconSaysThePropStaysEmpty(): void
    {
        $text = $this->sheet([], [], [$this->definition('Test:One', new PropSchema([Prop::icon('mark', 'Mark')]))])->text();

        self::assertStringContainsString('The site has no icon: an `icon` prop stays empty.', $text);
    }

    public function testNoListWithoutAnIconProp(): void
    {
        $text = $this->sheet(['live'], [], [$this->definition('Test:One', new PropSchema([Prop::text('title', 'Title')]))])->text();

        self::assertStringNotContainsString('## The icons', $text);
        self::assertStringNotContainsString('cms:live', $text);
    }

    public function testASectionReadAloneCarriesTheListToo(): void
    {
        $definition = $this->definition('Test:One', new PropSchema([Prop::icon('mark', 'Mark')]));

        self::assertStringContainsString('`cms:live`', $this->sheet(['live'], [], [$definition])->section($definition));
    }

    /**
     * @param list<string>                     $library
     * @param list<string>                     $theme
     * @param list<SectionDefinitionInterface> $definitions
     */
    private function sheet(array $library, array $theme, array $definitions): CatalogSheet
    {
        foreach ($theme as $name) {
            (new Filesystem())->dumpFile($this->directory.'/theme/assets/icons/'.$name.'.svg', '<svg/>');
        }

        $finder = new class($library) implements IconFileFinder {
            /** @param list<string> $names */
            public function __construct(private readonly array $names)
            {
            }

            public function fileOf(string $name): ?string
            {
                return null;
            }

            public function names(): array
            {
                return $this->names;
            }
        };
        $active = $this->createStub(TemplateDefinition::class);
        $active->method('getAbsolutePath')->willReturn($this->directory.'/theme');
        $active->method('getParentList')->willReturn([]);
        $helper = $this->createStub(TemplateHelperInterface::class);
        $helper->method('getActiveFrontTemplate')->willReturn($active);

        return new CatalogSheet(new SectionCatalog($definitions), new KnownIcons($finder, new ThemeIcons($helper)));
    }

    private function definition(string $name, PropSchema $schema): SectionDefinitionInterface
    {
        return new class($name, $schema) implements SectionDefinitionInterface {
            public function __construct(private readonly string $name, private readonly PropSchema $schema)
            {
            }

            public function name(): string
            {
                return $this->name;
            }

            public function label(): string
            {
                return 'Label';
            }

            public function description(): string
            {
                return 'A section.';
            }

            public function schema(): PropSchema
            {
                return $this->schema;
            }

            public function sample(): array
            {
                return [];
            }

            public function normalizeProps(array $props): array
            {
                return $props;
            }

            public function render(array $props, string $locale): string
            {
                return '';
            }
        };
    }
}
