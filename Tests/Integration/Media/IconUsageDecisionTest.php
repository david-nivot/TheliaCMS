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

namespace TheliaCMS\Tests\Integration\Media;

use Symfony\Contracts\Translation\TranslatorInterface;
use TheliaCMS\Media\Admin\CmsMediaWriter;
use TheliaCMS\Media\CmsMediaLibrary;
use TheliaCMS\Media\Icon\IconUsageDecision;
use TheliaCMS\Media\Svg\Color\SvgColoring;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;
use TheliaCMS\TheliaCMS;
use TheliaLibrary\TheliaLibrary;

/**
 * The decision on the usage of an SVG of the preparation screen: where it is
 * offered, what it makes of the colors, what stops an answer and what the
 * screen draws of it.
 */
final class IconUsageDecisionTest extends CmsIntegrationTestCase
{
    private const string DARK = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M0 0h24v24H0z" fill="#1C1C1C"/></svg>';

    private const string GRADIENT = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><defs><linearGradient id="g"><stop offset="0" stop-color="#000"/><stop offset="1" stop-color="#fff"/></linearGradient></defs><path d="M0 0h24v24H0z" fill="url(#g)"/></svg>';

    protected function tearDown(): void
    {
        foreach ($this->getService(CmsMediaLibrary::class)->images() as $image) {
            $file = TheliaLibrary::getImageDirectory().$image->getFileName();

            if (is_file($file)) {
                unlink($file);
            }
        }

        parent::tearDown();
    }

    public function testTheUsageIsOfferedOnlyForAFileAddedToTheLibrary(): void
    {
        self::assertTrue(IconUsageDecision::isOffered(null, false));
        self::assertFalse(IconUsageDecision::isOffered(12, false), 'A file that replaces an image.');
        self::assertFalse(IconUsageDecision::isOffered(null, true), 'A file chosen from the picker.');
        self::assertTrue(IconUsageDecision::isAsked(null, false, true));
        self::assertFalse(IconUsageDecision::isAsked(null, false, false));
        self::assertFalse(IconUsageDecision::isAsked(12, false, true));
    }

    public function testAnIconTakesTheMainColorAsTheColorOfTheTextUntilTheEditorSetsTheColors(): void
    {
        $decision = $this->decision();

        $icon = $decision->prepare(self::DARK, null, true, new SvgColoring(), false);
        $image = $decision->prepare(self::DARK, null, false, new SvgColoring(), false);

        self::assertStringContainsString('currentColor', $icon->result->content);
        self::assertStringNotContainsString('currentColor', $icon->judged, 'Judged as it is before the preset.');
        self::assertStringContainsString('1C1C1C', strtoupper($image->result->content), 'An image keeps its colors.');
        self::assertSame($image->result->content, $image->judged);
    }

    public function testADrawingThatCannotBeAnIconIsNeverGivenTheColorPreset(): void
    {
        $draft = $this->decision()->prepare(self::GRADIENT, null, true, new SvgColoring(), false);

        self::assertSame($draft->result->content, $draft->judged);
    }

    public function testAnAnswerThatMakesAnIconIsStoppedByWhatAPageWouldNotKeepOrByTheName(): void
    {
        $decision = $this->decision();
        $this->getService(CmsMediaWriter::class)->addPreparedSvg(self::DARK, 'usage-held.svg', $this->locale(), 'usage-held');

        self::assertNull($decision->problem(self::GRADIENT, false, '', null), 'An image is not judged.');
        self::assertSame($this->said('This file cannot be added as an icon: see below what a page would not keep. It can still be added as an image.'), $decision->problem(self::GRADIENT, true, 'usage-fit', null));
        self::assertSame($this->said('The icon name is made of lowercase letters and digits, separated by single hyphens, %d characters at most.', 64), $decision->problem(self::DARK, true, 'Not Valid', null));
        self::assertSame($this->said('The icon name "%s" is already used by another image.', 'cms:usage-held'), $decision->problem(self::DARK, true, 'usage-held', null));
        self::assertNull($decision->problem(self::DARK, true, 'usage-free', null));
    }

    public function testTheScreenOfAFileThatCannotBeAnIconSaysWhatStandsInTheWay(): void
    {
        $variables = $this->decision()->variables('plan.svg', null, false, self::GRADIENT, true, null, null);

        self::assertTrue($variables['icon_offered']);
        self::assertFalse($variables['icon_chosen'], 'Held to what the drawing allows.');
        self::assertNotSame([], $variables['icon_obstacles']);
    }

    public function testTheScreenProposesTheNameOfTheFileAndRaisesATakenNameAtOnce(): void
    {
        $decision = $this->decision();
        $this->getService(CmsMediaWriter::class)->addPreparedSvg(self::DARK, 'usage-plan.svg', $this->locale(), 'usage-plan');

        $free = $decision->variables('Usage Free.svg', null, false, self::DARK, true, null, null);
        $taken = $decision->variables('usage-plan.svg', null, false, self::DARK, true, null, null);

        self::assertSame('usage-free', $free['icon_name']);
        self::assertNull($free['icon_error']);
        self::assertSame($this->said('The icon name "%s" is already used by another image.', 'cms:usage-plan'), $taken['icon_error']);
    }

    public function testTheScreenOfAReplacementDrawsTheIconItReplacesAndWhoCitesIt(): void
    {
        $icon = $this->getService(CmsMediaWriter::class)->addPreparedSvg(self::DARK, 'usage-replaced.svg', $this->locale(), 'usage-replaced');

        $variables = $this->decision()->variables('new.svg', (int) $icon->getId(), false, self::DARK, false, null, null);
        $plain = $this->decision()->variables('new.svg', 999_999_999, false, self::DARK, false, null, 'why');

        self::assertFalse($variables['icon_offered']);
        self::assertSame('cms:usage-replaced', $variables['icon_replaced']);
        self::assertSame(0, $variables['icon_citation']->pages);
        self::assertNull($plain['icon_replaced']);
        self::assertSame('why', $plain['icon_error'], 'Nothing is drawn of an image that is no icon, but the reason stays.');
    }

    private function decision(): IconUsageDecision
    {
        return $this->getService(IconUsageDecision::class);
    }

    private function said(string $english, mixed ...$values): string
    {
        return vsprintf($this->getService(TranslatorInterface::class)->trans($english, [], TheliaCMS::DOMAIN_NAME, $this->locale()), $values);
    }
}
