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

use FlexyBundle\Toolkit\StoryProviderInterface;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Test\FixtureFactory;
use TheliaCMS\Content\Document;
use TheliaCMS\Content\DocumentRenderer;
use TheliaCMS\Content\DocumentSchema;
use TheliaCMS\Content\RichTextSection;
use TheliaCMS\Content\Section;
use TheliaCMS\Content\SectionAvailability;
use TheliaCMS\Content\SectionCatalog;
use TheliaCMS\Content\Theme\ThemeComponentSection;
use TheliaCMS\Generation\Admin\ToolkitLink;
use TheliaCMS\Generation\Brief;
use TheliaCMS\Generation\CatalogSheet;
use TheliaCMS\Generation\GenerationRequest;
use TheliaCMS\Generation\GenerationTarget;
use TheliaCMS\Generation\Tool\ReadSectionTool;
use TheliaCMS\Security\CmsResources;
use TheliaCMS\Tests\Integration\Security\AdminScreenTestCase;

/**
 * CMS > Components, requested over HTTP by an administrator: the list of the
 * catalogue with the pages using each component, putting one away and back,
 * and what putting it away changes for the generator and for the pages.
 */
final class ComponentScreenTest extends AdminScreenTestCase
{
    private const string SCREEN = '/admin/cms/components';

    private const string ACTION = '/admin/cms/components/availability';

    /** A component the module ships, so the test does not depend on the theme. */
    private const string COMPONENT = 'Partial:cms-form';

    public function testTheScreenListsTheCatalogueWithThePagesUsingEachComponent(): void
    {
        $before = (int) $this->cell($this->screen(self::SCREEN), 'pages', RichTextSection::NAME)->attr('data-pages');

        $this->createPage('Page counted by the components');
        $screen = $this->screen(self::SCREEN);

        self::assertSame($before + 1, (int) $this->cell($screen, 'pages', RichTextSection::NAME)->attr('data-pages'), 'The new page is counted.');
        self::assertSame('1', $this->cell($screen, 'offered', self::COMPONENT)->attr('data-offered'));
        self::assertStringContainsString('partial', $this->cell($screen, 'origin', self::COMPONENT)->text());
        self::assertSame(0, $this->cell($screen, 'toggle', RichTextSection::NAME)->count(), 'Free text cannot be put away.');

        $entry = $screen->filter('[data-testid="bo-nav-cms-components"]');
        self::assertSame(1, $entry->count(), 'The sidebar offers the components.');
        self::assertSame(self::SCREEN, parse_url((string) $entry->attr('href'), \PHP_URL_PATH));
    }

    /**
     * Sabotage: in list.html.twig, drop the `data-origin` of the rows, or give
     * the origin a column of its own again: the filter or the badge go red.
     */
    public function testTheListGivesEachComponentOneLinkToItsPageAndAFilterByOrigin(): void
    {
        $screen = $this->screen(self::SCREEN);

        $see = $this->cell($screen, 'see', self::COMPONENT);
        self::assertSame(1, $see->count());
        self::assertSame('/admin/cms/components/'.self::COMPONENT, parse_url((string) $see->attr('href'), \PHP_URL_PATH), 'The only action leads to the page of the component.');
        self::assertSame(1, $screen->filter('[data-testid="cms-component-row-'.self::COMPONENT.'"] a.btn')->count(), 'One link per row.');
        self::assertSame('partial', $screen->filter('[data-testid="cms-component-row-'.self::COMPONENT.'"]')->attr('data-origin'));
        $origin = $this->cell($screen, 'origin', self::COMPONENT);
        self::assertStringNotContainsString('badge', (string) $origin->attr('class'), 'The origin is a bare coloured icon, with no background.');
        self::assertSame('Origin: partial', $origin->attr('title'), 'Its tooltip says the origin.');
        self::assertSame('Origin: partial', $origin->filter('.visually-hidden')->text(), 'And so does its hidden text.');
        self::assertSame(1, $origin->filter('i.bi')->count(), 'It draws an icon.');
        $cell = $screen->filter('[data-testid="cms-component-row-'.self::COMPONENT.'"] td:first-child');
        self::assertSame(1, $cell->filter('[data-testid="cms-component-origin-'.self::COMPONENT.'"]')->count(), 'The badge sits in the cell of the name.');
        self::assertLessThan(
            strpos((string) $cell->html(), 'fw-medium'),
            strpos((string) $cell->html(), 'cms-component-origin-'),
            'The badge comes before the name, on its line.',
        );
        $colour = static fn (Crawler $element, string $prefix): array => array_values(preg_grep('/^'.$prefix.'/', explode(' ', (string) $element->attr('class'))) ?: []);
        self::assertSame(['text-secondary'], $colour($origin, 'text-'), 'The icon wears the text colour of its origin.');
        self::assertSame(['text-bg-secondary'], $colour($screen->filter('[data-testid="cms-components-filter-count-partial"]'), 'text-bg-'), 'The count of the button wears the same colour, as a badge.');
        self::assertSame(1, $screen->filter('[data-testid="cms-components-filter-partial"] i.bi-layout-text-window')->count(), 'The button shows the icon of its origin.');
        self::assertStringContainsString('All components', $screen->filter('[data-testid="cms-components-filter-all"]')->text(), 'The button that shows everything has a key of its own, not the one of the other screens.');
        self::assertSame(4, $screen->filter('thead th')->count(), 'No column of its own for the origin: name, pages, offered, actions.');

        $partials = (int) $screen->filter('[data-testid="cms-components-filter-count-partial"]')->text();
        self::assertGreaterThan(0, $partials);
        self::assertSame($partials, $screen->filter('tr[data-origin="partial"]')->count(), 'The count of the button is the number of rows of the origin.');
        self::assertSame($screen->filter('tbody tr[data-origin]')->count(), (int) $screen->filter('[data-testid="cms-components-filter-count-all"]')->text());
    }

    /**
     * Sabotage: in CmsComponentAdminController::list(), set `may_disable` to
     * true for every component: free text gets an active switch.
     */
    public function testTheListLinksToTheToolkitWhereItAnswers(): void
    {
        $toolkit = $this->toolkitThatAnswers();

        $link = $this->screen(self::SCREEN)->filter('[data-testid="cms-components-toolkit"]');

        self::assertSame(1, $link->count(), 'The list links to the toolkit where it answers.');
        self::assertSame($toolkit->url(), $link->attr('href'), 'It opens the page that draws every component offered.');
        self::assertSame('_blank', $link->attr('target'));
    }

    public function testNeitherTheListNorAComponentLinksToAToolkitThatDoesNotAnswer(): void
    {
        $this->setToolkit(new ToolkitLink($this->urls(), false, storiesAvailable: true, themeToolkit: false));

        self::assertSame(0, $this->screen(self::SCREEN)->filter('[data-testid="cms-components-toolkit"]')->count(), 'No link outside debug unless the theme opens its toolkit.');
        self::assertSame(0, $this->screen('/admin/cms/components/'.self::COMPONENT)->filter('[data-testid="cms-component-toolkit"]')->count());
    }

    public function testThePageOfAComponentLinksToItsSectionOfTheToolkit(): void
    {
        $toolkit = $this->toolkitThatAnswers();

        $link = $this->screen('/admin/cms/components/'.self::COMPONENT)->filter('[data-testid="cms-component-toolkit"]');

        self::assertSame(1, $link->count(), 'The page of a component offered links to the toolkit.');
        self::assertSame($toolkit->url().'#partial-cms-form', $link->attr('href'), 'It opens the section of the component, by the id the toolkit page gives it.');
        self::assertSame('_blank', $link->attr('target'));
    }

    public function testThePageOfAComponentSwitchedOffDoesNotLinkToTheToolkit(): void
    {
        $this->toolkitThatAnswers();
        $this->getService(SectionAvailability::class)->setOffered(self::COMPONENT, false);

        $screen = $this->screen('/admin/cms/components/'.self::COMPONENT);

        self::assertSame(0, $screen->filter('[data-testid="cms-component-toolkit"]')->count(), 'The toolkit page draws the components offered only: no link to a section it does not have.');
    }

    public function testAComponentAlwaysOfferedHasADisabledSwitch(): void
    {
        $screen = $this->screen(self::SCREEN);

        self::assertSame(0, $this->cell($screen, 'toggle', RichTextSection::NAME)->count());
        $locked = $this->cell($screen, 'switch-disabled', RichTextSection::NAME);
        self::assertSame(1, $locked->count());
        self::assertNotNull($locked->attr('disabled'));
        self::assertSame(1, $screen->filter('[data-testid="cms-component-row-'.RichTextSection::NAME.'"] .bi-lock')->count());
        self::assertSame(1, $this->cell($screen, 'toggle', self::COMPONENT)->count(), 'A component that may be put away has a live switch.');
    }

    /**
     * Sabotage: in the controller, answer the page of a component of the
     * catalogue only for the components of the site: the module and the
     * partial go red.
     */
    public function testEveryComponentOfTheCatalogueHasItsPage(): void
    {
        foreach ([RichTextSection::NAME, self::COMPONENT] as $name) {
            $screen = $this->screen('/admin/cms/components/'.$name);
            $definition = $this->catalog()->find($name);
            \assert(null !== $definition);

            self::assertStringContainsString($definition->label(), $screen->filter('title')->text(), $name);
            self::assertSame($name, $screen->filter('.cms-content__bar code')->first()->text());
            self::assertGreaterThan(0, $screen->filter('[data-testid="cms-site-component-props"] tbody tr')->count(), \sprintf('%s lists its props.', $name));
            self::assertSame(0, $screen->filter('[data-testid="cms-site-component-copy"], [data-testid="cms-site-component-delete"], [data-testid="cms-site-component-change"]')->count(), 'No column of management for what the site did not make.');
            self::assertSame(1, $screen->filter('[data-testid="cms-site-component-frame"]')->count(), 'Every component has its preview.');
            self::assertSame(1, $screen->filter('script[src*="/toasts.js"]')->count(), 'Its notices are shown: the page replaces the layout, and its messages with it.');
        }
    }

    public function testAComponentOfTheThemeHasItsPageToo(): void
    {
        $theme = null;

        foreach ($this->catalog()->all() as $name => $definition) {
            if ($definition instanceof ThemeComponentSection) {
                $theme = $name;

                break;
            }
        }

        if (null === $theme) {
            self::markTestSkipped('The theme of this host opens no component to pages.');
        }

        $screen = $this->screen('/admin/cms/components/'.$theme);
        self::assertSame($theme, $screen->filter('.cms-content__bar code')->first()->text());
        self::assertSame(0, $screen->filter('[data-testid="cms-site-component-copy"]')->count());
    }

    /**
     * Sabotage: drop the 404 of the controller (`?? throw`): the unknown name
     * is no longer refused.
     */
    public function testANameTheCatalogueDoesNotHoldAnswers404(): void
    {
        $this->client->request('GET', '/admin/cms/components/Cms:Nothing');
        self::assertSame(404, $this->client->getResponse()->getStatusCode());

        $this->client->request('GET', '/admin/cms/components/availability');
        self::assertSame(404, $this->client->getResponse()->getStatusCode(), 'The address of the switch is not the page of a component.');
    }

    /**
     * Sabotage: in CmsSiteComponentController::show(), answer 302 or point at
     * another name.
     */
    public function testTheOldAddressOfAComponentOfTheSiteRedirectsForGood(): void
    {
        $this->client->request('GET', '/admin/cms/components/site/PriceTable');

        self::assertSame(301, $this->client->getResponse()->getStatusCode());
        self::assertSame('/admin/cms/components/Site:PriceTable', parse_url((string) $this->client->getResponse()->headers->get('Location'), \PHP_URL_PATH));
    }

    public function testADisabledComponentIsNoLongerOfferedToTheGeneratorAndComesBack(): void
    {
        self::assertSame(302, $this->toggle(self::COMPONENT, false));

        $list = $this->client->followRedirect();
        self::assertSame('0', $this->cell($list, 'offered', self::COMPONENT)->attr('data-offered'));
        self::assertStringContainsString('no longer offered', $list->filter('[role="alert"]')->text(''));

        $catalog = $this->catalog();
        self::assertNotNull($catalog->find(self::COMPONENT), 'The component is still known: the pages using it render.');
        self::assertNotContains(self::COMPONENT, $catalog->offeredNames());
        self::assertNotContains(self::COMPONENT, $this->componentsOfTheStrictSchema([]));
        self::assertStringNotContainsString('`'.self::COMPONENT.'`', $this->sheet()->text());

        self::assertSame(302, $this->toggle(self::COMPONENT, true));

        self::assertSame('1', $this->cell($this->client->followRedirect(), 'offered', self::COMPONENT)->attr('data-offered'));
        self::assertContains(self::COMPONENT, $this->catalog()->offeredNames());
        self::assertStringContainsString('`'.self::COMPONENT.'`', $this->sheet()->text());
    }

    public function testARevisionMayKeepADisabledComponentTheDocumentAlreadyUses(): void
    {
        self::assertSame(302, $this->toggle(self::COMPONENT, false));

        $target = GenerationTarget::page($this->createPage('Page revised with a disabled component'), $this->locale());
        $document = new Document([new Section('contact', self::COMPONENT, ['form' => 1])]);
        $request = GenerationRequest::revision($target, $this->locale(), $document, new Brief('Shorter.'));

        self::assertContains(self::COMPONENT, $this->componentsOfTheStrictSchema($request->keptComponents()));

        $tool = $this->getService(ReadSectionTool::class);
        \assert($tool instanceof ReadSectionTool);
        self::assertStringContainsString('`'.self::COMPONENT.'`', $tool->run(['component' => self::COMPONENT], $request), 'The sheet of a kept component is read.');

        $fresh = GenerationRequest::fromBrief($target, $this->locale(), new Brief('A contact page.'));
        self::assertStringStartsWith('The catalogue has no such component.', $tool->run(['component' => self::COMPONENT], $fresh));

        $renderer = $this->getService(DocumentRenderer::class);
        \assert($renderer instanceof DocumentRenderer);
        self::assertStringContainsString('data-cms-section="contact"', $renderer->render($document, $this->locale()), 'A page using it still renders.');
    }

    public function testFreeTextCannotBePutAway(): void
    {
        self::assertSame(400, $this->toggle(RichTextSection::NAME, false));
        self::assertContains(RichTextSection::NAME, $this->catalog()->offeredNames());
    }

    public function testAComponentTheCatalogueDoesNotHoldAnswers404(): void
    {
        self::assertSame(404, $this->toggle('Cms:Nothing', false));
    }

    public function testSeeingTheComponentsIsNotTheRightToPutThemAway(): void
    {
        $token = $this->tokenOfTheForm($this->screen(self::SCREEN), self::ACTION);

        $this->logInAs((new FixtureFactory($this->getPropelConnection()))->restrictedAdmin([
            CmsResources::SETTINGS => [AccessManager::VIEW],
        ]));

        $screen = $this->screen(self::SCREEN);
        self::assertSame(1, $this->cell($screen, 'offered', self::COMPONENT)->count(), 'The list is readable.');
        self::assertSame(0, $screen->filter('[data-testid^="cms-component-toggle-"]')->count(), 'No button is offered.');

        self::assertSame(403, $this->send('POST', self::ACTION, ['_token' => $token, 'component' => self::COMPONENT, 'offered' => '0']));
        self::assertContains(self::COMPONENT, $this->catalog()->offeredNames());
    }

    /**
     * The toolkit as where the kernel runs in debug: the tests run without it,
     * where the toolkit answers 404 and nothing links to it.
     */
    private function toolkitThatAnswers(): ToolkitLink
    {
        if (!interface_exists(StoryProviderInterface::class)) {
            self::markTestSkipped('The theme has no toolkit: the section stories cannot load.');
        }

        $toolkit = new ToolkitLink($this->urls(), true, storiesAvailable: true);
        self::assertNotNull($toolkit->url(), 'The toolkit of the theme has its route.');
        $this->setToolkit($toolkit);

        return $toolkit;
    }

    private function setToolkit(ToolkitLink $toolkit): void
    {
        static::getContainer()->set(ToolkitLink::class, $toolkit);
    }

    private function urls(): UrlGeneratorInterface
    {
        return $this->getService(UrlGeneratorInterface::class);
    }

    private function toggle(string $component, bool $offered): int
    {
        $token = $this->tokenOfTheForm($this->screen(self::SCREEN), self::ACTION);

        return $this->send('POST', self::ACTION, ['_token' => $token, 'component' => $component, 'offered' => $offered ? '1' : '0']);
    }

    private function cell(Crawler $screen, string $column, string $component): Crawler
    {
        return $screen->filter(\sprintf('[data-testid="cms-component-%s-%s"]', $column, $component));
    }

    /**
     * @param list<string> $kept
     *
     * @return list<string>
     */
    private function componentsOfTheStrictSchema(array $kept): array
    {
        $schema = $this->getService(DocumentSchema::class);
        \assert($schema instanceof DocumentSchema);

        $properties = $schema->strict($kept)['properties']['sections']['items']['properties'];

        return $properties['component']['enum'];
    }

    private function catalog(): SectionCatalog
    {
        $catalog = $this->getService(SectionCatalog::class);
        \assert($catalog instanceof SectionCatalog);

        return $catalog;
    }

    private function sheet(): CatalogSheet
    {
        $sheet = $this->getService(CatalogSheet::class);
        \assert($sheet instanceof CatalogSheet);

        return $sheet;
    }
}
