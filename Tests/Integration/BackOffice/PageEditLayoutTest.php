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

namespace TheliaCMS\Tests\Integration\BackOffice;

use Symfony\Component\DomCrawler\Crawler;
use Thelia\Model\LangQuery;
use TheliaCMS\Tests\Integration\Security\AdminScreenTestCase;

/**
 * Where the screen of a page puts what concerns the whole page, and the
 * height of its summary.
 */
final class PageEditLayoutTest extends AdminScreenTestCase
{
    public function testTheActionsOnTheWholePageSitInItsHeaderNotUnderTheTabs(): void
    {
        $screen = $this->screen($this->edit((int) $this->createPage('Actions de la page')->getId()));

        foreach (['cms-page-duplicate', 'cms-page-set-as-home'] as $action) {
            self::assertSame(1, $screen->filter(\sprintf('.bo-page-header__actions [data-testid="%s"]', $action))->count(), $action.' is in the header.');
            self::assertSame(0, $screen->filter(\sprintf('.tab-content ~ * [data-testid="%s"]', $action))->count(), $action.' no longer follows the tabs.');
        }
    }

    public function testTheSummaryIsMarkedToBeHalfTheHeightOfTheDescription(): void
    {
        $screen = $this->screen($this->edit((int) $this->createPage('Résumé court')->getId()));

        $summary = $screen->filter('[data-cms-summary] textarea[name$="[chapo]"]');
        self::assertSame(1, $summary->count(), 'The row of the summary carries the mark the style reads.');
        self::assertSame('4', $summary->attr('rows'), 'Without the editor, half the rows of the description.');
        self::assertSame('8', $screen->filter('textarea[name$="[description]"]')->attr('rows'));
        $styles = implode("\n", $screen->filter('style')->each(static fn (Crawler $style): string => $style->text('')));
        self::assertStringContainsString('[data-cms-summary] .tiptap-content { min-height: 160px !important; }', $styles);
    }

    private function edit(int $id): string
    {
        return \sprintf('/admin/cms/pages/%d?edit_language_id=%d', $id, (int) LangQuery::create()->findOneByLocale($this->locale())?->getId());
    }
}
