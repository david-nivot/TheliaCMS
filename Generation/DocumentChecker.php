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

namespace TheliaCMS\Generation;

use Propel\Runtime\ActiveQuery\Criteria;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Exception\MethodNotAllowedException;
use Symfony\Component\Routing\Exception\ResourceNotFoundException as RoutingResourceNotFoundException;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Template\Exception\ResourceNotFoundException;
use Thelia\Core\Template\Parser\ParserResolver;
use Thelia\Core\Template\TemplateHelperInterface;
use Thelia\Model\CategoryQuery;
use Thelia\Model\FolderQuery;
use Thelia\Model\ProductQuery;
use Thelia\Model\RewritingUrlQuery;
use TheliaCMS\Content\Document;
use TheliaCMS\Content\DocumentRenderer;
use TheliaCMS\Content\InvalidDocumentException;
use TheliaCMS\Content\Pipeline\HeadingChecker;
use TheliaCMS\Content\Prop;
use TheliaCMS\Content\PropType;
use TheliaCMS\Content\Section;
use TheliaCMS\Content\SectionCatalog;
use TheliaCMS\Model\CmsFormQuery;
use TheliaCMS\Model\CmsMenuQuery;
use TheliaCMS\Model\CmsPageQuery;
use TheliaCMS\Page\PublishedPageRepository;
use TheliaCMS\TheliaCMS;
use TheliaLibrary\Model\LibraryImageQuery;

/**
 * What a generated document says against what the site holds, once its shape
 * is valid: the images of the media library, the records its references name,
 * the internal addresses of its links, and the headings of its rendering.
 *
 * Whatever the model wrote may be a guess: an image id nobody uploaded, a
 * `/contact` the site does not answer. The validator checks the shape and
 * cannot know. A problem found here goes back to the model while corrections
 * are left; past them, `settle()` empties the value and says so, because a
 * page paid for is not thrown away for a link.
 *
 * An https address is never fetched: the check makes no call outside the site.
 */
final readonly class DocumentChecker
{
    /** What a reference names, by the last segment of the address of its picker. */
    private const array PICKED = [
        'products' => 'product',
        'categories' => 'category',
        'folders' => 'folder',
        'menus' => 'menu',
        'forms' => 'form',
        'pages' => 'page',
    ];

    /**
     * Routes that answer any path: the view route of the Flexy theme takes
     * `/anything` and serves the view of that name of the active front theme,
     * a 404 when it has none. A path only they match is an address when that
     * view exists and the theme shows it by name (the core's own test, see
     * `ViewRenderer`). A site without Flexy has none of them, and every route
     * counts.
     *
     * @var list<string>
     */
    private const array CATCH_ALL_ROUTES = ['flexy_view'];

    /** How many sentences the check adds to what the page leaves out. */
    private const int LEFT_OUT_COUNT = 10;

    public function __construct(
        private SectionCatalog $catalog,
        private DocumentRenderer $renderer,
        private HeadingChecker $headings,
        private PublishedPageRepository $pages,
        private TranslatorInterface $translator,
        private LoggerInterface $logger,
        private RouterInterface $router,
        private ParserResolver $parserResolver,
        private TemplateHelperInterface $templateHelper,
    ) {
    }

    /**
     * @param Document      $document a validated document
     * @param Document|null $before   the document the request started from: what it already held is not the model's, and is not checked
     *
     * @return list<DocumentProblem>
     */
    public function problems(Document $document, GenerationRequest $request, ?Document $before = null): array
    {
        $problems = [];

        // A translation copies every image, reference and address of its
        // source: a record gone since is not for the model to mend, and the
        // translation must not differ from the page it translates.
        if (GenerationRequest::KIND_TRANSLATION !== $request->kind) {
            $problems = $this->valueProblems($document, $request, $before);
        }

        return [...$problems, ...$this->headingProblems($document, $request, $before)];
    }

    /**
     * The document with every value the problems name emptied, and one
     * sentence for the page to leave out for each, in the language of the page.
     * A section that cannot do without what it named loses the row that held it,
     * then goes (a section written alone is kept as it was). A heading problem
     * changes nothing: it is said, and the editor mends it where it shows.
     *
     * @param list<DocumentProblem> $problems
     *
     * @return array{0: Document, 1: list<string>}
     */
    public function settle(Document $document, array $problems, GenerationRequest $request): array
    {
        $sentences = [];
        $sections = [];

        foreach ($document->sections as $section) {
            $own = array_values(array_filter($problems, static fn (DocumentProblem $problem): bool => $problem->sectionId === $section->id && DocumentProblem::HEADINGS !== $problem->kind));

            if ([] === $own) {
                $sections[] = $section;

                continue;
            }

            foreach ($own as $problem) {
                $sentences[] = $this->sentence($problem, $section->id, $request->locale);
            }

            $settled = $this->emptied($section, $own);

            if (null !== $settled) {
                $sections[] = $settled;

                continue;
            }

            $original = $request->current?->section($section->id);

            if (GenerationRequest::KIND_SECTION === $request->kind && null !== $original) {
                $sections[] = $original;
                $sentences[] = $this->translator->trans('The section "%section%" is unchanged: what it named is not on the site.', ['%section%' => $section->id], TheliaCMS::DOMAIN_NAME, $request->locale);

                continue;
            }

            $sentences[] = $this->translator->trans('The section "%section%" is left out: what it named is not on the site.', ['%section%' => $section->id], TheliaCMS::DOMAIN_NAME, $request->locale);
        }

        foreach ($problems as $problem) {
            if (DocumentProblem::HEADINGS === $problem->kind) {
                $sentences[] = $this->translator->trans('The headings of the page need a look before it is published: %problem%', ['%problem%' => $problem->message], TheliaCMS::DOMAIN_NAME, $request->locale);
            }
        }

        return [new Document($sections), \array_slice(array_values(array_unique($sentences)), 0, self::LEFT_OUT_COUNT)];
    }

    /**
     * @return list<DocumentProblem>
     */
    private function valueProblems(Document $document, GenerationRequest $request, ?Document $before): array
    {
        /** @var list<array{section: Section, index: int, kind: string, id: int|string, path: list<int|string>, row: list<int|string>|null, prop: Prop}> $found */
        $found = [];

        foreach ($document->sections as $index => $section) {
            if (GenerationRequest::KIND_SECTION === $request->kind && $section->id !== $request->sectionId) {
                continue;
            }

            $definition = $this->catalog->find($section->component);

            if (null === $definition || $this->isUnchanged($section, $before)) {
                continue;
            }

            $this->collect($definition->schema()->props, $section->props, [], null, static function (string $kind, Prop $prop, array $path, ?array $row, int|string $value) use (&$found, $section, $index): void {
                $found[] = ['section' => $section, 'index' => $index, 'kind' => $kind, 'id' => $value, 'path' => $path, 'row' => $row, 'prop' => $prop];
            });
        }

        $existing = [];

        foreach ($found as $candidate) {
            if (DocumentProblem::ADDRESS === $candidate['kind']) {
                continue;
            }

            $existing[$this->recordKind($candidate['kind'], $candidate['prop'])][] = (int) $candidate['id'];
        }

        foreach ($existing as $kind => $ids) {
            $existing[$kind] = $this->existing((string) $kind, array_values(array_unique($ids)));
        }

        $problems = [];

        foreach ($found as $candidate) {
            $section = $candidate['section'];
            $label = \sprintf('Section %d ("%s")', $candidate['index'] + 1, $section->id);
            $where = self::where($candidate['path']);

            if (DocumentProblem::ADDRESS === $candidate['kind']) {
                if (!$this->isAddressOfTheSite((string) $candidate['id'])) {
                    $problems[] = new DocumentProblem(DocumentProblem::ADDRESS, \sprintf('%s: "%s" points to %s, which is no page of the site. Take the address from `search_pages`, or use an https address, or leave it empty.', $label, $where, $candidate['id']), $section->id, $candidate['path'], $candidate['row'], (string) $candidate['id']);
                }

                continue;
            }

            $kind = $this->recordKind($candidate['kind'], $candidate['prop']);

            if (null === $kind || \in_array((int) $candidate['id'], $existing[$kind] ?? [], true)) {
                continue;
            }

            $problems[] = DocumentProblem::IMAGE === $candidate['kind']
                ? new DocumentProblem(DocumentProblem::IMAGE, \sprintf('%s: "%s" is the image %d, which the media library does not hold. Take an id from `search_media`, or leave the image empty (0).', $label, $where, $candidate['id']), $section->id, $candidate['path'], $candidate['row'], (string) $candidate['id'])
                : new DocumentProblem(DocumentProblem::REFERENCE, \sprintf('%s: "%s" is the %s %d, which the site does not hold. Take the id from the search tools, or leave it empty (0).', $label, $where, $kind, $candidate['id']), $section->id, $candidate['path'], $candidate['row'], (string) $candidate['id']);
        }

        return $problems;
    }

    /**
     * The headings of the document as the theme draws it: the title of the
     * page is the level 1, the sections start at level 2. What the document
     * the request started from held already is not the answer's.
     *
     * @return list<DocumentProblem>
     */
    private function headingProblems(Document $document, GenerationRequest $request, ?Document $before): array
    {
        $found = $this->headingsOf($document, $request->locale);

        if ([] !== $found && null !== $before) {
            $found = array_values(array_diff($found, $this->headingsOf($before, $request->locale)));
        }

        return array_map(static fn (string $message): DocumentProblem => new DocumentProblem(DocumentProblem::HEADINGS, $message), $found);
    }

    /**
     * @return list<string>
     */
    private function headingsOf(Document $document, string $locale): array
    {
        try {
            return $this->headings->check($this->renderer->render($document, $locale), underTitle: true);
        } catch (\Throwable $exception) {
            // The page is rendered again, and refused there, when it is saved.
            $this->logger->warning('TheliaCMS: the headings of a generated document could not be checked: {message}', ['message' => $exception->getMessage(), 'exception' => $exception]);

            return [];
        }
    }

    private function isUnchanged(Section $section, ?Document $before): bool
    {
        $held = $before?->section($section->id);

        if (null === $held || $held->component !== $section->component) {
            return false;
        }

        try {
            return $this->catalog->find($section->component)?->normalizeProps($held->props) === $section->props;
        } catch (InvalidDocumentException) {
            return false;
        }
    }

    /**
     * Calls `$visit` for every image, reference and internal address of the props.
     *
     * @param list<Prop>                                                                        $definitions
     * @param array<string, mixed>                                                              $values
     * @param list<int|string>                                                                  $path
     * @param list<int|string>|null                                                             $row
     * @param \Closure(string, Prop, list<int|string>, list<int|string>|null, int|string): void $visit
     */
    private function collect(array $definitions, array $values, array $path, ?array $row, \Closure $visit): void
    {
        foreach ($definitions as $prop) {
            $value = $values[$prop->name] ?? null;
            $at = [...$path, $prop->name];

            match ($prop->type) {
                PropType::Image => \is_int($value) && $value > 0 ? $visit(DocumentProblem::IMAGE, $prop, $at, $row, $value) : null,
                PropType::Reference => \is_int($value) && $value > 0 ? $visit(DocumentProblem::REFERENCE, $prop, $at, $row, $value) : null,
                PropType::Url => \is_string($value) && str_starts_with($value, '/') && !str_starts_with($value, '//') ? $visit(DocumentProblem::ADDRESS, $prop, $at, $row, $value) : null,
                PropType::Items => \is_array($value) ? $this->collectRows($prop, $value, $at, $visit) : null,
                default => null,
            };
        }
    }

    /**
     * @param array<array-key, mixed>                                                           $rows
     * @param list<int|string>                                                                  $at
     * @param \Closure(string, Prop, list<int|string>, list<int|string>|null, int|string): void $visit
     */
    private function collectRows(Prop $prop, array $rows, array $at, \Closure $visit): void
    {
        foreach ($rows as $index => $row) {
            $rowPath = [...$at, $index];

            if ($prop->scalarItems) {
                $this->collect([$prop->items[0]], ['value' => $row], $rowPath, $rowPath, static function (string $kind, Prop $item, array $path, ?array $rowAt, int|string $value) use ($visit, $rowPath): void {
                    // A scalar row is the value: the path stops at the row.
                    $visit($kind, $item, $rowPath, $rowPath, $value);
                });

                continue;
            }

            if (\is_array($row)) {
                $this->collect($prop->items, $row, $rowPath, $rowPath, $visit);
            }
        }
    }

    /**
     * The section with the values the problems name emptied: an image, a
     * reference or an address to nothing. When that leaves the props refused
     * (the value was required), the rows of lists that held a problem are
     * dropped instead; null when the section cannot be made valid.
     *
     * @param list<DocumentProblem> $problems
     */
    private function emptied(Section $section, array $problems): ?Section
    {
        $definition = $this->catalog->find($section->component);

        if (null === $definition) {
            return null;
        }

        $props = $section->props;

        foreach ($problems as $problem) {
            $props = self::set($props, $problem->path, DocumentProblem::ADDRESS === $problem->kind ? '' : null);
        }

        try {
            return $section->withProps($definition->normalizeProps($props));
        } catch (InvalidDocumentException) {
        }

        $props = $section->props;
        $rows = [];

        foreach ($problems as $problem) {
            if (null === $problem->rowPath) {
                $props = self::set($props, $problem->path, DocumentProblem::ADDRESS === $problem->kind ? '' : null);
            } else {
                $rows[implode('.', $problem->rowPath)] = $problem->rowPath;
            }
        }

        // The deepest and the last rows first, so an index above is still the one named.
        uasort($rows, static fn (array $left, array $right): int => [\count($right), $right] <=> [\count($left), $left]);

        foreach ($rows as $row) {
            $props = self::without($props, $row);
        }

        try {
            return $section->withProps($definition->normalizeProps($props));
        } catch (InvalidDocumentException) {
            return null;
        }
    }

    /**
     * @param array<array-key, mixed> $props
     * @param list<int|string>        $path
     *
     * @return array<array-key, mixed>
     */
    private static function set(array $props, array $path, mixed $value): array
    {
        $key = array_shift($path);

        if ([] === $path) {
            $props[$key] = $value;

            return $props;
        }

        if (\is_array($props[$key] ?? null)) {
            $props[$key] = self::set($props[$key], $path, $value);
        }

        return $props;
    }

    /**
     * @param array<array-key, mixed> $props
     * @param list<int|string>        $path  the row to drop
     *
     * @return array<array-key, mixed>
     */
    private static function without(array $props, array $path): array
    {
        $key = array_shift($path);

        if ([] === $path) {
            $wasList = array_is_list($props);
            unset($props[$key]);

            return $wasList ? array_values($props) : $props;
        }

        if (\is_array($props[$key] ?? null)) {
            $props[$key] = self::without($props[$key], $path);
        }

        return $props;
    }

    private function sentence(DocumentProblem $problem, string $sectionId, string $locale): string
    {
        $parameters = ['%section%' => $sectionId, '%address%' => $problem->location];

        return match ($problem->kind) {
            DocumentProblem::IMAGE => $this->translator->trans('An image of the section "%section%" is not in the media library: it is left out.', $parameters, TheliaCMS::DOMAIN_NAME, $locale),
            DocumentProblem::ADDRESS => $this->translator->trans('A link of the section "%section%" (%address%) leads to no page of the site: it is left out.', $parameters, TheliaCMS::DOMAIN_NAME, $locale),
            default => $this->translator->trans('The section "%section%" names a record the site does not hold: it is left out.', $parameters, TheliaCMS::DOMAIN_NAME, $locale),
        };
    }

    /**
     * @param list<int|string> $path
     */
    private static function where(array $path): string
    {
        $where = '';

        foreach ($path as $step) {
            $where .= \is_int($step) ? '['.($step + 1).']' : ('' === $where ? '' : '.').$step;
        }

        return $where;
    }

    /**
     * What a value names: `image`, or the kind of record a reference points
     * at, read from the picker of its prop and failing that from the first
     * word of its help (`a product. …`); null for a reference that says none.
     */
    private function recordKind(string $kind, Prop $prop): ?string
    {
        if (DocumentProblem::IMAGE === $kind) {
            return 'image';
        }

        if (null !== $prop->picker) {
            $segment = basename(parse_url($prop->picker, \PHP_URL_PATH) ?: $prop->picker);

            if (isset(self::PICKED[$segment])) {
                return self::PICKED[$segment];
            }
        }

        $word = strtolower(trim((string) preg_replace('/^(?:a|an|the)\s+/i', '', trim((string) strtok((string) $prop->help, '.')))));

        return \in_array($word, self::PICKED, true) ? $word : null;
    }

    /**
     * @param list<int> $ids
     *
     * @return list<int>
     */
    private function existing(string $kind, array $ids): array
    {
        if ('' === $kind || [] === $ids) {
            return [];
        }

        $found = match ($kind) {
            'image' => LibraryImageQuery::create()->filterById($ids, Criteria::IN)->select('Id')->find()->toArray(),
            'product' => ProductQuery::create()->filterById($ids, Criteria::IN)->select('Id')->find()->toArray(),
            'category' => CategoryQuery::create()->filterById($ids, Criteria::IN)->select('Id')->find()->toArray(),
            'folder' => FolderQuery::create()->filterById($ids, Criteria::IN)->select('Id')->find()->toArray(),
            'menu' => CmsMenuQuery::create()->filterById($ids, Criteria::IN)->select('Id')->find()->toArray(),
            'form' => CmsFormQuery::create()->filterById($ids, Criteria::IN)->filterByDeletedAt(null, Criteria::ISNULL)->select('Id')->find()->toArray(),
            'page' => CmsPageQuery::create()->filterById($ids, Criteria::IN)->filterByDeletedAt(null, Criteria::ISNULL)->select('Id')->find()->toArray(),
            default => [],
        };

        return array_map(intval(...), $found);
    }

    /**
     * Whether a path of the site is answered: the home page, a page of the
     * CMS that is reachable, an address the core rewrote, or else a route of
     * the router other than a catch-all (`/login`, `/cart`: shop routes the
     * core does not rewrite).
     */
    private function isAddressOfTheSite(string $address): bool
    {
        $path = trim((string) strtok(rawurldecode((string) strtok($address, '#')), '?'), '/');

        if ('' === $path) {
            return true;
        }

        $rewrittenAddress = false;

        foreach (RewritingUrlQuery::create()->filterByUrl($path)->find() as $rewritten) {
            $rewrittenAddress = true;

            if (TheliaCMS::PAGE_VIEW !== $rewritten->getView()) {
                return true;
            }

            if ($this->pages->isReachable((int) $rewritten->getViewId(), (string) $rewritten->getViewLocale())) {
                return true;
            }
        }

        // An address the core rewrote, of a page that is not online, is
        // answered by the router too: it is not a route of the shop.
        return !$rewrittenAddress && $this->isRoute('/'.$path);
    }

    private function isRoute(string $path): bool
    {
        try {
            $matched = $this->router->match($path);
        } catch (MethodNotAllowedException) {
            return true;
        } catch (RoutingResourceNotFoundException) {
            return false;
        }

        $route = (string) ($matched['_route'] ?? '');

        if ('' === $route) {
            return false;
        }

        return !\in_array($route, self::CATCH_ALL_ROUTES, true) || $this->isViewOfTheTheme((string) ($matched['_view'] ?? ''));
    }

    /**
     * Whether the active front theme serves this view by its name: the
     * template exists, and the theme does not declare it internal (a layout,
     * a step of the checkout), which the core answers with a 404.
     */
    private function isViewOfTheTheme(string $view): bool
    {
        if ('' === $view) {
            return false;
        }

        try {
            $theme = $this->templateHelper->getActiveFrontTemplate();

            if (\in_array($view, $theme->getInternalViews(), true)) {
                return false;
            }

            $this->parserResolver->getParser($theme->getAbsolutePath(), $view);

            return true;
        } catch (ResourceNotFoundException) {
            return false;
        }
    }
}
