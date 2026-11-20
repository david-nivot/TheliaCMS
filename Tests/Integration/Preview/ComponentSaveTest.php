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

namespace TheliaCMS\Tests\Integration\Preview;

use Propel\Runtime\ActiveQuery\Criteria;
use Thelia\Core\Security\AccessManager;
use Thelia\Model\Lang;
use Thelia\Model\LangQuery;
use Thelia\Test\FixtureFactory;
use TheliaCMS\Content\Document;
use TheliaCMS\Content\ImageSection;
use TheliaCMS\Content\RichTextSection;
use TheliaCMS\Content\SectionAvailability;
use TheliaCMS\Content\SectionCatalog;
use TheliaCMS\Content\Site\SiteComponentRepository;
use TheliaCMS\Generation\Admin\SectionFormBuilder;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Model\CmsPageContent;
use TheliaCMS\Model\CmsPageContentQuery;
use TheliaCMS\Model\Map\CmsPageContentTableMap;
use TheliaCMS\Preview\ComponentPreviewLink;
use TheliaCMS\Security\CmsResources;
use TheliaCMS\Tests\Integration\Security\AdminScreenTestCase;
use TheliaCMS\Tests\Unit\Content\Site\PriceTable;

/**
 * What is kept of the props typed in the controls of the page of a component:
 * its example (a component of the site), or a section of a page.
 */
final class ComponentSaveTest extends AdminScreenTestCase
{
    private const string FORM = SectionFormBuilder::COMPONENT_FORM;

    private const string SAMPLE = '/admin/cms/components/site/PriceTable/sample';

    /**
     * Sabotage: in CmsSiteComponentController::sample(), skip the save() call:
     * the frame still draws the old example.
     */
    public function testTheTypedPropsBecomeTheExampleTheFrameDraws(): void
    {
        $this->keepPriceTable(SiteComponentRepository::STATUS_READY);
        $token = $this->tokenOfTheControls('Site:PriceTable');

        $this->client->request('GET', $this->frame('Site:PriceTable'));
        self::assertStringContainsString('Our plans', (string) $this->client->getResponse()->getContent());

        self::assertSame(302, $this->send('POST', self::SAMPLE, ['_token' => $token, self::FORM => $this->typedPlans('Typed plans')]));
        self::assertStringEndsWith('/admin/cms/components/Site:PriceTable', (string) $this->client->getResponse()->headers->get('Location'));

        $this->client->request('GET', $this->frame('Site:PriceTable'));
        $page = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Typed plans', $page, 'The example is what was typed.');
        self::assertStringNotContainsString('Our plans', $page);
        self::assertSame(SiteComponentRepository::STATUS_READY, $this->getService(SiteComponentRepository::class)->status('PriceTable'));
    }

    /**
     * Sabotage: pass STATUS_READY to save() instead of the status read: the
     * prototype becomes offered to everybody.
     */
    public function testAPrototypeStaysAPrototype(): void
    {
        $this->keepPriceTable(SiteComponentRepository::STATUS_DRAFT);
        $token = $this->tokenOfTheControls('Site:PriceTable');

        self::assertSame(302, $this->send('POST', self::SAMPLE, ['_token' => $token, self::FORM => $this->typedPlans('Typed prototype')]));

        self::assertSame(SiteComponentRepository::STATUS_DRAFT, $this->getService(SiteComponentRepository::class)->status('PriceTable'));
        self::assertSame('Typed prototype', $this->getService(SiteComponentRepository::class)->find('PriceTable')?->sample['title']);
    }

    /**
     * Sabotage: drop denyUnlessMayMake() from sample(): the 403 is a 302.
     */
    public function testTheExampleIsKeptOnlyByWhoMayMakeComponentsAndOnlyForAComponentOfTheSite(): void
    {
        $this->keepPriceTable(SiteComponentRepository::STATUS_READY);
        $token = $this->tokenOfTheControls('Site:PriceTable');
        $typed = [self::FORM => $this->typedPlans('Refused')];

        self::assertSame(403, $this->send('POST', self::SAMPLE, $typed), 'No token.');
        self::assertSame(403, $this->send('POST', self::SAMPLE, ['_token' => 'not-the-token'] + $typed), 'Another token.');
        self::assertSame(404, $this->send('POST', '/admin/cms/components/site/Nothing/sample', ['_token' => $token] + $typed), 'A code the site has not.');
        // A component of the module has no sample to rewrite: the address is not a route.
        self::assertSame(404, $this->send('POST', '/admin/cms/components/site/RichText/sample', ['_token' => $token] + $typed));

        $this->logInAs((new FixtureFactory($this->getPropelConnection()))->restrictedAdmin([
            CmsResources::SETTINGS => [AccessManager::VIEW, AccessManager::UPDATE],
        ]));
        $screen = $this->screen('/admin/cms/components/Site:PriceTable');
        self::assertSame(0, $screen->filter('[data-testid="cms-component-save-sample"]')->count(), 'No button without the right.');
        self::assertSame(403, $this->send('POST', self::SAMPLE, ['_token' => $this->tokenOfTheScripts($screen)] + $typed), 'Without the custom code right.');

        self::assertSame('Our plans', $this->getService(SiteComponentRepository::class)->find('PriceTable')?->sample['title']);
    }

    /**
     * Sabotage: in show.html.twig, turn the condition of the button into
     * `{% if true %}`: the module component offers it.
     */
    public function testOnlyAComponentOfTheSiteOffersToSaveTheExample(): void
    {
        $this->keepPriceTable(SiteComponentRepository::STATUS_READY);

        self::assertSame(1, $this->screen('/admin/cms/components/Site:PriceTable')->filter('[data-testid="cms-component-save-sample"]')->count());
        self::assertSame(0, $this->screen('/admin/cms/components/'.RichTextSection::NAME)->filter('[data-testid="cms-component-save-sample"]')->count());
    }

    /**
     * Sabotage: in sample(), catch InvalidSiteComponentException only: the
     * refusal of the props is a 500.
     */
    public function testPropsTheComponentRefusesAreNotKeptAsTheExample(): void
    {
        $this->keepPriceTable(SiteComponentRepository::STATUS_READY);
        $token = $this->tokenOfTheControls('Site:PriceTable');

        self::assertSame(302, $this->send('POST', self::SAMPLE, ['_token' => $token, self::FORM => ['title' => 'Refused', 'tone' => 'enormous']]));

        self::assertSame('Our plans', $this->getService(SiteComponentRepository::class)->find('PriceTable')?->sample['title']);
    }

    /**
     * Sabotage: in SectionUpdate::addComponent(), ignore $props (always the
     * sample): the typed text is not in the draft.
     */
    public function testTheTypedPropsAreAddedAtTheEndOfTheDraftOfThePageAndNothingIsPublished(): void
    {
        $page = $this->pageWithAParagraph();
        $token = $this->tokenOfTheControls(RichTextSection::NAME);

        $before = (string) $this->content($page)?->getPublishedHtml();

        self::assertSame(302, $this->send('POST', $this->addUrl(RichTextSection::NAME), $this->added($token, $page, ['html' => '<p>Typed in the controls</p>'])));
        self::assertSame(\sprintf('/admin/cms/pages/%d/content?edit_language_id=%d', $page->getId(), Lang::getDefaultLanguage()->getId()), $this->client->getResponse()->headers->get('Location'));

        $sections = Document::fromJson((string) $this->content($page)?->getDraftDocument())->sections;
        self::assertCount(2, $sections);
        self::assertSame(RichTextSection::NAME, $sections[1]->component, 'At the end.');
        self::assertSame('<p>Typed in the controls</p>', $sections[1]->props['html']);
        self::assertSame($before, (string) $this->content($page)?->getPublishedHtml(), 'Nothing is published.');
    }

    /**
     * Sabotage: skip steps->record() in addComponentToPage(): there is no step
     * to undo.
     */
    public function testTheAdditionIsAStepUndoPutsBack(): void
    {
        $page = $this->pageWithAParagraph();
        $token = $this->tokenOfTheControls(RichTextSection::NAME);

        self::assertSame(302, $this->send('POST', $this->addUrl(RichTextSection::NAME), $this->added($token, $page, ['html' => '<p>Typed</p>'])));
        self::assertCount(2, Document::fromJson((string) $this->content($page)?->getDraftDocument())->sections);

        $base = \sprintf('/admin/cms/pages/%d/content', $page->getId());
        // Undo is a write of the draft: it posts the version the content screen shows.
        $version = $this->draftVersionOf($this->screen($base));
        self::assertSame(302, $this->send('POST', $base.'/undo', ['_token' => $token, 'draft_version' => $version]));
        self::assertCount(1, Document::fromJson((string) $this->content($page)?->getDraftDocument())->sections);
    }

    /**
     * Sabotage: in addComponentToPage(), catch something else than
     * NotFoundHttpException, drop the language check, or catch something else
     * than InvalidDocumentException around the read: a 404, a 500 or a section
     * added instead of the redirect.
     */
    public function testARefusalComesBackToThePageOfTheComponentWithAMessage(): void
    {
        $page = $this->pageWithAParagraph();
        $token = $this->tokenOfTheControls(RichTextSection::NAME);
        $component = '/admin/cms/components/'.RichTextSection::NAME;

        self::assertSame(302, $this->send('POST', $this->addUrl(RichTextSection::NAME), $this->added($token, $page, ['html' => '<p>X</p>'], pageId: 999999)));
        self::assertStringEndsWith($component, (string) $this->client->getResponse()->headers->get('Location'), 'A page that does not exist.');
        self::assertStringContainsString('does not exist', $this->flashes());

        self::assertSame(302, $this->send('POST', $this->addUrl(RichTextSection::NAME), $this->added($token, $page, ['html' => '<p>X</p>'], language: 999999)));
        self::assertStringEndsWith($component, (string) $this->client->getResponse()->headers->get('Location'), 'A language the shop has not.');

        // A language the shop has, turned off: the same refusal, said as such.
        $off = LangQuery::create()->filterById(Lang::getDefaultLanguage()->getId(), Criteria::NOT_EQUAL)->findOne();
        self::assertNotNull($off, 'The shop of the tests has a second language.');
        $off->setActive(0)->save();
        self::assertSame(302, $this->send('POST', $this->addUrl(RichTextSection::NAME), $this->added($token, $page, ['html' => '<p>X</p>'], language: (int) $off->getId())));
        self::assertStringEndsWith($component, (string) $this->client->getResponse()->headers->get('Location'), 'A language turned off.');
        self::assertStringContainsString('not active', $this->flashes());

        self::assertSame(302, $this->send('POST', $this->addUrl(ImageSection::NAME), $this->added($token, $page, ['image' => '', 'width' => 'enormous'])));
        self::assertStringEndsWith('/admin/cms/components/'.ImageSection::NAME, (string) $this->client->getResponse()->headers->get('Location'), 'Props the form refuses.');

        self::assertCount(1, Document::fromJson((string) $this->content($page)?->getDraftDocument())->sections, 'Nothing was added.');
    }

    /**
     * The page of the component shows no draft: the version checked is the
     * one read with the draft the section goes into. A save landed between
     * that read and the write is refused, not written over. The read is made
     * stale here through the instance pool of Propel, which keeps the row the
     * test loaded while the version moves in the table.
     *
     * Sabotage: pass no version to saver() in addComponentToPage(): the
     * section is written over the save that landed.
     */
    public function testASaveLandedDuringTheAdditionRefusesIt(): void
    {
        $page = $this->pageWithAParagraph();
        $token = $this->tokenOfTheControls(RichTextSection::NAME);
        $content = $this->content($page);
        \assert(null !== $content);
        $this->getPropelConnection()->exec(\sprintf('UPDATE cms_page_content SET draft_version = draft_version + 1 WHERE id = %d', $content->getId()));

        self::assertSame(302, $this->send('POST', $this->addUrl(RichTextSection::NAME), $this->added($token, $page, ['html' => '<p>Typed</p>'])));
        self::assertStringEndsWith('/admin/cms/components/'.RichTextSection::NAME, (string) $this->client->getResponse()->headers->get('Location'), 'Back to the page of the component.');
        self::assertStringContainsString('nothing was added', $this->flashes());

        CmsPageContentTableMap::clearInstancePool();
        self::assertCount(1, Document::fromJson((string) $this->content($page)?->getDraftDocument())->sections, 'Nothing was written.');
    }

    /**
     * A page holding no content yet starts its draft with the section.
     *
     * Sabotage: in SectionUpdate::addComponent(), drop `$current ??= new
     * Document([])`: the addition fails on a page with nothing written.
     */
    public function testAPageWithNothingWrittenStartsItsDraftWithTheSection(): void
    {
        $page = $this->createPage('Nothing written yet', published: false);
        CmsPageContentQuery::create()->filterByPageId($page->getId())->delete();
        $this->client->request('GET', '/admin/cms/pages');
        $token = $this->tokenOfTheControls(RichTextSection::NAME);

        self::assertSame(302, $this->send('POST', $this->addUrl(RichTextSection::NAME), $this->added($token, $page, ['html' => '<p>First words</p>'])));
        self::assertStringContainsString(\sprintf('/admin/cms/pages/%d/content', $page->getId()), (string) $this->client->getResponse()->headers->get('Location'));

        $sections = Document::fromJson((string) $this->content($page)?->getDraftDocument())->sections;
        self::assertCount(1, $sections);
        self::assertSame('<p>First words</p>', $sections[0]->props['html']);
    }

    /**
     * Sabotage: in SectionUpdate::addComponent(), look the name up in all()
     * instead of offered(): a component put away is added.
     */
    public function testAComponentTheSiteDoesNotOfferIsNotAdded(): void
    {
        $page = $this->pageWithAParagraph();
        $token = $this->tokenOfTheControls('Site:PriceTable', keep: true);
        $this->getService(SectionAvailability::class)->setOffered('Site:PriceTable', false);
        $this->getService(SectionCatalog::class)->reset();

        self::assertSame(302, $this->send('POST', $this->addUrl('Site:PriceTable'), $this->added($token, $page, $this->typedPlans('Put away'))));
        self::assertStringEndsWith('/admin/cms/components/Site:PriceTable', (string) $this->client->getResponse()->headers->get('Location'));
        self::assertCount(1, Document::fromJson((string) $this->content($page)?->getDraftDocument())->sections);

        $screen = $this->screen('/admin/cms/components/Site:PriceTable');
        self::assertSame(0, $screen->filter('[data-testid="cms-component-add-to-page"]')->count(), 'No form for a component not offered.');
    }

    /**
     * Sabotage: drop denyUnlessMayChange() from addComponentToPage(): the
     * reader is let add a section.
     */
    public function testAddingToAPageTakesTheRightToChangePages(): void
    {
        $page = $this->pageWithAParagraph();
        $token = $this->tokenOfTheControls(RichTextSection::NAME);

        $this->logInAs((new FixtureFactory($this->getPropelConnection()))->restrictedAdmin([
            CmsResources::SETTINGS => [AccessManager::VIEW],
            CmsResources::PAGE => [AccessManager::VIEW],
        ]));
        $screen = $this->screen('/admin/cms/components/'.RichTextSection::NAME);
        self::assertSame(0, $screen->filter('[data-testid="cms-component-add-to-page"]')->count(), 'No form without the right.');

        self::assertSame(403, $this->send('POST', $this->addUrl(RichTextSection::NAME), $this->added($this->tokenOfTheScripts($screen), $page, ['html' => '<p>X</p>'])));
        self::assertSame(403, $this->send('POST', $this->addUrl(RichTextSection::NAME), $this->added('not-the-token', $page, ['html' => '<p>X</p>'])), 'Another token.');
        self::assertCount(1, Document::fromJson((string) $this->content($page)?->getDraftDocument())->sections);
        self::assertNotSame('', $token);
    }

    /**
     * Sabotage: in the template, drop the `form` attribute of the page field:
     * the page is not posted.
     */
    public function testThePageOfTheComponentOffersTheChoiceOfAPageAndOfALanguage(): void
    {
        $screen = $this->screen('/admin/cms/components/'.RichTextSection::NAME);
        $add = $screen->filter('[data-testid="cms-component-add-to-page"]');

        self::assertSame(1, $add->count());
        self::assertStringContainsString('/admin/cms/partials/sources/pages', (string) $add->filter('input[data-cms-picker]')->attr('data-cms-picker'));
        self::assertSame('cms-component-controls', $add->filter('input[name="page"]')->attr('form'));
        self::assertGreaterThan(0, $add->filter('select[name="language"] option')->count());
        self::assertSame(1, $add->filter('option[selected]')->count(), 'A language is chosen by default.');
        self::assertSame('cms-component-controls', $screen->filter('form[data-cms-preview-form]')->attr('id'));
        self::assertStringEndsWith('/add-to-page', (string) $add->filter('button[form="cms-component-controls"]')->attr('formaction'));
        self::assertSame('_self', $add->filter('button[form="cms-component-controls"]')->attr('formtarget'));
    }

    /**
     * The addition from the content screen is untouched: the sample.
     */
    public function testTheAdditionFromTheContentScreenStillStartsFromTheSample(): void
    {
        $page = $this->pageWithAParagraph();
        $base = \sprintf('/admin/cms/pages/%d/content?edit_language_id=%d', $page->getId(), Lang::getDefaultLanguage()->getId());

        $form = $this->screen($base)->filter('[data-testid="cms-content-add-cms:richtext"]')->form();
        self::assertSame(302, $this->send('POST', $form->getUri(), $form->getPhpValues()));

        $sections = Document::fromJson((string) $this->content($page)?->getDraftDocument())->sections;
        self::assertCount(2, $sections);
        self::assertStringContainsString('A paragraph of text', (string) $sections[1]->props['html']);
    }

    /**
     * @param array<string, mixed> $props
     * @param array<string, mixed> $raw
     *
     * @return array<string, mixed>
     */
    private function added(string $token, CmsPage $page, array $props, ?int $pageId = null, ?int $language = null, array $raw = []): array
    {
        return ['_token' => $token, 'page' => $pageId ?? $page->getId(), 'language' => $language ?? Lang::getDefaultLanguage()->getId()] + ([] !== $raw ? $raw : [self::FORM => $props]);
    }

    private function addUrl(string $name): string
    {
        return '/admin/cms/components/'.$name.'/add-to-page';
    }

    /**
     * @return array<string, mixed>
     */
    private function typedPlans(string $title): array
    {
        return ['title' => $title, 'tone' => 'dark', 'plans' => [['name' => 'Solo', 'price' => '5 €', 'details' => '<p>One</p>', 'featured' => '1']]];
    }

    private function keepPriceTable(string $status): void
    {
        $this->getService(SiteComponentRepository::class)->save(PriceTable::component(), $status);
        $this->getService(SectionCatalog::class)->reset();
    }

    private function pageWithAParagraph(): CmsPage
    {
        $page = $this->createPage('Added to', published: true);
        $this->client->request('GET', '/admin/cms/pages');

        return $page;
    }

    private function content(CmsPage $page): ?CmsPageContent
    {
        return CmsPageContentQuery::create()->filterByPageId($page->getId())->filterByLocale($this->locale())->findOne();
    }

    private function tokenOfTheControls(string $name, bool $keep = false): string
    {
        if ($keep) {
            $this->keepPriceTable(SiteComponentRepository::STATUS_READY);
        }

        return (string) $this->screen('/admin/cms/components/'.$name)->filter('form[data-cms-preview-form] input[name="_token"]')->attr('value');
    }

    private function flashes(): string
    {
        return $this->client->request('GET', '/admin/cms/components/'.RichTextSection::NAME)->filter('[data-testid^="cms-builder-flash-"]')->text('');
    }

    private function frame(string $name): string
    {
        return (string) parse_url($this->getService(ComponentPreviewLink::class)->section($name, 'en_US'), \PHP_URL_PATH);
    }
}
