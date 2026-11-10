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
use TheliaCMS\Content\Prop;
use TheliaCMS\Content\PropSchema;
use TheliaCMS\Content\SectionDefinitionInterface;
use TheliaCMS\Generation\CatalogSheet;

/**
 * The generator is told a list of scalars is a list of strings, not of objects.
 */
final class CatalogSheetScalarListTest extends TestCase
{
    public function testAListOfScalarsIsToldAsAListOfStrings(): void
    {
        $definition = self::definitionOf(new PropSchema([
            Prop::items('steps', 'steps', [Prop::text('value', 'value', required: true, max: 500)], 0, 50, 'The steps', scalarItems: true),
            Prop::items('blocks', 'blocks', [Prop::html('value', 'value', required: true)], 0, 50, scalarItems: true),
            Prop::items('ingredients', 'ingredients', [Prop::text('name', 'name', required: true)], 0, 50),
        ]));

        $sheet = (new \ReflectionClass(CatalogSheet::class))->newInstanceWithoutConstructor()->section($definition);

        self::assertStringContainsString('- `steps` (list of 0 to 50 strings, each 500 characters at most)', $sheet);
        self::assertStringContainsString('- `blocks` (list of 0 to 50 html strings)', $sheet);
        self::assertStringNotContainsString('`value`', $sheet, 'No object per row is described.');
        self::assertStringContainsString('- `ingredients` (list of 0 to 50 items)', $sheet);
        self::assertStringContainsString('`name`* (text', $sheet, 'A list of rows is still described by its fields.');
    }

    /**
     * The editor chooses a video, the generator never looks for one.
     */
    public function testAVideoIsToldAsAlwaysNullBecauseTheEditorChoosesIt(): void
    {
        $sheet = (new \ReflectionClass(CatalogSheet::class))->newInstanceWithoutConstructor()->section(self::definitionOf(new PropSchema([
            Prop::video('clip', 'clip'),
            Prop::items('gallery', 'gallery', [Prop::video('video', 'video')], 0, 4),
        ])));

        self::assertStringContainsString('- `clip` (always null: the video is chosen by the editor', $sheet);
        self::assertStringContainsString('`video` (always null: the video is chosen by the editor', $sheet);
        self::assertStringNotContainsString('search_media', $sheet, 'No tool is offered to find a video.');
    }

    /**
     * The generator gives the address a brief names, never one it makes up.
     */
    public function testAnEmbedIsToldAsTheAddressOfAVideoOrEmpty(): void
    {
        $embed = Prop::embed('video', 'Presentation video', help: 'The address the brief gives.');
        $sheet = (new \ReflectionClass(CatalogSheet::class))->newInstanceWithoutConstructor()->section(self::definitionOf(new PropSchema([
            $embed,
            Prop::items('gallery', 'gallery', [Prop::embed('clip', 'clip')], 0, 4),
        ])));

        self::assertStringContainsString('- `video` (https address of a YouTube, Vimeo or Dailymotion video, or empty', $sheet);
        self::assertStringContainsString('`clip` (https address of a YouTube, Vimeo or Dailymotion video, or empty', $sheet);

        $schema = $embed->toJsonSchema();

        self::assertSame('string', $schema['type']);
        self::assertStringContainsString('Empty when not needed.', $schema['description']);
    }

    private static function definitionOf(PropSchema $schema): SectionDefinitionInterface
    {
        return new class($schema) implements SectionDefinitionInterface {
            public function __construct(private readonly PropSchema $schema)
            {
            }

            public function name(): string
            {
                return 'Organisms:Recipe:Base';
            }

            public function label(): string
            {
                return 'Recipe';
            }

            public function description(): string
            {
                return 'A recipe.';
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
