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

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\ViewEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use TheliaCMS\Form\FormCatalog;
use TheliaCMS\Locale\AlternateUrls;
use TheliaCMS\Media\CmsMediaLibrary;
use TheliaCMS\Menu\MenuTargetResolver;
use TheliaCMS\Menu\MenuTargetType;
use TheliaCMS\Model\CmsForm;
use TheliaCMS\Model\CmsMenu;
use TheliaCMS\Model\CmsMenuItem;
use TheliaCMS\Model\CmsMenuQuery;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Model\Map\CmsPageContentTableMap;
use TheliaCMS\Model\Map\CmsPageI18nTableMap;
use TheliaCMS\Model\Map\CmsPageTableMap;
use TheliaCMS\Model\Map\CmsTagTableMap;
use TheliaCMS\Page\CmsHomeViewListener;
use TheliaCMS\Page\PageTagWriter;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;
use TheliaCMS\Tests\Integration\CountsStatements;
use TheliaCMS\TheliaCMS;
use TheliaLibrary\Model\LibraryImage;
use TheliaLibrary\Model\Map\LibraryImageTableMap;

/**
 * The checks that a page can be opened, made by the services that only need a
 * yes or a no: the language switcher and the hreflang tags, a menu entry, the
 * home page, the privacy link of a form.
 *
 * Reading the whole page to answer costs its content (the HTML of the whole
 * page), its image, its sharing image and its tag. So each check is made on a
 * page that has all of these and on a bare one: they must cost the same, and no
 * statement may read the published HTML.
 */
final class ReachabilityCostTest extends CmsIntegrationTestCase
{
    use CountsStatements;

    private const MAPS = [
        CmsPageTableMap::class,
        CmsPageI18nTableMap::class,
        CmsPageContentTableMap::class,
        CmsTagTableMap::class,
        LibraryImageTableMap::class,
    ];

    private ?Request $replacedRequest = null;

    private ?string $homePageId = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->homePageId = TheliaCMS::getConfigValue('home_page_id');
    }

    protected function tearDown(): void
    {
        TheliaCMS::setConfigValue('home_page_id', $this->homePageId);

        if (null !== $this->replacedRequest) {
            $stack = $this->getService(RequestStack::class);
            $stack->pop();
            $stack->push($this->replacedRequest);
            $this->replacedRequest = null;
        }

        parent::tearDown();
    }

    public function testTheAlternateAddressesOfAPageDoNotReadIt(): void
    {
        $check = function (CmsPage $page): ?string {
            $this->serve($page);

            return $this->getService(AlternateUrls::class)->forLocale($this->locale());
        };

        $this->assertCheckReadsOnlyWhatItNeeds($check);
        self::assertNull($check($this->createPage('Page masquée', visible: false)), 'a hidden page has no alternate');
    }

    public function testAMenuEntryToAPageDoesNotReadIt(): void
    {
        $check = fn (CmsPage $page): ?string => $this->getService(MenuTargetResolver::class)
            ->resolve($this->entryTo($page), $this->locale())
            ->url;

        $this->assertCheckReadsOnlyWhatItNeeds($check);
        self::assertNull($check($this->createPage('Page masquée', visible: false)), 'a menu links to a hidden page');
    }

    public function testTheHomePageIsNotReadToBeChosen(): void
    {
        $check = function (CmsPage $page): ?string {
            TheliaCMS::setConfigValue('home_page_id', (string) $page->getId());
            $request = Request::create('/');
            $request->attributes->set('_view', 'index');
            $this->getService(CmsHomeViewListener::class)->onKernelView(
                new ViewEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, null),
            );

            return $request->query->get(TheliaCMS::PAGE_VIEW.'_id');
        };

        $this->assertCheckReadsOnlyWhatItNeeds($check);
        self::assertNull($check($this->createPage('Accueil masqué', visible: false)), 'a hidden home page is served');
    }

    public function testThePrivacyLinkOfAFormDoesNotReadThePage(): void
    {
        $check = function (CmsPage $page): ?string {
            $form = (new CmsForm())->setCode('reachability-'.bin2hex(random_bytes(4)))->setPrivacyPolicyPageId((int) $page->getId());
            $form->setLocale($this->locale())->setTitle('Contact');
            $form->save();

            return $this->getService(FormCatalog::class)->definitionOf($form, $this->locale())->privacyPolicyUrl;
        };

        $this->assertCheckReadsOnlyWhatItNeeds($check);
        self::assertNull($check($this->createPage('Mentions masquées', visible: false)), 'a form links to a hidden page');
    }

    /**
     * @param callable(CmsPage): ?string $check
     */
    private function assertCheckReadsOnlyWhatItNeeds(callable $check): void
    {
        $bare = $this->createPage('Page nue');
        $full = $this->fullPage();

        // Once each before measuring: what the check writes on its first run
        // (a form, a setting) is not what is measured.
        self::assertNotNull($check($bare), 'the bare page is online, the check says otherwise');
        self::assertNotNull($check($full), 'the full page is online, the check says otherwise');

        $this->forgetWhatWasRead(self::MAPS);
        $bareCost = $this->statementsFor(static fn () => $check($bare));
        $this->forgetWhatWasRead(self::MAPS);
        $fullCost = $this->statementsFor(static fn () => $check($full));
        $this->forgetWhatWasRead(self::MAPS);
        $statements = $this->statementsSentBy(static fn () => $check($full));

        foreach ($statements as $statement) {
            self::assertDoesNotMatchRegularExpression(
                '/^\s*SELECT(?:(?!\bFROM\b).)*published_html/is',
                $statement,
                'the check reads the HTML of the page it only has to find online',
            );
        }

        self::assertSame($bareCost, $fullCost, \sprintf(
            'the check costs %d statements on a bare page and %d on one with an image, a sharing image and a tag: it reads the page',
            $bareCost,
            $fullCost,
        ));
    }

    private function fullPage(): CmsPage
    {
        $library = $this->getService(CmsMediaLibrary::class);
        $page = $this->createPage('Page complète');
        $tag = $this->getService(PageTagWriter::class)->add('Étiquette '.bin2hex(random_bytes(3)));

        $page->setImageId((int) $this->image($library, 'page.jpg')->getId())
            ->setTagId($tag->getId())
            ->setLocale($this->locale())
            ->setOgImageId((int) $this->image($library, 'partage.jpg')->getId())
            ->save();

        return $page;
    }

    private function image(CmsMediaLibrary $library, string $fileName): LibraryImage
    {
        $image = new LibraryImage();
        $image->setLocale($this->locale())->setFileName($fileName)->setTitle($fileName);
        $image->save();
        $library->tag($image);

        return $image;
    }

    /**
     * Puts a request for the page in place of the one the test case pushed:
     * the services ask the stack for the main request, which stays the first.
     */
    private function serve(CmsPage $page): void
    {
        $request = Request::create('/x?'.TheliaCMS::PAGE_VIEW.'_id='.$page->getId());
        $request->attributes->set('_view', TheliaCMS::PAGE_VIEW);

        $stack = $this->getService(RequestStack::class);
        $previous = $stack->pop();
        $this->replacedRequest ??= $previous;

        if ($previous?->hasSession()) {
            $request->setSession($previous->getSession());
        }

        $stack->push($request);
    }

    private function entryTo(CmsPage $page): CmsMenuItem
    {
        $menu = CmsMenuQuery::findShared('footer');
        self::assertInstanceOf(CmsMenu::class, $menu);

        $entry = (new CmsMenuItem())
            ->setMenuId($menu->getId())
            ->setParent(0)
            ->setPosition(1)
            ->setTargetType(MenuTargetType::Page->value)
            ->setTargetId((int) $page->getId());
        $entry->save();

        return $entry;
    }
}
