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

use Thelia\Core\Template\TemplateHelperInterface;
use Thelia\Model\LangQuery;
use TheliaCMS\Content\Document;
use TheliaCMS\Content\SectionCatalog;
use TheliaCMS\Generation\Brief;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Model\CmsPageContentQuery;
use TheliaCMS\Model\CmsPageQuery;
use TheliaCMS\Model\Map\CmsPageTableMap;
use TheliaCMS\Page\Admin\CmsPageAdminRepository;
use TheliaCMS\Page\Admin\MissingPageFieldsException;
use TheliaCMS\Page\Admin\ModelPagePublicationException;
use TheliaCMS\Page\Admin\PageDraft;
use TheliaCMS\Page\Admin\PageFilters;
use TheliaCMS\Page\Admin\PageParentCheck;
use TheliaCMS\Page\CmsPageRenderer;
use TheliaCMS\Page\Field\InvalidFieldsException;
use TheliaCMS\Page\Field\PageFields;
use TheliaCMS\Page\PageListQuery;
use TheliaCMS\Page\PageTemplateResolver;
use TheliaCMS\Page\PageTypeRepository;
use TheliaCMS\Page\PageTypeWriter;
use TheliaCMS\Page\PublishedPageRepository;
use TheliaCMS\Partial\Catalogue\PickablePages;
use TheliaCMS\Partial\Definition\PageListPartial;
use TheliaCMS\Partial\PartialRenderer;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;
use TheliaCMS\Twig\CmsExtension;

/**
 * A page type as a template of pages (docs/adr/0009): its brief and fields,
 * its model, the pages started from it, and the lists of its pages.
 */
final class PageTemplateTest extends CmsIntegrationTestCase
{
    public function testATypeIsDescribedAsATemplate(): void
    {
        $this->givenTheRecipeType();

        $details = $this->types()->details('recipe-test');

        self::assertSame('Recette', $details->title($this->locale()));
        self::assertSame('Une recette de saison, pas à pas.', $details->brief()?->text);
        self::assertSame('chaleureux', $details->brief()?->tone);
        self::assertSame(['servings', 'cooking_time', 'tip'], array_map(static fn ($field): string => $field->code, $details->fields()->fields));
        self::assertTrue($details->isTemplate());
        self::assertFalse($this->types()->details('default')->isTemplate(), 'A type nobody described is a code.');
    }

    public function testAFieldIsChangedWithoutChangingItsCode(): void
    {
        $this->givenTheRecipeType();

        $this->typeWriter()->saveField('recipe-test', 'servings', $this->locale(), ['code' => 'people', 'kind' => 'integer', 'label' => 'Couverts', 'required' => false]);
        $this->typeWriter()->moveField('recipe-test', 'tip', -1);
        $this->typeWriter()->deleteField('recipe-test', 'cooking_time');

        $fields = $this->types()->details('recipe-test')->fields();

        self::assertSame(['servings', 'tip'], array_map(static fn ($field): string => $field->code, $fields->fields));
        self::assertSame('Couverts', $fields->find('servings')?->label($this->locale()));
        self::assertFalse((bool) $fields->find('servings')?->required);
    }

    public function testATypeDoesNotTakeAFieldItHasAlready(): void
    {
        $this->givenTheRecipeType();

        $this->expectException(InvalidFieldsException::class);

        $this->typeWriter()->saveField('recipe-test', null, $this->locale(), ['code' => 'servings', 'kind' => 'text', 'label' => 'Encore']);
    }

    public function testTheModelIsNeverPublished(): void
    {
        $this->givenTheRecipeType();
        $model = $this->givenTheModel();

        $this->expectException(ModelPagePublicationException::class);

        $this->writer()->publish($model, $this->locale());
    }

    public function testATypeHasOneModel(): void
    {
        $this->givenTheRecipeType();
        $this->givenTheModel();

        $this->expectException(\LogicException::class);

        $this->writer()->createModel('recipe-test', $this->locale(), 'Second modèle');
    }

    public function testANewPageStartsAsACopyOfTheModel(): void
    {
        $this->givenTheRecipeType();
        $model = $this->givenTheModel();

        $page = $this->writer()->startFromModel($model, $this->locale(), 'Tarte aux pommes');

        $page = $this->reloaded($page);
        $page->setLocale($this->locale());

        self::assertSame('Tarte aux pommes', $page->getTitle());
        self::assertSame('recipe-test', $page->getPageType());
        self::assertSame(0, $page->getModel(), 'The copy is a page of the site, not a second model.');
        self::assertSame(1, $page->getVisible());
        self::assertSame(['servings' => 4, 'cooking_time' => 45, 'tip' => 'Servir tiède.'], $this->fields()->valuesOf($page, $this->locale()));

        $draft = CmsPageContentQuery::create()->filterByPageId($page->getId())->filterByLocale($this->locale())->findOne();

        self::assertStringContainsString('Le modèle de la recette', (string) $draft?->getDraftHtml());
        self::assertNull($draft?->getPublishedAt(), 'A copy starts as a draft.');
    }

    public function testARequiredFieldLeftEmptyKeepsThePageOffline(): void
    {
        $this->givenTheRecipeType();
        $page = $this->givenARecipe('Gratin', ['cooking_time' => 30], publish: false);

        try {
            $this->writer()->publish($page, $this->locale());
            self::fail('The page is refused.');
        } catch (MissingPageFieldsException $exception) {
            self::assertSame(['Personnes'], $exception->labels);
        }

        $this->writer()->saveFields($page, $this->locale(), ['servings' => 2]);
        $this->writer()->publish($page, $this->locale());

        self::assertNotNull($this->published()->find((int) $page->getId(), $this->locale()));
    }

    public function testTheValuesOfAPageAreCheckedAgainstItsType(): void
    {
        $this->givenTheRecipeType();
        $page = $this->givenARecipe('Gratin', ['servings' => 4], publish: false);

        try {
            $this->writer()->saveFields($page, $this->locale(), ['servings' => 'beaucoup']);
            self::fail('A value the field does not take is refused.');
        } catch (InvalidFieldsException) {
        }

        $this->writer()->saveFields($page, $this->locale(), ['cooking_time' => 20, 'colour' => 'rouge']);

        self::assertSame(['servings' => 4, 'cooking_time' => 20, 'tip' => null], $this->fields()->valuesOf($this->reloaded($page), $this->locale()), 'A value left out is kept, a code the type lacks is dropped.');
        self::assertStringNotContainsString('colour', (string) $this->reloaded($page)->getFields());
    }

    public function testATranslatedFieldIsWrittenForEachLanguage(): void
    {
        $other = LangQuery::create()->filterByActive(1)->filterByLocale($this->locale(), \Propel\Runtime\ActiveQuery\Criteria::NOT_EQUAL)->findOne()?->getLocale();

        if (null === $other) {
            self::markTestSkipped('The shop has a single language.');
        }

        $this->givenTheRecipeType();
        $page = $this->givenARecipe('Gratin', ['servings' => 4, 'tip' => 'Servir chaud.'], publish: false);
        $this->writer()->saveFields($page, $other, ['tip' => 'Serve hot.', 'servings' => 6]);

        $page = $this->reloaded($page);

        self::assertSame(['servings' => 6, 'cooking_time' => null, 'tip' => 'Servir chaud.'], $this->fields()->valuesOf($page, $this->locale()), 'A value the same in every language changes in every language.');
        self::assertSame('Serve hot.', $this->fields()->valuesOf($page, $other)['tip']);
    }

    public function testAPageThatChangesTypeDropsTheValuesOfTheFormer(): void
    {
        $this->givenTheRecipeType();
        $page = $this->givenARecipe('Gratin', ['servings' => 4], publish: false);

        $page->setPageType('default');
        $this->writer()->saveDraft($page, $this->locale(), new PageDraft(title: 'Gratin'));

        self::assertNull($this->reloaded($page)->getFields());
    }

    public function testDeletingATypeDeletesItsModel(): void
    {
        $this->givenTheRecipeType();
        $model = $this->givenTheModel();

        self::assertSame(0, $this->types()->countPagesOfType('recipe-test'), 'The model is not a page that keeps the type.');

        $this->typeWriter()->delete('recipe-test');

        CmsPageTableMap::clearInstancePool();
        self::assertNull(CmsPageQuery::create()->findPk($model->getId()));
        self::assertFalse($this->types()->exists('recipe-test'));
    }

    public function testTheModelIsNotListedWithThePagesOfTheSite(): void
    {
        $this->givenTheRecipeType();
        $model = $this->givenTheModel();
        $repository = $this->getService(CmsPageAdminRepository::class);
        $filters = PageFilters::fromRequest(new \Symfony\Component\HttpFoundation\Request());

        $ids = array_map(static fn ($row): int => (int) $row->page->getId(), $repository->branch($this->locale(), $filters));

        self::assertNotContains((int) $model->getId(), $ids);
        self::assertSame([], $this->getService(PickablePages::class)->search($this->titleOf($model), $this->locale()), 'A model is never offered as a parent,');
        self::assertNotNull($this->getService(PageParentCheck::class)->refusal(null, (int) $model->getId(), $this->locale()), 'nor taken as one.');
        self::assertSame((int) $model->getId(), $this->types()->modelIds()['recipe-test'] ?? null);
    }

    public function testAListOfTheTypeSortsAndFiltersOnTheFields(): void
    {
        $this->givenTheRecipeType();
        $this->givenTheModel();
        $this->givenARecipe('Tarte', ['servings' => 6, 'cooking_time' => 45]);
        $this->givenARecipe('Salade', ['servings' => 2, 'cooking_time' => 0]);
        $this->givenARecipe('Crumble', ['servings' => 4, 'cooking_time' => 30]);
        $this->givenARecipe('Brouillon', ['servings' => 4, 'cooking_time' => 5], publish: false);
        $this->createPage('Une page d’un autre type');

        $titles = static fn (array $cards): array => array_map(static fn ($card): string => $card->title, $cards);

        self::assertSame(['Salade', 'Crumble', 'Tarte'], $titles($this->published()->ofType('recipe-test', $this->locale(), new PageListQuery(sort: 'cooking_time'))), 'Online pages of the type only, the model left out.');
        self::assertSame(['Tarte', 'Crumble'], $titles($this->published()->ofType('recipe-test', $this->locale(), PageListQuery::fromArray(['sort' => 'servings', 'direction' => 'desc', 'filter' => ['servings' => ['min' => 4]]]))));
        self::assertSame(['Salade'], $titles($this->published()->ofType('recipe-test', $this->locale(), new PageListQuery(sort: 'cooking_time', limit: 1))));

        $card = $this->published()->ofType('recipe-test', $this->locale(), new PageListQuery(sort: PageListQuery::SORT_TITLE))[0];

        self::assertSame('Crumble', $card->title);
        self::assertSame(['servings' => 4, 'cooking_time' => 30, 'tip' => null], $card->fields);
        self::assertSame([], $this->published()->ofType('not a code', $this->locale()));
    }

    public function testThePublishedPageCarriesItsFields(): void
    {
        $this->givenTheRecipeType();
        $page = $this->givenARecipe('Tarte', ['servings' => 6, 'cooking_time' => 90]);

        $published = $this->published()->find((int) $page->getId(), $this->locale());

        self::assertSame(6, $published?->fields['servings']);
        self::assertSame(90, $published?->withHtml('')->fields['cooking_time'], 'The fields survive the dynamic blocks being resolved.');
    }

    public function testATemplateListsThePagesOfTheTypeAndShowsTheirFields(): void
    {
        $this->givenTheRecipeType();
        $page = $this->givenARecipe('Tarte', ['servings' => 6, 'cooking_time' => 90]);
        $this->givenARecipe('Salade', ['servings' => 2, 'cooking_time' => 5]);

        $cards = $this->callTwig('cms_pages_of_type', 'recipe-test', ['sort' => 'cooking_time', 'direction' => 'desc']);

        self::assertSame(['Tarte', 'Salade'], array_map(static fn ($card): string => $card->title, $cards));

        $rows = $this->callTwig('cms_page_fields', $this->published()->find((int) $page->getId(), $this->locale()));

        self::assertSame(['servings', 'cooking_time'], array_column($rows, 'code'), 'An empty field is not shown.');
        self::assertSame('1 h 30 min', $rows[1]['text']);
        self::assertSame('Recette', $this->callTwig('cms_page_type', 'recipe-test')['title']);
    }

    public function testAPageListsThePagesOfATypeWhenItIsServed(): void
    {
        $this->useAnInstalledFrontTheme();
        $this->givenTheRecipeType();
        $this->givenARecipe('Tarte', ['servings' => 6, 'cooking_time' => 90]);

        $section = $this->getService(SectionCatalog::class)->find('Partial:'.PageListPartial::NAME);
        self::assertNotNull($section, 'The list is a section of the catalogue.');

        $props = $section->normalizeProps(['type' => 'recipe-test', 'title' => 'Nos recettes', 'sort' => 'field', 'field' => 'cooking_time']);
        $html = $this->getService(PartialRenderer::class)->renderOne(PageListPartial::NAME, $props, $this->locale(), cache: false);

        self::assertStringContainsString('Nos recettes', (string) $html);
        self::assertStringContainsString('Tarte', (string) $html);
        self::assertStringContainsString('1 h 30 min', (string) $html);
    }

    public function testAPageOfATypeWithoutATemplateOfItsOwnIsLaidOutLikeAContentOfTheShop(): void
    {
        $this->useAnInstalledFrontTheme();

        if ('flexy' !== $this->getService(TemplateHelperInterface::class)->getActiveFrontTemplate()->getName()) {
            self::markTestSkipped('The layout shipped for the theme is the one of Flexy.');
        }

        $this->givenTheRecipeType();
        $page = $this->givenARecipe('Tarte aux pommes', ['servings' => 6, 'cooking_time' => 90]);

        self::assertSame('cmspage.html.twig', $this->getService(PageTemplateResolver::class)->resolve('recipe-test')->name, 'The layout the module ships for the theme is found like one of the theme.');

        $published = $this->published()->find((int) $page->getId(), $this->locale());
        self::assertNotNull($published);
        $html = $this->getService(CmsPageRenderer::class)->render($published);

        self::assertStringContainsString('Subheader', $html, 'The heading band of the theme, as on a content.');
        self::assertMatchesRegularExpression('#<h1[^>]*>\s*Tarte aux pommes\s*</h1>#', $html, 'The title of the page is its level 1 heading.');
        self::assertStringContainsString('1 h 30 min', $html, 'The fields of the type are shown.');
        self::assertStringContainsString('cms-page-content', $html);
    }

    private function callTwig(string $name, mixed ...$arguments): mixed
    {
        foreach ($this->getService(CmsExtension::class)->getFunctions() as $function) {
            if ($name === $function->getName()) {
                return ($function->getCallable())(...$arguments);
            }
        }

        self::fail(\sprintf('The extension registers no "%s" function.', $name));
    }

    private function givenTheRecipeType(): void
    {
        $writer = $this->typeWriter();
        $writer->add('recipe-test');
        $writer->describe('recipe-test', $this->locale(), 'Recette', 'Les recettes du site.', new Brief('Une recette de saison, pas à pas.', 'chaleureux'));
        $writer->saveField('recipe-test', null, $this->locale(), ['code' => 'servings', 'kind' => 'integer', 'label' => 'Personnes', 'required' => true, 'min' => 1]);
        $writer->saveField('recipe-test', null, $this->locale(), ['code' => 'cooking_time', 'kind' => 'duration', 'label' => 'Cuisson']);
        $writer->saveField('recipe-test', null, $this->locale(), ['code' => 'tip', 'kind' => 'text', 'label' => 'Astuce', 'translated' => true]);
    }

    private function givenTheModel(): CmsPage
    {
        $model = $this->writer()->createModel('recipe-test', $this->locale(), 'Recette (modèle)');
        $this->writer()->saveContent($model, $this->locale(), Document::richText('<h1>Recette</h1><p>Le modèle de la recette.</p>'));
        $this->writer()->saveFields($model, $this->locale(), ['servings' => 4, 'cooking_time' => 45, 'tip' => 'Servir tiède.']);

        return $model;
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function givenARecipe(string $title, array $fields, bool $publish = true): CmsPage
    {
        $page = $this->createPage($title, published: false);
        $page->setPageType('recipe-test');
        $this->writer()->saveDraft($page, $this->locale(), new PageDraft(title: $title, fields: $fields));

        if ($publish) {
            $this->writer()->publish($page, $this->locale());
        }

        return $page;
    }

    private function titleOf(CmsPage $page): string
    {
        return (string) $page->setLocale($this->locale())->getTitle();
    }

    private function reloaded(CmsPage $page): CmsPage
    {
        CmsPageTableMap::clearInstancePool();

        $reloaded = CmsPageQuery::create()->findPk($page->getId());
        self::assertNotNull($reloaded);

        return $reloaded;
    }

    private function types(): PageTypeRepository
    {
        return $this->getService(PageTypeRepository::class);
    }

    private function typeWriter(): PageTypeWriter
    {
        return $this->getService(PageTypeWriter::class);
    }

    private function fields(): PageFields
    {
        return $this->getService(PageFields::class);
    }

    private function published(): PublishedPageRepository
    {
        return $this->getService(PublishedPageRepository::class);
    }
}
