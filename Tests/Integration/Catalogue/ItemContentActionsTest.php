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

namespace TheliaCMS\Tests\Integration\Catalogue;

use Propel\Runtime\ActiveQuery\Criteria;
use Thelia\Domain\Ai\AiGatewayInterface;
use Thelia\Model\Content;
use Thelia\Model\Lang;
use Thelia\Model\LangQuery;
use Thelia\Test\FixtureFactory;
use TheliaCMS\Catalogue\Admin\ItemContentWriter;
use TheliaCMS\Catalogue\CatalogueItemType;
use TheliaCMS\Content\Document;
use TheliaCMS\Content\Section;
use TheliaCMS\Model\CmsItemContent;
use TheliaCMS\Model\CmsItemContentQuery;
use TheliaCMS\Model\CmsPageGenerationQuery;
use TheliaCMS\Settings\CmsSettings;
use TheliaCMS\Tests\Integration\Security\AdminScreenTestCase;
use TheliaCMS\Tests\Support\FakeAiGateway;
use TheliaCMS\TheliaCMS;

/**
 * What the content screen of a catalogue item does with the document, one
 * action at a time: the changes by hand, the three ways to ask the
 * generator, the publication and its heading warnings. The page screen has
 * its own tests for the same actions (SectionEditScreenTest,
 * ContentScreenTest): the two must answer alike.
 */
final class ItemContentActionsTest extends AdminScreenTestCase
{
    private FakeAiGateway $api;

    protected function setUp(): void
    {
        parent::setUp();

        $this->api = new FakeAiGateway();
        static::getContainer()->set(AiGatewayInterface::class, $this->api);
    }

    public function testASectionIsEditedByHand(): void
    {
        $content = $this->contentWith([
            new Section('intro', 'cms:richtext', ['html' => '<h2>Avant</h2><p>Un texte.</p>']),
            new Section('faq', 'cms:richtext', ['html' => '<h2>Questions</h2><p>Sur devis.</p>']),
        ]);

        $form = $this->screen($this->url($content))->filter('[data-testid="cms-section-edit-intro"] form[data-cms-section-form]')->form();
        $form['cms_section_intro[html]'] = '<h2>Après</h2><p>Un texte <strong>corrigé</strong>.</p>';

        self::assertSame(302, $this->send('POST', $form->getUri(), $form->getPhpValues()));

        $row = $this->row($content);
        $document = Document::fromJson($row?->getDraftDocument());
        self::assertSame('<h2>Après</h2><p>Un texte <strong>corrigé</strong>.</p>', $document?->section('intro')?->props['html']);
        self::assertSame('<h2>Questions</h2><p>Sur devis.</p>', $document->section('faq')?->props['html'], 'The other sections are kept.');
        self::assertStringContainsString('Après', (string) $row?->getDraftHtml(), 'The draft is rendered again.');
        self::assertNull($row?->getPublishedHtml());

        $ledger = $this->ledger($content)->findOne();
        self::assertSame('manual', $ledger?->getKind());
        self::assertSame('done', $ledger->getStatus());

        self::assertSame(1, $this->screen($this->url($content))->filter('[data-testid="cms-builder-flash-success"]')->count());
    }

    public function testASectionIsRemovedByHand(): void
    {
        $content = $this->contentWith([
            new Section('intro', 'cms:richtext', ['html' => '<h2>Avant</h2><p>Un texte.</p>']),
            new Section('faq', 'cms:richtext', ['html' => '<h2>Questions</h2><p>Sur devis.</p>']),
        ]);

        $form = $this->screen($this->url($content))->filter('[data-testid="cms-section-remove-faq"]')->form();
        self::assertSame(302, $this->send('POST', $form->getUri(), $form->getPhpValues()));

        $row = $this->row($content);
        self::assertSame(['intro'], $this->sectionIds($row?->getDraftDocument()));
        self::assertStringNotContainsString('Sur devis.', (string) $row?->getDraftHtml());
        self::assertStringContainsString('removed', (string) $this->ledger($content)->findOne()?->getPrompt());
        self::assertSame(1, $this->screen($this->url($content))->filter('[data-testid="cms-builder-flash-success"]')->count());

        self::assertSame(302, $this->send('POST', $form->getUri(), $form->getPhpValues()), 'Removing it again is refused, not an error.');
        self::assertSame(1, $this->screen($this->url($content))->filter('[data-testid="cms-builder-flash-danger"]')->count());
    }

    public function testASectionIsMovedByHand(): void
    {
        $content = $this->contentWith([
            new Section('intro', 'cms:richtext', ['html' => '<h2>Avant</h2>']),
            new Section('services', 'cms:richtext', ['html' => '<h2>Services</h2>']),
            new Section('faq', 'cms:richtext', ['html' => '<h2>Questions</h2>']),
        ]);

        $form = $this->screen($this->url($content))->filter('[data-testid="cms-section-move-up-faq"]')->form();
        self::assertSame(302, $this->send('POST', $form->getUri(), $form->getPhpValues()));

        $row = $this->row($content);
        self::assertSame(['intro', 'faq', 'services'], $this->sectionIds($row?->getDraftDocument()));
        self::assertStringContainsString('moved up', (string) $this->ledger($content)->findOne()?->getPrompt());
        self::assertNull($row?->getPublishedHtml(), 'What is online does not move until the draft is published.');
        self::assertSame(1, $this->screen($this->url($content))->filter('[data-testid="cms-builder-flash-success"]')->count());
    }

    public function testAChangeAskedToTheGeneratorKeepsTheDraftItReplaces(): void
    {
        $content = $this->contentWith([new Section('content', 'cms:richtext', ['html' => '<h2>Avant</h2><p>Un texte.</p>'])]);
        $this->api->answer(FakeAiGateway::response(self::document([['id' => 'content', 'component' => 'cms:richtext', 'props' => ['html' => '<h2>Après</h2><p>Un texte.</p>']]])));

        $form = $this->screen($this->url($content))->filter('[data-testid="cms-content-change"] form')->form();
        $form['cms_change[text]'] = 'Écris Après à la place de Avant.';
        self::assertSame(302, $this->send('POST', $form->getUri(), $form->getPhpValues()));

        self::assertStringContainsString('<h2>Avant</h2><p>Un texte.</p>', $this->api->requests[0]->messages[0]->content, 'The document of the item is sent with the change.');
        self::assertStringContainsString('<h2>Après</h2>', (string) $this->row($content)?->getDraftHtml());
        self::assertSame('revision', $this->ledger($content)->findOne()?->getKind());
        self::assertCount(1, $this->screen($this->url($content))->filter('[data-testid="cms-content-previous-drafts"] form[data-cms-restore]'), 'The draft the change replaced is kept.');
    }

    public function testOneSectionIsWrittenAgainByItsOwnForm(): void
    {
        $content = $this->contentWith([
            new Section('intro', 'cms:richtext', ['html' => '<h2>Intro</h2><p>Un texte.</p>']),
            new Section('faq', 'cms:richtext', ['html' => '<h2>Questions</h2><p>Sur devis.</p>']),
        ]);
        $this->api->answer(FakeAiGateway::response(json_encode(['id' => 'faq', 'component' => 'cms:richtext', 'props' => ['html' => '<h2>Questions</h2><p>Gratuit.</p>']], \JSON_THROW_ON_ERROR)));

        $form = $this->screen($this->url($content))->filter('li[data-section="faq"] form[data-cms-generate]')->form();
        $form['cms_change_faq[text]'] = 'Dis que c’est gratuit.';
        self::assertSame(302, $this->send('POST', $form->getUri(), $form->getPhpValues()));

        $row = $this->row($content);
        $document = Document::fromJson($row?->getDraftDocument());
        self::assertSame(['intro', 'faq'], $this->sectionIds($row?->getDraftDocument()));
        self::assertSame('<h2>Intro</h2><p>Un texte.</p>', $document?->section('intro')?->props['html'], 'The other sections are untouched.');
        self::assertStringContainsString('Gratuit.', (string) $document->section('faq')?->props['html']);
        self::assertSame('section', $this->ledger($content)->findOne()?->getKind());
    }

    public function testALanguageWithoutADocumentIsWrittenFromAnotherOne(): void
    {
        $content = $this->contentWith([new Section('content', 'cms:richtext', ['html' => '<h2>Notre méthode</h2><p>Un texte.</p>'])]);
        $source = Lang::getDefaultLanguage();
        $target = LangQuery::create()->filterByActive(true)->filterById($source->getId(), Criteria::NOT_EQUAL)->orderById()->findOne();
        self::assertNotNull($target, 'The shop of the tests has a second active language.');
        $this->api->answer(FakeAiGateway::response(self::document([['id' => 'content', 'component' => 'cms:richtext', 'props' => ['html' => '<h2>Our method</h2><p>A text.</p>']]])));

        $screen = $this->screen(\sprintf('%s?edit_language_id=%d', $this->url($content), $target->getId()));
        self::assertSame(1, $screen->filter('[data-testid="cms-content-translate"]')->count());

        $form = $screen->filter('[data-testid="cms-content-translate"] form')->form();
        self::assertSame(302, $this->send('POST', $form->getUri(), $form->getPhpValues()));

        self::assertStringContainsString('<h2>Notre méthode</h2><p>Un texte.</p>', $this->api->requests[0]->messages[0]->content, 'The document of the other language is what the model writes from.');
        $written = CmsItemContentQuery::create()->filterByItemType('content')->filterByItemId($content->getId())->filterByLocale($target->getLocale())->findOne();
        self::assertStringContainsString('Our method', (string) $written?->getDraftHtml());
        self::assertStringContainsString('Notre méthode', (string) $this->row($content)?->getDraftHtml(), 'The source draft is untouched.');
        self::assertSame('translation', $this->ledger($content)->filterByLocale($target->getLocale())->findOne()?->getKind());
    }

    public function testPublishingWarnsAboutTheHeadingsWhenTheSiteOnlyWarns(): void
    {
        $content = $this->contentWith([new Section('content', 'cms:richtext', ['html' => '<h2>Un</h2><h4>Saut</h4>'])]);

        $this->withHeadingMode('warn', function () use ($content): void {
            $form = $this->screen($this->url($content))->filter('form[data-testid="cms-content-publish"]')->form();
            self::assertSame(302, $this->send('POST', $form->getUri(), $form->getPhpValues()));
        });

        self::assertNotNull($this->row($content)?->getPublishedAt(), 'The content went online.');

        $screen = $this->screen($this->url($content));
        self::assertGreaterThan(0, $screen->filter('[data-testid="cms-builder-flash-warning"]')->count(), 'The author is told what to fix.');
        self::assertSame(1, $screen->filter('[data-testid="cms-builder-flash-success"]')->count());
        self::assertSame(0, $screen->filter('[data-testid="cms-builder-flash-danger"]')->count());
    }

    public function testPublishingIsRefusedWithTheHeadingsWhenTheSiteBlocks(): void
    {
        $content = $this->contentWith([new Section('content', 'cms:richtext', ['html' => '<h2>Un</h2><h4>Saut</h4>'])]);

        $this->withHeadingMode('block', function () use ($content): void {
            $form = $this->screen($this->url($content))->filter('form[data-testid="cms-content-publish"]')->form();
            self::assertSame(302, $this->send('POST', $form->getUri(), $form->getPhpValues()));
        });

        self::assertNull($this->row($content)?->getPublishedAt(), 'Nothing went online.');

        $screen = $this->screen($this->url($content));
        self::assertGreaterThan(1, $screen->filter('[data-testid="cms-builder-flash-danger"]')->count(), 'The problems, then the refusal.');
        self::assertSame(0, $screen->filter('[data-testid="cms-builder-flash-success"]')->count());
    }

    public function testWhatHoldsNothingYetCannotBeChanged(): void
    {
        $content = $this->content();
        $base = $this->url($content);
        $token = $this->tokenOfTheScripts($this->screen($base));

        $posts = [
            'update' => ['cms_section_intro' => ['section' => 'intro', 'html' => '<h2>Texte</h2>']],
            'remove' => ['cms_section_intro' => ['section' => 'intro']],
            'move' => ['section' => 'intro', 'direction' => 'down'],
            'duplicate' => ['cms_section_intro' => ['section' => 'intro']],
            'revise' => ['cms_change' => ['text' => 'Plus court.']],
            'section' => ['cms_change_intro' => ['section' => 'intro', 'text' => 'Plus court.']],
        ];

        foreach ($posts as $action => $fields) {
            self::assertSame(302, $this->send('POST', $base.'/'.$action, ['_token' => $token, ...$fields]), $action);

            $screen = $this->screen($base);
            self::assertStringContainsString(
                'This content holds nothing to change yet: write it from a brief first.',
                $screen->filter('[data-testid="cms-builder-flash-danger"]')->text(),
                \sprintf('%s of an item without a draft says so.', $action),
            );
        }

        self::assertSame([], $this->api->requests, 'Nothing was asked of the generator.');
        self::assertNull($this->row($content), 'Nothing was written.');
    }

    private function content(): Content
    {
        $factory = new FixtureFactory($this->getPropelConnection());

        return $factory->content($factory->folder(), ['title' => 'Notre méthode', 'locale' => $this->locale()]);
    }

    /**
     * @param list<Section> $sections
     */
    private function contentWith(array $sections): Content
    {
        $content = $this->content();
        // A section rendered before any screen leaves the `app` global of Twig without a request.
        $this->client->request('GET', '/admin/cms/pages');
        $this->getService(ItemContentWriter::class)->saveContent(CatalogueItemType::Content, (int) $content->getId(), $this->locale(), new Document($sections));

        return $content;
    }

    private function url(Content $content): string
    {
        return \sprintf('/admin/cms/catalogue/content/%d/content', $content->getId());
    }

    private function row(Content $content): ?CmsItemContent
    {
        return CmsItemContentQuery::create()->filterByItemType('content')->filterByItemId($content->getId())->filterByLocale($this->locale())->findOne();
    }

    private function ledger(Content $content): CmsPageGenerationQuery
    {
        return CmsPageGenerationQuery::create()->filterByTargetType('content')->filterByTargetId($content->getId())->orderById(Criteria::DESC);
    }

    /**
     * @return list<string>
     */
    private function sectionIds(?string $json): array
    {
        return array_map(static fn (Section $section): string => $section->id, Document::fromJson($json)->sections);
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

    /**
     * @param list<array<string, mixed>> $sections
     */
    private static function document(array $sections): string
    {
        return json_encode(['version' => 1, 'sections' => $sections], \JSON_THROW_ON_ERROR);
    }
}
