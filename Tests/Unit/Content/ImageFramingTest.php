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
use TheliaCMS\Content\ImageFraming;
use TheliaCMS\Content\InvalidDocumentException;
use TheliaCMS\Content\Prop;
use TheliaCMS\Content\PropSchema;
use TheliaCMS\Content\PropType;

final class ImageFramingTest extends TestCase
{
    public function testEachPictureGetsFourPropsOnlyTheEditorSetsRightAfterIt(): void
    {
        $props = (new ImageFraming())->extend([Prop::text('title', 'Title'), Prop::image('photo', 'Photo'), Prop::text('caption', 'Caption')]);

        self::assertSame(
            ['title', 'photo', 'photo__proportions', 'photo__focus_x', 'photo__focus_y', 'photo__zoom', 'caption'],
            array_map(static fn (Prop $prop): string => $prop->name, $props),
        );

        foreach (\array_slice($props, 2, 4) as $prop) {
            self::assertTrue($prop->editorOnly);
            self::assertTrue(ImageFraming::isFraming($prop));
            self::assertSame('photo', ImageFraming::imageOf($prop));
        }

        self::assertFalse(ImageFraming::isFraming($props[1]));
        self::assertNull(ImageFraming::imageOf($props[0]));
        self::assertSame('original', $props[2]->default);
        self::assertSame([50, 50, 100], [$props[3]->default, $props[4]->default, $props[5]->default]);
    }

    public function testTheModelIsNeverShownThem(): void
    {
        $schema = new PropSchema((new ImageFraming())->extend([Prop::image('photo', 'Photo')]));

        self::assertSame(['photo'], array_keys((array) $schema->toJsonSchema()['properties']));
    }

    public function testThePicturesOfTheRowsOfAListAreFramedToo(): void
    {
        $props = (new ImageFraming())->extend([Prop::items('slides', 'Slides', [Prop::text('title', 'Title'), Prop::image('picture', 'Picture')], 1, 5)]);

        self::assertCount(1, $props);
        self::assertSame(
            ['title', 'picture', 'picture__proportions', 'picture__focus_x', 'picture__focus_y', 'picture__zoom'],
            array_map(static fn (Prop $prop): string => $prop->name, $props[0]->items),
        );
        self::assertSame(1, $props[0]->minItems);
        self::assertSame(5, $props[0]->maxItems);
    }

    public function testAListOfScalarsAndAComponentWithoutPictureAreLeftAlone(): void
    {
        $props = [Prop::items('tags', 'Tags', [Prop::text('value', 'Value')], scalarItems: true), Prop::text('title', 'Title')];

        self::assertEquals($props, (new ImageFraming())->extend($props));
    }

    public function testAPictureWhoseNamesAComponentPropAlreadyHoldsIsLeftUnframed(): void
    {
        $props = [Prop::image('photo', 'Photo'), Prop::integer('photo__zoom', 'Zoom', 1, 0, 9)];

        self::assertSame(['photo', 'photo__zoom'], array_map(static fn (Prop $prop): string => $prop->name, (new ImageFraming())->extend($props)));
    }

    public function testTheComponentIsNotGivenTheFraming(): void
    {
        $schema = (new ImageFraming())->extend([Prop::text('title', 'Title'), Prop::image('photo', 'Photo')]);

        self::assertSame(
            ['title' => 'A', 'photo' => 12],
            ImageFraming::withoutFraming($schema, ['title' => 'A', 'photo' => 12, 'photo__zoom' => 200, 'photo__focus_x' => 10, 'photo__focus_y' => 10, 'photo__proportions' => '16:9']),
        );
    }

    public function testAZoomOffTheStepsOfTenIsRefusedWhereverThePictureIs(): void
    {
        $schema = (new ImageFraming())->extend([Prop::image('photo', 'Photo'), Prop::items('rows', 'Rows', [Prop::image('picture', 'Picture')], 0, 4)]);

        ImageFraming::assertZoomSteps($schema, ['photo__zoom' => 150, 'rows' => [['picture__zoom' => 300]]]);

        try {
            ImageFraming::assertZoomSteps($schema, ['photo__zoom' => 155, 'rows' => []]);
            self::fail('A zoom of 155 is off the steps.');
        } catch (InvalidDocumentException $exception) {
            self::assertStringContainsString('photo__zoom', implode(' ', $exception->problems));
        }

        try {
            ImageFraming::assertZoomSteps($schema, ['photo__zoom' => 100, 'rows' => [['picture__zoom' => 100], ['picture__zoom' => 125]]]);
            self::fail('A zoom of 125 in a row is off the steps.');
        } catch (InvalidDocumentException $exception) {
            self::assertStringContainsString('rows', implode(' ', $exception->problems));
            self::assertStringContainsString('picture__zoom', implode(' ', $exception->problems));
        }
    }

    public function testPicturesAreTheOnlyPropsTheFramingFollows(): void
    {
        $props = (new ImageFraming())->extend([Prop::reference('page', 'Page', 'a page')]);

        self::assertCount(1, $props);
        self::assertSame(PropType::Reference, $props[0]->type);
    }
}
