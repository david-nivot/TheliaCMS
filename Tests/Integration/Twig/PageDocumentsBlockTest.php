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

namespace TheliaCMS\Tests\Integration\Twig;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Thelia\Core\Template\ParserInterface;
use TheliaCMS\Front\ThemeTemplateRenderer;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Model\CmsPageDocumentQuery;
use TheliaCMS\Page\Document\PageDocumentStorage;
use TheliaCMS\Page\Document\PageDocumentWriter;
use TheliaCMS\Page\PublishedPage;
use TheliaCMS\Page\PublishedPageRepository;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;
use TheliaCMS\Twig\CmsExtension;

/**
 * `cms_page_documents_block()`: the list of the files of a page, drawn by the
 * module unless the theme ships `cms-page-documents.html.twig`.
 *
 * Called from the render of a Twig template, as a page template does, which is
 * a nested render of the theme parser.
 */
final class PageDocumentsBlockTest extends CmsIntegrationTestCase
{
    /** @var list<string> paths of the temporary uploads */
    private array $uploads = [];

    private string $themeDirectory = '';

    private string $realThemePath = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->useAnInstalledFrontTheme();

        // The active theme may ship its own cms-page-documents.html.twig (the Weitech one does): measure the module
        // template and the override branch against a directory of the test instead, empty unless a test fills it.
        $this->themeDirectory = sys_get_temp_dir().'/cms-block-theme-'.bin2hex(random_bytes(4));
        mkdir($this->themeDirectory, 0o777, true);
        $renderer = $this->renderer();
        // Resolved first: resolving sets the path of the active theme.
        $parser = $this->parser();
        $property = new \ReflectionProperty($renderer, 'themePath');
        $this->realThemePath = (string) $property->getValue($renderer);
        $property->setValue($renderer, $this->themeDirectory);
        $parser->getTwig()->getLoader()->prependPath($this->themeDirectory);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->themeDirectory.'/*') ?: []);
        @rmdir($this->themeDirectory);
        (new \ReflectionProperty($this->renderer(), 'themePath'))->setValue($this->renderer(), $this->realThemePath);

        $storage = new PageDocumentStorage();

        foreach (CmsPageDocumentQuery::create()->select(['File'])->find()->toArray() as $file) {
            $storage->delete((string) $file);
        }

        foreach ($this->uploads as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    public function testAPageWithoutDocumentDrawsNothing(): void
    {
        $page = $this->createPage('Page sans fichier');

        self::assertSame('', $this->block((int) $page->getId()));
        self::assertSame('', $this->block(99_999_999));
        self::assertSame('', $this->block(null));
    }

    public function testTheDocumentsAreListedWithTheirTitleTypeAndSize(): void
    {
        $page = $this->createPage('Page à fichiers');
        $this->givenDocument($page, 'Notice produit.pdf', $this->pdf());
        $this->givenDocument($page, 'Tarifs.zip', 'PK'."\x03\x04".str_repeat('x', 2000));

        $html = $this->block((int) $page->getId(), 'en_US');

        self::assertSame(1, substr_count($html, '<section class="cms-documents"'));
        self::assertStringContainsString('>Documents to download</h2>', $html);
        self::assertSame(2, substr_count($html, 'cms-documents__item'));
        self::assertStringContainsString('>Notice produit</a>', $html);
        self::assertStringContainsString('PDF · ', $html);
        self::assertStringContainsString('ZIP · 2 KB', $html);
        // The pdf opens in the tab, the archive is saved.
        self::assertSame(1, substr_count($html, ' download>'));
    }

    public function testTheHeadingFollowsTheLanguageAskedFor(): void
    {
        $page = $this->createPage('Page à titre');
        $this->givenDocument($page, 'Notice.pdf', $this->pdf());

        self::assertStringContainsString('>Documents à télécharger</h2>', $this->block((int) $page->getId(), 'fr_FR'));
    }

    public function testATemplateOfTheThemeTakesTheListOver(): void
    {
        $page = $this->createPage('Page au thème');
        $this->givenDocument($page, 'Notice.pdf', $this->pdf());

        file_put_contents($this->themeDirectory.'/cms-page-documents.html.twig', '<div class="theme-docs">{% for d in cms_page_documents_block.documents %}[{{ d.title }}|{{ d.type }}]{% endfor %}{{ cms_page_documents_block.heading }}</div>');

        self::assertSame('<div class="theme-docs">[Notice|PDF]Documents to download</div>', $this->block((int) $page->getId(), 'en_US'));
    }

    /**
     * The theme parser keeps every variable of a render for the rest of the
     * page: the block sets one, under a name no other template uses.
     *
     * The parser also assigns its own variables on every render (THELIA_VERSION,
     * locale, lang_code, current_url, app...), new to the request on its first
     * render only: a first render of a template of the test leaves them there,
     * and only what the block adds remains to be measured.
     */
    public function testOnlyOneVariableIsLeftToTheRestOfThePage(): void
    {
        $page = $this->createPage('Page à variables');
        $this->givenDocument($page, 'Notice.pdf', $this->pdf());
        file_put_contents($this->themeDirectory.'/cms-block-warm-up.html.twig', 'warm-up');
        self::assertSame('warm-up', $this->renderer()->renderTemplate('cms-block-warm-up.html.twig'));
        $parser = $this->parser();
        $assigned = new \ReflectionMethod($parser, 'withAssignedVariables');
        $before = array_keys($assigned->invoke($parser, []));

        $this->block((int) $page->getId(), 'en_US');

        $added = array_values(array_diff(array_keys($assigned->invoke($parser, [])), $before));
        self::assertSame(['cms_page_documents_block'], $added, 'Nothing but cms_page_documents_block is left to the next render.');
    }

    public function testAPublishedPageIsListedInItsOwnLanguage(): void
    {
        $page = $this->createPage('Page en anglais', 'en_US');
        $this->givenDocument($page, 'Notice.pdf', $this->pdf());
        $published = $this->getService(PublishedPageRepository::class)->find((int) $page->getId(), 'en_US');
        self::assertNotNull($published);

        self::assertStringContainsString('>Documents to download</h2>', $this->blockOf($published));
    }

    private function block(?int $pageId, ?string $locale = null): string
    {
        return $this->blockOf($pageId, $locale);
    }

    private function blockOf(PublishedPage|int|null $page, ?string $locale = null): string
    {
        $extension = $this->getService(CmsExtension::class);

        foreach ($extension->getFunctions() as $function) {
            if ('cms_page_documents_block' === $function->getName()) {
                self::assertSame(['html'], $function->getSafe(new \Twig\Node\Node()), 'The markup is not escaped by Twig.');

                return (string) ($function->getCallable())($page, $locale);
            }
        }

        self::fail('The extension registers no "cms_page_documents_block" function, so no theme can ask.');
    }

    private function renderer(): ThemeTemplateRenderer
    {
        $extension = $this->getService(CmsExtension::class);

        return (new \ReflectionProperty($extension, 'templates'))->getValue($extension);
    }

    private function parser(): ParserInterface
    {
        return (new \ReflectionMethod($this->renderer(), 'parser'))->invoke($this->renderer());
    }

    private function givenDocument(CmsPage $page, string $name, string $content): void
    {
        $path = tempnam(sys_get_temp_dir(), 'cms-page-document-');
        file_put_contents($path, $content);
        $this->uploads[] = $path;

        $result = $this->getService(PageDocumentWriter::class)->add((int) $page->getId(), [new UploadedFile($path, $name, null, \UPLOAD_ERR_OK, test: true)]);
        self::assertSame([], $result->refused, 'The file of the scenario is accepted.');
    }

    private function pdf(): string
    {
        return "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";
    }
}
