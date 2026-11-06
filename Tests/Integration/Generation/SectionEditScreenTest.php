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
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Model\Lang;
use Thelia\Test\FixtureFactory;
use TheliaCMS\Content\Document;
use TheliaCMS\Content\Section;
use TheliaCMS\Content\SectionAppearance;
use TheliaCMS\Content\SectionAvailability;
use TheliaCMS\Content\SectionCatalog;
use TheliaCMS\Content\Site\SiteComponent;
use TheliaCMS\Content\Site\SiteComponentRepository;
use TheliaCMS\Generation\Admin\SectionFormBuilder;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Model\CmsPageContentQuery;
use TheliaCMS\Model\CmsPageGenerationQuery;
use TheliaCMS\Page\Admin\CmsPageWriter;
use TheliaCMS\Page\Admin\PageDraft;
use TheliaCMS\Page\PageTypeWriter;
use TheliaCMS\Preview\PreviewLink;
use TheliaCMS\Security\CmsResources;
use TheliaCMS\Tests\Integration\Security\AdminScreenTestCase;
use TheliaCMS\Tests\Unit\Content\Site\PriceTable;
use TheliaCMS\TheliaCMS;

/**
 * A section changed by hand on the content screen: a form read from the
 * schema of its component, the document and the draft updated, a row of the
 * ledger, and the schema still the judge of what comes in.
 */
final class SectionEditScreenTest extends AdminScreenTestCase
{
    /** A component of the Flexy theme holding a list of products, opened to pages by the theme. */
    private const string LIST_COMPONENT = 'Layouts:CrossSelling:Base';

    public function testTheFormOfASectionFollowsTheSchemaOfItsComponent(): void
    {
        $page = $this->pageWith([
            new Section('intro', 'cms:richtext', ['html' => '<h1>Avant</h1><p>Un texte.</p>']),
            new Section('contact', 'Partial:cms-form', ['form' => 1]),
        ]);

        $screen = $this->screen($this->url($page));
        $intro = $screen->filter('[data-testid="cms-section-edit-intro"] form[data-cms-section-form]');

        self::assertSame(1, $intro->count());
        self::assertSame(1, $screen->filter('dialog#cms-section-edit-intro[data-testid="cms-section-edit-intro"] form[data-cms-section-form]')->count(), 'The form of a section is in a dialog.');
        self::assertSame('cms-section-edit-intro', $screen->filter('[data-testid="cms-section-edit-open-intro"]')->attr('commandfor'), 'Its button opens that dialog.');
        self::assertSame(1, $intro->filter('input[name="_token"]')->count());
        self::assertSame(1, $intro->filter('textarea[name="cms_section_intro[html]"].wysiwyg')->count(), 'A html prop is a textarea the editor module upgrades.');
        self::assertSame(SectionFormBuilder::HTML_TOOLBAR, $intro->filter('textarea[name="cms_section_intro[html]"]')->attr('data-tiptap-toolbar'), 'The editor offers a reduced toolbar for a free text.');
        self::assertSame('2,3,4', $intro->filter('textarea[name="cms_section_intro[html]"]')->attr('data-tiptap-headings'), 'The editor offers no h1.');
        self::assertStringNotContainsString('image', SectionFormBuilder::HTML_TOOLBAR);
        self::assertStringNotContainsString('source', SectionFormBuilder::HTML_TOOLBAR);

        $contact = $screen->filter('[data-testid="cms-section-edit-contact"] form[data-cms-section-form]');
        self::assertSame(1, $contact->filter('[name="cms_section_contact[form]"]')->count(), 'A reference is picked in its own field.');
    }

    public function testEditingASectionUpdatesTheDraftAndTheLedger(): void
    {
        $page = $this->pageWith([
            new Section('intro', 'cms:richtext', ['html' => '<h1>Avant</h1><p>Un texte.</p>']),
            new Section('contact', 'Partial:cms-form', ['form' => 1]),
        ]);

        $form = $this->screen($this->url($page))->filter('[data-testid="cms-section-edit-intro"] form[data-cms-section-form]')->form();
        $form['cms_section_intro[html]'] = '<h1>Après</h1><p>Un texte <strong>corrigé</strong>.</p>';

        self::assertSame(302, $this->send('POST', $form->getUri(), $form->getPhpValues()));

        $content = CmsPageContentQuery::create()->filterByPageId($page->getId())->filterByLocale($this->locale())->findOne();
        $document = Document::fromJson($content?->getDraftDocument());

        self::assertSame('<h2>Après</h2><p>Un texte <strong>corrigé</strong>.</p>', $document?->section('intro')?->props['html'], 'A h1 typed by hand is stored as a h2.');
        self::assertSame(1, $document?->section('contact')?->props['form'], 'The other sections are kept as they are.');
        self::assertStringContainsString('Après', (string) $content?->getDraftHtml(), 'The draft is rendered again.');
        self::assertNull($content?->getPublishedHtml());

        $row = CmsPageGenerationQuery::create()->filterByTargetId($page->getId())->orderById(\Propel\Runtime\ActiveQuery\Criteria::DESC)->findOne();
        self::assertSame('manual', $row?->getKind());
        self::assertSame('done', $row?->getStatus());
        self::assertSame('0.000000', $row?->getCostUsd());
        self::assertStringContainsString('<h2>Après</h2>', (string) $row?->getDocument(), 'The ledger keeps what the draft keeps.');

        $screen = $this->screen($this->url($page));
        self::assertSame(1, $screen->filter('[data-testid="cms-builder-flash-success"]')->count());
        self::assertStringContainsString('Après', $screen->filter('[data-testid="cms-content-sections"] li[data-section="intro"]')->text());
    }

    public function testTheAppearanceOfASectionIsSetByHand(): void
    {
        $page = $this->pageWith([new Section('intro', 'cms:richtext', ['html' => '<h1>Avant</h1>'], new SectionAppearance(background: '#f8d7d7'))]);

        $form = $this->screen($this->url($page))->filter('[data-testid="cms-section-edit-intro"] form[data-cms-section-form]')->form();
        self::assertSame('#f8d7d7', $form['cms_section_intro[cms_appearance][background]']->getValue(), 'The form shows the appearance the section has.');

        $form['cms_section_intro[cms_appearance][background]'] = '';
        $form['cms_section_intro[cms_appearance][text]'] = '#1C1F22';
        $form['cms_section_intro[cms_appearance][align]'] = 'center';

        self::assertSame(302, $this->send('POST', $form->getUri(), $form->getPhpValues()));

        $content = CmsPageContentQuery::create()->filterByPageId($page->getId())->filterByLocale($this->locale())->findOne();
        self::assertSame(['text' => '#1c1f22', 'align' => 'center'], Document::fromJson($content?->getDraftDocument())?->section('intro')?->appearance->toArray(), 'An emptied setting is dropped, the others are kept.');
        self::assertStringContainsString('style="color:#1c1f22;text-align:center"', (string) $content?->getDraftHtml());
    }

    public function testThePreciseRoomOfASectionComesBackInTheFormAfterTheSave(): void
    {
        $page = $this->pageWith([new Section('intro', 'cms:richtext', ['html' => '<h1>Avant</h1>'], new SectionAppearance(background: '#f8d7d7'))]);
        $field = static fn (string $name): string => \sprintf('cms_section_intro[cms_appearance][%s]', $name);

        $screen = $this->screen($this->url($page));
        self::assertCount(1, $screen->filter('[data-testid="cms-section-edit-intro"] [data-testid="cms-section-spacing"][data-cms-precise-room]:not([hidden])'), 'The precise fields are served shown; the script hides them until their button.');
        $form = $screen->filter('[data-testid="cms-section-edit-intro"] form[data-cms-section-form]')->form();
        $form[$field('padding')] = SectionFormBuilder::PRECISE_ROOM;
        $form[$field('padding_top')] = '0px';
        $form[$field('padding_bottom')] = '64PX';
        $form[$field('margin_top')] = '2rem';
        $form[$field('margin_bottom')] = '';
        $form[$field('padding_left')] = '1rem';
        $form[$field('margin_right')] = '48px';

        self::assertSame(302, $this->send('POST', $form->getUri(), $form->getPhpValues()));

        $content = CmsPageContentQuery::create()->filterByPageId($page->getId())->filterByLocale($this->locale())->findOne();
        self::assertSame(
            ['background' => '#f8d7d7', 'padding_top' => '0px', 'padding_bottom' => '64px', 'padding_left' => '1rem', 'margin_top' => '2rem', 'margin_right' => '48px'],
            Document::fromJson($content?->getDraftDocument())?->section('intro')?->appearance->toArray(),
        );
        self::assertStringContainsString('style="background-color:#f8d7d7;padding-top:0px;padding-bottom:64px;padding-left:max(min(1rem, 5vw), min((100vw - 48rem) * 1000, 1rem));margin-top:2rem;margin-right:max(min(48px, 5vw), min((100vw - 48rem) * 1000, 48px))"', (string) $content?->getDraftHtml());

        $screen = $this->screen($this->url($page));
        $form = $screen->filter('[data-testid="cms-section-edit-intro"] form[data-cms-section-form]')->form();
        self::assertSame(['0px', '64px', '1rem', '', '2rem', '', '', '48px'], array_map(static fn (string $side): string => (string) $form[$field($side)]->getValue(), ['padding_top', 'padding_bottom', 'padding_left', 'padding_right', 'margin_top', 'margin_bottom', 'margin_left', 'margin_right']), 'The form shows what was saved.');
        self::assertNotNull($screen->filter('[data-testid="cms-section-appearance"]')->attr('open'), 'A section with a precise room has its appearance open.');
        self::assertSame(SectionFormBuilder::PRECISE_ROOM, $form[$field('padding')]->getValue(), 'A precise room checks its button.');
    }

    public function testAPresetLeavesThePreciseFieldsOutOfTheSave(): void
    {
        $page = $this->pageWith([new Section('intro', 'cms:richtext', ['html' => '<h1>Avant</h1>'], new SectionAppearance(padding_top: '64px', margin_bottom: '2rem'))]);
        $field = static fn (string $name): string => \sprintf('cms_section_intro[cms_appearance][%s]', $name);

        $form = $this->screen($this->url($page))->filter('[data-testid="cms-section-edit-intro"] form[data-cms-section-form]')->form();
        $form[$field('padding')] = 'medium';

        self::assertSame(302, $this->send('POST', $form->getUri(), $form->getPhpValues()));

        $content = CmsPageContentQuery::create()->filterByPageId($page->getId())->filterByLocale($this->locale())->findOne();
        self::assertSame(['padding' => 'medium'], Document::fromJson($content?->getDraftDocument())?->section('intro')?->appearance->toArray(), 'The hidden fields, still filled, are not saved.');
    }

    public function testAPresetBesideAPreciseSideIsShownAsItsLength(): void
    {
        $page = $this->pageWith([new Section('intro', 'cms:richtext', ['html' => '<h1>Avant</h1>'], new SectionAppearance(padding: 'medium', padding_top: '64px'))]);
        $field = static fn (string $name): string => \sprintf('cms_section_intro[cms_appearance][%s]', $name);

        $form = $this->screen($this->url($page))->filter('[data-testid="cms-section-edit-intro"] form[data-cms-section-form]')->form();
        self::assertSame(SectionFormBuilder::PRECISE_ROOM, $form[$field('padding')]->getValue());
        self::assertSame(['64px', '3rem'], [$form[$field('padding_top')]->getValue(), $form[$field('padding_bottom')]->getValue()]);

        self::assertSame(302, $this->send('POST', $form->getUri(), $form->getPhpValues()));

        $content = CmsPageContentQuery::create()->filterByPageId($page->getId())->filterByLocale($this->locale())->findOne();
        self::assertSame(['padding_top' => '64px', 'padding_bottom' => '3rem'], Document::fromJson($content?->getDraftDocument())?->section('intro')?->appearance->toArray(), 'Saved again, the section keeps its room.');
    }

    public function testARoomWrittenAsALengthForBothSidesIsShownInTheTopAndBottomFields(): void
    {
        $page = $this->pageWith([new Section('intro', 'cms:richtext', ['html' => '<h1>Avant</h1>'], new SectionAppearance(padding: '4.5rem'))]);
        $field = static fn (string $name): string => \sprintf('cms_section_intro[cms_appearance][%s]', $name);

        $form = $this->screen($this->url($page))->filter('[data-testid="cms-section-edit-intro"] form[data-cms-section-form]')->form();
        self::assertSame(SectionFormBuilder::PRECISE_ROOM, $form[$field('padding')]->getValue());
        self::assertSame('4.5rem', $form[$field('padding_top')]->getValue());
        self::assertSame('4.5rem', $form[$field('padding_bottom')]->getValue());

        self::assertSame(302, $this->send('POST', $form->getUri(), $form->getPhpValues()));

        $content = CmsPageContentQuery::create()->filterByPageId($page->getId())->filterByLocale($this->locale())->findOne();
        self::assertStringContainsString('padding-top:4.5rem;padding-bottom:4.5rem', (string) $content?->getDraftHtml());
    }

    public function testARoomThatIsNoLengthIsRefusedByHand(): void
    {
        $page = $this->pageWith([new Section('intro', 'cms:richtext', ['html' => '<h1>Avant</h1>'])]);

        $form = $this->screen($this->url($page))->filter('[data-testid="cms-section-edit-intro"] form[data-cms-section-form]')->form();
        $values = $form->getPhpValues();
        $values['cms_section_intro']['cms_appearance']['padding'] = SectionFormBuilder::PRECISE_ROOM;
        $values['cms_section_intro']['cms_appearance']['margin_top'] = 'calc(1px + 1px)';

        self::assertSame(302, $this->send('POST', $form->getUri(), $values));

        $content = CmsPageContentQuery::create()->filterByPageId($page->getId())->filterByLocale($this->locale())->findOne();
        self::assertTrue((bool) Document::fromJson($content?->getDraftDocument())?->section('intro')?->appearance->isEmpty(), 'Nothing was changed.');
        self::assertStringContainsString('margin_top', $this->screen($this->url($page))->filter('[data-testid="cms-builder-flash-danger"]')->text());
    }

    public function testAnAppearanceThatIsNoColourIsRefusedByHand(): void
    {
        $page = $this->pageWith([new Section('intro', 'cms:richtext', ['html' => '<h1>Avant</h1>'])]);

        $form = $this->screen($this->url($page))->filter('[data-testid="cms-section-edit-intro"] form[data-cms-section-form]')->form();
        $values = $form->getPhpValues();
        $values['cms_section_intro']['cms_appearance']['background'] = 'red;position:fixed';

        self::assertSame(302, $this->send('POST', $form->getUri(), $values));

        $content = CmsPageContentQuery::create()->filterByPageId($page->getId())->filterByLocale($this->locale())->findOne();
        self::assertTrue((bool) Document::fromJson($content?->getDraftDocument())?->section('intro')?->appearance->isEmpty(), 'Nothing was changed.');
        self::assertStringContainsString('#rrggbb', $this->screen($this->url($page))->filter('[data-testid="cms-builder-flash-danger"]')->text());
    }

    /**
     * The document refuses it in English for the generator; the editor reads
     * the same problem in the language of the back office.
     */
    public function testWhatTheDocumentRefusesByHandReadsInTheLanguageOfTheBackOffice(): void
    {
        $page = $this->pageWith([new Section('intro', 'cms:richtext', ['html' => '<h1>Avant</h1>'])]);
        $inFrench = $this->url($page).'&lang=fr_FR';

        $form = $this->screen($inFrench)->filter('[data-testid="cms-section-edit-intro"] form[data-cms-section-form]')->form();
        $values = $form->getPhpValues();
        $values['cms_section_intro']['cms_appearance']['background'] = 'red';

        self::assertSame(302, $this->send('POST', $form->getUri(), $values));

        $flash = $this->screen($inFrench)->filter('[data-testid="cms-builder-flash-danger"]')->text();
        self::assertStringContainsString('Le réglage background de l’apparence n’est pas une couleur écrite #rrggbb.', $flash);
        self::assertStringNotContainsString('The background of the appearance', $flash, 'The generator reads it in English, not the editor.');
    }

    public function testASectionIsRemovedByHandAndTheLedgerSaysSo(): void
    {
        $page = $this->pageWith([
            new Section('intro', 'cms:richtext', ['html' => '<h1>Avant</h1><p>Un texte.</p>']),
            new Section('faq', 'cms:richtext', ['html' => '<h2>Questions</h2><p>Combien ? Sur devis.</p>']),
        ]);

        $screen = $this->screen($this->url($page));
        self::assertSame(['1', '2'], $screen->filter('[data-testid="cms-content-sections"] .cms-content__item-rank')->each(static fn (Crawler $rank): string => trim($rank->text())), 'The sections are numbered, as they are in the preview.');
        $form = $screen->filter('[data-testid="cms-section-remove-faq"]')->form();

        self::assertSame(302, $this->send('POST', $form->getUri(), $form->getPhpValues()));

        $content = CmsPageContentQuery::create()->filterByPageId($page->getId())->filterByLocale($this->locale())->findOne();
        $document = Document::fromJson($content?->getDraftDocument());

        self::assertSame(['intro'], array_map(static fn (Section $section): string => $section->id, $document?->sections ?? []));
        self::assertStringNotContainsString('Combien ?', (string) $content?->getDraftHtml(), 'The draft is rendered again without it.');
        self::assertStringContainsString('data-cms-section="intro"', (string) $content?->getDraftHtml(), 'The rendered section carries its id, for the preview to point at it.');

        $row = CmsPageGenerationQuery::create()->filterByTargetId($page->getId())->orderById(\Propel\Runtime\ActiveQuery\Criteria::DESC)->findOne();
        self::assertSame('manual', $row?->getKind());
        self::assertStringContainsString('removed', (string) $row?->getPrompt());

        $screen = $this->screen($this->url($page));
        self::assertSame(1, $screen->filter('[data-testid="cms-builder-flash-success"]')->count());
        self::assertSame(1, $screen->filter('[data-testid="cms-content-sections"] li[data-section]')->count());

        self::assertSame(302, $this->send('POST', $form->getUri(), $form->getPhpValues()), 'Removing it again is refused, not an error.');
        self::assertSame(1, $this->screen($this->url($page))->filter('[data-testid="cms-builder-flash-danger"]')->count());
    }

    public function testASectionIsMovedByHandAndTheLedgerSaysSo(): void
    {
        $page = $this->pageWith([
            new Section('intro', 'cms:richtext', ['html' => '<h1>Avant</h1><p>Un texte.</p>']),
            new Section('services', 'cms:richtext', ['html' => '<h2>Services</h2><p>Ce que nous faisons.</p>']),
            new Section('faq', 'cms:richtext', ['html' => '<h2>Questions</h2><p>Combien ? Sur devis.</p>']),
        ]);

        $screen = $this->screen($this->url($page));
        self::assertSame(0, $screen->filter('[data-testid="cms-section-move-up-intro"]')->count(), 'The first section has nowhere to go up to.');
        self::assertSame(0, $screen->filter('[data-testid="cms-section-move-down-faq"]')->count(), 'The last section has nowhere to go down to.');
        self::assertSame(1, $screen->filter('[data-testid="cms-section-move-down-intro"]')->count());

        $form = $screen->filter('[data-testid="cms-section-move-up-faq"]')->form();
        self::assertSame(302, $this->send('POST', $form->getUri(), $form->getPhpValues()));

        $content = CmsPageContentQuery::create()->filterByPageId($page->getId())->filterByLocale($this->locale())->findOne();
        $document = Document::fromJson($content?->getDraftDocument());

        self::assertSame(['intro', 'faq', 'services'], array_map(static fn (Section $section): string => $section->id, $document?->sections ?? []));
        $html = (string) $content?->getDraftHtml();
        self::assertLessThan(strpos($html, 'Services'), strpos($html, 'Combien ?'), 'The draft is rendered again in the new order.');
        self::assertNull($content?->getPublishedHtml(), 'What is online does not move until the draft is published.');

        $row = CmsPageGenerationQuery::create()->filterByTargetId($page->getId())->orderById(\Propel\Runtime\ActiveQuery\Criteria::DESC)->findOne();
        self::assertSame('manual', $row?->getKind());
        self::assertStringContainsString('moved up', (string) $row?->getPrompt());

        $screen = $this->screen($this->url($page));
        self::assertSame(1, $screen->filter('[data-testid="cms-builder-flash-success"]')->count());
        self::assertSame(['intro', 'faq', 'services'], $screen->filter('[data-testid="cms-content-sections"] li[data-section]')->each(static fn (Crawler $item): string => (string) $item->attr('data-section')));
    }

    public function testASectionIsDroppedAtARank(): void
    {
        $page = $this->pageWith([
            new Section('intro', 'cms:richtext', ['html' => '<h1>Avant</h1>']),
            new Section('services', 'cms:richtext', ['html' => '<h2>Services</h2>']),
            new Section('faq', 'cms:richtext', ['html' => '<h2>Questions</h2>']),
        ]);

        $screen = $this->screen($this->url($page));
        $list = $screen->filter('[data-testid="cms-content-sections"] ol[data-cms-reorder]');
        self::assertSame(1, $list->count(), 'The list says where a dropped section is posted.');
        self::assertSame(3, $list->filter('[data-cms-grip]')->count(), 'Each section has a handle.');

        $action = (string) $list->attr('data-cms-reorder');
        self::assertSame(302, $this->send('POST', $action, ['_token' => (string) $list->attr('data-cms-reorder-token'), 'section' => 'intro', 'position' => '2']));

        $content = CmsPageContentQuery::create()->filterByPageId($page->getId())->filterByLocale($this->locale())->findOne();
        self::assertSame(['services', 'faq', 'intro'], array_map(static fn (Section $section): string => $section->id, Document::fromJson($content?->getDraftDocument())?->sections ?? []));

        $row = CmsPageGenerationQuery::create()->filterByTargetId($page->getId())->orderById(\Propel\Runtime\ActiveQuery\Criteria::DESC)->findOne();
        self::assertStringContainsString('moved to rank 3', (string) $row?->getPrompt());
    }

    public function testASingleSectionHasNothingToDrag(): void
    {
        $page = $this->pageWith([new Section('intro', 'cms:richtext', ['html' => '<h1>Seule</h1>'])]);

        $screen = $this->screen($this->url($page));
        self::assertSame(0, $screen->filter('[data-cms-reorder]')->count());
        self::assertSame(0, $screen->filter('[data-cms-grip]')->count());
    }

    public function testTheComponentsToAddAreGroupedEachWithAnIconAndWordsForAnEditor(): void
    {
        $page = $this->pageWith([new Section('intro', 'cms:richtext', ['html' => '<h2>Avant</h2>'])]);

        $dialog = $this->screen($this->url($page))->filter('dialog[data-cms-add-dialog]');
        $families = $dialog->filter('.cms-content__add-family')->each(static fn (Crawler $heading): string => trim($heading->text()));

        self::assertGreaterThanOrEqual(2, \count($families), 'The components come in families.');
        self::assertSame(\count($families), \count(array_unique($families)), 'Each family is listed once.');
        self::assertSame(
            $dialog->filter('.cms-content__add-choice')->count(),
            $dialog->filter('.cms-content__add-choice .cms-content__add-icon i.bi')->count(),
            'Every component has its icon.',
        );

        self::assertSame('cms-content-add-cms:richtext', $dialog->filter('.cms-content__add-list form')->first()->attr('data-testid'), 'Free text opens the list.');

        $freeText = $dialog->filter('[data-testid="cms-content-add-cms:richtext"] .cms-content__add-choice');
        self::assertSame('bi bi-text-paragraph', $freeText->filter('.cms-content__add-icon i')->attr('class'));
        self::assertStringNotContainsString('cms:richtext', $freeText->text(), 'The name of the component is not shown to an editor.');
        self::assertSame(
            $this->getService(TranslatorInterface::class)->trans('Paragraphs, headings and lists.', [], TheliaCMS::DOMAIN_NAME),
            trim($freeText->filter('.cms-content__add-description')->text()),
        );
    }

    public function testALongTextTakesTheWholeLineOfTheFormAndAShortOneHalf(): void
    {
        $column = ['image' => null, 'image_alt' => '', 'title' => 'Un titre', 'text' => 'Une phrase.'];
        $page = $this->pageWith([new Section('cols', 'cms:columns', ['title' => '', 'columns' => [$column, $column]])]);

        $form = $this->screen($this->url($page))->filter('[data-testid="cms-section-edit-cols"] form[data-cms-section-form]');

        self::assertSame(1, $form->filter('input[name="cms_section_cols[columns][0][text]"][data-cms-long]')->count(), 'A text of up to 500 characters is marked long: the stylesheet gives it the line.');
        self::assertSame(0, $form->filter('input[name="cms_section_cols[columns][0][title]"][data-cms-long]')->count(), 'A title of the default length shares its line.');
        self::assertSame(0, $form->filter('input[name="cms_section_cols[title]"][data-cms-long]')->count());
    }

    public function testTheHelpOfAFieldIsATooltipOnAQuestionMarkBesideItsLabel(): void
    {
        $column = ['image' => null, 'image_alt' => '', 'title' => 'Un titre', 'text' => 'Une phrase.'];
        $page = $this->pageWith([new Section('cols', 'cms:columns', ['title' => '', 'columns' => [$column, $column]])]);

        $form = $this->screen($this->url($page))->filter('[data-testid="cms-section-edit-cols"] form[data-cms-section-form]');
        $fieldOf = static fn (string $name): Crawler => $form->filter('.cms-content__field')->reduce(static fn (Crawler $field): bool => 0 < $field->filter(\sprintf('input[name="%s"]', $name))->count());

        $alt = $fieldOf('cms_section_cols[columns][0][image_alt]');
        self::assertSame(1, $alt->count());
        self::assertSame(1, $alt->filter('.cms-content__field-head > label + button[data-cms-help-toggle][aria-expanded="false"]')->count(), 'A "?" beside the label carries the help.');
        self::assertSame($alt->filter('[data-cms-help-toggle]')->attr('aria-describedby'), $alt->filter('.cms-content__help[role="tooltip"]')->attr('id'), 'The button names the tooltip that describes it.');
        self::assertSame(0, $alt->filter('label .bo-form-label__help')->count(), 'The label holds no help: the fields of a line stay level.');

        $title = $fieldOf('cms_section_cols[columns][0][title]');
        self::assertSame(0, $title->filter('[data-cms-help-toggle], .cms-content__help')->count(), 'A prop without help has no "?".');
    }

    public function testTheFormOfASectionFoldsWhatIsSeldomChangedAndKeepsItsSaveButtonInView(): void
    {
        $page = $this->pageWith([new Section('picture', 'cms:image', ['image' => null, 'alt' => '', 'caption' => 'Une légende', 'width' => 'narrow'])]);

        $dialog = $this->screen($this->url($page))->filter('[data-testid="cms-section-edit-picture"]');

        self::assertStringNotContainsString('cms:image', $dialog->filter('h2')->text(), 'The title names the section, not its component.');

        $sources = $dialog->filter('details.cms-content__more')->reduce(static fn (Crawler $details): bool => 0 < $details->filter('[data-cms-image-url]')->count());
        self::assertCount(1, $sources, 'The other sources of the picture are folded together.');
        self::assertNull($sources->attr('open'), 'Closed while no address is set.');

        $appearance = $dialog->filter('[data-testid="cms-section-appearance"]');
        self::assertSame('details', $appearance->nodeName());
        self::assertNull($appearance->attr('open'), 'Closed while the section has no look of its own.');

        self::assertSame(1, $dialog->filter('.cms-content__edit-actions [data-testid="cms-section-save"]')->count(), 'The save button sits in the bar that stays in view.');
        self::assertSame('cms:image', $this->screen($this->url($page))->filter('[data-cms-section-item]')->attr('data-component'), 'The list says which component a section is, for the script to edit free text in place.');
    }

    public function testABriefOnADraftThatHoldsSectionsSaysItReplacesThemAndAsksFirst(): void
    {
        $page = $this->pageWith([
            new Section('intro', 'cms:richtext', ['html' => '<h2>Un</h2>']),
            new Section('story', 'cms:richtext', ['html' => '<h2>Deux</h2>']),
        ]);

        $screen = $this->screen($this->url($page));

        self::assertStringContainsString('2', $screen->filter('[data-testid="cms-content-brief-replaces"]')->text(), 'The warning counts the sections that go.');
        self::assertNotEmpty($screen->filter('form[data-cms-generate][data-cms-confirm]')->attr('data-cms-confirm'), 'The brief form asks before it replaces the draft.');
        self::assertSame(0, $screen->filter('[data-testid="cms-content-template"]')->count(), 'A page of the default type names no template.');

        $blank = $this->screen($this->url($this->pageWith([])));
        self::assertSame(0, $blank->filter('[data-testid="cms-content-brief-replaces"]')->count(), 'A blank page has nothing to lose.');
        self::assertSame(0, $blank->filter('form[data-cms-confirm]')->count());
    }

    public function testThePreviewOfAPageShowsTheWholePageOrItsSectionsAlone(): void
    {
        $page = $this->pageWith([new Section('intro', 'cms:richtext', ['html' => '<h2>Les sections seules</h2>'])]);

        $screen = $this->screen($this->url($page));
        $modes = $screen->filter('[data-testid="cms-content-preview-modes"] input');
        $frame = (string) $screen->filter('[data-testid="cms-content-frame"]')->attr('src');

        self::assertSame(2, $modes->count(), 'The whole page, or the content alone.');
        self::assertSame($frame, $modes->eq(0)->attr('data-url'), 'The whole page first.');
        self::assertStringNotContainsString(PreviewLink::CONTENT_ONLY.'=', $frame);
        self::assertStringContainsString(PreviewLink::CONTENT_ONLY.'=1', (string) $modes->eq(1)->attr('data-url'));

        $this->client->request('GET', $frame);
        $whole = (string) $this->client->getResponse()->getContent();
        self::assertSame('no-referrer', $this->client->getResponse()->headers->get('Referrer-Policy'), 'The signed address of the whole page does not leak through the Referer.');
        $this->client->request('GET', (string) $modes->eq(1)->attr('data-url'));
        $alone = (string) $this->client->getResponse()->getContent();

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('Les sections seules', $alone);
        self::assertSame(1, (new Crawler($whole))->filter('h1')->count(), 'The whole page draws its title.');
        self::assertSame(0, (new Crawler($alone))->filter('h1')->count(), 'The content alone does not.');
        self::assertSame('noindex, nofollow', $this->client->getResponse()->headers->get('X-Robots-Tag'), 'A draft stays out of the indexes.');
        self::assertSame('no-referrer', $this->client->getResponse()->headers->get('Referrer-Policy'), 'Nor does the address of the content alone.');
    }

    public function testThePropsOfAThemeComponentReadInTheLanguageOfTheBackOffice(): void
    {
        if (null === $this->getService(SectionCatalog::class)->find('Layouts:Subheader:Base')) {
            self::markTestSkipped('The theme offers no heading band.');
        }

        $page = $this->pageWith([new Section('bandeau', 'Layouts:Subheader:Base', ['title' => 'Titre', 'tag' => 'h2', 'container' => 'large', 'centered' => false, 'breadcrumb' => []])]);
        $translator = $this->getService(TranslatorInterface::class);
        $themeWords = "HTML tag for the heading. Defaults to 'h1'";

        $dialog = $this->screen($this->url($page))->filter('[data-testid="cms-section-edit-bandeau"]');
        $help = $dialog->filter('.cms-content__help')->each(static fn (Crawler $node): string => trim($node->text()));

        self::assertNotSame($themeWords, $translator->trans($themeWords, [], TheliaCMS::DOMAIN_NAME), 'The module says it in its own words, in every language.');
        self::assertContains($translator->trans($themeWords, [], TheliaCMS::DOMAIN_NAME), $help, 'The help of a prop of the theme goes through the translator.');
        self::assertNotContains($themeWords, $help);
        self::assertStringContainsString($translator->trans('Heading level', [], TheliaCMS::DOMAIN_NAME), $dialog->text(), 'The label of a prop reads as words.');
    }

    public function testAPageOfATemplateNamesItAndLinksToItsSettings(): void
    {
        $types = $this->getService(PageTypeWriter::class);
        $types->add('screen-template');
        $types->describe('screen-template', $this->locale(), 'Fiche atelier', null, null);
        $page = $this->pageWith([new Section('intro', 'cms:richtext', ['html' => '<h2>Un</h2>'])]);
        $page->setPageType('screen-template')->save();

        $template = $this->screen($this->url($page))->filter('[data-testid="cms-content-template"]');

        self::assertStringContainsString('Fiche atelier', $template->text());
        self::assertStringEndsWith('/admin/cms/settings/page-types/screen-template', (string) $template->attr('href'));
    }

    public function testASectionIsAddedAtTheEndWithItsExampleProps(): void
    {
        $page = $this->pageWith([new Section('intro', 'cms:richtext', ['html' => '<h1>Avant</h1>'])]);

        $screen = $this->screen($this->url($page));
        self::assertSame(1, $screen->filter('dialog[data-cms-add-dialog]')->count(), 'The components to add a section from are on the screen.');
        self::assertSame(1, $screen->filter('[data-testid="cms-content-add-Partial:cms-form"]')->count());

        $form = $screen->filter('[data-testid="cms-content-add-cms:richtext"]')->form();
        self::assertSame(302, $this->send('POST', $form->getUri(), $form->getPhpValues()));

        $content = CmsPageContentQuery::create()->filterByPageId($page->getId())->filterByLocale($this->locale())->findOne();
        $document = Document::fromJson($content?->getDraftDocument());

        self::assertSame(['intro', 'richtext'], array_map(static fn (Section $section): string => $section->id, $document?->sections ?? []));
        self::assertStringContainsString('<h2>', (string) $document?->section('richtext')?->props['html'], 'The section holds the example of its component.');
        self::assertStringContainsString('data-cms-section="richtext"', (string) $content?->getDraftHtml(), 'The draft is rendered again with it.');

        $row = CmsPageGenerationQuery::create()->filterByTargetId($page->getId())->orderById(\Propel\Runtime\ActiveQuery\Criteria::DESC)->findOne();
        self::assertSame('manual', $row?->getKind());
        self::assertStringContainsString('added by hand', (string) $row?->getPrompt());

        self::assertSame(1, $this->screen($this->url($page))->filter('[data-testid="cms-builder-flash-success"]')->count());
    }

    public function testEveryComponentOfferedCanBeAddedFromItsExample(): void
    {
        $page = $this->pageWith([new Section('intro', 'cms:richtext', ['html' => '<h1>Avant</h1>'])]);
        $screen = $this->screen($this->url($page));
        $names = $this->getService(SectionCatalog::class)->offeredNames();

        foreach ($names as $name) {
            $form = $screen->filter(\sprintf('[data-testid="cms-content-add-%s"]', $name))->form();
            self::assertSame(302, $this->send('POST', $form->getUri(), $form->getPhpValues()), $name);
        }

        $content = CmsPageContentQuery::create()->filterByPageId($page->getId())->filterByLocale($this->locale())->findOne();
        $added = array_map(static fn (Section $section): string => $section->component, Document::fromJson($content?->getDraftDocument())?->sections ?? []);

        self::assertSame(['cms:richtext', ...$names], $added, 'The example of every component is a section the site accepts.');
    }

    public function testAPageHoldingNothingStartsItsDraftWithTheAddedSection(): void
    {
        $page = new CmsPage();
        $page->setParent(0)->setPosition(0)->setVisible(1)->setPageType('default');
        $this->getService(CmsPageWriter::class)->saveDraft($page, $this->locale(), new PageDraft(title: 'Empty page'));
        $this->renderedOnce();

        $screen = $this->screen($this->url($page));
        self::assertSame(1, $screen->filter('[data-testid="cms-content-empty"] [data-cms-add-open]')->count(), 'An empty page offers to add a section by hand.');

        $form = $screen->filter('[data-testid="cms-content-add-cms:richtext"]')->form();
        self::assertSame(302, $this->send('POST', $form->getUri(), $form->getPhpValues()));

        $content = CmsPageContentQuery::create()->filterByPageId($page->getId())->filterByLocale($this->locale())->findOne();
        self::assertSame(['richtext'], array_map(static fn (Section $section): string => $section->id, Document::fromJson($content?->getDraftDocument())?->sections ?? []));
    }

    public function testAComponentTheSiteNoLongerOffersIsNotAdded(): void
    {
        $page = $this->pageWith([new Section('intro', 'cms:richtext', ['html' => '<h1>Avant</h1>'])]);
        $form = $this->screen($this->url($page))->filter('[data-testid="cms-content-add-Partial:cms-form"]')->form();

        $this->getService(SectionAvailability::class)->setOffered('Partial:cms-form', false);

        self::assertSame(302, $this->send('POST', $form->getUri(), $form->getPhpValues()));
        self::assertSame(1, $this->screen($this->url($page))->filter('[data-testid="cms-builder-flash-danger"]')->count());
        self::assertSame(0, $this->client->getCrawler()->filter('[data-testid="cms-content-add-Partial:cms-form"]')->count(), 'The list no longer offers it.');
    }

    public function testMovingASectionThatIsNoLongerThereIsRefused(): void
    {
        $page = $this->pageWith([
            new Section('intro', 'cms:richtext', ['html' => '<h1>Avant</h1>']),
            new Section('faq', 'cms:richtext', ['html' => '<h2>Questions</h2>']),
        ]);

        $form = $this->screen($this->url($page))->filter('[data-testid="cms-section-move-up-faq"]')->form();
        $values = $form->getPhpValues();
        $values['section'] = 'gone';

        self::assertSame(302, $this->send('POST', $form->getUri(), $values));
        self::assertSame(1, $this->screen($this->url($page))->filter('[data-testid="cms-builder-flash-danger"]')->count());
    }

    public function testASectionIsDuplicatedRightAfterItselfAndTheLedgerSaysSo(): void
    {
        $page = $this->pageWith([
            new Section('intro', 'cms:richtext', ['html' => '<h2>Services</h2><p>Ce que nous faisons.</p>'], new SectionAppearance(background: '#f8d7d7')),
            new Section('faq', 'cms:richtext', ['html' => '<h2>Questions</h2><p>Combien ? Sur devis.</p>']),
        ]);

        $form = $this->screen($this->url($page))->filter('[data-testid="cms-section-duplicate-intro"]')->form();
        self::assertSame(302, $this->send('POST', $form->getUri(), $form->getPhpValues()));

        $content = CmsPageContentQuery::create()->filterByPageId($page->getId())->filterByLocale($this->locale())->findOne();
        $document = Document::fromJson($content?->getDraftDocument());

        self::assertSame(['intro', 'richtext', 'faq'], array_map(static fn (Section $section): string => $section->id, $document?->sections ?? []), 'The copy takes an id no other section holds, right after the original.');
        self::assertSame($document?->section('intro')?->props, $document?->section('richtext')?->props);
        self::assertSame(['background' => '#f8d7d7'], $document?->section('richtext')?->appearance->toArray(), 'The copy keeps the appearance of the original.');
        self::assertStringContainsString('data-cms-section="richtext"', (string) $content?->getDraftHtml(), 'The draft is rendered again with the copy, which the preview points at by its own id.');
        self::assertNull($content?->getPublishedHtml(), 'What is online does not change until the draft is published.');

        $row = CmsPageGenerationQuery::create()->filterByTargetId($page->getId())->orderById(\Propel\Runtime\ActiveQuery\Criteria::DESC)->findOne();
        self::assertSame('manual', $row?->getKind());
        self::assertStringContainsString('duplicated by hand as "richtext"', (string) $row?->getPrompt());

        $screen = $this->screen($this->url($page));
        self::assertSame(1, $screen->filter('[data-testid="cms-builder-flash-success"]')->count());
        self::assertSame(['intro', 'richtext', 'faq'], $screen->filter('[data-testid="cms-content-sections"] li[data-section]')->each(static fn (Crawler $item): string => (string) $item->attr('data-section')));
    }

    public function testASectionOfAComponentTheSitePutAwayIsNotDuplicated(): void
    {
        $page = $this->pageWith([
            new Section('intro', 'cms:richtext', ['html' => '<h1>Avant</h1>']),
            new Section('contact', 'Partial:cms-form', ['form' => 1]),
        ]);
        $form = $this->screen($this->url($page))->filter('[data-testid="cms-section-duplicate-contact"]')->form();

        $this->getService(SectionAvailability::class)->setOffered('Partial:cms-form', false);

        self::assertSame(302, $this->send('POST', $form->getUri(), $form->getPhpValues()));

        $content = CmsPageContentQuery::create()->filterByPageId($page->getId())->filterByLocale($this->locale())->findOne();
        self::assertSame(['intro', 'contact'], array_map(static fn (Section $section): string => $section->id, Document::fromJson($content?->getDraftDocument())?->sections ?? []), 'Nothing was copied.');

        $screen = $this->screen($this->url($page));
        self::assertSame(1, $screen->filter('[data-testid="cms-builder-flash-danger"]')->count());
        self::assertSame(0, $screen->filter('[data-testid="cms-section-duplicate-contact"]')->count(), 'The section no longer offers to be duplicated.');
        self::assertSame(1, $screen->filter('[data-testid="cms-section-duplicate-intro"]')->count());
    }

    public function testASectionOfAPrototypeIsDuplicatedInThePageHoldingIt(): void
    {
        $component = PriceTable::component();
        $this->getService(SiteComponentRepository::class)->save(new SiteComponent('PriceGrid', $component->label, $component->description, $component->props, $component->sample, $component->template), SiteComponentRepository::STATUS_DRAFT);
        $page = $this->pageWith([
            new Section('plans', 'Site:PriceGrid', ['title' => 'Nos formules', 'plans' => [['name' => 'Studio', 'price' => '40 €', 'details' => '<p>Une heure</p>']]]),
        ]);

        $form = $this->screen($this->url($page))->filter('[data-testid="cms-section-duplicate-plans"]')->form();
        self::assertSame(302, $this->send('POST', $form->getUri(), $form->getPhpValues()));

        $content = CmsPageContentQuery::create()->filterByPageId($page->getId())->filterByLocale($this->locale())->findOne();
        self::assertSame(['plans', 'pricegrid'], array_map(static fn (Section $section): string => $section->id, Document::fromJson($content?->getDraftDocument())?->sections ?? []), 'A prototype is offered to nobody else, but the page holding it copies it like it edits it.');
    }

    public function testDuplicatingASectionThatIsNoLongerThereIsRefused(): void
    {
        $page = $this->pageWith([new Section('intro', 'cms:richtext', ['html' => '<h1>Avant</h1>'])]);

        $form = $this->screen($this->url($page))->filter('[data-testid="cms-section-duplicate-intro"]')->form();
        $values = $form->getPhpValues();
        $values['section'] = 'gone';

        self::assertSame(302, $this->send('POST', $form->getUri(), $values));
        self::assertSame(1, $this->screen($this->url($page))->filter('[data-testid="cms-builder-flash-danger"]')->count());

        $content = CmsPageContentQuery::create()->filterByPageId($page->getId())->filterByLocale($this->locale())->findOne();
        self::assertSame(['intro'], array_map(static fn (Section $section): string => $section->id, Document::fromJson($content?->getDraftDocument())?->sections ?? []));
    }

    public function testRowsOfAListAreAddedAndRemoved(): void
    {
        if (null === $this->getService(SectionCatalog::class)->find(self::LIST_COMPONENT)) {
            self::markTestSkipped('The theme opens no component holding a list to pages.');
        }

        $factory = new FixtureFactory($this->getPropelConnection());
        [$one, $two, $three] = array_map(
            static fn (int $rank): int => (int) $factory->product($factory->category(), $factory->taxRule(), $factory->currency())->getId(),
            [1, 2, 3],
        );
        // Stored as it is rather than through the writer: the component reads
        // its products with the request of a screen, and there is none here.
        $page = $this->createPage('Edited by hand', published: false);
        CmsPageContentQuery::create()->filterByPageId($page->getId())->filterByLocale($this->locale())->findOneOrCreate()
            ->setDraftDocument((new Document([
                new Section('row', self::LIST_COMPONENT, ['title' => 'Nos produits', 'product_ids' => [['id' => $one], ['id' => $two]]]),
            ]))->toJson())
            ->save();

        $screen = $this->screen($this->url($page));
        $list = $screen->filter('[data-testid="cms-section-edit-row"] [data-cms-items]');
        self::assertSame(1, $list->count());
        self::assertStringContainsString('cms_section_row[product_ids][__name__]', (string) $list->attr('data-prototype'), 'The rows are added from the prototype.');

        $form = $screen->filter('[data-testid="cms-section-edit-row"] form[data-cms-section-form]')->form();
        $values = $form->getPhpValues();
        // The second row dropped, a third one picked as the script would add it.
        unset($values['cms_section_row']['product_ids'][1]);
        $values['cms_section_row']['product_ids'][2] = ['id' => (string) $three];

        self::assertSame(302, $this->send('POST', $form->getUri(), $values));

        $document = Document::fromJson(CmsPageContentQuery::create()->filterByPageId($page->getId())->findOne()?->getDraftDocument());
        self::assertSame([$one, $three], array_column($document?->section('row')?->props['product_ids'] ?? [], 'id'));
    }

    public function testARowOfAListMovesUpAndDownFromToolsBesideItsFold(): void
    {
        $form = $this->screen($this->url($this->pageWithHighlights()))->filter('[data-testid="cms-section-edit-hl"] form[data-cms-section-form]');
        $translator = $this->getService(TranslatorInterface::class);

        self::assertSame('cms_section_hl[blocks]', $form->filter('[data-cms-items]')->attr('data-cms-items-name'), 'The script renames the fields of the rows from the name of the collection.');
        self::assertSame(3, $form->filter('li[data-cms-item] > .cms-content__item-tools > button[data-cms-item-up]')->count());
        self::assertSame(3, $form->filter('li[data-cms-item] > .cms-content__item-tools > button[data-cms-item-down]')->count());
        self::assertSame($translator->trans('Move this row up', [], TheliaCMS::DOMAIN_NAME), $form->filter('[data-cms-item-up]')->first()->attr('aria-label'));
        self::assertSame($translator->trans('Move this row down', [], TheliaCMS::DOMAIN_NAME), $form->filter('[data-cms-item-down]')->first()->attr('aria-label'));
        self::assertSame(0, $form->filter('summary button')->count(), 'No button inside a summary.');
    }

    public function testRowsSentInAnotherOrderAreSavedInThatOrder(): void
    {
        $screen = $this->screen($this->url($page = $this->pageWithHighlights()));
        $form = $screen->filter('[data-testid="cms-section-edit-hl"] form[data-cms-section-form]')->form();
        $values = $form->getPhpValues();
        [$first, $second, $third] = $values['cms_section_hl']['blocks'];

        // What the script sends once the second row is moved to the top: the fields renamed to the order of the rows.
        $values['cms_section_hl']['blocks'] = [$second, $first, $third];
        self::assertSame(302, $this->send('POST', $form->getUri(), $values));

        $document = Document::fromJson(CmsPageContentQuery::create()->filterByPageId($page->getId())->findOne()?->getDraftDocument());
        self::assertSame(['Second', 'Premier', 'Troisième'], array_column($document?->section('hl')?->props['blocks'] ?? [], 'title'));

        // Moved in the page only, with the names left as they were, the order is the one of the indexes: hence the renaming.
        $values['cms_section_hl']['blocks'] = [1 => $second, 0 => $first, 2 => $third];
        self::assertSame(302, $this->send('POST', $form->getUri(), $values));

        $document = Document::fromJson(CmsPageContentQuery::create()->filterByPageId($page->getId())->findOne()?->getDraftDocument());
        self::assertSame(['Premier', 'Second', 'Troisième'], array_column($document?->section('hl')?->props['blocks'] ?? [], 'title'));
    }

    public function testTheNameOfAListOfTheThemeReadsInTheLanguageOfTheBackOffice(): void
    {
        // A list named by its bare name, as a prop of the theme is: the form says it in words of its own.
        $props = PriceTable::component()->props;
        $props[1]['name'] = 'products';
        $props[1]['label'] = 'products';
        $this->getService(SiteComponentRepository::class)->save(new SiteComponent('ProductGrid', 'Product grid', 'Products side by side.', $props, ['products' => [['name' => 'Basic']]], '<section></section>'), SiteComponentRepository::STATUS_DRAFT);
        $page = $this->pageWith([new Section('row', 'Site:ProductGrid', ['title' => 'Nos produits', 'products' => [['name' => 'Studio']]])]);

        $legend = $this->screen($this->url($page).'&lang=fr_FR')->filter('[data-testid="cms-section-edit-row"] [data-cms-items] > legend');

        self::assertSame('Produits', trim($legend->text()), 'The name of a list goes through the translator, as the label of any field.');
    }

    public function testTheSchemaStillRefusesWhatItRefusesToTheModel(): void
    {
        $page = $this->pageWith([
            new Section('contact', 'Partial:cms-form', ['form' => 1]),
        ]);

        $form = $this->screen($this->url($page))->filter('[data-testid="cms-section-edit-contact"] form[data-cms-section-form]')->form();
        $values = $form->getPhpValues();
        $values['cms_section_contact']['form'] = '';

        self::assertSame(302, $this->send('POST', $form->getUri(), $values));

        $document = Document::fromJson(CmsPageContentQuery::create()->filterByPageId($page->getId())->findOne()?->getDraftDocument());
        self::assertSame(1, $document?->section('contact')?->props['form'], 'Nothing was changed.');
        self::assertSame(0, CmsPageGenerationQuery::create()->filterByTargetId($page->getId())->count(), 'Nothing was written to the ledger.');

        $screen = $this->screen($this->url($page));
        self::assertStringContainsString('form', $screen->filter('[data-testid="cms-builder-flash-danger"]')->text());
    }

    public function testASectionNoLongerThereIsRefused(): void
    {
        $page = $this->pageWith([new Section('intro', 'cms:richtext', ['html' => '<h1>Avant</h1>'])]);
        $token = $this->tokenOfTheScripts($this->screen($this->url($page)));

        self::assertSame(302, $this->send('POST', \sprintf('/admin/cms/pages/%d/content/update', $page->getId()), ['_token' => $token, 'cms_section_gone' => ['section' => 'gone', 'html' => 'X']]));
        self::assertSame(1, $this->screen($this->url($page))->filter('[data-testid="cms-builder-flash-danger"]')->count());
    }

    public function testEditingByHandTakesTheRightToChangePagesNotTheGenerator(): void
    {
        $page = $this->pageWith([new Section('intro', 'cms:richtext', ['html' => '<h1>Avant</h1>'])]);

        $this->logInAs((new FixtureFactory($this->getPropelConnection()))->restrictedAdmin([
            CmsResources::PAGE => [AccessManager::VIEW, AccessManager::UPDATE],
        ]));
        $form = $this->screen($this->url($page))->filter('[data-testid="cms-section-edit-intro"] form[data-cms-section-form]')->form();
        $form['cms_section_intro[html]'] = '<h1>Sans générateur</h1>';
        self::assertSame(302, $this->send('POST', $form->getUri(), $form->getPhpValues()));
        self::assertStringContainsString('Sans générateur', (string) CmsPageContentQuery::create()->filterByPageId($page->getId())->findOne()?->getDraftHtml());

        $this->logInAs((new FixtureFactory($this->getPropelConnection()))->restrictedAdmin([
            CmsResources::PAGE => [AccessManager::VIEW],
        ]));
        $screen = $this->screen($this->url($page));
        self::assertSame(0, $screen->filter('[data-testid="cms-section-edit-intro"]')->count(), 'Reading the screen is not the right to change a section.');
        self::assertSame(403, $this->send('POST', \sprintf('/admin/cms/pages/%d/content/update', $page->getId()), ['_token' => $this->tokenOfTheScripts($screen), 'cms_section_intro' => ['section' => 'intro', 'html' => 'Refused']]));
    }

    private function pageWithHighlights(): CmsPage
    {
        $block = static fn (string $title): array => ['title' => $title, 'text' => '', 'button_label' => '', 'button_url' => '', 'image' => null, 'image_alt' => '', 'image_mobile' => null, 'focus_x' => 50, 'focus_y' => 50];

        return $this->pageWith([new Section('hl', 'cms:highlights', ['blocks' => [$block('Premier'), $block('Second'), $block('Troisième')]])]);
    }

    /**
     * @param list<Section> $sections
     */
    private function pageWith(array $sections): CmsPage
    {
        $page = $this->createPage('Edited by hand', published: false);
        $this->renderedOnce();
        $this->getService(CmsPageWriter::class)->saveContent($page, $this->locale(), new Document($sections));

        return $page;
    }

    /**
     * A screen rendered before the sections are: the parser of Thelia sets
     * the `app` global of Twig at the first render of the process, with the
     * request of the moment — none, when the test renders a section before it
     * asks for a screen — and the screens then read `app.request` as null.
     */
    private function renderedOnce(): void
    {
        $this->client->request('GET', '/admin/cms/pages');
    }

    private function url(CmsPage $page): string
    {
        return \sprintf('/admin/cms/pages/%d/content?edit_language_id=%d', $page->getId(), Lang::getDefaultLanguage()->getId());
    }
}
