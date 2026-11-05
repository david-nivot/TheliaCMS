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
use TheliaCMS\Content\DocumentSchema;
use TheliaCMS\Content\RichTextSection;
use TheliaCMS\Content\SectionCatalog;

/**
 * The full schema documents and validates the catalogue; the strict one is
 * the small envelope a generator can be constrained with.
 */
final class DocumentSchemaTest extends TestCase
{
    private DocumentSchema $schema;

    protected function setUp(): void
    {
        $this->schema = new DocumentSchema(new SectionCatalog([new RichTextSection(), new FakeSection()]));
    }

    public function testTheFullSchemaDescribesOneAlternativePerComponent(): void
    {
        $full = $this->schema->full();

        self::assertSame(['version', 'sections'], $full['required']);
        self::assertSame(1, $full['properties']['version']['const']);

        $alternatives = $full['properties']['sections']['items']['anyOf'];

        self::assertCount(2, $alternatives);
        self::assertSame(RichTextSection::NAME, $alternatives[0]['properties']['component']['const']);
        self::assertSame(FakeSection::NAME, $alternatives[1]['properties']['component']['const']);
        self::assertFalse($alternatives[1]['additionalProperties']);
        self::assertSame(['id', 'component', 'props'], $alternatives[1]['required']);
        self::assertSame(['title'], $alternatives[1]['properties']['props']['required']);
        self::assertSame(1, ((array) $alternatives[1]['properties']['props']['properties'])['level']['minimum'], 'The full schema keeps the ranges.');
    }

    public function testTheStrictSchemaIsTheEnvelopeAlone(): void
    {
        $strict = $this->schema->strict();
        $json = json_encode($strict, \JSON_THROW_ON_ERROR);

        foreach (['minimum', 'maximum', 'maxLength', 'minLength', 'minItems', 'maxItems', 'anyOf', 'null'] as $keyword) {
            self::assertStringNotContainsString('"'.$keyword.'"', $json, $keyword.' has no place in a grammar the API compiles.');
        }

        self::assertIsArray($strict['properties'], 'The gateway of the core reads the properties as an array: an object would make it see none.');

        $section = $strict['properties']['sections']['items'];

        self::assertSame(['id', 'component', 'props'], $section['required']);
        self::assertSame([RichTextSection::NAME, FakeSection::NAME], $section['properties']['component']['enum']);
        self::assertSame('string', $section['properties']['props']['type'], 'The props travel as a JSON string the validator reads back.');
        self::assertSame(['background', 'text', 'padding', 'align'], array_keys($section['properties']['appearance']['properties']), 'The appearance is optional, and so is each of its settings.');
        self::assertSame(['small', 'medium', 'large'], $section['properties']['appearance']['properties']['padding']['enum']);
        self::assertStringNotContainsString('"pattern"', $json, 'A colour is checked when the document is read: the API compiles no pattern.');
        self::assertSame(1, $strict['properties']['version']['const']);
    }

    public function testTheFieldsOfAPageJoinTheEnvelopeOnlyWhenAsked(): void
    {
        self::assertArrayNotHasKey('fields', $this->schema->strict()['properties'], 'A page without fields is asked for the envelope it always was.');

        $strict = $this->schema->strict([], withFields: true);

        self::assertSame('string', $strict['properties']['fields']['type'], 'The fields travel as a JSON string, like the props.');
        self::assertSame(['version', 'sections', 'fields'], $strict['required']);
    }

    public function testWhatThePageLeavesOutIsAnOptionalListOfSentences(): void
    {
        $strict = $this->schema->strict();

        self::assertSame(['type' => 'string'], $strict['properties']['left_out']['items']);
        self::assertNotContains('left_out', $strict['required'], 'A page that holds everything the brief asked says nothing.');
    }

    public function testTheHeaderOfAPageJoinsTheEnvelopeOnlyWhenAskedAndIsThenRequired(): void
    {
        $header = ['title', 'summary', 'description', 'og_title', 'og_description'];

        $without = $this->schema->strict(withFields: true, withSeo: true)['properties'];
        foreach ($header as $property) {
            self::assertArrayNotHasKey($property, $without, 'A page that is not translated is asked for the envelope it always was.');
        }

        $strict = $this->schema->strict(withFields: true, withSeo: true, withHeader: true);

        foreach ($header as $property) {
            self::assertSame('string', $strict['properties'][$property]['type']);
            self::assertContains($property, $strict['required'], 'Asked means required: a model held to a strict schema leaves an optional key out (seen on real translations, 2026-11-05).');
            self::assertStringContainsString('empty string', $strict['properties'][$property]['description'], 'The model is told how to say it has nothing to propose.');
        }

        $validator = new \Thelia\Domain\Ai\Guardrail\AiSchemaValidator();
        $empty = ['version' => 1, 'sections' => [], 'fields' => '{}', 'meta_title' => '', 'meta_description' => '', 'title' => '', 'summary' => '', 'description' => '', 'og_title' => '', 'og_description' => ''];
        self::assertSame([], $validator->validate($strict, $empty), 'An empty string says there is nothing to propose.');
        $withoutTitle = $empty;
        unset($withoutTitle['title']);
        self::assertNotSame([], $validator->validate($strict, $withoutTitle), 'An answer without the title asked for is refused.');

        self::assertArrayHasKey('meta_title', $strict['properties'], 'The search fields stay beside it.');
        self::assertStringContainsString('255', $strict['properties']['title']['description'], 'The model is told the limit it is held to.');
        self::assertSame([], $validator->validate($strict, ['title' => 'Nos tarifs', 'summary' => '<p>Court.</p>'] + $empty), 'The gateway of the core accepts the answer with a header.');
    }

    public function testTheSearchFieldsAreRequiredOnlyWhenAsked(): void
    {
        self::assertArrayNotHasKey('meta_title', $this->schema->strict()['properties'], 'A revision is asked for the envelope alone.');

        $strict = $this->schema->strict(withSeo: true);

        foreach (['meta_title', 'meta_description'] as $property) {
            self::assertContains($property, $strict['required'], 'Asked means required: a model held to a strict schema leaves an optional key out.');
            self::assertStringContainsString('empty string', $strict['properties'][$property]['description']);
        }

        self::assertSame(['version', 'sections', 'meta_title', 'meta_description'], $strict['required']);
    }

    public function testTheGatewayOfTheCoreAcceptsADocumentAndRefusesAnUnknownKey(): void
    {
        $validator = new \Thelia\Domain\Ai\Guardrail\AiSchemaValidator();
        $strict = $this->schema->strict();
        $document = ['version' => 1, 'sections' => [['id' => 'intro', 'component' => RichTextSection::NAME, 'props' => '{}']]];

        self::assertSame([], $validator->validate($strict, $document), 'A document of the envelope passes the check the gateway runs on the answer.');
        self::assertNotSame([], $validator->validate($strict, $document + ['unknown' => true]), 'The envelope is closed: a key it does not name is refused.');
    }
}
