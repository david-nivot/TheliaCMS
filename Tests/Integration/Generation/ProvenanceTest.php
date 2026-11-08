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

use Propel\Runtime\ActiveQuery\Criteria;
use Thelia\Core\Template\TemplateDefinition;
use Thelia\Core\Template\TemplateHelperInterface;
use Thelia\Domain\Ai\AiGatewayInterface;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Lang;
use TheliaCMS\Content\Document;
use TheliaCMS\Generation\GeneratorSettings;
use TheliaCMS\Model\CmsPageContent;
use TheliaCMS\Model\CmsPageContentQuery;
use TheliaCMS\Model\CmsPageGenerationQuery;
use TheliaCMS\Model\CmsPageRevisionQuery;
use TheliaCMS\Page\Admin\CmsPageWriter;
use TheliaCMS\Page\CmsPageRenderer;
use TheliaCMS\Page\PublishedPageRepository;
use TheliaCMS\Tests\Integration\Security\AdminScreenTestCase;
use TheliaCMS\Tests\Support\FakeAiGateway;

/**
 * The mark of a generated page (AI Act, article 50(2)): written with the
 * draft by the generation, copied by every step a draft goes through, and
 * printed by the template when the page is served. It is a column, not an
 * attribute of the HTML, because the sanitizer of the published HTML removes
 * every data-* attribute it does not know.
 */
final class ProvenanceTest extends AdminScreenTestCase
{
    private FakeAiGateway $api;

    protected function setUp(): void
    {
        parent::setUp();

        $this->api = new FakeAiGateway();
        static::getContainer()->set(AiGatewayInterface::class, $this->api);
        $this->useAnInstalledFrontTheme();
    }

    public function testAGeneratedPageCarriesTheMarkInItsPublicHtmlAndAHandWrittenOneDoesNot(): void
    {
        $handWritten = $this->createPage('Écrite à la main');
        $generated = $this->createPage('Écrite par le générateur', published: false);

        $this->generate($generated->getId(), withMark: true);
        $this->publish($generated->getId());

        $served = $this->served((int) $generated->getId());
        self::assertStringContainsString('data-ai-generated="true"', $served);
        self::assertStringContainsString('data-ai-usage="theliacms.page_generation"', $served);
        self::assertStringContainsString('data-ai-model="fake-model"', $served);
        self::assertStringContainsString('data-ai-generated-at="2026-10-28T10:00:00+00:00"', $served);
        self::assertStringNotContainsString('cms-page__ai-label', $served, 'The sentence for the visitor is off by default: the mark for machines is enough.');
        self::assertStringContainsString('Écrit par le générateur', $served);

        $stored = CmsPageContentQuery::create()->filterByPageId($generated->getId())->findOne();
        self::assertStringNotContainsString('data-ai-generated', (string) $stored?->getPublishedHtml(), 'The mark is not in the HTML: the sanitizer would have removed it.');

        self::assertStringNotContainsString('data-ai-generated', $this->served((int) $handWritten->getId()), 'A page a person wrote is not marked.');
        self::assertStringNotContainsString('cms-page__ai-label', $this->served((int) $handWritten->getId()));

        $row = CmsPageGenerationQuery::create()->filterByTargetId($generated->getId())->orderById('desc')->findOne();
        self::assertStringContainsString('"usage":"theliacms.page_generation"', (string) $row?->getProvenance(), 'The row of the ledger keeps the mark of its call.');
    }

    public function testASiteThatShowsTheSentenceGetsItUnderAGeneratedPageOnly(): void
    {
        $handWritten = $this->createPage('Écrite à la main');
        $generated = $this->createPage('Écrite par le générateur', published: false);
        $this->generate($generated->getId(), withMark: true);
        $this->publish($generated->getId());

        $settings = $this->getService(GeneratorSettings::class);
        $settings->saveAiLabel(true);

        try {
            self::assertStringContainsString('<p class="cms-page__ai-label">', $this->served((int) $generated->getId()), 'A sentence a visitor can read, after the article.');
            self::assertStringNotContainsString('cms-page__ai-label', $this->served((int) $handWritten->getId()), 'A page a person wrote has none.');
        } finally {
            $settings->saveAiLabel(false);
        }
    }

    public function testTheEditorTakesTheMarkOffAReviewedPage(): void
    {
        $page = $this->createPage('Relue', published: false);
        $this->generate($page->getId(), withMark: true);
        $this->publish($page->getId());
        self::assertStringContainsString('data-ai-generated="true"', $this->served((int) $page->getId()));
        $draftBefore = CmsPageRevisionQuery::create()->filterByPageId($page->getId())->filterByProvenance(null, Criteria::ISNOTNULL)->count();

        $url = \sprintf('/admin/cms/pages/%d?edit_language_id=%d', $page->getId(), Lang::getDefaultLanguage()->getId());
        $form = $this->screen($url)->filter('[data-testid="cms-page-remove-ai-mark"]')->form();
        self::assertSame(302, $this->send('POST', $form->getUri(), $form->getPhpValues()));

        $content = $this->content($page->getId());
        self::assertNull($content?->getDraftProvenance(), 'The draft is no longer marked.');
        self::assertNull($content?->getPublishedProvenance(), 'Nor what is online.');
        self::assertStringNotContainsString('data-ai-generated', $this->served((int) $page->getId()));
        self::assertSame($draftBefore, CmsPageRevisionQuery::create()->filterByPageId($page->getId())->filterByProvenance(null, Criteria::ISNOTNULL)->count(), 'The revisions keep theirs: putting one back brings its mark back.');
        self::assertCount(0, $this->screen($url)->filter('[data-testid="cms-page-remove-ai-mark"]'), 'Nothing left to remove, no button.');
        self::assertFalse($this->writer()->removeProvenance($page, $this->locale()));
    }

    public function testAPageWrittenByHandOffersNoMarkToRemove(): void
    {
        $page = $this->createPage('Écrite à la main');
        $url = \sprintf('/admin/cms/pages/%d?edit_language_id=%d', $page->getId(), Lang::getDefaultLanguage()->getId());

        self::assertCount(0, $this->screen($url)->filter('[data-testid="cms-page-remove-ai-mark"]'));
    }

    public function testAShopThatTurnedTheMarkOffGetsNoMark(): void
    {
        $page = $this->createPage('Sans marque', published: false);

        $this->generate($page->getId(), withMark: false);
        $this->publish($page->getId());

        self::assertStringNotContainsString('data-ai-generated', $this->served((int) $page->getId()), 'The core gave no mark: the module invents none.');
    }

    public function testAHumanEditOfAGeneratedDraftKeepsTheMark(): void
    {
        $page = $this->createPage('Retouchée', published: false);
        $this->generate($page->getId(), withMark: true);
        $marked = $this->content($page->getId())?->getDraftProvenance();
        self::assertNotNull($marked);

        $this->writer()->saveContent($page, $this->locale(), Document::richText('<h2>Retouchée</h2><p>Réécrite en entier par une personne.</p>'));

        self::assertSame($marked, $this->content($page->getId())?->getDraftProvenance(), 'A rewrite by hand does not take the mark away: nothing tells when the page stopped being the work of the model.');
    }

    public function testADraftPutBackBringsItsOwnMarkOrNone(): void
    {
        $page = $this->createPage('Restaurée', published: false);
        $this->writer()->saveContent($page, $this->locale(), Document::richText('<h2>Avant</h2><p>Écrit à la main.</p>'));
        $this->generate($page->getId(), withMark: true);
        $marked = $this->content($page->getId())?->getDraftProvenance();
        self::assertNotNull($marked, 'The generation wrote the mark of the call.');

        $handWritten = $this->writer()->previousDrafts($page, $this->locale());
        self::assertCount(1, $handWritten);
        self::assertNull(CmsPageRevisionQuery::create()->findPk($handWritten[0]['id'])?->getProvenance());

        $this->writer()->restoreDraft($page, $this->locale(), $handWritten[0]['id']);
        self::assertNull($this->content($page->getId())?->getDraftProvenance(), 'The hand-written draft is put back without the mark of the one that replaced it.');

        $generatedBefore = $this->writer()->previousDrafts($page, $this->locale())[0]['id'];
        self::assertSame($marked, CmsPageRevisionQuery::create()->findPk($generatedBefore)?->getProvenance(), 'The generated draft was kept with its mark.');
        $this->writer()->restoreDraft($page, $this->locale(), $generatedBefore);
        self::assertSame($marked, $this->content($page->getId())?->getDraftProvenance());
    }

    public function testThePublicationCopiesTheMarkAndTheUnpublicationTakesItDown(): void
    {
        $page = $this->createPage('Publiée', published: false);
        $this->generate($page->getId(), withMark: true);
        $draftMark = $this->content($page->getId())?->getDraftProvenance();
        self::assertNotNull($draftMark, 'The generation wrote the mark of the call.');

        self::assertNull($this->content($page->getId())?->getPublishedProvenance(), 'A draft is not online.');

        $this->writer()->publish($page, $this->locale());

        $content = $this->content($page->getId());
        self::assertSame($draftMark, $content?->getPublishedProvenance(), 'Published, the page carries the mark of its draft.');
        $publication = CmsPageRevisionQuery::create()->filterByPageId($page->getId())->filterByLabel(null, Criteria::ISNULL)->findOne();
        self::assertSame($draftMark, $publication?->getProvenance(), 'The revision taken at the publication keeps it.');

        $this->writer()->unpublish($page, $this->locale());
        self::assertNull($this->content($page->getId())?->getPublishedProvenance(), 'Nothing online, nothing marked.');
        self::assertSame($draftMark, $this->content($page->getId())?->getDraftProvenance(), 'The draft keeps its mark.');
    }

    public function testACopyOfAGeneratedPageIsMarkedToo(): void
    {
        $page = $this->createPage('Modèle généré', published: false);
        $this->generate($page->getId(), withMark: true);
        $mark = $this->content($page->getId())?->getDraftProvenance();
        self::assertNotNull($mark, 'The generation wrote the mark of the call.');

        $copy = $this->writer()->duplicate($page, $this->locale(), ' (copie)');

        self::assertSame($mark, $this->content($copy->getId())?->getDraftProvenance());
    }

    /**
     * Points the shop at a front theme it has: the seed names the one of a
     * stock install, which is not necessarily installed here.
     */
    private function useAnInstalledFrontTheme(): void
    {
        $helper = $this->getService(TemplateHelperInterface::class);

        if (is_dir($helper->getActiveFrontTemplate()->getAbsolutePath())) {
            return;
        }

        $installed = $helper->getList(TemplateDefinition::FRONT_OFFICE);

        if ([] === $installed) {
            self::markTestSkipped('The shop has no front theme installed, so no page can be rendered.');
        }

        ConfigQuery::write('active-front-template', $installed[0]->getName());
    }

    private function writer(): CmsPageWriter
    {
        return $this->getService(CmsPageWriter::class);
    }

    private function generate(int|string $pageId, bool $withMark): void
    {
        $this->api->answer(FakeAiGateway::response(
            json_encode(['version' => 1, 'sections' => [['id' => 'intro', 'component' => 'cms:richtext', 'props' => ['html' => '<h2>Écrit par le générateur</h2><p>Un texte du modèle.</p>']]]], \JSON_THROW_ON_ERROR),
            provenance: $withMark ? FakeAiGateway::provenance() : null,
        ));
        $url = \sprintf('/admin/cms/pages/%d/content?edit_language_id=%d', $pageId, Lang::getDefaultLanguage()->getId());
        $form = $this->screen($url)->filter('[data-testid="cms-content-brief"] form[data-cms-generate]')->form();
        $form['cms_brief[text]'] = 'Une page de présentation.';
        self::assertSame(302, $this->send('POST', $form->getUri(), $form->getPhpValues()));
    }

    private function publish(int|string $pageId): void
    {
        $url = \sprintf('/admin/cms/pages/%d/content?edit_language_id=%d', $pageId, Lang::getDefaultLanguage()->getId());
        $form = $this->screen($url)->filter('[data-testid="cms-content-publish"]')->form();
        self::assertSame(302, $this->send('POST', $form->getUri(), $form->getPhpValues()));
    }

    /**
     * The page as a visitor gets it, in the template of the active theme.
     */
    private function served(int $pageId): string
    {
        $page = $this->getService(PublishedPageRepository::class)->find($pageId, $this->locale());
        self::assertNotNull($page, 'The page is not online.');

        return $this->getService(CmsPageRenderer::class)->render($page);
    }

    private function content(int|string $pageId): ?CmsPageContent
    {
        return CmsPageContentQuery::create()->filterByPageId($pageId)->filterByLocale($this->locale())->findOne();
    }
}
