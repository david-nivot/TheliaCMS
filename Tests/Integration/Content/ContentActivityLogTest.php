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

use Thelia\Core\Security\AccessManager;
use Thelia\Model\AdminLogQuery;
use Thelia\Model\Content;
use Thelia\Test\FixtureFactory;
use TheliaCMS\Security\CmsResources;
use TheliaCMS\Tests\Integration\Security\AdminScreenTestCase;

/**
 * What the system log of the back office says about the content of a page
 * and of a catalogue item: the resource an entry is filed under, its
 * resource id and its message. Both families file their entries under the
 * resource of the pages, an item with its own id, so the system log is read
 * the same way; the messages are the ones a person searches for.
 */
final class ContentActivityLogTest extends AdminScreenTestCase
{
    public function testThePageEntriesOfTheContentScreen(): void
    {
        $page = $this->createPage('Logged page', published: false);
        $id = (int) $page->getId();
        $base = \sprintf('/admin/cms/pages/%d/content', $id);
        $this->forgetTheLog();

        $this->postAddedSection($base);
        $this->post($base.'/publish', $base);
        $this->post(\sprintf('/admin/cms/pages/%d/unpublish', $id), $base);

        self::assertSame([
            ['UPDATE', 'admin.cms.page', $id, \sprintf('CMS page #%d content edited in %s', $id, $this->locale())],
            ['PUBLISH', 'admin.cms.page', $id, \sprintf('CMS page #%d published in %s (custom code allowed)', $id, $this->locale())],
            ['UNPUBLISH', 'admin.cms.page', $id, \sprintf('CMS page #%d unpublished in %s', $id, $this->locale())],
        ], $this->entries($id));
    }

    public function testTheItemEntriesOfTheContentScreen(): void
    {
        $content = $this->content();
        $id = (int) $content->getId();
        $base = \sprintf('/admin/cms/catalogue/content/%d/content', $id);
        $this->forgetTheLog();

        $this->postAddedSection($base);
        $this->post($base.'/publish', $base);
        $this->post($base.'/unpublish', $base);

        self::assertSame([
            ['UPDATE', 'admin.cms.page', $id, \sprintf('CMS content of the content #%d edited in %s', $id, $this->locale())],
            ['PUBLISH', 'admin.cms.page', $id, \sprintf('CMS content of the content #%d published in %s (custom code allowed)', $id, $this->locale())],
            ['UNPUBLISH', 'admin.cms.page', $id, \sprintf('CMS content of the content #%d unpublished in %s', $id, $this->locale())],
        ], $this->entries($id));
    }

    public function testTheCustomCodeIsTracedOnlyWhenTheAdminMayPublishIt(): void
    {
        $page = $this->createPage('Logged without custom code', published: false);
        $pageId = (int) $page->getId();
        $content = $this->content();
        $contentId = (int) $content->getId();
        $this->forgetTheLog();

        $this->logInAs((new FixtureFactory($this->getPropelConnection()))->restrictedAdmin([
            CmsResources::PAGE => [AccessManager::VIEW, AccessManager::UPDATE],
        ]));

        $pageBase = \sprintf('/admin/cms/pages/%d/content', $pageId);
        $itemBase = \sprintf('/admin/cms/catalogue/content/%d/content', $contentId);
        $this->postAddedSection($itemBase);
        $this->post($pageBase.'/publish', $pageBase);
        $this->post($itemBase.'/publish', $itemBase);

        self::assertSame(\sprintf('CMS page #%d published in %s', $pageId, $this->locale()), $this->entries($pageId, 'CMS page #')[0][3]);
        self::assertSame(
            [
                ['UPDATE', 'admin.cms.page', $contentId, \sprintf('CMS content of the content #%d edited in %s', $contentId, $this->locale())],
                ['PUBLISH', 'admin.cms.page', $contentId, \sprintf('CMS content of the content #%d published in %s', $contentId, $this->locale())],
            ],
            $this->entries($contentId, 'CMS content of the content #'),
        );
    }

    private function content(): Content
    {
        $factory = new FixtureFactory($this->getPropelConnection());

        return $factory->content($factory->folder(), ['title' => 'Logged item', 'locale' => $this->locale()]);
    }

    /**
     * The fixtures are written outside a request or inside the one of the
     * test client, according to the case: the entries of the test start here.
     */
    private function forgetTheLog(): void
    {
        AdminLogQuery::create()->filterByResource(CmsResources::PAGE)->delete();
    }

    private function postAddedSection(string $base): void
    {
        $form = $this->screen($base)->filter('[data-testid="cms-content-add-cms:richtext"]')->form();

        self::assertSame(302, $this->send('POST', $form->getUri(), $form->getPhpValues()));
    }

    private function post(string $url, string $screen): void
    {
        $token = $this->tokenOfTheScripts($this->screen($screen));

        self::assertSame(302, $this->send('POST', $url, ['_token' => $token]));
    }

    /**
     * @return list<array{string, string, int, string}> action, resource, resource id, message
     */
    private function entries(int $resourceId, string $about = ''): array
    {
        $entries = [];

        // A page and a catalogue item share the resource of the log: when they
        // get the same id, only the start of the message tells them apart.
        foreach (AdminLogQuery::create()->filterByResource(CmsResources::PAGE)->filterByResourceId($resourceId)->orderById()->find() as $entry) {
            if (!str_starts_with((string) $entry->getMessage(), $about)) {
                continue;
            }

            $entries[] = [(string) $entry->getAction(), (string) $entry->getResource(), (int) $entry->getResourceId(), (string) $entry->getMessage()];
        }

        return $entries;
    }
}
