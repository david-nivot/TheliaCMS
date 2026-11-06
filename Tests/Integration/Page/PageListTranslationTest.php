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

        $label = $this->row($page->getId(), $other)->filter(\sprintf('[data-testid="cms-page-untranslated-%d"]', $page->getId()));
        self::assertSame(1, $label->count(), 'The name on the row comes from another language, and the row says so.');
        self::assertSame('Settings to translate', trim($label->text()), 'It names what is missing: the settings, where the title is written.');
        self::assertSame(\sprintf('/admin/cms/pages/%d?edit_language_id=%d', $page->getId(), $other->getId()), (string) $label->attr('href'), 'It leads to the settings of the page, in the language of the list.');

        self::assertSame(0, $this->untranslatedLabels($page->getId(), Lang::getDefaultLanguage()), 'In its own language the page is not to be translated.');

        $this->getService(CmsPageWriter::class)->saveDraft($page, $other->getLocale(), new PageDraft(title: 'Our services'));

        self::assertSame(0, $this->untranslatedLabels($page->getId(), $other), 'Named in that language, it is no longer to be translated.');
    }

    private function untranslatedLabels(int $pageId, Lang $lang): int
    {
        return $this->row($pageId, $lang)->filter(\sprintf('[data-testid="cms-page-untranslated-%d"]', $pageId))->count();
    }

    private function row(int $pageId, Lang $lang): Crawler
    {
        $row = $this->screen(\sprintf('/admin/cms/pages?edit_language_id=%d', $lang->getId()))->filter(\sprintf('[data-testid="cms-page-row-%d"]', $pageId));
        self::assertSame(1, $row->count(), 'The page is on the list.');

        return $row;
    }
}
