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

namespace TheliaCMS\Tests\Integration;

use Psr\Log\NullLogger;
use Symfony\Component\HttpKernel\KernelInterface;
use Thelia\Core\Template\TemplateDefinition;
use Thelia\Core\Template\TemplateHelperInterface;
use Thelia\Domain\Localization\Service\LangService;
use Thelia\Model\ConfigQuery;
use Thelia\Model\LangQuery;
use Thelia\Test\IntegrationTestCase;
use TheliaCMS\Content\Document;
use TheliaCMS\Content\Theme\ThemeComponentSections;
use TheliaCMS\Http\CachePurger;
use TheliaCMS\Http\CachePurgerInterface;
use TheliaCMS\ImportExport\ExportFormat;
use TheliaCMS\ImportExport\SiteExporter;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Page\Admin\CmsPageWriter;
use TheliaCMS\Page\Admin\PageDraft;
use TheliaCMS\Page\PageInvalidator;
use TheliaCMS\Partial\PartialCache;

/**
 * Shared ground for the integration tests of the module: a booted shop, a
 * transaction rolled back after each test, and pages created the way the back
 * office creates them.
 *
 * Pages go through CmsPageWriter rather than straight into the tables, because
 * publishing is what fills the rewritten URLs and the search index: fixtures
 * written by hand would test a shape of the data that never occurs.
 */
abstract class CmsIntegrationTestCase extends IntegrationTestCase
{
    use BuildsItemTargets;

    /** Checked once per run: the answer cannot change between two test cases. */
    private static ?string $checkedDatabase = null;

    /** @var list<int> ids of the pages created by createPage(), newest last */
    private array $createdPages = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->refuseToRunOnAnythingButTheTestDatabase();
    }

    /**
     * Undoes by hand what the transaction cannot, for the test cases that run
     * without one — see PageSearchTest for why any of them do.
     */
    protected function tearDown(): void
    {
        if (!$this->useTransaction && [] !== $this->createdPages) {
            $connection = $this->getPropelConnection();
            $ids = implode(',', $this->createdPages);

            // The children of a page go with it (FK CASCADE), its URLs do not.
            $connection->exec(\sprintf("DELETE FROM rewriting_url WHERE view = 'cmspage' AND view_id IN (%s)", $ids));
            $connection->exec(\sprintf('DELETE FROM cms_page WHERE id IN (%s)', $ids));
        }

        $this->createdPages = [];

        parent::tearDown();
    }

    /**
     * Stops the run when the tests are connected to anything but the database
     * `.env.test` names.
     *
     * Not a hypothetical. A container that exports `DATABASE_NAME` into the
     * shell wins over `.env.test`, because Dotenv does not overwrite variables
     * that are already set, and the Propel configuration is generated from
     * whichever value is in scope. The suite then runs against the *shop*
     * database, and the cases that deliberately work outside a transaction, the
     * module lifecycle among them, delete every CMS address of a live site and
     * rebuild it. That is how a real site lost its 124 addresses.
     *
     * `bin/test-prepare` clears those variables before generating the Propel
     * configuration, which is why the suite is normally fine. This is the guard
     * for the run where somebody purged `var/propel/test` and went straight to
     * phpunit.
     */
    private function refuseToRunOnAnythingButTheTestDatabase(): void
    {
        $connection = $this->getPropelConnection();

        self::$checkedDatabase ??= (string) $connection->query('SELECT DATABASE()')->fetchColumn();

        $declared = $this->databaseNamedInEnvTest();

        if (null === $declared || $declared === self::$checkedDatabase) {
            return;
        }

        self::fail(\sprintf(
            'These tests write outside a transaction and are connected to "%s", while .env.test names "%s". '
            .'Run `php bin/test-prepare` and check that nothing exports DATABASE_NAME into the shell, '
            .'the web_environment of a DDEV project for instance.',
            self::$checkedDatabase,
            $declared,
        ));
    }

    private function databaseNamedInEnvTest(): ?string
    {
        $file = $this->getService(KernelInterface::class)->getProjectDir().'/.env.test';

        if (!is_file($file)) {
            return null;
        }

        preg_match('/^\s*DATABASE_NAME\s*=\s*[\'"]?([^\'"\s#]+)/m', (string) file_get_contents($file), $matches);

        return $matches[1] ?? null;
    }

    /**
     * The class of a free text section under the active theme, which may
     * name classes of its own for written text.
     */
    protected function richTextClass(): string
    {
        $themeClass = $this->getService(ThemeComponentSections::class)->declaration()->richTextClass;

        return null === $themeClass ? 'cms-richtext' : 'cms-richtext '.$themeClass;
    }

    /**
     * Points the shop at a front theme it actually has.
     *
     * `bin/test-prepare` seeds the theme name of a stock install, which is not
     * necessarily the theme installed here: left alone, anything resolving a
     * parser works on a directory that does not exist, and the tests fail on
     * something that has nothing to do with what they measure. The write is
     * undone with the transaction of the test.
     */
    protected function useAnInstalledFrontTheme(): void
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

    /**
     * The export file of one page, in every active language: what a test of
     * the import changes before reading it back.
     *
     * @return array<string, mixed>
     */
    protected function exportedPage(CmsPage $page): array
    {
        $locales = array_map(strval(...), LangQuery::create()->filterByActive(1)->select(['Locale'])->find()->getData());

        return [
            ...ExportFormat::header('test', $locales, new \DateTimeImmutable()),
            'pages' => [$this->getService(SiteExporter::class)->pageDocument($page, $locales)],
        ];
    }

    protected function writer(): CmsPageWriter
    {
        return $this->getService(CmsPageWriter::class);
    }

    /**
     * The writer of the container, but with a shared cache that remembers what
     * it was told to drop.
     */
    protected function writerPurgingWith(CachePurgerInterface $purger): CmsPageWriter
    {
        return $this->purgingWith(CmsPageWriter::class, $purger);
    }

    /**
     * A service built as the container builds it, down to the services that
     * hand tags to a shared cache, which hand them to this purger instead.
     *
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    protected function purgingWith(string $class, CachePurgerInterface $purger): object
    {
        $arguments = [];

        foreach ((new \ReflectionMethod($class, '__construct'))->getParameters() as $parameter) {
            /** @var class-string $type */
            $type = ltrim((string) $parameter->getType(), '?');
            $arguments[] = match (true) {
                CachePurger::class === $type => new CachePurger([$purger], new NullLogger()),
                $this->reachesAPurger($type) => $this->purgingWith($type, $purger),
                // What the container has no service for keeps the default the
                // constructor gives it, as autowiring leaves it.
                $parameter->isDefaultValueAvailable() && !static::getContainer()->has($type) => $parameter->getDefaultValue(),
                default => $this->getService($type),
            };
        }

        return new $class(...$arguments);
    }

    /**
     * Whether a service of the module holds a purger, itself or through the
     * services it takes: those are rebuilt around the spy, so the purges of
     * a writer cannot reach the real one whatever service it hands them to.
     *
     * @param array<string, true> $seen
     */
    private function reachesAPurger(string $type, array $seen = []): bool
    {
        if (\in_array($type, [CachePurger::class, PageInvalidator::class, PartialCache::class], true)) {
            return true;
        }

        if (isset($seen[$type]) || !str_starts_with($type, 'TheliaCMS\\') || !class_exists($type) || !method_exists($type, '__construct')) {
            return false;
        }

        $seen[$type] = true;

        foreach ((new \ReflectionMethod($type, '__construct'))->getParameters() as $parameter) {
            if ($this->reachesAPurger(ltrim((string) $parameter->getType(), '?'), $seen)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The language the module answers in here.
     *
     * Read rather than hardcoded: services resolve the locale of the visitor
     * through LangService, and a fixture written in another language makes them
     * return empty titles — which reads as a bug in the code under test.
     */
    protected function locale(): string
    {
        return $this->getService(LangService::class)->getLocale();
    }

    /**
     * A page with content, published in the given locale unless told otherwise.
     */
    protected function createPage(
        string $title,
        ?string $locale = null,
        int $parent = 0,
        ?string $html = null,
        bool $published = true,
        bool $visible = true,
    ): CmsPage {
        $locale ??= $this->locale();
        $page = new CmsPage();
        $page->setParent($parent)
            ->setPosition(0)
            ->setVisible($visible ? 1 : 0)
            ->setPageType('default');

        $writer = $this->writer();
        $writer->saveDraft($page, $locale, new PageDraft(title: $title));
        $writer->saveContent($page, $locale, Document::richText($html ?? \sprintf('<h2>%s</h2>', htmlspecialchars($title))));

        if ($published) {
            $writer->publish($page, $locale);
        }

        $this->createdPages[] = (int) $page->getId();

        return $page;
    }
}
