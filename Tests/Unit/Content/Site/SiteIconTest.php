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

namespace TheliaCMS\Tests\Unit\Content\Site;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\UX\Icons\IconRendererInterface;
use Thelia\Core\Template\TemplateDefinition;
use Thelia\Core\Template\TemplateHelperInterface;
use TheliaCMS\Content\Site\SiteIcon;
use TheliaCMS\Content\Site\SiteTemplateRenderer;
use TheliaCMS\Media\Icon\IconFileFinder;
use TheliaCMS\Media\Icon\KnownIcons;
use TheliaCMS\Media\Icon\ThemeIcons;
use Twig\Sandbox\SecurityError;

/**
 * `cms_icon()` of the sandbox: known names only, a short list of attributes.
 */
final class SiteIconTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/theliacms-site-icon-'.bin2hex(random_bytes(6));
        $filesystem = new Filesystem();
        $filesystem->dumpFile($this->directory.'/library/known.svg', '<svg/>');
        $filesystem->dumpFile($this->directory.'/theme/assets/icons/arrow-left.svg', '<svg/>');
        $filesystem->dumpFile($this->directory.'/theme/assets/icons/set/flag.svg', '<svg/>');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function known(): iterable
    {
        yield 'a CMS icon' => ['cms:known'];
        yield 'an icon of the theme' => ['arrow-left'];
        yield 'an icon of a set of the theme' => ['set:flag'];
    }

    #[DataProvider('known')]
    public function testAKnownReferenceIsDrawnByTheRenderer(string $reference): void
    {
        $icon = $this->icon($logger = $this->logger(), $drawn = self::recording());

        self::assertSame('<svg data-name="'.$reference.'"/>', $icon->render($reference));
        self::assertSame([$reference], array_column($drawn->getArrayCopy(), 'name'));
        self::assertSame([], $logger->records);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function unknown(): iterable
    {
        yield 'a CMS icon taken away' => ['cms:gone'];
        yield 'a theme icon that is not there' => ['nowhere'];
        yield 'a set icon that is not there' => ['set:nowhere'];
        yield 'an invalid shape' => ['cms:Bad Name'];
        yield 'a path' => ['../../etc/passwd'];
        yield 'an Iconify name' => ['lucide:home'];
    }

    #[DataProvider('unknown')]
    public function testAnUnknownOrInvalidReferenceDrawsNothingAndWarns(string $reference): void
    {
        $icon = $this->icon($logger = $this->logger(), $drawn = self::recording());

        self::assertSame('', $icon->render($reference));
        self::assertCount(0, $drawn, 'The renderer, which could reach the network, is not asked.');
        self::assertCount(1, $logger->records);
        self::assertSame('warning', $logger->records[0]['level']);
        self::assertSame($reference, $logger->records[0]['context']['icon']);
    }

    public function testNothingIsDrawnForAnEmptyValueWithoutAWarning(): void
    {
        $icon = $this->icon($logger = $this->logger(), $drawn = self::recording());

        self::assertSame('', $icon->render(''));
        self::assertSame('', $icon->render(null));
        self::assertSame([], $logger->records);
    }

    public function testOnlyTheListedAttributesAreGiven(): void
    {
        $icon = $this->icon($this->logger(), $drawn = self::recording());

        $icon->render('cms:known', ['class' => 'mark', 'aria-label' => 'Home', 'width' => 24, 'onclick' => 'x()', 'style' => 'color:red', 'role' => 'img', 'height' => '', 'aria-hidden' => 'true', 'title' => 't', 'data-x' => '1']);

        self::assertSame(['class' => 'mark', 'aria-label' => 'Home', 'width' => 24, 'role' => 'img', 'aria-hidden' => 'true'], $drawn[0]['attributes']);
    }

    public function testTheSandboxOffersCmsIconAndNotUxIcon(): void
    {
        self::assertContains('cms_icon', SiteTemplateRenderer::FUNCTIONS);
        self::assertNotContains('ux_icon', SiteTemplateRenderer::FUNCTIONS);

        $renderer = new SiteTemplateRenderer($this->icon($this->logger(), $drawn = self::recording()));
        self::assertSame('<i><svg data-name="cms:known"/></i>', $renderer->render('<i>{{ cms_icon(props.icon, {class: "a"}) }}</i>', ['icon' => 'cms:known']), 'Printed as markup, not escaped.');
        self::assertSame(['class' => 'a'], $drawn[0]['attributes']);
        self::assertSame('', (new SiteTemplateRenderer())->render('{{ cms_icon("cms:known") }}', []), 'Without the service, nothing is drawn.');

        $this->expectException(SecurityError::class);
        $renderer->render('{{ ux_icon("cms:known") }}', []);
    }

    /**
     * @return \ArrayObject<int, array{name: string, attributes: array<string, mixed>}>
     */
    private static function recording(): \ArrayObject
    {
        return new \ArrayObject();
    }

    /**
     * @param \ArrayObject<int, array{name: string, attributes: array<string, mixed>}> $drawn
     */
    private function icon(object $logger, \ArrayObject $drawn): SiteIcon
    {
        $library = new class($this->directory.'/library') implements IconFileFinder {
            public function __construct(private readonly string $directory)
            {
            }

            public function fileOf(string $name): ?string
            {
                return 'known' === $name ? $this->directory.'/known.svg' : (str_starts_with($name, 'gone') ? $this->directory.'/gone.svg' : null);
            }

            public function names(): array
            {
                return ['known'];
            }
        };
        $renderer = new class($drawn) implements IconRendererInterface {
            /** @param \ArrayObject<int, array{name: string, attributes: array<string, mixed>}> $drawn */
            public function __construct(private readonly \ArrayObject $drawn)
            {
            }

            public function renderIcon(string $name, array $attributes = []): string
            {
                $this->drawn->append(['name' => $name, 'attributes' => $attributes]);

                return '<svg data-name="'.$name.'"/>';
            }
        };

        return new SiteIcon(new KnownIcons($library, new ThemeIcons($this->helper())), $renderer, $logger);
    }

    private function helper(): TemplateHelperInterface
    {
        $active = $this->createStub(TemplateDefinition::class);
        $active->method('getAbsolutePath')->willReturn($this->directory.'/theme');
        $active->method('getParentList')->willReturn([]);
        $helper = $this->createStub(TemplateHelperInterface::class);
        $helper->method('getActiveFrontTemplate')->willReturn($active);

        return $helper;
    }

    private function logger(): object
    {
        return new class extends AbstractLogger {
            /** @var list<array{level: string, context: array<string, mixed>}> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => (string) $level, 'context' => $context];
            }
        };
    }
}
