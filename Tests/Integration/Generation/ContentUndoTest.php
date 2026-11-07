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
use Thelia\Core\Security\AccessManager;
use Thelia\Domain\Ai\AiGatewayInterface;
use Thelia\Model\Content;
use Thelia\Model\Lang;
use Thelia\Test\FixtureFactory;
use TheliaCMS\Catalogue\CatalogueItemType;
use TheliaCMS\Content\Document;
use TheliaCMS\Content\Editing\ContentKey;
use TheliaCMS\Content\Editing\ContentSteps;
use TheliaCMS\Content\Editing\ContentWriter;
use TheliaCMS\Content\Section;
use TheliaCMS\Model\CmsContentStepQuery;
use TheliaCMS\Model\CmsItemContentQuery;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Model\CmsPageContentQuery;
use TheliaCMS\Model\CmsPageQuery;
use TheliaCMS\Model\Map\CmsItemContentTableMap;
use TheliaCMS\Model\Map\CmsPageContentTableMap;
use TheliaCMS\Page\Admin\CmsPageWriter;
use TheliaCMS\Page\TrashPurger;
use TheliaCMS\Security\CmsResources;
use TheliaCMS\Tests\Integration\BuildsItemTargets;
use TheliaCMS\Tests\Integration\Security\AdminScreenTestCase;
use TheliaCMS\Tests\Support\FakeAiGateway;

/**
 * Undo and Redo on the content screen: each change made there, by hand or by
 * the generator, is put back and played again, its provenance with it, and
 * only on the draft it left.
 */
final class ContentUndoTest extends AdminScreenTestCase
{
    use BuildsItemTargets;

    private FakeAiGateway $api;

    protected function setUp(): void
    {
        parent::setUp();

        $this->api = new FakeAiGateway();
        static::getContainer()->set(AiGatewayInterface::class, $this->api);
    }

    public function testASectionEditedByHandIsUndoneThenRedone(): void
    {
        $page = $this->pageWith('<h2>Avant</h2><p>Le texte.</p>');
        $url = $this->url($page);

        $screen = $this->screen($url);
        self::assertNotNull($this->button($screen, 'undo')->attr('disabled'), 'Nothing done on the screen yet.');
        self::assertNotNull($this->button($screen, 'redo')->attr('disabled'));

        $this->edit($page, '<h2>Après</h2><p>Le texte.</p>');

        $screen = $this->screen($url);
        self::assertNull($this->button($screen, 'undo')->attr('disabled'));
        self::assertStringContainsString('intro', (string) $this->button($screen, 'undo')->attr('title'), 'The button names the section of the step.');
        self::assertSame('intro', $screen->filter('[data-testid="cms-content-undo"]')->attr('data-cms-step-section'), 'The screen keeps that section in view.');

        self::assertSame(302, $this->play($screen, 'undo'));
        self::assertStringContainsString('Avant', $this->draft($page));

        $screen = $this->screen($url);
        self::assertSame(1, $screen->filter('[data-testid="cms-builder-flash-success"]')->count());
        self::assertNotNull($this->button($screen, 'undo')->attr('disabled'), 'The only step is undone.');
        self::assertNull($this->button($screen, 'redo')->attr('disabled'));

        self::assertSame(302, $this->play($screen, 'redo'));
        self::assertStringContainsString('Après', $this->draft($page));
        self::assertNotNull($this->button($this->screen($url), 'redo')->attr('disabled'), 'Nothing left to redo.');
    }

    public function testAGenerationIsUndoneWithItsMarkAndRedoneWithoutAnotherCall(): void
    {
        $page = $this->pageWith('<h2>Avant</h2><p>Écrit à la main.</p>');
        $url = $this->url($page);

        $this->api->answer(FakeAiGateway::response(json_encode(['version' => 1, 'sections' => [
            ['id' => 'intro', 'component' => 'cms:richtext', 'props' => ['html' => '<h2>Après</h2><p>Écrit par le générateur.</p>']],
        ]], \JSON_THROW_ON_ERROR), provenance: FakeAiGateway::provenance()));
        $form = $this->screen($url)->filter('[data-testid="cms-content-brief"] form')->form();
        $form['cms_brief[text]'] = 'Une page qui présente nos services.';
        self::assertSame(302, $this->send('POST', $form->getUri(), $form->getPhpValues()));
        self::assertNotNull($this->content($page)?->getDraftProvenance(), 'The answer marks the draft as the model’s work.');

        self::assertSame(302, $this->play($this->screen($url), 'undo'));
        self::assertStringContainsString('Écrit à la main.', $this->draft($page));
        self::assertNull($this->content($page)?->getDraftProvenance(), 'The draft written by hand comes back without the mark.');

        self::assertSame(302, $this->play($this->screen($url), 'redo'));
        self::assertStringContainsString('Écrit par le générateur.', $this->draft($page));
        self::assertNotNull($this->content($page)?->getDraftProvenance(), 'The mark comes back with the answer.');
        self::assertCount(1, $this->api->requests, 'Redo plays the answer again without asking the generator.');
    }

    public function testUndoIsRefusedWhenTheDraftChangedElsewhere(): void
    {
        $page = $this->pageWith('<h2>Avant</h2><p>Le texte.</p>');
        $this->edit($page, '<h2>Après</h2><p>Le texte.</p>');
        $screen = $this->screen($this->url($page));

        // An import, another tab, the command line: nothing the steps know of.
        $this->getService(CmsPageWriter::class)->saveContent($page, $this->locale(), Document::richText('<h2>Importé</h2><p>Ailleurs.</p>'));

        self::assertSame(302, $this->play($screen, 'undo'));
        self::assertStringContainsString('Importé', $this->draft($page), 'Nothing is written over the draft changed since.');
        self::assertSame(1, $this->screen($this->url($page))->filter('[data-testid="cms-builder-flash-danger"]')->count());
    }

    public function testANewChangeDropsWhatRedoCouldPlay(): void
    {
        $page = $this->pageWith('<h2>Un</h2><p>Le texte.</p>');
        $this->edit($page, '<h2>Deux</h2><p>Le texte.</p>');
        self::assertSame(302, $this->play($this->screen($this->url($page)), 'undo'));

        $this->edit($page, '<h2>Trois</h2><p>Le texte.</p>');

        self::assertNotNull($this->button($this->screen($this->url($page)), 'redo')->attr('disabled'));
        self::assertSame(0, CmsContentStepQuery::create()->filterByContentId($page->getId())->filterByUndone(1)->count());
        self::assertSame(302, $this->play($this->screen($this->url($page)), 'undo'));
        self::assertStringContainsString('Un', $this->draft($page), 'Undo goes back over the new change to the draft before it.');
        self::assertStringNotContainsString('Trois', $this->draft($page));
    }

    public function testOnlyTheLatestStepsAreKeptAndNoneOutsideTheScreen(): void
    {
        $page = $this->pageWith('<h2>Zéro</h2><p>Le texte.</p>');
        $writer = $this->getService(CmsPageWriter::class);
        $target = $this->getService(\TheliaCMS\Catalogue\Admin\ContentTargets::class)->forPage($page, $this->locale());
        $steps = $this->getService(ContentSteps::class);

        for ($index = 1; $index <= ContentSteps::KEPT + 2; ++$index) {
            $steps->record($target, 'apply', 'intro', fn () => $writer->saveContent($page, $this->locale(), Document::richText(\sprintf('<h2>Pas %d</h2><p>Le texte.</p>', $index))));
        }

        self::assertSame(ContentSteps::KEPT, CmsContentStepQuery::create()->filterByContentKind(ContentKey::PAGE)->filterByContentId($page->getId())->count());

        $writer->saveContent($page, $this->locale(), Document::richText('<h2>Import</h2><p>Le texte.</p>'));
        self::assertSame(ContentSteps::KEPT, CmsContentStepQuery::create()->filterByContentId($page->getId())->count(), 'A write outside the screen keeps no step.');
    }

    public function testACatalogueItemUndoesLikeAPage(): void
    {
        $factory = new FixtureFactory($this->getPropelConnection());
        $content = $factory->content($factory->folder(), ['title' => 'Notre méthode', 'locale' => $this->locale()]);
        $this->getService(ContentWriter::class)->saveContent($this->itemTarget(CatalogueItemType::Content, (int) $content->getId(), $this->locale()), new Document([
            new Section('intro', 'cms:richtext', ['html' => '<h2>Méthode</h2><p>Le texte.</p>']),
            new Section('faq', 'cms:richtext', ['html' => '<h2>Questions</h2><p>Combien ?</p>']),
        ]));
        $url = $this->itemUrl($content);

        $remove = $this->screen($url)->filter('[data-testid="cms-section-remove-faq"]')->form();
        self::assertSame(302, $this->send('POST', $remove->getUri(), $remove->getPhpValues()));
        self::assertStringNotContainsString('Combien', $this->itemDraft($content));

        self::assertSame(302, $this->play($this->screen($url), 'undo'));
        self::assertStringContainsString('Combien', $this->itemDraft($content), 'The section removed is back.');
        self::assertSame(1, CmsContentStepQuery::create()->filterByContentKind(CatalogueItemType::Content->value)->filterByContentId($content->getId())->count());
    }

    public function testUndoTakesTheRightToChangePagesAndNotTheGenerator(): void
    {
        $page = $this->pageWith('<h2>Avant</h2><p>Le texte.</p>');
        $this->edit($page, '<h2>Après</h2><p>Le texte.</p>');
        $token = $this->tokenOfTheScripts($this->screen($this->url($page)));

        $this->logInAs((new FixtureFactory($this->getPropelConnection()))->restrictedAdmin([CmsResources::PAGE => [AccessManager::VIEW]]));
        self::assertSame(403, $this->send('POST', $this->stepUrl($page, 'undo'), ['_token' => $token]));
        self::assertStringContainsString('Après', $this->draft($page));

        $this->logInAs((new FixtureFactory($this->getPropelConnection()))->restrictedAdmin([CmsResources::PAGE => [AccessManager::VIEW, AccessManager::UPDATE]]));
        $screen = $this->screen($this->url($page));
        self::assertSame(302, $this->play($screen, 'undo'), 'No right to generate needed.');
        self::assertStringContainsString('Avant', $this->draft($page));
    }

    public function testThePurgeOfAPageDeletesItsSteps(): void
    {
        $page = $this->pageWith('<h2>Avant</h2><p>Le texte.</p>');
        $this->edit($page, '<h2>Après</h2><p>Le texte.</p>');
        self::assertSame(1, CmsContentStepQuery::create()->filterByContentId($page->getId())->filterByContentKind(ContentKey::PAGE)->count());

        CmsPageQuery::create()->findPk($page->getId())?->setDeletedAt(new \DateTimeImmutable('-10 years'))->save();
        $this->getService(TrashPurger::class)->purge();

        self::assertSame(0, CmsContentStepQuery::create()->filterByContentId($page->getId())->filterByContentKind(ContentKey::PAGE)->count());
    }

    private function pageWith(string $html): CmsPage
    {
        $page = $this->createPage('Undo', published: false);
        // The parser of Thelia sets `app.request` at the first render of the process.
        $this->client->request('GET', '/admin/cms/pages');
        $this->getService(CmsPageWriter::class)->saveContent($page, $this->locale(), new Document([new Section('intro', 'cms:richtext', ['html' => $html])]));

        return $page;
    }

    private function edit(CmsPage $page, string $html): void
    {
        $form = $this->screen($this->url($page))->filter('[data-testid="cms-section-edit-intro"] form[data-cms-section-form]')->form();
        $form['cms_section_intro[html]'] = $html;

        self::assertSame(302, $this->send('POST', $form->getUri(), $form->getPhpValues()));
    }

    private function button(Crawler $screen, string $move): Crawler
    {
        return $screen->filter(\sprintf('[data-testid="cms-content-%s"] button[type=submit]', $move));
    }

    private function play(Crawler $screen, string $move): int
    {
        $form = $screen->filter(\sprintf('[data-testid="cms-content-%s"]', $move))->form();

        return $this->send('POST', $form->getUri(), $form->getPhpValues());
    }

    private function content(CmsPage $page): ?\TheliaCMS\Model\CmsPageContent
    {
        // Read from the database, not from what the test process kept of it.
        CmsPageContentTableMap::clearInstancePool();

        return CmsPageContentQuery::create()->filterByPageId($page->getId())->filterByLocale($this->locale())->findOne();
    }

    private function draft(CmsPage $page): string
    {
        return (string) $this->content($page)?->getDraftDocument();
    }

    private function itemDraft(Content $content): string
    {
        CmsItemContentTableMap::clearInstancePool();

        return (string) CmsItemContentQuery::create()->filterByItemType(CatalogueItemType::Content->value)->filterByItemId($content->getId())->filterByLocale($this->locale())->findOne()?->getDraftDocument();
    }

    private function url(CmsPage $page): string
    {
        return \sprintf('/admin/cms/pages/%d/content?edit_language_id=%d', $page->getId(), Lang::getDefaultLanguage()->getId());
    }

    private function stepUrl(CmsPage $page, string $move): string
    {
        return \sprintf('/admin/cms/pages/%d/content/%s', $page->getId(), $move);
    }

    private function itemUrl(Content $content): string
    {
        return \sprintf('/admin/cms/catalogue/content/%d/content?edit_language_id=%d', $content->getId(), Lang::getDefaultLanguage()->getId());
    }
}
