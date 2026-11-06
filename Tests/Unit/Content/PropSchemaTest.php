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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheliaCMS\Content\InvalidDocumentException;
use TheliaCMS\Content\Prop;
use TheliaCMS\Content\PropSchema;

/**
 * What a section accepts from whoever wrote its props: strict on types and
 * ranges, forgiving on what is missing, and every problem told at once.
 */
final class PropSchemaTest extends TestCase
{
    private PropSchema $schema;

    protected function setUp(): void
    {
        $this->schema = new PropSchema([
            Prop::text('title', 'Title', required: true, max: 20),
            Prop::html('body', 'Body'),
            Prop::integer('count', 'Count', 3, 1, 12),
            Prop::boolean('open', 'Open'),
            Prop::choice('tone', 'Tone', ['light' => 'Light', 'dark' => 'Dark'], 'light'),
            Prop::image('picture', 'Picture'),
            Prop::url('link', 'Link'),
            Prop::items('items', 'Items', [
                Prop::text('label', 'Label', required: true),
                Prop::image('image', 'Image', required: true),
            ], min: 1, max: 2),
        ]);
    }

    public function testAH1OfAHtmlPropBecomesAH2AndKeepsItsAttributes(): void
    {
        $values = $this->schema->normalize(['title' => 'T', 'items' => [['label' => 'One', 'image' => '7']], 'body' => '<H1 id="top" class="big">Title</H1 ><p>Text</p><h10>x</h10><h1>Two</h1>']);

        self::assertSame('<h2 id="top" class="big">Title</h2><p>Text</p><h10>x</h10><h2>Two</h2>', $values['body']);
    }

    public function testAnImageAlreadyInAHtmlPropIsKept(): void
    {
        $html = '<h2>Title</h2><p><img src="/image-library/12/full/max/0/default.jpg" alt="A field"></p>';

        self::assertSame($html, $this->schema->normalize(['title' => 'T', 'items' => [['label' => 'One', 'image' => '7']], 'body' => $html])['body']);
    }

    public function testMissingPropsGetTheirDefaultAndUnknownOnesAreDropped(): void
    {
        $values = $this->schema->normalize([
            'title' => '  Hello  ',
            'items' => [['label' => 'One', 'image' => '7', 'extra' => 'dropped']],
            'extra' => 'dropped',
        ]);

        self::assertSame([
            'title' => 'Hello',
            'body' => '',
            'count' => 3,
            'open' => false,
            'tone' => 'light',
            'picture' => null,
            'link' => '',
            'items' => [['label' => 'One', 'image' => 7]],
        ], $values);
    }

    public function testValuesAreReadInTheFormsAnAuthorWritesThem(): void
    {
        $values = $this->schema->normalize([
            'title' => 'T',
            'count' => '12',
            'open' => 'true',
            'tone' => 'dark',
            'picture' => 42,
            'link' => 'https://example.test/a?b=c',
            'items' => [['label' => 'One', 'image' => 7], ['label' => 'Two', 'image' => 8]],
        ]);

        self::assertSame(12, $values['count']);
        self::assertTrue($values['open']);
        self::assertSame('dark', $values['tone']);
        self::assertSame(42, $values['picture']);
    }

    public function testAnImageMayBeTheAddressTheMediaLibraryServesAPictureFrom(): void
    {
        $addresses = [
            '/image-library/17/full/max/0/default.webp' => 17,
            ' /image-library/3/full/480,!/0/default.jpg ' => 3,
            '/image-library/8/full/max/0/default.PNG' => 8,
            '/image-library/5/full/max/0/default.svg' => 5,
        ];

        foreach ($addresses as $address => $id) {
            $values = $this->schema->normalize(['title' => 'T', 'picture' => $address, 'items' => [['label' => 'One', 'image' => $address]]]);

            self::assertSame($id, $values['picture'], $address);
            self::assertSame($id, $values['items'][0]['image'], 'An image of an item reads it the same way.');
        }

        self::assertNull($this->schema->normalize(['title' => 'T', 'picture' => '/image-library/0/full/max/0/default.webp', 'items' => [['label' => 'One', 'image' => 7]]])['picture'], 'Zero is no picture, as for an id.');
    }

    /**
     * The id of a video of the library, or the path the library serves it
     * from as an editor copies it; nothing from outside the library.
     */
    public function testAVideoIsTheIdOfAVideoOfTheLibraryOrItsPath(): void
    {
        $schema = new PropSchema([
            Prop::video('clip', 'Clip'),
            Prop::items('videos', 'Videos', [Prop::text('title', 'Title'), Prop::video('video', 'Video', required: true)]),
        ]);

        foreach ([12, '12', '/video-library/12.mp4', ' /video-library/12.MP4 '] as $value) {
            $values = $schema->normalize(['clip' => $value, 'videos' => [['title' => 'One', 'video' => $value]]]);

            self::assertSame(12, $values['clip'], var_export($value, true));
            self::assertSame(12, $values['videos'][0]['video'], 'A video of a row reads it the same way.');
        }

        self::assertNull($schema->normalize(['clip' => 0, 'videos' => []])['clip'], 'Zero is no video yet.');
        self::assertNull($schema->normalize(['clip' => '0', 'videos' => []])['clip']);
        self::assertNull($schema->normalize(['clip' => null, 'videos' => []])['clip']);
        self::assertNull($schema->normalize(['videos' => []])['clip']);

        foreach (['https://videos.example.com/a.mp4', 'http://example.com/a.mp4', '/video-library/12.mov', '/video-library/x.mp4', '/video-library/12/full.mp4', '/image-library/12/full/max/0/default.jpg', -1, '-1', 'twelve', 1.5] as $refused) {
            try {
                $schema->normalize(['clip' => $refused, 'videos' => []]);
                self::fail(var_export($refused, true).' is no video of the library.');
            } catch (InvalidDocumentException $exception) {
                self::assertSame(['"clip" must be the id of a video of the media library.'], array_map(static fn (mixed $text): string => (string) $text, $exception->problems), var_export($refused, true));
            }
        }
    }

    public function testARequiredVideoLeftEmptyIsRefused(): void
    {
        $required = new PropSchema([Prop::video('clip', 'Clip', required: true)]);

        foreach ([0, '0', null, ''] as $empty) {
            try {
                $required->normalize(['clip' => $empty]);
                self::fail('A required video is not left empty.');
            } catch (InvalidDocumentException $exception) {
                self::assertSame('"clip" is required.', (string) $exception->problems[0]);
            }
        }
    }

    public function testTheJsonSchemaOfAVideoIsAnIdOrNothing(): void
    {
        $properties = (array) (new PropSchema([Prop::video('clip', 'Clip'), Prop::video('main', 'Main', required: true)]))->toJsonSchema()['properties'];

        self::assertSame(['integer', 'null'], $properties['clip']['type']);
        self::assertSame('integer', $properties['main']['type']);
        self::assertStringContainsString('0 when there is none', $properties['clip']['description']);
    }

    public function testAListOfScalarsIsStoredAsAListOfScalars(): void
    {
        $schema = new PropSchema([Prop::items('steps', 'steps', [Prop::text('value', 'value', required: true, max: 20)], 0, 3, scalarItems: true)]);

        self::assertSame(['one', 'two'], $schema->normalize(['steps' => ['one', ' two ']])['steps']);
        self::assertSame(['one', 'two'], $schema->normalize(['steps' => [['value' => 'one'], ['value' => 'two']]])['steps'], 'A row of the form is read too.');
        self::assertSame(['one', 'two'], $schema->normalize(['steps' => ['one', '', null, '  ', ['value' => ''], 'two']])['steps'], 'A blank row of the form is dropped.');
        self::assertSame(['one'], $schema->normalize(['steps' => '["one"]'])['steps'], 'A generator writes the list as a JSON string.');
        self::assertSame([], $schema->normalize([])['steps']);

        foreach ([[['a' => 1]], [['one']], ['one', 'two', 'three', 'four'], [str_repeat('x', 21)], 'nope'] as $wrong) {
            try {
                $schema->normalize(['steps' => $wrong]);
                self::fail('A wrong list should have been refused: '.json_encode($wrong));
            } catch (InvalidDocumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $blanks = new PropSchema([Prop::items('steps', 'steps', [Prop::text('value', 'value', required: true)], 2, 3, scalarItems: true)]);
        $this->expectException(InvalidDocumentException::class);
        $blanks->normalize(['steps' => ['one', '', '']]);
    }

    public function testAListOfScalarsIsDescribedAsAnArrayOfTheScalar(): void
    {
        $steps = Prop::items('steps', 'Steps', [Prop::text('value', 'value', required: true, max: 500)], 0, 50, scalarItems: true)->toJsonSchema();

        self::assertSame('array', $steps['type']);
        self::assertSame(50, $steps['maxItems']);
        self::assertSame('string', $steps['items']['type']);
        self::assertSame(500, $steps['items']['maxLength']);
        self::assertArrayNotHasKey('properties', $steps['items'], 'Not an object per row.');

        $html = Prop::items('blocks', 'Blocks', [Prop::html('value', 'value', required: true)], 0, 50, scalarItems: true)->toJsonSchema();

        self::assertSame('string', $html['items']['type']);
    }

    public function testAScalarListHoldsOneItemNamedValue(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Prop::items('steps', 'steps', [Prop::text('label', 'label')], scalarItems: true);
    }

    public function testAnImageMayBeTheHttpsAddressOfAPictureKeptElsewhere(): void
    {
        $values = $this->schema->normalize(['title' => 'T', 'picture' => ' https://cdn.example.org/a%20b.jpg?x=1 ', 'items' => [['label' => 'One', 'image' => 'https://example.org/one.png']]]);

        self::assertSame('https://cdn.example.org/a%20b.jpg?x=1', $values['picture'], 'Kept as typed, trimmed.');
        self::assertSame('https://example.org/one.png', $values['items'][0]['image']);

        foreach (['http://example.org/one.png', 'https://', 'https://example.org/a b.png', '/image-library/abc/full/max/0/default.webp', '/image-library/3/full/max/default.webp', '/image-library/3/full/max/0/default.exe', '//evil.example/image-library/3/full/max/0/default.webp', '/image-library/3/full/max/0/default.webp?x=1'] as $address) {
            try {
                $this->schema->normalize(['title' => 'T', 'picture' => $address, 'items' => [['label' => 'One', 'image' => 7]]]);
                self::fail(\sprintf('"%s" should have been refused.', $address));
            } catch (InvalidDocumentException $exception) {
                self::assertSame(['"picture" must be the id of an image of the media library, or an https address.'], $exception->problems);
            }
        }
    }

    public function testEveryProblemIsListedAtOnce(): void
    {
        try {
            $this->schema->normalize([
                'title' => 'A title that is far too long for the limit',
                'count' => 13,
                'open' => 'maybe',
                'tone' => 'neon',
                'picture' => 'cat.jpg',
                'link' => 'javascript:alert(1)',
                'items' => [['label' => '', 'image' => 7], 'not an item', ['label' => 'Three', 'image' => 9]],
            ]);
            self::fail('The props should have been refused.');
        } catch (InvalidDocumentException $exception) {
            self::assertSame([
                '"title" is longer than 20 characters.',
                '"count" must be between 1 and 12.',
                '"open" must be true or false.',
                '"tone" must be one of light, dark.',
                '"picture" must be the id of an image of the media library, or an https address.',
                '"link" must be a path of the site or an absolute http(s), mailto or tel address.',
                '"items" must hold between 1 and 2 items.',
            ], $exception->problems);
        }
    }

    public function testAListWrittenAsAJsonStringIsReadBack(): void
    {
        $values = $this->schema->normalize(['title' => 'T', 'items' => '[{"label":"One","image":7}]']);

        self::assertSame([['label' => 'One', 'image' => 7]], $values['items']);

        $this->expectException(InvalidDocumentException::class);
        $this->expectExceptionMessage('"items" is not valid JSON.');

        $this->schema->normalize(['title' => 'T', 'items' => '[{"label":']);
    }

    public function testProblemsInsideItemsNameTheItem(): void
    {
        try {
            $this->schema->normalize(['title' => 'T', 'items' => [['label' => '', 'image' => 7], 'not an item']]);
            self::fail('The props should have been refused.');
        } catch (InvalidDocumentException $exception) {
            self::assertSame([
                '"items" item 1: "label" is required.',
                '"items" item 2 is not an object.',
            ], $exception->problems);
        }
    }

    public function testZeroIsNoPictureYetUnlessThePictureIsRequired(): void
    {
        $optional = new PropSchema([Prop::image('picture', 'Picture')]);
        $required = new PropSchema([Prop::image('picture', 'Picture', required: true)]);

        self::assertNull($optional->normalize(['picture' => 0])['picture']);
        self::assertNull($optional->normalize(['picture' => '0'])['picture']);

        $this->expectException(InvalidDocumentException::class);
        $this->expectExceptionMessage('"picture" is required.');

        $required->normalize(['picture' => 0]);
    }

    public function testARequiredPropLeftEmptyIsRefused(): void
    {
        $this->expectException(InvalidDocumentException::class);
        $this->expectExceptionMessage('"title" is required.');

        $this->schema->normalize(['title' => '', 'items' => [['label' => 'One', 'image' => 7]]]);
    }

    #[DataProvider('addresses')]
    public function testOnlyAddressesAPageMaySendAVisitorToAreAccepted(string $url, bool $accepted): void
    {
        $schema = new PropSchema([Prop::url('link', 'Link')]);

        if (!$accepted) {
            $this->expectException(InvalidDocumentException::class);
        }

        self::assertSame($url, $schema->normalize(['link' => $url])['link']);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function addresses(): iterable
    {
        yield 'a path of the site' => ['/nos-services', true];
        yield 'an anchor' => ['#contact', true];
        yield 'an absolute address' => ['https://example.test/', true];
        yield 'a mail link' => ['mailto:hello@example.test', true];
        yield 'a phone link' => ['tel:+33102030405', true];
        yield 'a script' => ['javascript:alert(1)', false];
        yield 'a protocol-relative address' => ['//evil.test/', false];
        yield 'a bare word' => ['contact', false];
    }

    public function testTheJsonSchemaDescribesEveryPropForAGenerator(): void
    {
        $schema = $this->schema->toJsonSchema();

        self::assertSame('object', $schema['type']);
        self::assertSame(['title', 'items'], $schema['required']);
        self::assertFalse($schema['additionalProperties']);

        $properties = (array) $schema['properties'];

        self::assertSame(['type' => 'string', 'maxLength' => 20], array_diff_key($properties['title'], ['description' => true]));
        self::assertSame(['light', 'dark'], $properties['tone']['enum']);
        self::assertSame(['1', '2'], ((array) (new PropSchema([Prop::choice('level', 'Level', ['1' => 'h1', '2' => 'h2'], '1')]))->toJsonSchema()['properties'])['level']['enum'], 'A numeric choice stays a string in the schema.');
        self::assertSame(['integer', 'string', 'null'], $properties['picture']['type'], 'An id of the library, or an https address.');
        self::assertSame(1, $properties['items']['minItems']);
        self::assertSame(['label', 'image'], $properties['items']['items']['required']);
        self::assertSame(['integer', 'string'], ((array) $properties['items']['items']['properties'])['image']['type']);
    }
}
