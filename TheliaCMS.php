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

namespace TheliaCMS;

use FlexyBundle\Toolkit\StoryProviderInterface;
use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\Connection\ConnectionInterface;
use Propel\Runtime\Propel;
use SEOne\Service\SeoDefaultModels\SeoElementInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ServicesConfigurator;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Install\Database;
use Thelia\Core\SharedCache\SharedCacheContributorInterface;
use Thelia\Model\ConfigQuery;
use Thelia\Model\LangQuery;
use Thelia\Model\ProfileQuery;
use Thelia\Model\ProfileResource;
use Thelia\Model\ProfileResourceQuery;
use Thelia\Model\Resource;
use Thelia\Model\ResourceQuery;
use Thelia\Model\RewritingUrlQuery;
use Thelia\Module\BaseModule;
use TheliaCMS\Content\Section\SectionStories;
use TheliaCMS\Http\SharedCache\CmsSharedCacheContributor;
use TheliaCMS\Http\SharedCache\CoreSharedCache;
use TheliaCMS\Http\SharedCache\SharedMaxAgeCapListener;
use TheliaCMS\Install\HomePageSeeder;
use TheliaCMS\Install\LegalPagesSeeder;
use TheliaCMS\Install\MenuSeeder;
use TheliaCMS\Model\CmsPageContentQuery;
use TheliaCMS\Model\CmsPageQuery;
use TheliaCMS\Notice\NativeContentConsoleNotice;
use TheliaCMS\Page\CmsUrlService;
use TheliaCMS\Partial\PartialFragmentRenderer;
use TheliaCMS\Partial\PartialFragmentRendererInterface;
use TheliaCMS\Search\Tnt\CmsPageIndex;
use TheliaCMS\Security\CmsAdminResourcesCompiler;
use TheliaCMS\Security\CmsResources;
use TheliaCMS\Seo\CmsPageHreflangListener;
use TheliaCMS\Seo\CmsPageSeoElement;
use TheliaCMS\Seo\PageCanonicalListener;
use TheliaCMS\Seo\StandInPageCanonicalListener;
// The class CmsPageIndex extends. Importing it costs nothing when TntSearch is
// absent, and without the import the guard below would name TheliaCMS\BaseIndex.
use TntSearch\Index\BaseIndex;

class TheliaCMS extends BaseModule
{
    public const string DOMAIN_NAME = 'theliacms';

    /** Value written in `rewriting_url.view` for every CMS page URL. */
    public const string PAGE_VIEW = 'cmspage';

    /**
     * Request attribute holding the id of a page served in place of what the
     * address designates — the page shown on an address that does not exist.
     *
     * Whatever describes a response reads the request, not the render, so this
     * is how those readers are told the two do not agree.
     */
    public const string STAND_IN_PAGE_ATTRIBUTE = '_cms_stand_in_page_id';

    /**
     * Request attribute holding the id of the CMS page a response renders, set
     * before it is rendered, on its own address or in place of a missing one.
     * Never set by a preview: what reads it is told a visitor sees this page.
     */
    public const string SERVED_PAGE_ATTRIBUTE = '_cms_served_page_id';

    /**
     * The canonical address the editor typed for the page a response renders,
     * set before it is rendered: the head of the theme asks SEOne for it
     * halfway through the rendering, and PageCanonicalListener answers.
     */
    public const string PAGE_CANONICAL_ATTRIBUTE = '_cms_page_canonical';

    public function preActivation(?ConnectionInterface $con = null): bool
    {
        // Every CMS page is served through a rewritten URL. With URL rewriting
        // off, activating the module would publish pages that can never be
        // reached, so refuse loudly instead of failing later on the front.
        if ('1' !== ConfigQuery::read('rewriting_enable', '0')) {
            throw new \RuntimeException('Thelia CMS requires URL rewriting. Enable "rewriting_enable" in the store configuration, then activate the module again.');
        }

        $this->assertFullTextSearchIsAvailable();

        return true;
    }

    public function postActivation(?ConnectionInterface $con = null): void
    {
        if (!self::getConfigValue('is_initialized')) {
            $this->installSchema($con);

            // A site that lost this flag but kept its pages (a config purged by
            // hand, a restored dump) already has its home and legal pages, or
            // chose not to.
            if (0 === CmsPageQuery::create()->count()) {
                (new HomePageSeeder())->seed();
                (new LegalPagesSeeder())->seed();
            }

            self::setConfigValue('is_initialized', '1');
        }

        $this->createSearchIndex();
        $this->seedAdminResources();
        (new MenuSeeder())->seed();
        $this->regenerateRewrittenUrls();
        $this->tellTheConsoleAboutTheExistingContents();
    }

    /**
     * A shop that already has contents is told, once the command that
     * activated the module ends, that they stay in Folders and that nothing is
     * converted. The Pages screen says the same in the back office.
     *
     * The console output is out of reach from here, and the services of this
     * module do not exist yet in the container of the command that activates
     * it: the dispatcher of that container is what carries the line to the end
     * of the command. Outside a console command the listener simply never runs.
     */
    private function tellTheConsoleAboutTheExistingContents(): void
    {
        if (!$this->hasContainer() || !$this->getContainer()->has('event_dispatcher')) {
            return;
        }

        $dispatcher = $this->getContainer()->get('event_dispatcher');

        if ($dispatcher instanceof EventDispatcherInterface) {
            NativeContentConsoleNotice::writeWhenTheCommandEnds($dispatcher);
        }
    }

    /**
     * Creates the tables of the module, unless they exist already.
     *
     * A fresh install of Thelia creates them itself, with every update file
     * played on top, before the module is ever activated: `TheliaMain.sql`
     * drops each table before creating it, so playing it again there emptied
     * the tables the install had just filled, the contact form with them.
     *
     * When the tables are created here, the update files follow: the schema
     * file holds no rows, and the rows every site starts with (the two menus,
     * the contact form) are seeded by the update file that introduced their
     * table. Those files tolerate being applied twice.
     */
    private function installSchema(?ConnectionInterface $con): void
    {
        if ($this->schemaIsInstalled($con)) {
            return;
        }

        $database = new Database($con);
        $database->insertSql(null, [__DIR__.'/Config/TheliaMain.sql']);

        foreach (self::everyMigration() as $file) {
            $database->insertSql(null, [$file]);
        }
    }

    private function schemaIsInstalled(?ConnectionInterface $con): bool
    {
        $connection = $con ?? Propel::getConnection('TheliaMain');

        $statement = $connection->prepare(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table'
        );
        $statement->execute(['table' => 'cms_page']);

        return (int) $statement->fetchColumn() > 0;
    }

    /**
     * Applies the schema changes of every version between the one installed and
     * the one shipped.
     *
     * The core replays `Config/update/*.sql` on a fresh install and through
     * `module:schema:apply`, but a site that merely updates the module goes
     * through here — without it, new tables would only ever appear on new sites.
     * The files are written to tolerate being applied twice.
     *
     * Only SQL here, no model: the Propel runtime map of this process was built
     * before the statements below ran, so the tables they create have no table
     * map yet and any query against them fails. That is why the seed rows of a
     * new table are part of its update file rather than of a PHP seeder.
     */
    public function update($currentVersion, $newVersion, ?ConnectionInterface $con = null): void
    {
        foreach (self::migrationsBetween((string) $currentVersion, (string) $newVersion) as $file) {
            (new Database($con))->insertSql(null, [$file]);
        }

        // A site active before a right existed only runs this on its update:
        // seeded on activation alone, the right would stay denied to every
        // profile but the super administrator.
        $this->seedAdminResources();
    }

    /**
     * The migration files to apply to move a site from one version to another,
     * oldest first.
     *
     * @return list<string> absolute paths
     */
    public static function migrationsBetween(string $currentVersion, string $newVersion): array
    {
        return array_values(array_filter(self::everyMigration(), static function (string $file) use ($currentVersion, $newVersion): bool {
            $version = basename($file, '.sql');

            return version_compare($version, $currentVersion, '>') && version_compare($version, $newVersion, '<=');
        }));
    }

    /**
     * Every migration file the module ships, oldest first.
     *
     * Ordered by version rather than by name: a plain sort puts 0.10.0 before
     * 0.9.0, and a table would then be altered before it is created.
     *
     * @return list<string> absolute paths
     */
    public static function everyMigration(): array
    {
        $files = glob(__DIR__.'/Config/update/*.sql') ?: [];
        usort($files, static fn (string $a, string $b): int => version_compare(basename($a, '.sql'), basename($b, '.sql')));

        return $files;
    }

    /**
     * Rebuilds the page URLs dropped by preDeactivation, so deactivating and
     * reactivating the module leaves the site exactly as it was rather than
     * silently 404ing every page.
     *
     * Each page is put back on the slug it answered on, read from the page
     * itself. Deriving it from the title again would rename every address an
     * author had edited, and leave no redirection behind: a deactivation
     * somebody undid a minute later would cost the site every indexed address
     * it has.
     */
    private function regenerateRewrittenUrls(): void
    {
        $urls = new CmsUrlService();

        $pages = CmsPageQuery::create()
            ->filterByDeletedAt(null, Criteria::ISNULL)
            ->find();

        foreach ($pages as $page) {
            $locales = CmsPageContentQuery::create()
                ->filterByPageId($page->getId())
                ->select(['Locale'])
                ->find()
                ->toArray();

            foreach (array_unique($locales) as $locale) {
                $urls->rebuild($page, (string) $locale);
            }
        }
    }

    /**
     * Declaring a resource to the container is not enough: permissions are read
     * from `profile_resource`, so a resource with no row there is denied to
     * every profile but the super administrator, and the back office offers no
     * way to grant it.
     */
    private function seedAdminResources(): void
    {
        foreach (CmsResources::all() as $code) {
            if (null !== ResourceQuery::create()->findOneByCode($code)) {
                continue;
            }

            $resource = new Resource();
            $resource->setCode($code);

            foreach (LangQuery::create()->find() as $lang) {
                $resource->setLocale($lang->getLocale())->setTitle($this->resourceTitle($code, $lang->getLocale()));
            }

            $resource->save();

            // Existing profiles start with no access at all: an administrator
            // opens them deliberately from the profile screen. A right split
            // off an older one is the exception: each profile keeps what it
            // could do, with the access it had on the older right.
            $origin = CmsResources::splitFrom()[$code] ?? null;

            foreach (ProfileQuery::create()->find() as $profile) {
                (new ProfileResource())
                    ->setProfileId($profile->getId())
                    ->setResourceId($resource->getId())
                    ->setAccess(null === $origin ? 0 : $this->accessOf($profile->getId(), $origin))
                    ->save();
            }
        }
    }

    /**
     * The access a profile has on a resource of the module, 0 when it has none.
     */
    private function accessOf(int $profileId, string $code): int
    {
        $resource = ResourceQuery::create()->findOneByCode($code);

        if (null === $resource) {
            return 0;
        }

        $granted = ProfileResourceQuery::create()
            ->filterByProfileId($profileId)
            ->filterByResourceId($resource->getId())
            ->findOne();

        return null === $granted ? 0 : (int) $granted->getAccess();
    }

    private function resourceTitle(string $code, string $locale): string
    {
        $labels = [
            CmsResources::PAGE => ['en_US' => 'CMS: pages', 'fr_FR' => 'CMS : pages'],
            CmsResources::MENU => ['en_US' => 'CMS: menus', 'fr_FR' => 'CMS : menus'],
            CmsResources::FORM => ['en_US' => 'CMS: forms', 'fr_FR' => 'CMS : formulaires'],
            CmsResources::FORM_ANSWERS => ['en_US' => 'CMS: answers to the forms', 'fr_FR' => 'CMS : réponses aux formulaires'],
            CmsResources::FORM_ANSWERS_EXPORT => ['en_US' => 'CMS: export of the answers', 'fr_FR' => 'CMS : export des réponses'],
            CmsResources::MEDIA => ['en_US' => 'CMS: media', 'fr_FR' => 'CMS : médias'],
            CmsResources::SETTINGS => ['en_US' => 'CMS: settings', 'fr_FR' => 'CMS : réglages'],
            CmsResources::CUSTOM_CODE => ['en_US' => 'CMS: custom code', 'fr_FR' => 'CMS : code libre'],
            CmsResources::GENERATE => ['en_US' => 'CMS: page generation', 'fr_FR' => 'CMS : génération de pages'],
        ];

        return $labels[$code][$locale] ?? $labels[$code]['en_US'] ?? $code;
    }

    /**
     * Rewritten URLs outlive a deactivated module: left behind, they keep
     * routing visitors to a `cmspage` view nothing can render any more, which
     * is a 500 and not a 404. They are dropped here and regenerated from the
     * pages on the next activation.
     *
     * `markRewrittenUrlObsolete()` is deliberately not used: it points the URL
     * at an `obsolete-rewritten-url` view no shipped theme provides a template
     * for.
     */
    public function preDeactivation(?ConnectionInterface $con = null): bool
    {
        RewritingUrlQuery::create()
            ->filterByView(self::PAGE_VIEW)
            ->delete($con);

        return true;
    }

    public static function getCompilers(): array
    {
        return [new CmsAdminResourcesCompiler()];
    }

    public static function configureServices(ServicesConfigurator $servicesConfigurator): void
    {
        $servicesConfigurator->load(self::getModuleCode().'\\', __DIR__)
            ->exclude([
                __DIR__.'/I18n/*',
                __DIR__.'/Config/*',
                __DIR__.'/Model/*',
                __DIR__.'/templates/*',
                // Test doubles implement the module's own interfaces, so
                // autoconfiguration would tag them and the editor would offer
                // a "Fake" block in production.
                __DIR__.'/Tests/*',
                // Value objects: instantiated by the services that build them,
                // never wired by the container.
                __DIR__.'/Catalogue/CatalogueItem.php',
                __DIR__.'/Evaluation/Run.php',
                __DIR__.'/Evaluation/BriefReport.php',
                __DIR__.'/Content/Document.php',
                __DIR__.'/Content/Section.php',
                __DIR__.'/Content/Pipeline/StimulusAttributeSanitizer.php',
                __DIR__.'/Http/ContentSecurityPolicy.php',
                __DIR__.'/Content/Prop.php',
                __DIR__.'/Content/Editing/ContentKey.php',
                __DIR__.'/Content/Editing/ContentSnapshot.php',
                __DIR__.'/Content/Editing/Revision.php',
                __DIR__.'/Content/Editing/RestorableDraft.php',
                __DIR__.'/Content/Editing/TranslationSource.php',
                __DIR__.'/Content/Editing/TranslationLink.php',
                __DIR__.'/Generation/TranslationBackfillOutcome.php',
                // Built per call by ContentTargets, around the page or the item they serve.
                __DIR__.'/Page/Admin/PageContentTarget.php',
                __DIR__.'/Page/Admin/PageContentRow.php',
                // Built by LinkedPagesFinder for the drawer of the pages of an element.
                __DIR__.'/Page/Admin/LinkedPage.php',
                __DIR__.'/Page/Admin/LinkedPages.php',
                __DIR__.'/Zone/ZoneContentTarget.php',
                __DIR__.'/Catalogue/Admin/ItemContentTarget.php',
                __DIR__.'/Catalogue/Admin/ItemContentRow.php',
                __DIR__.'/Content/PropSchema.php',
                __DIR__.'/Content/PropType.php',
                __DIR__.'/Content/EmbedPlatform.php',
                __DIR__.'/Content/EmbedAddress.php',
                __DIR__.'/Content/InvalidDocumentException.php',
                __DIR__.'/Content/UnknownSectionException.php',
                // Built by their providers, one per partial or per component
                // of the theme, never wired by the container.
                __DIR__.'/Content/Partial/PartialSection.php',
                __DIR__.'/Content/Theme/ThemeComponentSection.php',
                __DIR__.'/Content/Theme/ThemeSections.php',
                __DIR__.'/Content/Site/SiteComponentSection.php',
                __DIR__.'/Content/Site/SiteComponent.php',
                __DIR__.'/Content/Site/InvalidSiteComponentException.php',
                __DIR__.'/Generation/Brief.php',
                __DIR__.'/Generation/ReferenceFile.php',
                __DIR__.'/Generation/ReferenceFileRefusedException.php',
                __DIR__.'/Generation/Component/ComponentRequest.php',
                __DIR__.'/Generation/Component/ComponentResult.php',
                __DIR__.'/Generation/Component/ComponentCandidate.php',
                __DIR__.'/Generation/GenerationRequest.php',
                __DIR__.'/Generation/GenerationResult.php',
                __DIR__.'/Generation/PageHeader.php',
                __DIR__.'/Generation/HeaderOutcome.php',
                __DIR__.'/Generation/Alongside.php',
                __DIR__.'/Generation/GenerationTarget.php',
                __DIR__.'/Generation/DocumentProblem.php',
                __DIR__.'/Generation/Admin/ContentSubject.php',
                __DIR__.'/Generation/Admin/GenerationOutcome.php',
                __DIR__.'/Generation/Usage.php',
                __DIR__.'/Skill/Skill.php',
                // Registered below, guarded: it implements an interface of the
                // Flexy theme.
                __DIR__.'/Content/Section/SectionStories.php',
                __DIR__.'/Page/Document/PageDocument.php',
                __DIR__.'/Page/Document/PageDocumentLink.php',
                __DIR__.'/Page/Document/PageDocumentUpload.php',
                __DIR__.'/Page/Admin/PageDocumentRow.php',
                __DIR__.'/Page/PageCard.php',
                __DIR__.'/Page/PageImage.php',
                __DIR__.'/Page/PageTagName.php',
                __DIR__.'/Page/PageTemplate.php',
                __DIR__.'/Page/PageTypeCode.php',
                __DIR__.'/Page/PublishedPage.php',
                __DIR__.'/Partial/Catalogue/PickableItem.php',
                __DIR__.'/Partial/PartialProp.php',
                __DIR__.'/Settings/ElementStyle.php',
                __DIR__.'/Settings/SiteTypography.php',
                // Registered below, guarded: it implements a SEOne interface.
                __DIR__.'/Seo/*',
                // Same, for TntSearch: the class extends one of its own, so
                // reflecting it on a site without the module is a fatal error.
                __DIR__.'/Search/Tnt/*',
                // Same, for the shared cache of the public pages of the core,
                // absent from a core that predates it.
                __DIR__.'/Http/SharedCache/*',
            ])
            ->autowire(true)
            ->autoconfigure(true);

        // Dynamic blocks are rendered through an interface so the substitution
        // of a page can be unit-tested without a theme behind it.
        $servicesConfigurator->alias(PartialFragmentRendererInterface::class, PartialFragmentRenderer::class);

        // SEOne is a soft dependency. Autodiscovering CmsPageSeoElement would
        // reflect a class implementing an interface that does not exist on a
        // site running without SEOne, which is a fatal error, not a skip.
        if (interface_exists(SeoElementInterface::class)) {
            $servicesConfigurator->set(CmsPageSeoElement::class)
                ->autowire(true)
                ->autoconfigure(true);

            $servicesConfigurator->set(CmsPageHreflangListener::class)
                ->autowire(true)
                ->autoconfigure(true);

            $servicesConfigurator->set(StandInPageCanonicalListener::class)
                ->autowire(true)
                ->autoconfigure(true);

            $servicesConfigurator->set(PageCanonicalListener::class)
                ->autowire(true)
                ->autoconfigure(true);
        }

        // The shared cache of the core: the pages of the module are shared under
        // its view and its tags, and every purge of the module reaches it.
        if (interface_exists(SharedCacheContributorInterface::class)) {
            $servicesConfigurator->set(CmsSharedCacheContributor::class)
                ->autoconfigure(true);

            $servicesConfigurator->set(CoreSharedCache::class)
                ->autowire(true)
                ->autoconfigure(true);

            $servicesConfigurator->set(SharedMaxAgeCapListener::class)
                ->autowire(true)
                ->autoconfigure(true);
        }

        // The toolkit of the Flexy theme lists the sections of the module when
        // the theme is there; another theme has no toolkit to list them in.
        if (interface_exists(StoryProviderInterface::class)) {
            $servicesConfigurator->set(SectionStories::class)
                ->autowire(true)
                ->autoconfigure(true);
        }

        // TntSearch is the other soft dependency. Its compiler pass collects
        // the `tntsearch.index` tag, so declaring the service is all it takes;
        // without the module, the built-in FULLTEXT search answers alone.
        if (class_exists(BaseIndex::class)) {
            $servicesConfigurator->set(CmsPageIndex::class)
                ->autowire(true)
                ->tag('tntsearch.index');
        }
    }

    /**
     * The front search (§2.11) runs on a native FULLTEXT index with no LIKE
     * fallback, so the storage engine has to support one before any page is
     * published.
     */
    private function assertFullTextSearchIsAvailable(): void
    {
        $connection = Propel::getConnection('TheliaMain');

        if ('mysql' !== $connection->getAttribute(\PDO::ATTR_DRIVER_NAME)) {
            throw new \RuntimeException('Thelia CMS requires a MySQL or MariaDB database: its front-office search relies on a native FULLTEXT index.');
        }

        $version = (string) $connection->getAttribute(\PDO::ATTR_SERVER_VERSION);

        if (str_contains(strtolower($version), 'mariadb')) {
            $supported = version_compare($this->normalizeVersion($version), '10.0.5', '>=');
        } else {
            $supported = version_compare($this->normalizeVersion($version), '5.6.0', '>=');
        }

        if (!$supported) {
            throw new \RuntimeException(\sprintf('Thelia CMS requires InnoDB FULLTEXT support (MySQL 5.6+ or MariaDB 10.0.5+), server reports "%s".', $version));
        }
    }

    private function normalizeVersion(string $version): string
    {
        preg_match('/\d+(\.\d+)*/', $version, $matches);

        return $matches[0] ?? '0';
    }

    /**
     * Kept out of TheliaMain.sql on purpose: that file is only replayed on the
     * very first activation and Propel has no portable FULLTEXT declaration.
     */
    private function createSearchIndex(): void
    {
        $connection = Propel::getConnection('TheliaMain');

        $exists = $connection->prepare(
            'SELECT COUNT(*) FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = :table AND index_name = :index'
        );
        $exists->execute(['table' => 'cms_page_search', 'index' => 'ft_cms_page_search_content']);

        if ((int) $exists->fetchColumn() > 0) {
            return;
        }

        $connection->exec('ALTER TABLE `cms_page_search` ADD FULLTEXT INDEX `ft_cms_page_search_content` (`content`)');
    }
}
