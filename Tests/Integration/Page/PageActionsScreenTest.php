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
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Test\FixtureFactory;
use TheliaCMS\Content\Document;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Model\CmsPageContent;
use TheliaCMS\Model\CmsPageContentQuery;
use TheliaCMS\Model\CmsPageQuery;
use TheliaCMS\Model\CmsPageRevisionQuery;
use TheliaCMS\Model\Map\CmsPageTableMap;
use TheliaCMS\Page\Admin\CmsPageWriter;
use TheliaCMS\Page\Field\PageFields;
use TheliaCMS\Page\PageTypeWriter;
use TheliaCMS\Security\CmsResources;
use TheliaCMS\Settings\CmsSettings;
use TheliaCMS\Tests\Integration\Security\AdminScreenTestCase;
use TheliaCMS\TheliaCMS;

/**
 * The actions of the settings screen of a page: one bar holding Save, Save and
 * publish, Publish and Unpublish, shown to whoever may use them, and a "Save
 * and publish" that writes what is typed and then puts the saved content online.
 */
final class PageActionsScreenTest extends AdminScreenTestCase
{
    private const string TYPE = 'n29-recipe';

    private const string BROKEN = '<h2>Un</h2><h4>Saut</h4>';

    public function testASavedPageHoldsTheFourActionsInOneNamedGroup(): void
    {
        $page = $this->createPage('Actions', published: false);

        $group = $this->screen($this->editUrl($page))->filter('[role="group"][data-testid="cms-page-actions"]');

        self::assertSame(1, $group->count());
        self::assertSame($this->say('Page actions'), $group->attr('aria-label'), 'The group is named.');
        self::assertSame(
            ['cms-page-save', 'cms-page-save-and-publish', 'cms-page-publish', 'cms-page-unpublish'],
            $group->filter('button')->each(static fn (Crawler $button): ?string => $button->attr('data-testid')),
            'Save, Save and publish, Publish, Unpublish, in this order.',
        );
        self::assertSame(
            [$this->say('Save'), $this->say('Save and publish'), $this->say('Publish'), $this->say('Unpublish')],
            $group->filter('button')->each(static fn (Crawler $button): string => trim($button->text())),
        );
    }

    public function testANewPageHoldsOnlySave(): void
    {
        $group = $this->screen('/admin/cms/pages/new')->filter('[data-testid="cms-page-actions"]');

        self::assertSame(['cms-page-save'], $group->filter('button')->each(static fn (Crawler $button): ?string => $button->attr('data-testid')));
    }

    public function testTheModelOfATypeHoldsOnlySave(): void
    {
        $model = $this->model();

        $group = $this->screen($this->editUrl($model))->filter('[data-testid="cms-page-actions"]');

        self::assertSame(['cms-page-save'], $group->filter('button')->each(static fn (Crawler $button): ?string => $button->attr('data-testid')));
    }

    public function testAProfileThatMayOnlyLookSeesNoAction(): void
    {
        $page = $this->createPage('Lecture seule', published: false);
        $this->logInAs((new FixtureFactory($this->getPropelConnection()))->restrictedAdmin([
            CmsResources::PAGE => [AccessManager::VIEW],
        ]));

        $screen = $this->screen($this->editUrl($page));

        self::assertSame(0, $screen->filter('[data-testid="cms-page-actions"] button')->count());
        self::assertSame(0, $screen->filter('form[name="cms_page"] button[type="submit"]')->count());
    }

    public function testTheButtonsPostWhatTheyPromise(): void
    {
        $page = $this->createPage('Boutons', published: false);
        $screen = $this->screen($this->editUrl($page));

        $publish = $screen->filter('[data-testid="cms-page-publish"]')->form();
        $unpublish = $screen->filter('[data-testid="cms-page-unpublish"]')->form();

        self::assertStringEndsWith(\sprintf('/admin/cms/pages/%d/publish', $page->getId()), (string) parse_url($publish->getUri(), \PHP_URL_PATH));
        self::assertStringEndsWith(\sprintf('/admin/cms/pages/%d/unpublish', $page->getId()), (string) parse_url($unpublish->getUri(), \PHP_URL_PATH));
        self::assertNotSame('', (string) ($publish->getPhpValues()['_token'] ?? ''), 'The click on Publish sends its token.');
        self::assertSame($this->token($screen), $unpublish->getPhpValues()['_token'] ?? null);
    }

    public function testSaveAndPublishSavesTheRequiredFieldAndPutsThePageOnline(): void
    {
        $page = $this->pageOfTheType();
        $screen = $this->screen($this->editUrl($page));

        $form = $screen->filter('[data-testid="cms-page-save-and-publish"]')->form();
        $form['cms_page[fields][servings]'] = '4';

        self::assertSame(302, $this->send('POST', $form->getUri(), $form->getPhpValues()));

        self::assertNotNull($this->content($page)->getPublishedAt(), 'The page went online.');
        self::assertSame(4, (int) ($this->storedFields($page)['servings'] ?? 0), 'The field was saved first.');
        self::assertSame(1, $this->revisions($page), 'One click, one revision.');

        $after = $this->screen($this->editUrl($page));
        self::assertContains($this->say('Settings saved.'), $this->flashes($after, 'success'));
        self::assertContains($this->say('The page is online.'), $this->flashes($after, 'success'));
    }

    public function testThePlainPublishLosesTheFieldTypedInTheForm(): void
    {
        $page = $this->pageOfTheType();
        $screen = $this->screen($this->editUrl($page));

        // The typed value is not part of what this click sends: this is today's behaviour, pinned.
        $form = $screen->filter('[data-testid="cms-page-publish"]')->form();

        self::assertSame(302, $this->send('POST', $form->getUri(), $form->getPhpValues()));

        self::assertNull($this->content($page)->getPublishedAt());
        $after = $this->screen($this->editUrl($page));
        self::assertGreaterThan(0, $after->filter('[data-testid="bo-flash-danger"]')->count(), 'The refusal is told.');
        self::assertSame([], $this->flashes($after, 'success'), 'Nothing was saved or published.');
    }

    public function testSaveAndPublishKeepsTheSettingsWhenTheHeadingsRefuseTheContentInBlockMode(): void
    {
        $page = $this->createPage('Titres cassés', published: false);
        $this->writer()->saveContent($page, $this->locale(), Document::richText(self::BROKEN));

        $this->withHeadingMode('block', function () use ($page): void {
            $form = $this->screen($this->editUrl($page))->filter('[data-testid="cms-page-save-and-publish"]')->form();
            $form['cms_page[title]'] = 'Titre corrigé';

            self::assertSame(302, $this->send('POST', $form->getUri(), $form->getPhpValues()));
        });

        self::assertSame('Titre corrigé', $this->reloaded($page)->setLocale($this->locale())->getTitle(), 'The settings were saved.');
        self::assertNull($this->content($page)->getPublishedAt(), 'The page was not published.');
        self::assertSame(0, $this->revisions($page), 'No revision was taken.');

        $after = $this->screen($this->editUrl($page));
        self::assertSame([$this->say('Settings saved.')], $this->flashes($after, 'success'));
        self::assertContains($this->say('The page was not published: fix the heading structure first.'), $this->flashes($after, 'danger'));
        self::assertGreaterThanOrEqual(2, \count($this->flashes($after, 'danger')), 'The problems come with the sentence.');
    }

    public function testSaveAndPublishPublishesInWarnModeAndWarnsOnceForEachProblem(): void
    {
        $page = $this->createPage('Titres cassés', published: false);
        $this->writer()->saveContent($page, $this->locale(), Document::richText(self::BROKEN));

        $this->withHeadingMode('warn', function () use ($page): void {
            $form = $this->screen($this->editUrl($page))->filter('[data-testid="cms-page-save-and-publish"]')->form();

            self::assertSame(302, $this->send('POST', $form->getUri(), $form->getPhpValues()));
        });

        self::assertNotNull($this->content($page)->getPublishedAt());

        $after = $this->screen($this->editUrl($page));
        self::assertCount(1, $after->filter('[data-testid="bo-flash-warning"]'));
        self::assertContains($this->say('Settings saved.'), $this->flashes($after, 'success'));
        self::assertSame([], $this->flashes($after, 'danger'));
    }

    public function testSaveAndPublishOfAnInvalidFormSavesAndPublishesNothing(): void
    {
        $page = $this->createPage('Avant', published: false);

        $form = $this->screen($this->editUrl($page))->filter('[data-testid="cms-page-save-and-publish"]')->form();
        $form['cms_page[title]'] = '';

        $this->client->request('POST', $form->getUri(), $form->getPhpValues(), [], self::SAME_SITE);

        self::assertSame(200, $this->client->getResponse()->getStatusCode(), 'The form is shown again with its errors.');
        self::assertSame('Avant', $this->reloaded($page)->setLocale($this->locale())->getTitle());
        self::assertNull($this->content($page)->getPublishedAt());
        self::assertSame(0, $this->revisions($page));

        self::assertSame([], $this->flashes($this->screen($this->editUrl($page)), 'success'));
    }

    public function testAHiddenPageIsPublishedButNotSaidToBeOnline(): void
    {
        $page = $this->createPage('Cachée', published: false);

        $form = $this->screen($this->editUrl($page))->filter('[data-testid="cms-page-save-and-publish"]')->form();
        $form['cms_page[visible]'] = '0';

        self::assertSame(302, $this->send('POST', $form->getUri(), $form->getPhpValues()));

        self::assertNotNull($this->content($page)->getPublishedAt());
        self::assertSame(0, $this->reloaded($page)->getVisible());

        $success = $this->flashes($this->screen($this->editUrl($page)), 'success');
        self::assertContains($this->say('The content is published, but the page is hidden: show it to put it online.'), $success);
        self::assertNotContains($this->say('The page is online.'), $success);
    }

    public function testTheSaveOnlyClickSaysTheSettingsAreSaved(): void
    {
        $page = $this->createPage('Enregistrer', published: false);

        $form = $this->screen($this->editUrl($page))->filter('[data-testid="cms-page-save"]')->form();
        $form['cms_page[title]'] = 'Enregistrée';

        self::assertSame(302, $this->send('POST', $form->getUri(), $form->getPhpValues()));

        self::assertSame('Enregistrée', $this->reloaded($page)->setLocale($this->locale())->getTitle());
        self::assertNull($this->content($page)->getPublishedAt(), 'Saving does not publish.');
        self::assertSame([$this->say('Settings saved.')], $this->flashes($this->screen($this->editUrl($page)), 'success'));
    }

    public function testThePlainPublishOfTheSettingsScreenSaysTheTruth(): void
    {
        $page = $this->createPage('Publier', published: false);
        $form = $this->screen($this->editUrl($page))->filter('[data-testid="cms-page-publish"]')->form();

        self::assertSame(302, $this->send('POST', $form->getUri(), $form->getPhpValues()));

        self::assertContains($this->say('The page is online.'), $this->flashes($this->screen($this->editUrl($page)), 'success'));
    }

    private function editUrl(CmsPage $page): string
    {
        return \sprintf('/admin/cms/pages/%d', $page->getId());
    }

    private function token(Crawler $screen): string
    {
        return (string) $screen->filter('form[name="cms_page"] input[name="_token"]')->attr('value');
    }

    private function say(string $message): string
    {
        $translator = $this->getService(TranslatorInterface::class);

        return $translator->trans($message, [], TheliaCMS::DOMAIN_NAME);
    }

    /**
     * @return list<string>
     */
    private function flashes(Crawler $screen, string $type): array
    {
        return $screen->filter(\sprintf('[data-testid="bo-flash-%s"]', $type))->each(static fn (Crawler $flash): string => trim($flash->text()));
    }

    private function pageOfTheType(): CmsPage
    {
        $types = $this->getService(PageTypeWriter::class);
        $types->add(self::TYPE);
        $types->saveField(self::TYPE, null, $this->locale(), ['code' => 'servings', 'kind' => 'integer', 'label' => 'Personnes', 'required' => true, 'min' => 1]);

        $page = $this->createPage('Recette', published: false);
        $page->setPageType(self::TYPE)->save();

        return $page;
    }

    private function model(): CmsPage
    {
        $types = $this->getService(PageTypeWriter::class);
        $types->add(self::TYPE);

        return $this->writer()->createModel(self::TYPE, $this->locale(), 'Modèle');
    }

    /**
     * @return array<string, mixed>
     */
    private function storedFields(CmsPage $page): array
    {
        $fields = $this->getService(PageFields::class);

        return $fields->valuesOf($this->reloaded($page), $this->locale());
    }

    private function content(CmsPage $page): CmsPageContent
    {
        $content = CmsPageContentQuery::create()->filterByPageId($page->getId())->filterByLocale($this->locale())->findOne();
        self::assertNotNull($content);

        return $content;
    }

    private function revisions(CmsPage $page): int
    {
        return CmsPageRevisionQuery::create()->filterByPageId($page->getId())->filterByLocale($this->locale())->count();
    }

    private function reloaded(CmsPage $page): CmsPage
    {
        CmsPageTableMap::clearInstancePool();
        $reloaded = CmsPageQuery::create()->findPk($page->getId());
        self::assertInstanceOf(CmsPage::class, $reloaded);

        return $reloaded;
    }

    private function writer(): CmsPageWriter
    {
        $writer = $this->getService(CmsPageWriter::class);

        return $writer;
    }

    private function withHeadingMode(string $mode, callable $run): void
    {
        $previous = TheliaCMS::getConfigValue(CmsSettings::HEADING_CHECK_MODE, 'warn');
        TheliaCMS::setConfigValue(CmsSettings::HEADING_CHECK_MODE, $mode);

        try {
            $run();
        } finally {
            TheliaCMS::setConfigValue(CmsSettings::HEADING_CHECK_MODE, (string) $previous);
        }
    }
}
