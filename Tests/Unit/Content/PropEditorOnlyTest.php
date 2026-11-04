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

namespace TheliaCMS\Tests\Unit\Content;

use PHPUnit\Framework\TestCase;
use TheliaCMS\Content\Prop;
use TheliaCMS\Content\PropSchema;
use TheliaCMS\Content\SectionDefinitionInterface;
use TheliaCMS\Generation\CatalogSheet;

/**
 * What is for the editor alone never reaches the model that writes a document:
 * not its JSON schema, not the sheet of the catalogue, and the instruction it
 * reads does not change when a help is written for the editor.
 */
final class PropEditorOnlyTest extends TestCase
{
    public function testAnEditorOnlyPropIsNotInTheJsonSchema(): void
    {
        $schema = new PropSchema([
            Prop::text('caption', 'Caption'),
            Prop::integer('width_px', 'Width', 0, 0, 2400)->forEditor(only: true),
        ]);

        $json = $schema->toJsonSchema();

        self::assertSame(['caption'], array_keys((array) $json['properties']));
        self::assertSame(0, $schema->normalize([])['width_px'], 'An editor-only prop keeps its default in a document.');
    }

    public function testAChoiceValueKeptForTheEditorIsNotOffered(): void
    {
        $width = Prop::choice('width', 'Width', ['narrow' => 'Narrow', 'full' => 'Full', 'custom' => 'Custom'], 'narrow')->forEditor(onlyChoices: ['custom']);

        self::assertSame(['narrow', 'full'], $width->toJsonSchema()['enum']);
        self::assertSame('custom', (new PropSchema([$width]))->normalize(['width' => 'custom'])['width'], 'The editor may still choose it.');
    }

    public function testTheModelReadsTheHelpAndNotTheEditorHelp(): void
    {
        $prop = Prop::text('caption', 'Caption', help: 'Under the image.')->forEditor(help: 'Words for the editor.');

        self::assertStringContainsString('Under the image.', $prop->toJsonSchema()['description']);
        self::assertStringNotContainsString('Words for the editor', $prop->toJsonSchema()['description']);
    }

    public function testTheSheetOfTheCatalogueDoesNotMove(): void
    {
        $plain = [
            Prop::text('caption', 'Caption', help: 'Under the image.'),
            Prop::choice('width', 'Width', ['narrow' => 'Narrow', 'full' => 'Full'], 'narrow', 'How wide.'),
        ];
        $withEditorProps = [
            Prop::text('caption', 'Caption', help: 'Under the image.')->forEditor(help: 'Words for the editor.'),
            Prop::choice('width', 'Width', ['narrow' => 'Narrow', 'full' => 'Full', 'custom' => 'Custom'], 'narrow', 'How wide.')->forEditor(help: 'Another help.', onlyChoices: ['custom']),
            Prop::integer('width_px', 'Width in pixels', 0, 0, 2400)->forEditor(only: true),
        ];

        self::assertSame($this->sheet($plain), $this->sheet($withEditorProps));
        self::assertStringNotContainsString('width_px', $this->sheet($withEditorProps));
        self::assertStringNotContainsString('custom', $this->sheet($withEditorProps));
    }

    /**
     * @param list<Prop> $props
     */
    private function sheet(array $props): string
    {
        $definition = new class($props) implements SectionDefinitionInterface {
            /**
             * @param list<Prop> $props
             */
            public function __construct(private readonly array $props)
            {
            }

            public function name(): string
            {
                return 'cms:test';
            }

            public function label(): string
            {
                return 'Test';
            }

            public function description(): string
            {
                return 'A test.';
            }

            public function schema(): PropSchema
            {
                return new PropSchema($this->props);
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

        return (new \ReflectionClass(CatalogSheet::class))->newInstanceWithoutConstructor()->section($definition);
    }
}
