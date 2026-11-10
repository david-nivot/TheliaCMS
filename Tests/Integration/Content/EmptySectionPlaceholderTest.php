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

namespace TheliaCMS\Tests\Integration\Content;

use Thelia\Model\Lang;
use TheliaCMS\Content\Document;
use TheliaCMS\Content\ImageSection;
use TheliaCMS\Content\Section;
use TheliaCMS\Model\CmsPageContentQuery;
use TheliaCMS\Page\Admin\CmsPageWriter;
use TheliaCMS\Tests\Integration\Security\AdminScreenTestCase;

/**
 * A section that draws nothing, such as an image without its picture, gets a
 * placeholder in the preview frame only: the screen hands the script the
 * sentences, and the stored draft stays what goes online.
 */
final class EmptySectionPlaceholderTest extends AdminScreenTestCase
{
    public function testTheScreenHandsTheScriptTheSentencesOfThePlaceholder(): void
    {
        $page = $this->createPage('Image without picture', published: false);
        $this->client->request('GET', '/admin/cms/pages');
        $this->getService(CmsPageWriter::class)->saveContent($page, $this->locale(), new Document([
            new Section('pic', ImageSection::NAME, ['image' => 0, 'alt' => '', 'width' => 'narrow']),
        ]));

        $screen = $this->screen(\sprintf('/admin/cms/pages/%d/content?edit_language_id=%d', $page->getId(), Lang::getDefaultLanguage()->getId()));
        $labels = json_decode((string) $screen->filter('[data-cms-content]')->attr('data-cms-content-labels'), true, flags: \JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('emptyImage', $labels);
        self::assertArrayHasKey('emptySection', $labels);
        self::assertNotSame('', $labels['emptyImage']);
        self::assertNotSame('', $labels['emptySection']);
        self::assertSame(1, $screen->filter('[data-cms-section-item][data-section="pic"]')->count(), 'The section is in the list.');

        $content = CmsPageContentQuery::create()->filterByPageId($page->getId())->filterByLocale($this->locale())->findOne();
        self::assertNotNull($content);
        self::assertStringNotContainsString('data-cms-placeholder', (string) $content->getDraftHtml());
        self::assertStringNotContainsString('cms-section-placeholder', (string) $content->getDraftHtml());
        self::assertStringNotContainsString($labels['emptyImage'], (string) $content->getDraftHtml(), 'The stored draft holds no placeholder.');
    }
}
