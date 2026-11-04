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
use TheliaCMS\Content\Document;
use TheliaCMS\Content\DocumentRenderer;
use TheliaCMS\Content\RichTextSection;
use TheliaCMS\Content\Section;
use TheliaCMS\Content\SectionAppearance;
use TheliaCMS\Content\SectionCatalog;
use TheliaCMS\Content\UnknownSectionException;

final class DocumentRendererTest extends TestCase
{
    private DocumentRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new DocumentRenderer(new SectionCatalog([new RichTextSection(), new FakeSection()]));
    }

    public function testSectionsAreRenderedInOrderInsideTheContainer(): void
    {
        $document = new Document([
            new Section('title', FakeSection::NAME, ['title' => 'Nos services', 'level' => 1]),
            new Section('intro', RichTextSection::NAME, ['html' => '<p>Texte</p>']),
        ]);

        self::assertSame(
            '<div class="cms-page-content"><h1 data-cms-section="title" lang="fr_FR">Nos services</h1>'."\n".'<div data-cms-section="intro" class="cms-richtext"><p>Texte</p></div></div>',
            $this->renderer->render($document, 'fr_FR'),
            'The first element of every section is told which section it renders, so a screen can point at it.',
        );
    }

    public function testASectionWithAnAppearanceIsWrappedInABandCarryingIt(): void
    {
        $document = new Document([
            new Section('intro', RichTextSection::NAME, ['html' => '<p>Texte</p>'], new SectionAppearance(background: '#f8d7d7', padding: 'medium')),
        ]);

        self::assertSame(
            '<div class="cms-page-content"><div class="cms-section-styled" data-cms-section="intro" style="background-color:#f8d7d7;padding-block:3rem"><div class="cms-richtext"><p>Texte</p></div></div></div>',
            $this->renderer->render($document, 'fr_FR'),
            'The band is the section for the content screen, and the component inside it is left as it draws itself.',
        );
    }

    public function testThePreciseRoomOfASectionIsWrittenInTheBandAndNothingElseChanges(): void
    {
        $document = new Document([
            new Section('intro', RichTextSection::NAME, ['html' => '<p>Texte</p>'], new SectionAppearance(background: '#f8d7d7', padding_top: '0px', padding_bottom: '64px', margin_top: '2rem')),
        ]);

        self::assertSame(
            '<div class="cms-page-content"><div class="cms-section-styled" data-cms-section="intro" style="background-color:#f8d7d7;padding-top:0px;padding-bottom:64px;margin-top:2rem"><div class="cms-richtext"><p>Texte</p></div></div></div>',
            $this->renderer->render($document, 'fr_FR'),
        );
    }

    public function testAFragmentWithoutAnElementIsLeftAsItIs(): void
    {
        $document = new Document([new Section('blank', RichTextSection::NAME, ['html' => '   '])]);

        self::assertSame('<div class="cms-page-content"></div>', $this->renderer->render($document, 'fr_FR'), 'An empty section has nothing to carry the anchor.');
    }

    public function testAnEmptyDocumentRendersAnEmptyContainer(): void
    {
        self::assertSame('<div class="cms-page-content"></div>', $this->renderer->render(new Document([]), 'fr_FR'));
    }

    public function testASectionWhoseComponentIsGoneIsAnErrorNotAHole(): void
    {
        $this->expectException(UnknownSectionException::class);

        $this->renderer->render(new Document([new Section('a', 'Gone:Component', [])]), 'fr_FR');
    }
}
