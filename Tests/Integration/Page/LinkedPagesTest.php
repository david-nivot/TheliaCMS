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

use Symfony\Component\DomCrawler\Crawler;
use Thelia\Core\Security\AccessManager;
use Thelia\Test\FixtureFactory;
use TheliaCMS\Catalogue\CatalogueItemType;
use TheliaCMS\Content\Admin\SectionUsageFinder;
use TheliaCMS\Content\Document;
use TheliaCMS\Content\Editing\ContentWriter;
use TheliaCMS\Content\ImageSection;
use TheliaCMS\Content\Section;
use TheliaCMS\Media\CmsMediaLibrary;
use TheliaCMS\Model\CmsItemContentQuery;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Model\CmsPageContentQuery;
use TheliaCMS\Model\CmsTag;
use TheliaCMS\Page\Admin\CmsPageWriter;
use TheliaCMS\Page\Admin\LinkedPagesFinder;
use TheliaCMS\Security\CmsResources;
use TheliaCMS\Tests\Integration\Security\AdminScreenTestCase;
use TheliaLibrary\Model\LibraryImage;

/**
 * The drawer of the pages behind a number: the route that serves it, and the
 * numbers of the screens that open it. Each test reads the number on the
 * screen, follows the address its button carries, and compares what comes back.
 *
 * The component a test hands to pages is the image section, written into the
 * stored draft as it is (the finders read the JSON). Their titles start with
 * "000", so they come first in a list cut to 25 rows whatever else the shop holds;
 * the numbers are compared with the figure of the screen, not with a constant,
 * except for what the test adds.
 */
final class LinkedPagesTest extends AdminScreenTestCase
{
    private const string PROBE = ImageSection::NAME;
    private const int PRODUCT_ID = 765431;

    /**
     * Sabotage: in SectionUsageFinder::usersByComponent(), drop the
     * `filterByDeletedAt(null, ISNULL)` of the pages: the bin joins the count and
     * the list (3 becomes 4), and the test goes red on the expected total.
     */
    public function testAComponentListsThePagesAndTheItemsItsNumberCounts(): void
    {
        $before = $this->getService(SectionUsageFinder::class)->pageCounts()[self::PROBE] ?? 0;
        $online = $this->createPage('000 Probe online');
        $draft = $this->createPage('000 Probe draft', published: false);
        $binned = $this->createPage('000 Probe binned');
        $bystander = $this->createPage('000 Probe bystander');
        foreach ([$online, $draft, $binned] as $page) {
            $this->holdComponent($page, self::PROBE);
        }
        $this->getService(CmsPageWriter::class)->moveToTrash($binned);
        $this->itemHoldingComponent(self::PROBE);

        $number = $this->numberOnScreen('/admin/cms/components', 'cms-component-pages-'.self::PROBE);
        $drawer = $this->drawerOf($this->screen('/admin/cms/components'), 'cms-component-pages-open-'.self::PROBE);

        self::assertSame($before + 3, $number, 'Two pages and the item: the page in the bin is not counted.');
        self::assertSame($number, $this->totalOf($drawer), 'The list is as long as the number.');
        self::assertSame(min($number, LinkedPagesFinder::SHOWN), $drawer->filter('[data-testid="cms-linked-page"]')->count());

        $titles = $drawer->filter('[data-testid="cms-linked-page"] a')->each(static fn (Crawler $link): string => trim($link->text()));
        self::assertContains('000 Probe online', $titles);
        self::assertContains('000 Probe draft', $titles);
        self::assertNotContains('000 Probe binned', $titles, 'The bin follows the rule of the number: out.');
        self::assertNotContains('000 Probe bystander', $titles);
        self::assertSame(1, $drawer->filter('[data-testid="cms-linked-page"][data-type="product"]')->count(), 'The item that holds it is listed, as the number counts it.');
        self::assertSame('/admin/cms/pages/'.$online->getId(), parse_url((string) $drawer->filter('[data-testid="cms-linked-page"][data-id="'.$online->getId().'"] a')->attr('href'), \PHP_URL_PATH));
    }

    /**
     * Sabotage: in the trigger partial, pass `key: component.label` instead of
     * `component.name`: the address asks for another element, the list is empty
     * and no longer equals the number.
     */
    public function testTheNumberOnTheComponentScreenIsTheLengthOfItsList(): void
    {
        $page = $this->createPage('Probe screen');
        $this->holdComponent($page, self::PROBE);

        $screen = $this->screen('/admin/cms/components');
        $number = $this->numberOnScreen('/admin/cms/components', 'cms-component-pages-'.self::PROBE);

        self::assertSame($number, $this->getService(SectionUsageFinder::class)->pageCounts()[self::PROBE]);
        self::assertSame($number, $this->totalOf($this->drawerOf($screen, 'cms-component-pages-open-'.self::PROBE)));
    }

    /**
     * Sabotage: in PageTagRepository::pagesOf(), add `->filterByDeletedAt(null,
     * Criteria::ISNULL)`: the binned page leaves the list but the number of the
     * screen still counts it, and the equality goes red.
     */
    public function testATagListsItsPagesTheBinIncluded(): void
    {
        $tag = (new CmsTag())->setName('Linked pages tag');
        $tag->save();
        $live = $this->createPage('Tagged live');
        $binned = $this->createPage('Tagged binned');
        $live->setTagId($tag->getId())->save();
        $binned->setTagId($tag->getId())->save();
        $this->getService(CmsPageWriter::class)->moveToTrash($binned);

        $screen = $this->screen('/admin/cms/tags');
        $number = (int) $screen->filter('[data-testid="cms-tag-pages-'.$tag->getId().'"]')->text();
        $drawer = $this->drawerOf($screen, 'cms-tag-pages-open-'.$tag->getId());

        self::assertSame(2, $number);
        self::assertSame($number, $this->totalOf($drawer));
        self::assertSame($number, $drawer->filter('[data-testid="cms-linked-page"]')->count());

        $row = $drawer->filter('[data-testid="cms-linked-page"][data-id="'.$binned->getId().'"]');
        self::assertSame('/admin/cms/pages/trash', parse_url((string) $row->filter('a')->attr('href'), \PHP_URL_PATH), 'A page in the bin has no screen: the bin is the link.');
        self::assertSame(1, $row->filter('[data-testid="cms-linked-page-state"]')->count());

        $list = (string) $drawer->filter('[data-testid="cms-linked-pages-list"]')->attr('href');
        self::assertSame('/admin/cms/pages', parse_url($list, \PHP_URL_PATH));
        self::assertStringContainsString('tag='.$tag->getId(), $list, 'The link goes to the list of pages filtered on the tag.');

        // The edit screen of the tag says the same number and opens the same drawer.
        $edit = $this->screen('/admin/cms/tags/'.$tag->getId());
        self::assertSame($number, $this->totalOf($this->drawerOf($edit, 'cms-tag-pages-open')));
    }

    /**
     * Sabotage: in PageTypeRepository::pagesOfType(), remove `filterByModel(0)`:
     * the model of the type joins the list, which then outgrows the number.
     */
    public function testAPageTypeListsItsPagesAndNotItsModel(): void
    {
        $first = $this->createPage('Recipe one');
        $second = $this->createPage('Recipe two');
        $model = $this->createPage('Recipe model', published: false);
        $first->setPageType('linked-recipe')->save();
        $second->setPageType('linked-recipe')->save();
        $model->setPageType('linked-recipe')->setModel(1)->save();

        $screen = $this->screen('/admin/cms/settings/page-types');
        $cell = $screen->filter('[data-testid="cms-page-type-pages-linked-recipe"]');

        // A type without a definition row is not on the screen: the drawer is asked directly.
        $drawer = 0 === $cell->count()
            ? $this->drawerAt('/admin/cms/linked-pages/page-type?key=linked-recipe')
            : $this->drawerOf($screen, 'cms-page-type-pages-open-linked-recipe');

        self::assertSame(2, $this->totalOf($drawer), 'The model is not a page of the type.');
        self::assertSame(2, $drawer->filter('[data-testid="cms-linked-page"]')->count());
        self::assertStringContainsString('type=linked-recipe', (string) $drawer->filter('[data-testid="cms-linked-pages-list"]')->attr('href'));
        self::assertSame(2, $this->getService(LinkedPagesFinder::class)->find(\TheliaCMS\Page\Admin\LinkedPageKind::PageType, 'linked-recipe', $this->locale(), 1)?->total);
    }

    /**
     * Sabotage: in LinkedPagesFinder::mediaRefs(), drop the loop over
     * itemsUsing(): the item leaves the list, and the number of the media screen
     * (page or item) no longer equals it.
     */
    public function testAnImageListsThePagesAndTheItemsThatUseIt(): void
    {
        $image = $this->cmsImage();
        $document = new Document([new Section('picture', ImageSection::NAME, ['image' => (int) $image->getId(), 'alt' => ''])]);
        $page = $this->createPage('Shows the image');
        $this->getService(CmsPageWriter::class)->saveContent($page, $this->locale(), $document);
        $this->getService(ContentWriter::class)->saveContent($this->itemTarget(CatalogueItemType::Product, self::PRODUCT_ID, $this->locale()), $document);

        $screen = $this->screen('/admin/cms/media');
        $button = $screen->filter('[data-testid="cms-media-usage-open-'.$image->getId().'"]');

        self::assertSame(1, $button->count(), 'The number of the card is a button.');
        self::assertStringContainsString('2', $button->text());

        $drawer = $this->drawerOf($screen, 'cms-media-usage-open-'.$image->getId());

        self::assertSame(2, $this->totalOf($drawer));
        self::assertSame(1, $drawer->filter('[data-testid="cms-linked-page"][data-type="page"]')->count());
        self::assertSame(1, $drawer->filter('[data-testid="cms-linked-page"][data-type="product"]')->count());
    }

    /**
     * Sabotage: set LinkedPagesFinder::SHOWN to 100: the 27 rows are all shown,
     * "and 2 more" disappears and the row count is no longer 25.
     */
    public function testALongListIsCutAndSaysHowManyAreLeft(): void
    {
        $tag = (new CmsTag())->setName('Linked pages long');
        $tag->save();

        for ($index = 1; $index <= 27; ++$index) {
            $this->createPage(\sprintf('Long %02d', $index), published: false)->setTagId($tag->getId())->save();
        }

        $drawer = $this->drawerAt('/admin/cms/linked-pages/tag?key='.$tag->getId());

        self::assertSame(27, $this->totalOf($drawer), 'The total is the number, not the rows shown.');
        self::assertSame(LinkedPagesFinder::SHOWN, $drawer->filter('[data-testid="cms-linked-page"]')->count());
        self::assertSame(1, $drawer->filter('[data-testid="cms-linked-pages-more"]')->count());
        self::assertStringContainsString('2', $drawer->filter('[data-testid="cms-linked-pages-more"]')->text());
    }

    /**
     * Sabotage: in CmsAdminGuard::resourceFor(), delete the `linked-pages`
     * branch: the route falls back to the page right, so a profile holding pages
     * only opens the drawer of a component or of an image and the 403 turns 200.
     */
    public function testTheDrawerAnswersToTheRightOfTheScreenTheNumberIsOn(): void
    {
        $image = $this->cmsImage();
        $tag = (new CmsTag())->setName('Linked pages rights');
        $tag->save();

        $routes = [
            'component' => ['/admin/cms/linked-pages/component?key='.self::PROBE, CmsResources::SETTINGS],
            'page-type' => ['/admin/cms/linked-pages/page-type?key=default', CmsResources::SETTINGS],
            'tag' => ['/admin/cms/linked-pages/tag?key='.$tag->getId(), CmsResources::PAGE],
            'media' => ['/admin/cms/linked-pages/media?key='.$image->getId(), CmsResources::MEDIA],
        ];

        foreach ($routes as $kind => [$url, $resource]) {
            $others = array_values(array_diff([CmsResources::PAGE, CmsResources::SETTINGS, CmsResources::MEDIA, CmsResources::FORM, CmsResources::MENU], [$resource]));

            foreach ($others as $other) {
                $this->logInAs($this->adminWith([$other => [AccessManager::VIEW]]));
                self::assertSame(403, $this->send('GET', $url, [], self::XHR), \sprintf('The right on %s does not open the drawer of a %s.', $other, $kind));
            }

            $this->logInAs($this->adminWith([$resource => [AccessManager::VIEW]]));
            self::assertSame(200, $this->send('GET', $url, [], self::XHR), \sprintf('Whoever sees the number of a %s opens its drawer.', $kind));
        }
    }

    /**
     * Sabotage: change `methods: ['GET']` of the route to ['GET', 'POST']: the
     * POST with the token is no longer refused with a 405.
     */
    public function testTheRouteOnlyReads(): void
    {
        $tag = (new CmsTag())->setName('Linked pages read only');
        $tag->save();
        $token = $this->tokenOfTheScripts($this->screen('/admin/cms/tags'));

        self::assertSame(405, $this->send('POST', '/admin/cms/linked-pages/tag?key='.$tag->getId(), ['_token' => $token]));
        self::assertSame(405, $this->send('DELETE', '/admin/cms/linked-pages/tag?key='.$tag->getId(), ['_token' => $token]));
    }

    /**
     * Sabotage: in LinkedPagesFinder::find(), return an empty list instead of
     * null for an unknown tag: the 404 turns 200.
     */
    public function testAnElementTheSiteHasNotIsNotFound(): void
    {
        self::assertSame(404, $this->send('GET', '/admin/cms/linked-pages/tag?key=99999999', [], self::XHR));
        self::assertSame(404, $this->send('GET', '/admin/cms/linked-pages/tag?key=abc', [], self::XHR));
        self::assertSame(404, $this->send('GET', '/admin/cms/linked-pages/media?key=99999999', [], self::XHR));
        self::assertSame(404, $this->send('GET', '/admin/cms/linked-pages/page-type?key=Not%20A%20Code', [], self::XHR));
        self::assertSame(404, $this->send('GET', '/admin/cms/linked-pages/unknown?key=1', [], self::XHR));
    }

    /**
     * Sabotage: remove the `aria-label` of the trigger partial: the button has
     * only its figure for a name.
     */
    public function testTheNumberIsANamedButtonThatOpensADialog(): void
    {
        $tag = (new CmsTag())->setName('Linked pages named');
        $tag->save();
        $this->createPage('Named')->setTagId($tag->getId())->save();

        $button = $this->screen('/admin/cms/tags')->filter('[data-testid="cms-tag-pages-open-'.$tag->getId().'"]');

        self::assertSame('button', $button->attr('type'));
        self::assertSame('dialog', $button->attr('aria-haspopup'));
        self::assertStringContainsString('Linked pages named', (string) $button->attr('aria-label'), 'The name says whose pages.');
        self::assertNotSame('', trim((string) $button->attr('data-close')), 'The drawer has a name for its close button.');
        self::assertStringContainsString('linked-pages.js', $this->client->getResponse()->getContent() ?: '');
    }

    private function numberOnScreen(string $url, string $testid): int
    {
        return (int) $this->screen($url)->filter('[data-testid="'.$testid.'"]')->attr('data-pages');
    }

    private function drawerOf(Crawler $screen, string $buttonTestid): Crawler
    {
        $button = $screen->filter('[data-testid="'.$buttonTestid.'"]');

        self::assertSame(1, $button->count(), \sprintf('The screen has no button %s.', $buttonTestid));

        return $this->drawerAt((string) $button->attr('data-url'));
    }

    private function drawerAt(string $url): Crawler
    {
        $crawler = $this->client->request('GET', $url, [], [], self::XHR);

        self::assertSame(200, $this->client->getResponse()->getStatusCode(), $url);

        return $crawler;
    }

    private function totalOf(Crawler $drawer): int
    {
        return (int) $drawer->filter('[data-testid="cms-linked-pages"]')->attr('data-total');
    }

    private function holdComponent(CmsPage $page, string $component): void
    {
        $document = json_encode(['sections' => [['id' => 'probe', 'component' => $component, 'props' => []]]], \JSON_THROW_ON_ERROR);

        foreach (CmsPageContentQuery::create()->filterByPageId($page->getId())->find() as $content) {
            $content->setDraftDocument($document)->save();
        }
    }

    private function itemHoldingComponent(string $component): void
    {
        $this->getService(ContentWriter::class)->saveContent($this->itemTarget(CatalogueItemType::Product, self::PRODUCT_ID, $this->locale()), Document::richText('<p>Item</p>'));
        $document = json_encode(['sections' => [['id' => 'probe', 'component' => $component, 'props' => []]]], \JSON_THROW_ON_ERROR);

        foreach (CmsItemContentQuery::create()->filterByItemType('product')->filterByItemId(self::PRODUCT_ID)->find() as $content) {
            $content->setDraftDocument($document)->save();
        }
    }

    private function cmsImage(): LibraryImage
    {
        $image = new LibraryImage();
        $image->setDecorative(1);
        $image->setLocale($this->locale())->setFileName('linked.jpg')->setTitle('linked.jpg');
        $image->save();
        $this->getService(CmsMediaLibrary::class)->tag($image);

        return $image;
    }

    private function adminWith(array $grants): \Thelia\Model\Admin
    {
        return (new FixtureFactory($this->getPropelConnection()))->restrictedAdmin($grants);
    }
}
