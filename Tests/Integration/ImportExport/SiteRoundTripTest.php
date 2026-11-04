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

use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\Propel;
use Thelia\Model\ModuleConfigQuery;
use TheliaCMS\Content\Document;
use TheliaCMS\Content\RichTextSection;
use TheliaCMS\Content\Section;
use TheliaCMS\Content\SectionAppearance;
use TheliaCMS\Generation\Brief;
use TheliaCMS\Generation\GeneratorSettings;
use TheliaCMS\ImportExport\ExportFormat;
use TheliaCMS\ImportExport\ImportOptions;
use TheliaCMS\ImportExport\SiteDocument;
use TheliaCMS\ImportExport\SiteExporter;
use TheliaCMS\ImportExport\SiteImporter;
use TheliaCMS\Model\CmsPageContentQuery;
use TheliaCMS\Model\CmsPageQuery;
use TheliaCMS\Model\CmsTag;
use TheliaCMS\Model\Map\CmsPageTableMap;
use TheliaCMS\Model\Map\CmsPageTypeDefinitionTableMap;
use TheliaCMS\Page\Admin\PageDraft;
use TheliaCMS\Page\Field\PageFields;
use TheliaCMS\Page\PageTypeRepository;
use TheliaCMS\Page\PageTypeWriter;
use TheliaCMS\Page\PublishedPageRepository;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;
use TheliaCMS\Tests\Support\FakeAiGateway;
use TheliaCMS\TheliaCMS;
use TheliaLibrary\Model\LibraryImage;

/**
 * The export file is the backup of a site. What it has to hold is not "most of
 * the content" but all of it: emptying the tables and importing the file back
 * has to give the same site.
 *
 * This is the test that used to be a manual procedure.
 */
final class SiteRoundTripTest extends CmsIntegrationTestCase
{
    public function testEmptyingTheSiteAndImportingItBackRestoresTheContent(): void
    {
        $parent = $this->createPage('Nos services', html: '<h1>Nos services</h1><p>Ce que nous faisons.</p>');
        $this->createPage(
            'Conseil et accompagnement',
            parent: (int) $parent->getId(),
            html: '<h1>Conseil</h1><p>Un texte reconnaissable, 42.</p>',
        );
        $this->createPage('Brouillon en cours', published: false);
        // A page the generator wrote, then published: the file carries the mark
        // of its draft and of its publication.
        $generated = $this->createPage('Page générée', published: false);
        $this->writer()->saveContent($generated, $this->locale(), Document::richText('<h1>Page générée</h1><p>Écrite par le modèle.</p>'), FakeAiGateway::provenance());
        $this->writer()->publish($generated, $this->locale());
        $image = new LibraryImage();
        $image->setLocale($this->locale())->setFileName('round-trip-page-image.jpg')->setTitle('Image de page');
        $image->save();
        $parent->setImageId((int) $image->getId());
        $parent->setPageType('full-width');
        $tag = (new CmsTag())->setName('Étiquette aller-retour');
        $tag->save();
        $parent->setTagId((int) $tag->getId());
        $parent->setLocale($this->locale())
            ->setChapo('<p>Un résumé reconnaissable.</p>')
            ->setDescription('<p>Une description reconnaissable.</p>')
            ->save();

        $before = $this->snapshot();

        self::assertArrayHasKey('nos-services', $before);
        self::assertStringContainsString('"model":"fake-model"', (string) $before['page-generee']['published_provenance']);
        self::assertSame($before['page-generee']['draft_provenance'], $before['page-generee']['published_provenance']);
        self::assertNull($before['nos-services']['published_provenance'], 'A page a person wrote has no mark.');
        self::assertArrayHasKey('nos-services/conseil-et-accompagnement', $before);
        self::assertSame('<p>Un résumé reconnaissable.</p>', $before['nos-services']['chapo']);
        self::assertSame('round-trip-page-image.jpg', $before['nos-services']['image_file']);
        self::assertSame('full-width', $before['nos-services']['page_type']);
        self::assertSame('Étiquette aller-retour', $before['nos-services']['tag_name']);
        self::assertStringContainsString(
            'reconnaissable, 42',
            (string) $before['nos-services/conseil-et-accompagnement']['published_html'],
        );

        $document = $this->getService(SiteExporter::class)->export();

        $this->emptyTheSite();
        self::assertSame([], $this->snapshot(), 'The site was not emptied, so the import proves nothing.');

        $report = $this->getService(SiteImporter::class)
            ->import(SiteDocument::fromArray($document), new ImportOptions());

        self::assertSame([], $report->warnings());
        self::assertSame($before, $this->snapshot());
    }

    public function testAMarkOfAFileThatIsNotOneTheCoreWroteIsLeftOut(): void
    {
        $page = $this->createPage('Marque inventée', published: false);
        $file = $this->exportedPage($page);
        $locale = $this->locale();
        $file['pages'][0]['contents'][$locale]['draft_provenance'] = '{"ai_generated":true,"usage":"x","model":"<script>alert(1)</script>"}';
        $file['pages'][0]['contents'][$locale]['published_provenance'] = 'not json';
        $this->emptyTheSite();

        $this->getService(SiteImporter::class)->import(SiteDocument::fromArray($file), new ImportOptions());

        $content = CmsPageContentQuery::create()->findOneByLocale($locale);
        self::assertNull($content?->getDraftProvenance(), 'A mark missing a field of the core is not one.');
        self::assertNull($content?->getPublishedProvenance());
    }

    public function testTheModelKeysOfAnEarlierFileAreIgnoredAndNothingFailsOnThem(): void
    {
        $file = $this->getService(SiteExporter::class)->export();
        $file['settings']['generator_model'] = 'claude-opus-5-5';
        $file['settings']['generator_text_model'] = 'claude-haiku-5';
        $file['settings']['generator_effort'] = 'max';

        $report = $this->getService(SiteImporter::class)->import(SiteDocument::fromArray($file), new ImportOptions(withSettings: true));

        ModuleConfigQuery::resetConfigCache();
        self::assertSame([], $report->warnings(), 'The keys of a setting that no longer exists are no reason to warn.');
        self::assertNull(TheliaCMS::getConfigValue('generator_model', null), 'The model is chosen in the AI settings of the core, never read from a file.');
        self::assertNull(TheliaCMS::getConfigValue('generator_text_model', null));
        self::assertSame('high', $this->getService(GeneratorSettings::class)->effort(), 'The generator settings were never part of an export.');
    }

    public function testASecondImportChangesNothing(): void
    {
        $this->createPage('Page idempotente', html: '<h1>Page idempotente</h1>');

        $document = SiteDocument::fromArray($this->getService(SiteExporter::class)->export());
        $importer = $this->getService(SiteImporter::class);

        $importer->import($document, new ImportOptions());
        $afterFirst = $this->snapshot();

        $report = $importer->import($document, new ImportOptions());

        self::assertSame($afterFirst, $this->snapshot(), 'Importing the same file twice duplicated content.');
        self::assertSame([], $report->createdCounts(), 'Nothing was new, so nothing should have been created.');
    }

    /**
     * A page holding an emoji survives being exported and imported back.
     *
     * The importer writes the content rows itself, without going through the
     * editor, which is exactly why the character is spelled out on the Propel
     * save hook and not in the writers: this is the path that would otherwise
     * hand the four-byte character straight to a statement that refuses it.
     */
    public function testAPageHoldingAnEmojiComesBackFromTheExportUnchanged(): void
    {
        $this->createPage('Page expressive', html: "<h1>Page expressive</h1><p>Photo \u{1F4F7} du studio</p>");

        $before = $this->snapshot();

        self::assertStringContainsString('&#128247;', (string) $before['page-expressive']['published_html']);

        $document = SiteDocument::fromArray($this->getService(SiteExporter::class)->export());
        $this->emptyTheSite();
        $report = $this->getService(SiteImporter::class)->import($document, new ImportOptions());

        self::assertSame([], $report->warnings());
        self::assertSame($before, $this->snapshot(), 'The emoji did not come back the way it went in.');
    }

    public function testThePreciseRoomOfASectionComesBackFromTheExport(): void
    {
        $page = $this->createPage('Page a l espace', published: false);
        $this->writer()->saveContent($page, $this->locale(), new Document([
            new Section('intro', RichTextSection::NAME, ['html' => '<p>Texte</p>'], new SectionAppearance(background: '#f8d7d7', padding_top: '0px', padding_bottom: '64px', margin_top: '2rem')),
        ]));
        $this->writer()->publish($page, $this->locale());

        $before = $this->snapshot();
        self::assertStringContainsString('padding-top:0px;padding-bottom:64px;margin-top:2rem', (string) $before['page-a-l-espace']['published_html']);

        $document = SiteDocument::fromArray($this->getService(SiteExporter::class)->export());
        $this->emptyTheSite();
        $report = $this->getService(SiteImporter::class)->import($document, new ImportOptions());

        self::assertSame([], $report->warnings());
        self::assertSame($before, $this->snapshot());

        $imported = CmsPageContentQuery::create()->filterByLocale($this->locale())->filterByPublishedHtml('%padding-top:0px%', Criteria::LIKE)->findOne();
        self::assertSame(
            ['background' => '#f8d7d7', 'padding_top' => '0px', 'padding_bottom' => '64px', 'margin_top' => '2rem'],
            Document::fromJson($imported?->getDraftDocument())?->section('intro')?->appearance->toArray(),
            'The document of the draft carries the room as it was written.',
        );
    }

    public function testAnImportedPageIsNotFlaggedAsChangedSinceItsPublication(): void
    {
        $this->createPage('Page publiée', html: '<h1>Page publiée</h1>');

        $document = SiteDocument::fromArray($this->getService(SiteExporter::class)->export());
        $this->emptyTheSite();
        $this->getService(SiteImporter::class)->import($document, new ImportOptions());

        $rows = $this->query(
            "SELECT c.updated_at, c.published_at
             FROM cms_page_content c
             INNER JOIN cms_page_i18n i ON i.id = c.page_id AND i.locale = c.locale
             WHERE i.title = 'Page publiée'",
        );

        self::assertCount(1, $rows);
        self::assertNotNull($rows[0]['published_at']);
        self::assertLessThanOrEqual(
            $rows[0]['published_at'],
            $rows[0]['updated_at'],
            'The restored page says it holds unpublished work, so the back office asks to publish it again.',
        );
    }

    /**
     * Everything a visitor would see, keyed by address rather than by id: the
     * ids of an imported site are new, and comparing them would only prove that
     * auto-increment works.
     *
     * @return array<string, array<string, string|null>>
     */
    private function snapshot(): array
    {
        $rows = $this->query(
            "SELECT u.url,
                    i.title,
                    i.locale,
                    p.page_type,
                    tag.name AS tag_name,
                    i.chapo,
                    i.description,
                    c.published_html,
                    c.published_at,
                    c.draft_provenance,
                    c.published_provenance,
                    parent_i18n.title AS parent_title,
                    image.file_name AS image_file
             FROM rewriting_url u
             INNER JOIN cms_page p ON p.id = u.view_id AND p.deleted_at IS NULL
             INNER JOIN cms_page_i18n i ON i.id = p.id AND i.locale = u.view_locale
             LEFT JOIN cms_page_content c ON c.page_id = p.id AND c.locale = u.view_locale
             LEFT JOIN cms_page_i18n parent_i18n ON parent_i18n.id = p.parent AND parent_i18n.locale = u.view_locale
             LEFT JOIN library_image image ON image.id = p.image_id
             LEFT JOIN cms_tag tag ON tag.id = p.tag_id
             WHERE u.view = 'cmspage' AND u.redirected IS NULL
             ORDER BY u.url",
        );

        $snapshot = [];

        foreach ($rows as $row) {
            $url = (string) $row['url'];
            unset($row['url']);
            $snapshot[$url] = $row;
        }

        return $snapshot;
    }

    /**
     * DELETE rather than TRUNCATE: the transaction of the test case has to be
     * able to roll this back, and TRUNCATE commits on MySQL.
     */
    /**
     * A file written by 1.x holds the markup of the former editor: its pages
     * come in without content, and the report says so once.
     */
    public function testAFileWrittenByTheFormerEditorImportsEverythingButTheContents(): void
    {
        $locale = $this->locale();
        $document = SiteDocument::fromArray([
            'format' => 'thelia-cms',
            'version' => 1,
            'module_version' => '1.5.0',
            'locales' => [$locale],
            'pages' => [[
                'uid' => 'page-1',
                'parent' => null,
                'position' => 0,
                'visible' => true,
                'page_type' => 'default',
                'translations' => [$locale => ['title' => 'Page venue de 1.5.0', 'slug' => 'page-venue-de-1-5-0']],
                'contents' => [$locale => [
                    'project_data' => '{"pages":[]}',
                    'draft_html' => '<div class="cms-page-content"><p>Ancien</p></div>',
                    'draft_css' => '',
                    'published_html' => '<div class="cms-page-content"><p>Ancien</p></div>',
                    'published_css' => '',
                    'published_at' => '2026-01-01T00:00:00+00:00',
                ]],
            ]],
        ]);

        $report = $this->getService(SiteImporter::class)->import($document, new ImportOptions());

        self::assertCount(1, $report->warnings());
        self::assertStringContainsString('earlier version of the module', $report->warnings()[0]);

        $rows = $this->query(
            "SELECT i.title, c.published_at, c.draft_document
             FROM cms_page_i18n i
             LEFT JOIN cms_page_content c ON c.page_id = i.id AND c.locale = i.locale
             WHERE i.title = 'Page venue de 1.5.0'",
        );

        self::assertCount(1, $rows, 'The page itself is imported.');
        self::assertNull($rows[0]['published_at'], 'Markup without a document cannot go online.');
        self::assertNull($rows[0]['draft_document']);
    }

    /**
     * A file written before the blocks were removed: the rest comes in, and
     * the report says the blocks were left out.
     */
    public function testTheBlocksOfAnEarlierFileAreLeftOutAndSaidSo(): void
    {
        $locale = $this->locale();
        $document = SiteDocument::fromArray([
            'format' => 'thelia-cms',
            'version' => 2,
            'module_version' => '2.0.0',
            'locales' => [$locale],
            'pages' => [[
                'uid' => 'page-1',
                'parent' => null,
                'position' => 0,
                'visible' => true,
                'page_type' => 'default',
                'translations' => [$locale => ['title' => 'Page à côté des blocs', 'slug' => 'page-a-cote-des-blocs']],
            ]],
            'blocks' => [
                ['code' => 'reassurance', 'translations' => [$locale => ['title' => 'Réassurance']], 'contents' => []],
                ['code' => 'horaires', 'translations' => [$locale => ['title' => 'Horaires']], 'contents' => []],
            ],
        ]);

        $report = $this->getService(SiteImporter::class)->import($document, new ImportOptions());

        self::assertSame(['This file holds 2 block(s), which this version of the module no longer has: they were left out.'], $report->warnings());
        self::assertCount(1, $this->query("SELECT id FROM cms_page_i18n WHERE title = 'Page à côté des blocs'"), 'The page is imported.');
    }

    public function testThePageTypesTheirModelAndTheFieldsOfThePagesComeBack(): void
    {
        $types = $this->getService(PageTypeWriter::class);
        $types->add('recipe-round-trip');
        $types->describe('recipe-round-trip', $this->locale(), 'Recette', 'Les recettes.', new Brief('Une recette de saison.', 'chaleureux'), instructions: '- 300 mots au plus.');
        $types->saveField('recipe-round-trip', null, $this->locale(), ['code' => 'servings', 'kind' => 'integer', 'label' => 'Personnes']);
        $types->saveField('recipe-round-trip', null, $this->locale(), ['code' => 'tip', 'kind' => 'text', 'label' => 'Astuce', 'translated' => true]);
        $model = $this->writer()->createModel('recipe-round-trip', $this->locale(), 'Recette (modèle)');
        $this->writer()->saveFields($model, $this->locale(), ['servings' => 4]);
        $page = $this->createPage('Tarte aux pommes');
        $page->setPageType('recipe-round-trip');
        $this->writer()->saveDraft($page, $this->locale(), new PageDraft(title: 'Tarte aux pommes', fields: ['servings' => 6, 'tip' => 'Servir tiède.']));

        $document = $this->getService(SiteExporter::class)->export();

        $this->emptyTheSite();
        $this->getPropelConnection()->exec("DELETE FROM cms_page_type WHERE code = 'recipe-round-trip'");
        CmsPageTypeDefinitionTableMap::clearInstancePool();

        $report = $this->getService(SiteImporter::class)->import(SiteDocument::fromArray($document), new ImportOptions());

        self::assertSame([], $report->warnings());

        $repository = $this->getService(PageTypeRepository::class);
        $details = $repository->details('recipe-round-trip');
        self::assertSame('Recette', $details->title($this->locale()));
        self::assertSame('chaleureux', $details->brief()?->tone);
        self::assertSame('- 300 mots au plus.', $details->instructions(), 'The instructions travel with the type.');
        self::assertSame(['servings', 'tip'], array_map(static fn ($field): string => $field->code, $details->fields()->fields));

        $importedModel = $repository->modelOf('recipe-round-trip');
        self::assertNotNull($importedModel, 'The model comes back as the model.');

        CmsPageTableMap::clearInstancePool();
        $imported = CmsPageQuery::create()->filterByPageType('recipe-round-trip')->filterByModel(0)->findOne();
        self::assertNotNull($imported);
        self::assertSame(['servings' => 6, 'tip' => 'Servir tiède.'], $this->getService(PageFields::class)->valuesOf($imported, $this->locale()));
    }

    /**
     * The model of a type is never published, and the front shows nothing but
     * published content: a file whose model carries a published version, edited
     * by hand or written elsewhere, must not put the model online, even when
     * the model is set visible.
     */
    public function testAModelImportedWithAPublishedVersionIsNotOnline(): void
    {
        $this->getService(PageTypeWriter::class)->add('recipe-model-online');
        $model = $this->writer()->createModel('recipe-model-online', $this->locale(), 'Recette (modèle) en ligne');

        $document = $this->getService(SiteExporter::class)->export();

        foreach ($document['pages'] as $index => $page) {
            if (true === $page['model'] && 'recipe-model-online' === $page['page_type']) {
                // Online, as the model of this shop is: its visibility is a
                // setting of the edit screen, and the file carries it.
                $document['pages'][$index]['visible'] = true;
                $document['pages'][$index]['contents'][$this->locale()]['published_html'] = '<div class="cms-page-content"><p>Modèle publié</p></div>';
                $document['pages'][$index]['contents'][$this->locale()]['published_at'] = '2026-01-01T10:00:00+00:00';
            }
        }

        $this->emptyTheSite();
        $this->getPropelConnection()->exec("DELETE FROM cms_page_type WHERE code = 'recipe-model-online'");
        CmsPageTypeDefinitionTableMap::clearInstancePool();

        $this->getService(SiteImporter::class)->import(SiteDocument::fromArray($document), new ImportOptions());

        CmsPageTableMap::clearInstancePool();
        $imported = $this->getService(PageTypeRepository::class)->modelOf('recipe-model-online');

        self::assertNotNull($imported, 'The model comes back as the model.');
        self::assertSame(0, $imported->getVisible(), 'A model comes in offline, whatever the file says.');
        self::assertNull(
            $this->getService(PublishedPageRepository::class)->find((int) $imported->getId(), $this->locale()),
            'The model of a type is never served to a visitor.',
        );
        self::assertSame([], $this->getService(PublishedPageRepository::class)->publishedLocales((int) $imported->getId()), 'The model is published in no language.');
        unset($model);
    }

    /**
     * A file written before the types had instructions: the type comes in
     * with its brief, and without instructions.
     */
    public function testATypeOfAFileWithoutInstructionsImports(): void
    {
        $document = [
            ...ExportFormat::header('test', [$this->locale()], new \DateTimeImmutable()),
            'page_types' => [['code' => 'recipe-before-instructions', 'brief' => 'Une recette de saison.', 'brief_tone' => null, 'brief_audience' => null, 'fields' => [], 'translations' => []]],
        ];

        $report = $this->getService(SiteImporter::class)->import(SiteDocument::fromArray($document), new ImportOptions());

        self::assertSame([], $report->warnings());
        $details = $this->getService(PageTypeRepository::class)->details('recipe-before-instructions');
        self::assertSame('Une recette de saison.', $details->brief()?->text);
        self::assertNull($details->instructions());
    }

    public function testATypeTheSiteDescribedAlreadyKeepsItsFields(): void
    {
        $types = $this->getService(PageTypeWriter::class);
        $types->add('recipe-kept');
        $types->saveField('recipe-kept', null, $this->locale(), ['code' => 'servings', 'kind' => 'integer', 'label' => 'Personnes']);

        $document = [
            ...ExportFormat::header('test', [$this->locale()], new \DateTimeImmutable()),
            'page_types' => [['code' => 'recipe-kept', 'fields' => [['code' => 'people', 'kind' => 'integer']], 'translations' => []]],
        ];

        $report = $this->getService(SiteImporter::class)->import(SiteDocument::fromArray($document), new ImportOptions());

        self::assertCount(1, $report->warnings());
        self::assertStringContainsString('recipe-kept', $report->warnings()[0]);
        self::assertNotNull($this->getService(PageTypeRepository::class)->details('recipe-kept')->fields()->find('servings'));
    }

    private function emptyTheSite(): void
    {
        $connection = $this->getPropelConnection();

        foreach ([
            'cms_page_search',
            'cms_page_revision',
            'cms_page_content',
            'cms_page_i18n',
            'cms_page',
        ] as $table) {
            $connection->exec('DELETE FROM '.$table);
        }

        $connection->exec("DELETE FROM rewriting_url WHERE view = 'cmspage'");
    }

    /**
     * @return list<array<string, string|null>>
     */
    private function query(string $sql): array
    {
        $statement = Propel::getConnection('TheliaMain')->prepare($sql);
        $statement->execute();

        return $statement->fetchAll(\PDO::FETCH_ASSOC);
    }
}
