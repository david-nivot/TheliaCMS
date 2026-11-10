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

namespace TheliaCMS\Tests\Integration\Generation;

use Symfony\Component\DomCrawler\Crawler;
use Thelia\Model\Lang;
use TheliaCMS\Content\Document;
use TheliaCMS\Content\Section;
use TheliaCMS\Content\Site\SiteComponent;
use TheliaCMS\Content\Site\SiteComponentRepository;
use TheliaCMS\Content\Site\SiteComponentValidator;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Model\CmsPageContentQuery;
use TheliaCMS\Page\Admin\CmsPageWriter;
use TheliaCMS\Tests\Integration\Security\AdminScreenTestCase;

/**
 * The address of a video is a text field of the section editor: what is typed
 * is kept as a platform and an identifier, the field shows the address of the
 * page built from them, and an address that names no video is refused
 * without being printed back.
 */
final class SectionEmbedEditorTest extends AdminScreenTestCase
{
    private const string FORM = 'cms_section_band';

    protected function setUp(): void
    {
        parent::setUp();

        $this->getService(SiteComponentRepository::class)->save((new SiteComponentValidator())->validate(new SiteComponent(
            'VideoBand',
            'Video band',
            'A video and a list of videos.',
            [
                ['name' => 'video', 'type' => 'embed', 'label' => 'Presentation video'],
                ['name' => 'gallery', 'type' => 'items', 'label' => 'Gallery', 'min' => 1, 'max' => 4, 'items' => [['name' => 'clip', 'type' => 'embed', 'label' => 'Clip']]],
            ],
            ['video' => 'youtube:dQw4w9WgXcQ', 'gallery' => [['clip' => '']]],
            '<div class="band">{{ props.video }}{% for row in props.gallery %}{{ row.clip }}{% endfor %}</div>',
        )), SiteComponentRepository::STATUS_READY);
    }

    public function testTheFieldIsATextFieldThatSaysWhatItTakes(): void
    {
        $dialog = $this->dialog($this->pageWith('youtube:dQw4w9WgXcQ'));
        $field = $dialog->filter('input[name="'.self::FORM.'[video]"]');

        self::assertSame(1, $field->count());
        self::assertSame('text', $field->attr('type'));
        self::assertSame('url', $field->attr('inputmode'));
        self::assertSame('https://www.youtube.com/watch?v=dQw4w9WgXcQ', $field->attr('value'), 'Filled back from what was kept.');
        self::assertStringContainsString('YouTube, Vimeo or Dailymotion', $dialog->text());
        self::assertSame(1, $dialog->filter('input[name^="'.self::FORM.'[gallery]"][name$="[clip]"]')->count(), 'A video of a row is the same field.');
    }

    public function testAnAddressIsKeptAsAPlatformAndAnIdentifierAndDrawnAsAFacade(): void
    {
        $page = $this->pageWith('');

        self::assertSame('vimeo:76979871', $this->save($page, 'https://vimeo.com/76979871?share=copy'));

        $html = (string) $this->content($page)?->getDraftHtml();

        self::assertStringContainsString('data-cms-embed-platform="vimeo"', $html);
        self::assertStringContainsString('data-cms-embed-id="76979871"', $html);
        self::assertStringContainsString('aria-label="Play the video: Presentation video (Vimeo)"', $html);
        self::assertStringNotContainsString('<iframe', $html);
        self::assertStringNotContainsString('share=copy', $html);
    }

    public function testAnAddressThatIsNoVideoIsRefusedAndNeverPrintedBack(): void
    {
        $page = $this->pageWith('youtube:dQw4w9WgXcQ');
        $typed = 'https://youtube.com.evil.tld/watch?v=dQw4w9WgXcQ';
        $form = $this->dialog($page)->filter('form[data-cms-section-form]')->form();
        $values = $form->getPhpValues();
        $values[self::FORM]['video'] = $typed;

        $this->client->request('POST', $form->getUri(), $values, [], self::SAME_SITE);
        $this->client->followRedirect();

        self::assertSame('youtube:dQw4w9WgXcQ', $this->draftProps($page)['video'], 'The video kept before is still there.');
        self::assertStringNotContainsString('evil.tld', (string) $this->client->getResponse()->getContent());
    }

    private function save(CmsPage $page, string $address): mixed
    {
        $form = $this->dialog($page)->filter('form[data-cms-section-form]')->form();
        $values = $form->getPhpValues();
        $values[self::FORM]['video'] = $address;

        self::assertSame(302, $this->send('POST', $form->getUri(), $values));

        return $this->draftProps($page)['video'] ?? null;
    }

    private function content(CmsPage $page): ?object
    {
        return CmsPageContentQuery::create()->filterByPageId($page->getId())->filterByLocale($this->locale())->findOne();
    }

    /**
     * @return array<string, mixed>
     */
    private function draftProps(CmsPage $page): array
    {
        return Document::fromJson($this->content($page)?->getDraftDocument())?->section('band')->props ?? [];
    }

    private function dialog(CmsPage $page): Crawler
    {
        return $this->screen(\sprintf('/admin/cms/pages/%d/content?edit_language_id=%d', $page->getId(), Lang::getDefaultLanguage()->getId()))
            ->filter('[data-testid="cms-section-edit-band"]');
    }

    private function pageWith(string $video): CmsPage
    {
        $page = $this->createPage('Video band', published: false);
        $this->client->request('GET', '/admin/cms/pages');
        $this->getService(CmsPageWriter::class)->saveContent($page, $this->locale(), new Document([
            new Section('band', 'Site:VideoBand', ['video' => $video, 'gallery' => [['clip' => '']]]),
        ]));

        return $page;
    }
}
