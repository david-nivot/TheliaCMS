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

use TheliaCMS\Model\CmsPageQuery;
use TheliaCMS\Model\Map\CmsPageTableMap;
use TheliaCMS\Tests\Integration\Security\AdminScreenTestCase;

/**
 * The title band is chosen in the settings of a page, on its General tab.
 */
final class TitleBandScreenTest extends AdminScreenTestCase
{
    public function testTheSettingsSaveTheChoiceAndShowItAgain(): void
    {
        $page = $this->createPage('Page à régler', published: false);
        $url = \sprintf('/admin/cms/pages/%d', $page->getId());

        $screen = $this->screen($url);
        self::assertSame('1', $screen->filter('input[name="cms_page[titleBand]"][checked]')->attr('value'), 'A page shows the band until told otherwise.');

        $form = $screen->filter('[data-testid="cms-page-save"]')->form();
        $form['cms_page[titleBand]'] = '0';
        self::assertSame(302, $this->send('POST', $form->getUri(), $form->getPhpValues()));

        CmsPageTableMap::clearInstancePool();
        self::assertSame(0, CmsPageQuery::create()->findPk($page->getId())?->getTitleBand());
        self::assertSame('0', $this->screen($url)->filter('input[name="cms_page[titleBand]"][checked]')->attr('value'));
    }

    public function testANewPageOffersTheBand(): void
    {
        $screen = $this->screen('/admin/cms/pages/new');

        self::assertSame('1', $screen->filter('input[name="cms_page[titleBand]"][checked]')->attr('value'));
    }
}
