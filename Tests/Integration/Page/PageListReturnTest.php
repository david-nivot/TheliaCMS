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

use Thelia\Model\Lang;
use TheliaCMS\Model\CmsTag;
use TheliaCMS\Page\Admin\CmsPageAdminRepository;
use TheliaCMS\Tests\Integration\Security\AdminScreenTestCase;

/**
 * The screens opened from a filtered list of pages lead back to that list, and
 * the list counts its pages per state.
 */
final class PageListReturnTest extends AdminScreenTestCase
{
    public function testTheSettingsAndTheContentOfARowLeadBackToTheFilteredList(): void
    {
        $tag = (new CmsTag())->setName('Return news');
        $tag->save();
        $page = $this->createPage('Returning page');
        $page->setTagId((int) $tag->getId())->save();
        $filters = ['tag' => (string) $tag->getId(), 'status' => ['published']];

        $row = $this->screen($this->listUrl($filters))->filter(\sprintf('[data-testid="cms-page-row-%d"]', $page->getId()));
        self::assertSame(1, $row->count(), 'The page is on the filtered list.');

        // The settings, opened from the title of the row.
        $settings = $this->screen((string) $row->filter(\sprintf('a[href^="/admin/cms/pages/%d?"]', $page->getId()))->first()->attr('href'));
        $this->assertLeadsTo($filters, (string) $settings->filter('[data-testid="cms-page-back-to-list"]')->attr('href'), 'the settings');

        // The content, opened from the settings, and back to the settings.
        $contentLink = $settings->filter(\sprintf('a[href^="/admin/cms/pages/%d/content"]', $page->getId()));
        self::assertGreaterThan(0, $contentLink->count(), 'The settings link to the content.');
        $content = $this->screen((string) $contentLink->first()->attr('href'));
        $backToSettings = $this->screen((string) $content->filter('[data-testid="cms-content-back"]')->attr('href'));
        $this->assertLeadsTo($filters, (string) $backToSettings->filter('[data-testid="cms-page-back-to-list"]')->attr('href'), 'the settings reached back from the content');
    }

    public function testPublishingFromTheSettingsKeepsTheWayBack(): void
    {
        $page = $this->createPage('Returning page', published: false);
        $filters = ['status' => ['draft']];
        $settings = $this->screen(\sprintf('/admin/cms/pages/%d?%s', $page->getId(), http_build_query([
            'edit_language_id' => Lang::getDefaultLanguage()->getId(),
            'return' => http_build_query($filters),
        ])));

        $form = $settings->filter('#cms-page-publish-form');
        self::assertSame(1, $form->count(), 'The settings offer to publish.');

        $this->send('POST', (string) $form->attr('action'), ['_token' => $this->tokenOfTheForm($settings, \sprintf('/admin/cms/pages/%d/publish', $page->getId()))]);

        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringStartsWith(\sprintf('/admin/cms/pages/%d?', $page->getId()), $location, 'Publishing goes back to the settings.');

        $after = $this->screen($location);
        $this->assertLeadsTo($filters, (string) $after->filter('[data-testid="cms-page-back-to-list"]')->attr('href'), 'the settings after publishing');
    }

    public function testAReturnThatIsNotAListOfFiltersLeadsToThePlainList(): void
    {
        $page = $this->createPage('Returning page');
        $languageId = Lang::getDefaultLanguage()->getId();

        foreach (['https://elsewhere.example/admin', '//elsewhere.example', 'q=x&next=/admin/logout'] as $return) {
            $settings = $this->screen(\sprintf('/admin/cms/pages/%d?%s', $page->getId(), http_build_query(['edit_language_id' => $languageId, 'return' => $return])));

            self::assertSame(
                \sprintf('/admin/cms/pages?edit_language_id=%d', $languageId),
                $settings->filter('[data-testid="cms-page-back-to-list"]')->attr('href'),
                \sprintf('"%s" is not a list of filters, and the way back ignores it.', $return),
            );
            self::assertStringNotContainsString('elsewhere', (string) $settings->filter('#cms-page-publish-form')->attr('action'));
        }
    }

    public function testEachStateSaysHowManyPagesItHolds(): void
    {
        $this->createPage('Counted draft', published: false);
        $counts = $this->getService(CmsPageAdminRepository::class)->statusCounts($this->locale());

        // Counted on the whole site, whatever is ticked: the numbers do not
        // move when a box is.
        foreach ([[], ['status' => ['published']]] as $filters) {
            $screen = $this->screen($this->listUrl($filters));

            foreach ($counts as $status => $count) {
                $label = $screen->filter(\sprintf('[data-testid="cms-pages-status-count-%s"]', $status));
                self::assertSame(1, $label->count(), \sprintf('The box "%s" shows no count.', $status));
                self::assertSame(\sprintf('(%d)', $count), trim($label->text()));
            }
        }

        self::assertGreaterThan(0, $counts['draft']);
    }

    /**
     * @param array<string, string|list<string>> $filters
     */
    private function listUrl(array $filters): string
    {
        return '/admin/cms/pages?'.http_build_query(['edit_language_id' => Lang::getDefaultLanguage()->getId(), ...$filters]);
    }

    /**
     * @param array<string, string|list<string>> $filters
     */
    private function assertLeadsTo(array $filters, string $href, string $from): void
    {
        self::assertSame('/admin/cms/pages', parse_url($href, \PHP_URL_PATH), \sprintf('The back link of %s goes elsewhere than the list.', $from));
        parse_str((string) parse_url($href, \PHP_URL_QUERY), $query);

        foreach ($filters as $name => $value) {
            self::assertSame($value, $query[$name] ?? null, \sprintf('The back link of %s drops "%s".', $from, $name));
        }

        $list = $this->screen($href);
        self::assertGreaterThan(0, $list->filter('[data-testid="cms-pages-active-filters"]')->count(), \sprintf('The list reached from %s is not filtered.', $from));
    }
}
