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

namespace TheliaCMS\Tests\Unit\Content\Theme;

use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\UX\TwigComponent\ComponentRendererInterface;
use Thelia\Core\Template\TemplateDefinition;
use Thelia\Core\Template\TemplateHelperInterface;
use TheliaCMS\Content\Prop;
use TheliaCMS\Content\PropSchema;
use TheliaCMS\Content\Theme\ThemeComponentSection;
use TheliaCMS\Media\Icon\IconFileFinder;
use TheliaCMS\Media\Icon\KnownIcons;
use TheliaCMS\Media\Icon\ThemeIcons;

/**
 * A component of the theme runs `ux_icon()` on the icon it is given: it must
 * never receive a name the site does not hold.
 */
final class ThemeComponentSectionIconTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/theliacms-section-icons-'.bin2hex(random_bytes(6));
        (new Filesystem())->dumpFile($this->directory.'/library/live.svg', '<svg/>');
        (new Filesystem())->dumpFile($this->directory.'/theme/assets/icons/arrow.svg', '<svg/>');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    public function testAnIconPropTheSiteDoesNotHoldReachesTheComponentEmptyAndIsLogged(): void
    {
        $logs = new \ArrayObject();
        $received = null;
        $section = $this->section($received, $logs);

        $section->render(['icon' => 'mdi:evil', 'title' => 'Hello'], 'en_US');

        self::assertSame(['title' => 'Hello'], $received, 'An empty prop is left to the default of the component.');
        self::assertSame([['icon' => 'mdi:evil']], $logs->getArrayCopy(), 'The name at fault, nothing else.');
    }

    public function testAKnownIconOfTheLibraryOrOfTheThemeIsHandedOverIntact(): void
    {
        $logs = new \ArrayObject();
        $received = null;
        $section = $this->section($received, $logs);

        $section->render(['icon' => 'cms:live'], 'en_US');
        self::assertSame(['icon' => 'cms:live'], $received);

        $section->render(['icon' => 'arrow'], 'en_US');
        self::assertSame(['icon' => 'arrow'], $received);
        self::assertCount(0, $logs);
    }

    public function testAnEmptyIconStaysEmptyWithoutLog(): void
    {
        $logs = new \ArrayObject();
        $received = null;
        $section = $this->section($received, $logs);

        $section->render(['icon' => '', 'title' => 'Hello'], 'en_US');

        self::assertSame(['title' => 'Hello'], $received);
        self::assertCount(0, $logs);
    }

    public function testAValueThatIsNotAStringIsEmptied(): void
    {
        $logs = new \ArrayObject();
        $received = null;
        $section = $this->section($received, $logs);

        $section->render(['icon' => ['x'], 'title' => 'Hello'], 'en_US');

        self::assertSame(['title' => 'Hello'], $received);
        self::assertCount(1, $logs);
    }

    public function testAnUnknownIconOfAListLeavesItAndTheListIsReindexed(): void
    {
        $logs = new \ArrayObject();
        $received = null;
        $section = $this->section($received, $logs);

        $section->render(['glyphs' => ['mdi:evil', '', 'cms:live', null, 'arrow']], 'en_US');

        self::assertSame(['cms:live', 'arrow'], $received['glyphs'], 'An empty entry is left out too: ux_icon() would throw on it.');
        self::assertCount(1, $logs, 'Only the unknown name is logged, not the empty entries.');
    }

    public function testAValueThatIsNotAStringIsLoggedByItsTypeAndAStringIsCut(): void
    {
        $logs = new \ArrayObject();
        $received = null;
        $section = $this->section($received, $logs);

        $section->render(['icon' => ['secret' => 'x']], 'en_US');
        $section->render(['icon' => str_repeat('a', 300)], 'en_US');

        self::assertSame([['icon' => 'array'], ['icon' => str_repeat('a', 128)]], $logs->getArrayCopy());
    }

    public function testTheIconFieldOfARowAndAListOfIconsAreCheckedToo(): void
    {
        $logs = new \ArrayObject();
        $received = null;
        $section = $this->section($received, $logs);

        $section->render(['links' => [['label' => 'A', 'glyph' => 'arrow'], ['label' => 'B', 'glyph' => 'mdi:evil']], 'glyphs' => ['cms:live', 'mdi:evil']], 'en_US');

        self::assertSame([['label' => 'A', 'glyph' => 'arrow'], ['label' => 'B', 'glyph' => '']], $received['links']);
        self::assertSame(['cms:live'], $received['glyphs'], 'An unknown entry leaves the list: a loop on the list would meet no empty name.');
        self::assertCount(2, $logs);
    }

    /**
     * @param array<string, mixed>|null $received
     * @param \ArrayObject<int, mixed>  $logs
     */
    private function section(?array &$received, \ArrayObject $logs): ThemeComponentSection
    {
        $renderer = $this->createStub(ComponentRendererInterface::class);
        $renderer->method('createAndRender')->willReturnCallback(static function (string $name, array $props) use (&$received): string {
            $received = $props;

            return '<div></div>';
        });
        $schema = new PropSchema([
            Prop::text('title', 'title'),
            Prop::icon('icon', 'icon'),
            Prop::items('links', 'links', [Prop::text('label', 'label'), Prop::icon('glyph', 'glyph')], 0, 12),
            Prop::items('glyphs', 'glyphs', [Prop::icon('value', 'value')], 0, 12, scalarItems: true),
        ]);
        $logger = new class($logs) extends AbstractLogger {
            /** @param \ArrayObject<int, mixed> $logs */
            public function __construct(private readonly \ArrayObject $logs)
            {
            }

            public function log(mixed $level, string|\Stringable $message, array $context = []): void
            {
                $this->logs->append($context);
            }
        };
        $names = ['title' => 'title', 'icon' => 'icon', 'links' => 'links', 'glyphs' => 'glyphs'];

        return new ThemeComponentSection('Atoms:Badge:Base', '', $schema, $names, $renderer, [], [], null, null, $this->known(), $logger);
    }

    private function known(): KnownIcons
    {
        $library = new class($this->directory.'/library/live.svg') implements IconFileFinder {
            public function __construct(private readonly string $file)
            {
            }

            public function fileOf(string $name): ?string
            {
                return 'live' === $name ? $this->file : null;
            }

            public function names(): array
            {
                return ['live'];
            }
        };
        $active = $this->createStub(TemplateDefinition::class);
        $active->method('getAbsolutePath')->willReturn($this->directory.'/theme');
        $active->method('getParentList')->willReturn([]);
        $helper = $this->createStub(TemplateHelperInterface::class);
        $helper->method('getActiveFrontTemplate')->willReturn($active);

        return new KnownIcons($library, new ThemeIcons($helper));
    }
}
