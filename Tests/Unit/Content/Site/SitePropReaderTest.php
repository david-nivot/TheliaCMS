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

use PHPUnit\Framework\TestCase;
use TheliaCMS\Content\PropType;
use TheliaCMS\Content\Site\InvalidSiteComponentException;
use TheliaCMS\Content\Site\SitePropReader;

/**
 * The props of a component of the site, read from the JSON the model writes.
 */
final class SitePropReaderTest extends TestCase
{
    public function testEveryTypeATemplateCanPrintIsRead(): void
    {
        $schema = (new SitePropReader())->read(PriceTable::component()->props);

        self::assertSame(['title', 'plans', 'tone'], array_map(static fn ($prop) => $prop->name, $schema->props));
        self::assertSame(PropType::Items, $schema->props[1]->type);
        self::assertSame([PropType::Text, PropType::Text, PropType::Html, PropType::Boolean], array_map(static fn ($prop) => $prop->type, $schema->props[1]->items));
        self::assertSame(120, $schema->props[0]->max);
        self::assertSame(['light' => 'Light', 'dark' => 'Dark'], $schema->props[2]->choices);
        self::assertSame('light', $schema->props[2]->default);
    }

    public function testAVideoIsReadAtTheTopAndInTheRowsOfAList(): void
    {
        $schema = (new SitePropReader())->read([
            ['name' => 'clip', 'type' => 'video', 'label' => 'Clip', 'required' => true],
            ['name' => 'gallery', 'type' => 'items', 'label' => 'Gallery', 'items' => [['name' => 'video', 'type' => 'video', 'label' => 'Video']]],
        ]);

        self::assertSame(PropType::Video, $schema->props[0]->type);
        self::assertTrue($schema->props[0]->required);
        self::assertSame(PropType::Video, $schema->props[1]->items[0]->type);
    }

    public function testEveryProblemIsListedAtOnce(): void
    {
        try {
            (new SitePropReader())->read([
                ['name' => 'Title', 'type' => 'text', 'label' => 'Heading'],
                ['name' => 'page', 'type' => 'reference', 'label' => 'Page'],
                ['name' => 'tone', 'type' => 'choice', 'label' => 'Tone', 'choices' => ['light', 'dark']],
                ['name' => 'rows', 'type' => 'items', 'label' => 'Rows', 'items' => [['name' => 'cells', 'type' => 'items', 'label' => 'Cells', 'items' => [['name' => 'a', 'type' => 'text', 'label' => 'A']]]]],
                ['name' => 'count', 'type' => 'integer', 'label' => 'Count', 'min' => 5, 'max' => 1],
                ['name' => 'count', 'type' => 'text', 'label' => 'Count again'],
            ]);
            self::fail('Props that do not read are refused.');
        } catch (InvalidSiteComponentException $exception) {
            self::assertCount(6, $exception->problems, implode("\n", $exception->problems));
            self::assertStringContainsString('lowercase', $exception->problems[0]);
            self::assertStringContainsString('"reference"', $exception->problems[1], 'A reference needs a function to be of use: not offered.');
            self::assertStringContainsString('value => label', $exception->problems[2]);
            self::assertStringContainsString('"rows.cells"', $exception->problems[3], 'An item never holds items.');
            self::assertStringContainsString('min ≤ default ≤ max', $exception->problems[4]);
            self::assertStringContainsString('declared twice', $exception->problems[5]);
        }
    }

    public function testAComponentWithoutPropsIsRefused(): void
    {
        $this->expectException(InvalidSiteComponentException::class);
        $this->expectExceptionMessage('every text it shows is one');

        (new SitePropReader())->read([]);
    }

    public function testAnIconIsReadAtTheTopAndInTheRowsOfAList(): void
    {
        $schema = (new SitePropReader())->read([
            ['name' => 'mark', 'type' => 'icon', 'label' => 'Mark', 'required' => true],
            ['name' => 'steps', 'type' => 'items', 'label' => 'Steps', 'items' => [['name' => 'icon', 'type' => 'icon', 'label' => 'Icon']]],
        ]);

        self::assertSame(PropType::Icon, $schema->props[0]->type);
        self::assertTrue($schema->props[0]->required);
        self::assertSame(PropType::Icon, $schema->props[1]->items[0]->type);
    }

    public function testTheGeneratorIsToldAboutTheIcon(): void
    {
        self::assertContains('icon', SitePropReader::TYPES, 'The sandbox draws an icon with cms_icon().');
    }
}
