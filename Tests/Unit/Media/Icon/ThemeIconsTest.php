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
use TheliaCMS\Media\Icon\ThemeIcons;

final class ThemeIconsTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/theliacms-theme-icons-'.bin2hex(random_bytes(6));
        (new Filesystem())->mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    public function testTheNamesAreTheSvgsOfTheIconDirectoryOfTheThemeWithoutTheExtension(): void
    {
        $this->icons('child', ['star', 'arrow-left'], 'ignored.txt');

        self::assertSame(['arrow-left', 'star'], (new ThemeIcons($this->helper('child')))->names());
    }

    public function testAnIconOfTheParentIsListedAndTheNearestThemeAnswersFirst(): void
    {
        $this->icons('parent', ['star', 'home']);
        $this->icons('child', ['star']);
        $icons = new ThemeIcons($this->helper('child', 'parent'));

        self::assertSame(['home', 'star'], $icons->names());
        self::assertSame($this->directory.'/child/assets/icons/star.svg', $icons->files()['star'], 'The child redraws the star.');
        self::assertSame($this->directory.'/parent/assets/icons/home.svg', $icons->files()['home']);
    }

    public function testAnIconOfASubDirectoryIsNamedAfterItsSetAndOnlyValidNamesAreListed(): void
    {
        $this->icons('child', ['star', 'Not Valid', 'with_underscore']);
        (new Filesystem())->dumpFile($this->directory.'/child/assets/icons/circle-flags/fr.svg', '<svg/>');
        (new Filesystem())->dumpFile($this->directory.'/child/assets/icons/Bad Set/x.svg', '<svg/>');
        (new Filesystem())->dumpFile($this->directory.'/child/assets/icons/set/deeper/inside.svg', '<svg/>');

        self::assertSame(['circle-flags:fr', 'set:deeper:inside', 'star'], (new ThemeIcons($this->helper('child')))->names(), 'Any depth, named as ux-icons reads it.');
    }

    public function testAThemeWithoutIconsGivesAnEmptyList(): void
    {
        (new Filesystem())->mkdir($this->directory.'/bare');

        self::assertSame([], (new ThemeIcons($this->helper('bare')))->names());
    }

    public function testASiteWithoutAnActiveThemeGivesAnEmptyListWithoutAnError(): void
    {
        $helper = $this->createStub(TemplateHelperInterface::class);
        $helper->method('getActiveFrontTemplate')->willThrowException(new \RuntimeException('No template.'));

        self::assertSame([], (new ThemeIcons($helper))->names());
    }

    /**
     * @param list<string> $names
     */
    private function icons(string $theme, array $names, string ...$others): void
    {
        foreach ($names as $name) {
            (new Filesystem())->dumpFile(\sprintf('%s/%s/assets/icons/%s.svg', $this->directory, $theme, $name), '<svg xmlns="http://www.w3.org/2000/svg"/>');
        }

        foreach ($others as $other) {
            (new Filesystem())->dumpFile(\sprintf('%s/%s/assets/icons/%s', $this->directory, $theme, $other), 'x');
        }
    }

    private function helper(string $theme, string ...$parents): TemplateHelperInterface
    {
        $definition = fn (string $name): TemplateDefinition => $this->createConfiguredStub(TemplateDefinition::class, ['getAbsolutePath' => $this->directory.'/'.$name]);
        $active = $this->createStub(TemplateDefinition::class);
        $active->method('getAbsolutePath')->willReturn($this->directory.'/'.$theme);
        $active->method('getParentList')->willReturn(array_map($definition, $parents));
        $helper = $this->createStub(TemplateHelperInterface::class);
        $helper->method('getActiveFrontTemplate')->willReturn($active);

        return $helper;
    }
}
