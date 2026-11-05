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

namespace TheliaCMS\Tests\Integration\Page;

use Propel\Runtime\ActiveQuery\Criteria;
use Symfony\Component\DomCrawler\Crawler;
use Thelia\Model\Lang;
use Thelia\Model\LangQuery;
use TheliaCMS\Page\Admin\CmsPageWriter;
use TheliaCMS\Page\Admin\PageDraft;
use TheliaCMS\Tests\Integration\Security\AdminScreenTestCase;

/**
 * The list of pages in a language the page is not written in yet says so on
 * the row, and stops saying it once the page has a name there.
 */
final class PageListTranslationTest extends AdminScreenTestCase
{
    public function testAPageNotNamedInTheLanguageOfTheListIsToBeTranslated(): void
    {
        $page = $this->createPage('Nos services', published: false);
        $other = LangQuery::create()->filterByActive(true)->filterById(Lang::getDefaultLanguage()->getId(), Criteria::NOT_EQUAL)->orderById()->findOne();
        self::assertNotNull($other, 'The shop of the tests has a second active language.');

        $row = $this->row($page->getId(), $other);
        self::assertStringContainsString('To be translated', $row->text(), 'The name on the row comes from another language, and the row says so.');

        self::assertStringNotContainsString('To be translated', $this->row($page->getId(), Lang::getDefaultLanguage())->text(), 'In its own language the page is not to be translated.');

        $this->getService(CmsPageWriter::class)->saveDraft($page, $other->getLocale(), new PageDraft(title: 'Our services'));

        self::assertStringNotContainsString('To be translated', $this->row($page->getId(), $other)->text(), 'Named in that language, it is no longer to be translated.');
    }

    private function row(int $pageId, Lang $lang): Crawler
    {
        $row = $this->screen(\sprintf('/admin/cms/pages?edit_language_id=%d', $lang->getId()))->filter(\sprintf('[data-testid="cms-page-row-%d"]', $pageId));
        self::assertSame(1, $row->count(), 'The page is on the list.');

        return $row;
    }
}
