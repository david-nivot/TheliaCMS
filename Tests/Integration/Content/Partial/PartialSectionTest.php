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

namespace TheliaCMS\Tests\Integration\Content\Partial;

use Symfony\Contracts\Translation\TranslatorInterface;
use TheliaCMS\Content\Document;
use TheliaCMS\Content\InvalidDocumentException;
use TheliaCMS\Content\Partial\PartialSection;
use TheliaCMS\Content\PropType;
use TheliaCMS\Content\Section;
use TheliaCMS\Content\SectionCatalog;
use TheliaCMS\Content\Theme\ThemeComponentSections;
use TheliaCMS\Model\CmsPageContentQuery;
use TheliaCMS\Partial\PartialProp;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;
use TheliaCMS\Tests\Unit\Partial\FakePartial;

/**
 * The partials of the site reach a document as sections: the page stores the
 * name and the settings, and the placeholder they render is what the partial
 * renderer substitutes when the page is served.
 */
final class PartialSectionTest extends CmsIntegrationTestCase
{
    public function testEveryPartialOfTheSiteIsASectionOfTheCatalogue(): void
    {
        $names = $this->getService(SectionCatalog::class)->names();

        self::assertContains('Partial:cms-form', $names);
        // Unless the theme puts it away for a component of its own.
        $hidden = $this->getService(ThemeComponentSections::class)->hidden();
        self::assertSame(\in_array('Partial:cms-form', $hidden, true), !\in_array('Partial:cms-form', $names, true));
    }

    public function testThePropsOfAPartialBecomeTheSchemaOfTheSection(): void
    {
        $section = new PartialSection(new FakePartial('news', [
            PartialProp::integer('count', 'How many', default: 3, min: 1, max: 12),
            PartialProp::reference('folder', 'Folder', source: '/admin/cms/partials/sources/folders', required: false),
            PartialProp::text('heading', 'Heading', required: false),
        ]), $this->getService(TranslatorInterface::class));

        $props = [];

        foreach ($section->schema()->props as $prop) {
            $props[$prop->name] = $prop;
        }

        self::assertSame(PropType::Integer, $props['count']->type);
        self::assertSame(3, $props['count']->default);
        self::assertSame(PropType::Reference, $props['folder']->type);
        self::assertFalse($props['folder']->required);
        self::assertSame(PropType::Text, $props['heading']->type);
        self::assertStringContainsString('Live content', $section->description());
    }

    public function testASectionRendersThePlaceholderThePageServesThrough(): void
    {
        $section = $this->getService(SectionCatalog::class)->find('Partial:cms-form');

        self::assertNotNull($section);

        $html = $section->render($section->normalizeProps(['form' => 7]), $this->locale());

        self::assertSame('<div data-cms-partial="cms-form" data-props="{&quot;form&quot;:7,&quot;title_level&quot;:&quot;h2&quot;,&quot;title_style&quot;:&quot;level&quot;}"></div>', $html, 'The level and the look of the heading come with their defaults.');
    }

    public function testAPartialNeedsItsRequiredReference(): void
    {
        $section = $this->getService(SectionCatalog::class)->find('Partial:cms-form');

        self::assertNotNull($section);
        $this->expectException(InvalidDocumentException::class);
        $this->expectExceptionMessage('"form" is required.');

        $section->normalizeProps([]);
    }

    public function testAPageHoldingAPartialSectionIsPublishedWithThePlaceholderAndServedResolved(): void
    {
        $page = $this->createPage('Page avec bloc dynamique', published: false);
        $this->writer()->saveContent($page, $this->locale(), new Document([
            new Section('intro', 'cms:richtext', ['html' => '<h1>Formulaire</h1>']),
            new Section('form', 'Partial:cms-form', ['form' => 1]),
        ]));
        $this->writer()->publish($page, $this->locale());

        $content = CmsPageContentQuery::create()->filterByPageId($page->getId())->filterByLocale($this->locale())->findOne();

        self::assertNotNull($content);
        self::assertStringContainsString('data-cms-partial="cms-form"', (string) $content->getPublishedHtml(), 'The placeholder survives the sanitizer and is what the page stores.');
        // The sanitizer rewrites the attribute with the quotes it likes; the
        // settings inside are what matters.
        self::assertStringContainsString('{"form":1,"title_level":"h2","title_style":"level"}', html_entity_decode((string) $content->getPublishedHtml(), \ENT_QUOTES));
    }
}
