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

namespace TheliaCMS\Tests\Integration\ImportExport;

use TheliaCMS\Content\Document;
use TheliaCMS\Content\Section;
use TheliaCMS\Content\Site\SiteComponent;
use TheliaCMS\Content\Site\SiteComponentRepository;
use TheliaCMS\Content\Site\SiteComponentValidator;
use TheliaCMS\ImportExport\ImportOptions;
use TheliaCMS\ImportExport\SiteDocument;
use TheliaCMS\ImportExport\SiteExporter;
use TheliaCMS\ImportExport\SiteImporter;
use TheliaCMS\Model\CmsComponentQuery;
use TheliaCMS\Model\CmsPageGeneration;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;
use TheliaCMS\Tests\Support\FakeAiGateway;
use TheliaCMS\Tests\Unit\Content\Site\PriceTable;

/**
 * The components the site made travel with its export, before the pages made
 * of them, and are checked again on the way in.
 */
final class SiteComponentImportTest extends CmsIntegrationTestCase
{
    public function testAKeptComponentLeavesWithTheExportAndComesBackBeforeItsPages(): void
    {
        $repository = $this->getService(SiteComponentRepository::class);
        $repository->save((new SiteComponentValidator())->validate(PriceTable::component(css: '.plan { padding: 1rem; }')), SiteComponentRepository::STATUS_READY);
        // A prototype, made from a page: it leaves too, marked as such, since
        // a page of the export may hold it.
        $prototype = PriceTable::component();
        $repository->save((new SiteComponentValidator())->validate(new SiteComponent('Prototyped', $prototype->label, $prototype->description, $prototype->props, $prototype->sample, $prototype->template)), SiteComponentRepository::STATUS_DRAFT);
        $page = $this->createPage('Nos tarifs', published: false);
        $this->writer()->saveContent($page, $this->locale(), new Document([new Section('plans', 'Site:PriceTable', ['plans' => [['name' => 'Studio', 'price' => '40 €']]])]));

        $export = $this->getService(SiteExporter::class)->export();

        self::assertSame(['PriceTable', 'Prototyped'], array_column($export['components'], 'code'), 'What is kept leaves, the prototypes too.');
        self::assertSame(['ready', 'draft'], array_column($export['components'], 'status'), 'A prototype is marked as such.');
        self::assertLessThan(array_search('pages', array_keys($export), true), array_search('components', array_keys($export), true), 'The components come before the pages made of them.');

        $json = json_decode(json_encode($export, \JSON_THROW_ON_ERROR), true);
        CmsComponentQuery::create()->filterByCode(['PriceTable', 'Prototyped'], \Propel\Runtime\ActiveQuery\Criteria::IN)->delete();

        $report = $this->getService(SiteImporter::class)->import(SiteDocument::fromArray($json), new ImportOptions());

        self::assertSame(2, $report->createdCounts()['components'] ?? 0);
        self::assertSame([], $report->warnings());
        self::assertSame('.plan { padding: 1rem; }', $repository->find('PriceTable')?->css);
        self::assertSame(SiteComponentRepository::STATUS_READY, $repository->status('PriceTable'));
        self::assertSame(SiteComponentRepository::STATUS_DRAFT, $repository->status('Prototyped'), 'A prototype comes back as a prototype.');

        $again = $this->getService(SiteImporter::class)->import(SiteDocument::fromArray($json), new ImportOptions());
        self::assertSame(2, $again->skippedCounts()['components'] ?? 0, 'The same components are left alone.');
        self::assertSame([], $again->warnings());
    }

    public function testTheMarkOfAGeneratedComponentTravelsWithIt(): void
    {
        $repository = $this->getService(SiteComponentRepository::class);
        $mark = json_encode(FakeAiGateway::provenance('theliacms.component')->toArray(), \JSON_THROW_ON_ERROR);
        $repository->save((new SiteComponentValidator())->validate(PriceTable::component())->withProvenance($mark), SiteComponentRepository::STATUS_READY);

        $export = $this->getService(SiteExporter::class)->export();
        self::assertSame($mark, $export['components'][0]['provenance']);

        $json = json_decode(json_encode($export, \JSON_THROW_ON_ERROR), true);
        CmsComponentQuery::create()->filterByCode('PriceTable')->delete();
        $this->getService(SiteImporter::class)->import(SiteDocument::fromArray($json), new ImportOptions());

        self::assertSame($mark, $repository->find('PriceTable')?->provenance);
    }

    /**
     * Sabotage: in SiteComponentRepository::save(), keep the call the row has
     * when none is given: the version of the file keeps the mark of the call
     * it replaced.
     */
    public function testAComponentTheSiteHasDifferentlyIsKeptUnlessTheImportReplaces(): void
    {
        $repository = $this->getService(SiteComponentRepository::class);
        $repository->save((new SiteComponentValidator())->validate(PriceTable::component()), SiteComponentRepository::STATUS_READY);
        // The version in place came from a call of the generator.
        $call = (new CmsPageGeneration())->setTargetType('component')->setTargetId($repository->idOf('PriceTable'))->setLocale('fr_FR')->setKind('component_change');
        $call->save();
        $component = $repository->find('PriceTable');
        \assert(null !== $component);
        $repository->save($component, SiteComponentRepository::STATUS_READY, 'PriceTable', (int) $call->getId());
        $file = self::file([self::row(PriceTable::component(css: '.plan { color: var(--cms-color-text); }'))]);

        $kept = $this->getService(SiteImporter::class)->import($file, new ImportOptions());
        self::assertSame('', $repository->find('PriceTable')?->css);
        self::assertStringContainsString('the one of the site was kept', implode(' ', $kept->warnings()));

        $replaced = $this->getService(SiteImporter::class)->import($file, new ImportOptions(replace: true));
        self::assertSame(1, $replaced->replacedCounts()['components'] ?? 0);
        self::assertSame('.plan { color: var(--cms-color-text); }', $repository->find('PriceTable')?->css);
        self::assertNull($repository->generationOf('PriceTable'), 'The version of the file came from no call of this site: no version of the history is in place.');
    }

    public function testAComponentTheSiteRefusesIsLeftOutAndAPageMadeOfAMissingOneIsSaidSo(): void
    {
        $locale = $this->locale();
        $document = json_encode(['version' => 1, 'sections' => [['id' => 'x', 'component' => 'Site:Ghost', 'props' => []]]], \JSON_THROW_ON_ERROR);
        $file = self::file([self::row(PriceTable::component('{% include "base.html.twig" %}'))], [[
            'uid' => 'page-1',
            'parent' => null,
            'position' => 0,
            'visible' => true,
            'page_type' => 'default',
            'translations' => [$locale => ['title' => 'Page fantôme', 'slug' => 'page-fantome']],
            'contents' => [$locale => ['draft_document' => $document, 'draft_html' => '<div></div>']],
        ]], $locale);

        $report = $this->getService(SiteImporter::class)->import($file, new ImportOptions());
        $warnings = implode("\n", $report->warnings());

        self::assertNull($this->getService(SiteComponentRepository::class)->find('PriceTable'), 'A file is no reason to trust a template.');
        self::assertStringContainsString('The component "PriceTable" was left out', $warnings);
        self::assertStringContainsString('"Site:Ghost"', $warnings);
    }

    /**
     * @return array<string, mixed>
     */
    private static function row(SiteComponent $component): array
    {
        return ['code' => $component->code, 'label' => $component->label, 'description' => $component->description, 'props' => $component->props, 'sample' => $component->sample, 'template' => $component->template, 'css' => $component->css];
    }

    /**
     * @param list<array<string, mixed>> $components
     * @param list<array<string, mixed>> $pages
     */
    private static function file(array $components, array $pages = [], string $locale = 'fr_FR'): SiteDocument
    {
        return SiteDocument::fromArray([
            'format' => 'thelia-cms',
            'version' => 2,
            'module_version' => '2.0.0',
            'locales' => [$locale],
            'components' => $components,
            'pages' => $pages,
        ]);
    }
}
