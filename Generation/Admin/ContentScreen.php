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

namespace TheliaCMS\Generation\Admin;

use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\SecurityContext;
use TheliaCMS\Content\Document;
use TheliaCMS\Content\Editing\ContentKey;
use TheliaCMS\Content\Editing\ContentSteps;
use TheliaCMS\Content\Editing\StepView;
use TheliaCMS\Content\Editing\TranslationLink;
use TheliaCMS\Content\Section;
use TheliaCMS\Content\SectionCatalog;
use TheliaCMS\Content\Site\SiteComponentPermission;
use TheliaCMS\Content\Site\SitePropRows;
use TheliaCMS\Documentation\DocumentationLink;
use TheliaCMS\Generation\Component\ComponentCandidates;
use TheliaCMS\Generation\GenerationLog;
use TheliaCMS\Generation\GenerationRequest;
use TheliaCMS\Generation\HeaderOutcome;
use TheliaCMS\Generation\WebPageRead;
use TheliaCMS\Model\CmsPageGeneration;
use TheliaCMS\Preview\ComponentPreviewLink;
use TheliaCMS\Preview\PreviewLink;
use TheliaCMS\Security\CmsResources;
use TheliaCMS\TheliaCMS;
use Twig\Environment;

/**
 * The content screen: a brief and the generator on the left, the draft on
 * the right. Everything the template reads is shaped here, so the
 * controller only says which page it is about.
 */
final readonly class ContentScreen
{
    private const string TEMPLATE = '@TheliaCMSModule/backOffice/default-twig/content/edit.html.twig';

    /** How much of a section is quoted in the list of sections. */
    private const int EXCERPT_LENGTH = 110;

    public function __construct(
        private Environment $twig,
        private FormFactoryInterface $forms,
        private TranslatorInterface $translator,
        private SecurityContext $securityContext,
        private SectionCatalog $catalog,
        private GenerationLog $log,
        private SectionFormBuilder $sectionForms,
        private ToolkitLink $toolkit,
        private PreviewWidths $previewWidths,
        private SectionChoices $sectionChoices,
        private ComponentCandidates $candidates,
        private ComponentPreviewLink $componentPreviews,
        private SiteComponentPermission $componentPermission,
        private SitePropRows $propRows,
        private DocumentationLink $documentation,
        private ContentSteps $steps,
    ) {
    }

    public function render(Request $request, ContentSubject $subject): string
    {
        $mayGenerate = $this->securityContext->isGranted(['ADMIN'], [CmsResources::GENERATE], [], [AccessManager::UPDATE]);
        $mayChange = $this->securityContext->isGranted(['ADMIN'], [CmsResources::PAGE], [], [AccessManager::UPDATE]);
        // The right to change the content, until the right to publish is its own.
        $mayPublish = $mayChange;
        $document = $subject->document;
        $lastBrief = $this->lastBrief($subject);

        return $this->twig->render(self::TEMPLATE, [
            'subject' => $subject,
            'last_brief' => $lastBrief,
            'brief_form' => $this->forms->create(BriefType::class, $lastBrief, ['action' => $subject->url('generate')])->createView(),
            'change_form' => $this->forms->create(ChangeType::class, null, ['action' => $subject->url('revise')])->createView(),
            'translate_form' => $this->translateForm($subject),
            'translation' => $this->translation($subject),
            'sections' => null === $document ? [] : $this->sections($document, $subject, $mayChange),
            'history' => array_map($this->row(...), $this->log->history($subject->target, $subject->locale, 20)),
            'outcome' => $this->outcome($request, $subject),
            'header_outcome' => $this->headerOutcome($request, $subject),
            'may_generate' => $mayGenerate,
            'may_change' => $mayChange,
            'may_publish' => $mayPublish,
            'toolkit_url' => $this->toolkit->url(),
            // The screen replaces the layout the link of every other screen
            // is drawn in: it carries its own, at the top of its panel.
            'help_url' => $this->documentation->forRoute((string) $request->attributes->get('_route')),
            'preview_widths' => $this->previewWidths->widths(),
            'addable' => $mayChange ? $this->sectionChoices->groups() : [],
            // Prototyping a component from the page: the right to generate
            // and the rights a component of the site takes, on a screen that
            // has the routes for it (a catalogue item has none).
            'may_prototype' => $mayGenerate && isset($subject->routes['component_generate']) && $this->componentPermission->mayMake(),
            'prototype' => $this->prototype($request, $subject),
            'steps' => $mayChange && isset($subject->routes['undo']) ? $this->steps($subject) : null,
            'assets_version' => self::assetsVersion(),
        ]);
    }

    /**
     * Undo and Redo as their buttons say them: what each would play, null when
     * there is nothing to play.
     *
     * @return array{undo: string|null, redo: string|null, undo_section: string|null, redo_section: string|null}
     */
    private function steps(ContentSubject $subject): array
    {
        ['undo' => $undo, 'redo' => $redo] = $this->steps->state(new ContentKey($subject->target->type, $subject->target->id, $subject->locale));

        return [
            'undo' => null === $undo ? null : $this->translator->trans('Undo: %step%', ['%step%' => $this->stepLabel($undo)], TheliaCMS::DOMAIN_NAME),
            'redo' => null === $redo ? null : $this->translator->trans('Redo: %step%', ['%step%' => $this->stepLabel($redo)], TheliaCMS::DOMAIN_NAME),
            'undo_section' => $undo?->section,
            'redo_section' => $redo?->section,
        ];
    }

    private function stepLabel(StepView $step): string
    {
        $parameters = ['%section%' => (string) $step->section];
        $label = match ($step->action) {
            'apply' => $this->translator->trans('section "%section%" edited by hand', $parameters, TheliaCMS::DOMAIN_NAME),
            'remove' => $this->translator->trans('section "%section%" removed', $parameters, TheliaCMS::DOMAIN_NAME),
            'move' => $this->translator->trans('section "%section%" moved', $parameters, TheliaCMS::DOMAIN_NAME),
            'duplicate' => $this->translator->trans('section "%section%" duplicated', $parameters, TheliaCMS::DOMAIN_NAME),
            'add' => $this->translator->trans('section added', [], TheliaCMS::DOMAIN_NAME),
            'component' => $this->translator->trans('section "%section%" added from a prototype', $parameters, TheliaCMS::DOMAIN_NAME),
            GenerationRequest::KIND_BRIEF => $this->translator->trans('content written by the generator', [], TheliaCMS::DOMAIN_NAME),
            GenerationRequest::KIND_REVISION => $this->translator->trans('content changed by the generator', [], TheliaCMS::DOMAIN_NAME),
            GenerationRequest::KIND_SECTION => $this->translator->trans('section "%section%" rewritten by the generator', $parameters, TheliaCMS::DOMAIN_NAME),
            GenerationRequest::KIND_TRANSLATION => $this->translator->trans('content translated by the generator', [], TheliaCMS::DOMAIN_NAME),
            'restore' => $this->translator->trans('previous draft put back', [], TheliaCMS::DOMAIN_NAME),
            default => $this->translator->trans('change of the draft', [], TheliaCMS::DOMAIN_NAME),
        };

        return null === $step->author ? $label : $this->translator->trans('%step%, by %author%', ['%step%' => $label, '%author%' => $step->author], TheliaCMS::DOMAIN_NAME);
    }

    /**
     * The component prototyped from this screen, when its address names a
     * candidate: what was asked, what it cost, the component with its props,
     * and the addresses of its preview, in the whole page of the theme or
     * alone, as the draft is shown. A candidate that is a change of a kept
     * component belongs to the components screen, not here.
     *
     * @return array{candidate: \TheliaCMS\Generation\Component\ComponentCandidate, component: \TheliaCMS\Content\Site\SiteComponent|null, props: list<array<string, mixed>>, preview_url: string|null, preview_content_url: string|null, failed: bool, figures: string}|null
     */
    private function prototype(Request $request, ContentSubject $subject): ?array
    {
        $id = $request->query->getInt(CmsPageComponentController::PARAMETER);

        if ($id <= 0 || !isset($subject->routes['component_keep'])) {
            return null;
        }

        $candidate = $this->candidates->find($id);

        if (null === $candidate || $candidate->componentId > 0) {
            return null;
        }

        $previewUrl = null === $candidate->component ? null : $this->componentPreviews->candidate($candidate->logId, $subject->locale);

        return [
            'candidate' => $candidate,
            'component' => $candidate->component,
            'props' => null === $candidate->component ? [] : $this->propRows->of($candidate->component),
            'preview_url' => $previewUrl,
            'preview_content_url' => null === $previewUrl ? null : $previewUrl.'?'.PreviewLink::CONTENT_ONLY.'=1',
            'failed' => GenerationLog::STATUS_DONE !== $candidate->status,
            'figures' => $this->translator->trans('%turns% turn(s), %duration% s, %cost% USD', [
                '%turns%' => $candidate->turns,
                '%duration%' => number_format($candidate->durationMs / 1000, 1, '.', ''),
                '%cost%' => number_format($candidate->costUsd, 3, '.', ''),
            ], TheliaCMS::DOMAIN_NAME),
        ];
    }

    /**
     * The brief the target was last written from, back in its form: a page
     * is written again from its own brief, corrected, rather than from a
     * blank.
     *
     * @return array{text: string, tone: string|null, audience: string|null}|null
     */
    private function lastBrief(ContentSubject $subject): ?array
    {
        $brief = $this->log->latestBrief($subject->target, $subject->locale);

        return null === $brief ? null : ['text' => $brief->text, 'tone' => $brief->tone, 'audience' => $brief->audience];
    }

    /**
     * The form that writes this language from another, offered when the
     * target holds nothing here and a draft elsewhere. The first language
     * in the order of the shop is picked by default.
     */
    private function translateForm(ContentSubject $subject): ?FormView
    {
        if ($subject->hasContent() || [] === $subject->translationSources) {
            return null;
        }

        return $this->forms
            ->create(TranslateType::class, ['source' => $subject->translationSources[0]['locale']], [
                'action' => $subject->url('translate'),
                'sources' => array_column($subject->translationSources, 'locale', 'label'),
            ])
            ->createView();
    }

    /**
     * The language this one was written from, what changed in its draft
     * since, and the form that writes this language again from it when
     * something did and this one still holds a draft.
     *
     * The sections are named by their position and their words in this
     * language: the ids are the same on both sides, and this is the one the
     * editor reads. A section only the source has (added since) has no
     * position here.
     *
     * @return array{source: string, label: string, stale: bool, header: bool, changed: list<array{id: string, rank: int|null, excerpt: string}>, removed: list<array{id: string, rank: int|null, excerpt: string}>, retranslate_form: FormView|null}|null
     */
    private function translation(ContentSubject $subject): ?array
    {
        $link = $subject->translationLink;

        if (!$link instanceof TranslationLink) {
            return null;
        }

        $held = [];

        foreach ($subject->document->sections ?? [] as $index => $section) {
            $held[$section->id] = ['id' => $section->id, 'rank' => $index + 1, 'excerpt' => self::excerpt($section)];
        }

        $named = static fn (string $id): array => $held[$id] ?? ['id' => $id, 'rank' => null, 'excerpt' => ''];
        $labels = array_column($subject->translationSources, 'label', 'locale');
        $label = $labels[$link->sourceLocale] ?? $link->sourceLocale;
        $retranslate = $link->isStale() && $subject->hasContent() && isset($labels[$link->sourceLocale]);

        return [
            'source' => $link->sourceLocale,
            'label' => $label,
            'stale' => $link->isStale(),
            'header' => $link->header,
            'changed' => array_map($named, $link->changed),
            'removed' => array_map($named, $link->removed),
            'retranslate_form' => $retranslate
                ? $this->forms->create(TranslateType::class, ['source' => $link->sourceLocale], [
                    'action' => $subject->url('translate'),
                    'sources' => [$label => $link->sourceLocale],
                ])->createView()
                : null,
        ];
    }

    /**
     * Changes with the script and the stylesheet of the screen, so an updated
     * module is not shadowed by what the browser already holds.
     */
    private static function assetsVersion(): string
    {
        $assets = __DIR__.'/../../templates/backOffice/default-twig/assets/';

        return substr(hash('xxh3', (string) @filemtime($assets.'content-editor.js').(string) @filemtime($assets.'content-editor.css').(string) @filemtime($assets.'media-picker.js').(string) @filemtime($assets.'image-crop.js')), 0, 12);
    }

    /**
     * @param bool $mayEdit whether the form to change the props by hand is offered
     *
     * @return list<array{id: string, rank: int, component: string, label: string, excerpt: string, form: FormView, edit: FormView|null}>
     */
    private function sections(Document $document, ContentSubject $subject, bool $mayEdit): array
    {
        $sections = [];

        foreach ($document->sections as $index => $section) {
            $definition = $this->catalog->find($section->component);

            $sections[] = [
                'id' => $section->id,
                'rank' => $index + 1,
                'component' => $section->component,
                'label' => $this->sectionChoices->label($section->component, $definition?->label() ?? $section->component),
                'excerpt' => self::excerpt($section),
                'duplicable' => $this->catalog->canBeDuplicated($section->component),
                // Changing a word by hand costs nothing: the right to change
                // the page is enough, where the generator takes its own.
                'edit' => $mayEdit && null !== $definition ? $this->sectionForms->build($definition, $section, $subject->url('update'))->createView() : null,
                'form' => $this->forms
                    ->createNamed(self::sectionFormName($section->id), ChangeType::class, ['section' => $section->id], ['section' => true, 'action' => $subject->url('section')])
                    ->createView(),
            ];
        }

        return $sections;
    }

    /**
     * The form of a section is named after it: two sections on one screen
     * never share a field id, which a label has to point at.
     */
    public static function sectionFormName(string $sectionId): string
    {
        return 'cms_change_'.preg_replace('/[^a-z0-9_]/i', '_', $sectionId);
    }

    /**
     * The name of the section form a request posted, read from its fields.
     */
    public static function postedSectionForm(Request $request): string
    {
        foreach (array_keys($request->request->all()) as $name) {
            if (\is_string($name) && str_starts_with($name, 'cms_change_')) {
                return $name;
            }
        }

        return 'cms_change_none';
    }

    /**
     * The first words a reader would recognise the section by: its title,
     * else its text, else the first text of its items.
     */
    private static function excerpt(Section $section): string
    {
        foreach (['title', 'text', 'html', 'quote', 'label', 'caption', 'alt'] as $name) {
            $value = $section->props[$name] ?? null;

            if (\is_string($value) && '' !== trim(strip_tags($value))) {
                return self::shorten($value);
            }
        }

        foreach ($section->props as $value) {
            if (\is_array($value) && \is_array($value[0] ?? null)) {
                foreach ($value[0] as $itemValue) {
                    if (\is_string($itemValue) && '' !== trim(strip_tags($itemValue))) {
                        return self::shorten($itemValue);
                    }
                }
            }
        }

        return '';
    }

    private static function shorten(string $html): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), \ENT_QUOTES | \ENT_HTML5)));

        return mb_strlen($text) > self::EXCERPT_LENGTH ? rtrim(mb_substr($text, 0, self::EXCERPT_LENGTH)).'…' : $text;
    }

    /**
     * @return array{id: int, date: \DateTimeInterface|null, kind: string, status: string, status_class: string, cost: string, duration: string, turns: int, details: string, error: string|null, pages: list<array{url: string, read: bool, error: string|null, tokens: int|null}>, left_out: list<string>}
     */
    private function row(CmsPageGeneration $row): array
    {
        return [
            'id' => (int) $row->getId(),
            'date' => $row->getCreatedAt(),
            'kind' => $this->kindLabel((string) $row->getKind()),
            'status' => $this->statusLabel((string) $row->getStatus()),
            'status_class' => match ($row->getStatus()) {
                GenerationLog::STATUS_DONE => 'text-bg-success',
                GenerationLog::STATUS_REFUSED, GenerationLog::STATUS_INVALID => 'text-bg-warning',
                GenerationLog::STATUS_RUNNING => 'text-bg-info',
                GenerationLog::STATUS_INTERRUPTED => 'text-bg-secondary',
                default => 'text-bg-danger',
            },
            'cost' => number_format((float) $row->getCostUsd(), 3, '.', ''),
            'duration' => number_format(((int) $row->getDurationMs()) / 1000, 1, '.', ''),
            'turns' => (int) $row->getTurns(),
            'details' => $this->detailsLabel($row),
            'error' => $row->getError(),
            'pages' => array_map(fn (WebPageRead $page): array => [
                'url' => $page->url,
                'read' => $page->wasRead(),
                'error' => null === $page->errorCode ? null : $this->readFailureLabel($page->errorCode),
                'tokens' => $page->tokens,
            ], GenerationLog::webPagesOf($row)),
            'left_out' => GenerationLog::leftOutOf($row),
        ];
    }

    /**
     * The outcome of the call the screen was reloaded after, read from the
     * ledger: a stream cannot leave a flash message behind, and the ledger
     * says more than a flash would.
     *
     * @return array{type: string, message: string, details: list<string>}|null
     */
    private function outcome(Request $request, ContentSubject $subject): ?array
    {
        $id = $request->query->getInt(GenerationRunner::OUTCOME);

        if ($id <= 0) {
            return null;
        }

        $row = $this->log->find($id, $subject->target, $subject->locale);

        if (null === $row) {
            return null;
        }

        $figures = $this->translator->trans('%turns% turn(s), %duration% s, %cost% USD', [
            '%turns%' => (int) $row->getTurns(),
            '%duration%' => number_format(((int) $row->getDurationMs()) / 1000, 1, '.', ''),
            '%cost%' => number_format((float) $row->getCostUsd(), 3, '.', ''),
        ], TheliaCMS::DOMAIN_NAME);

        $leftOut = GenerationLog::leftOutOf($row);

        return match ($row->getStatus()) {
            GenerationLog::STATUS_DONE => [] !== $leftOut ? [
                'type' => 'warning',
                'message' => $this->translator->trans('The draft is written (%figures%), without part of what the brief asked:', ['%figures%' => $figures], TheliaCMS::DOMAIN_NAME),
                'details' => $leftOut,
            ] : [
                'type' => 'success',
                'message' => $this->translator->trans('The draft is written (%figures%). Read it, ask for changes, then publish it.', ['%figures%' => $figures], TheliaCMS::DOMAIN_NAME),
                'details' => [],
            ],
            GenerationLog::STATUS_REFUSED => [
                'type' => 'warning',
                'message' => $this->translator->trans('The model declined to write this (%figures%). Reword the brief.', ['%figures%' => $figures], TheliaCMS::DOMAIN_NAME),
                'details' => [],
            ],
            GenerationLog::STATUS_INVALID => [
                'type' => 'warning',
                'message' => $this->translator->trans('The model answered with a document the site cannot store, twice (%figures%). Nothing was changed; try again or reword the brief.', ['%figures%' => $figures], TheliaCMS::DOMAIN_NAME),
                'details' => [(string) $row->getError()],
            ],
            GenerationLog::STATUS_RUNNING => [
                'type' => 'info',
                'message' => $this->translator->trans('The generation is still running (%figures%). Come back to this page in a moment.', ['%figures%' => $figures], TheliaCMS::DOMAIN_NAME),
                'details' => [],
            ],
            GenerationLog::STATUS_INTERRUPTED => [
                'type' => 'danger',
                'message' => $this->translator->trans('The generation was interrupted before it ended (%figures%). Nothing was changed; try again.', ['%figures%' => $figures], TheliaCMS::DOMAIN_NAME),
                'details' => [],
            ],
            default => [
                'type' => 'danger',
                'message' => $this->translator->trans('The generation failed (%figures%). Nothing was changed.', ['%figures%' => $figures], TheliaCMS::DOMAIN_NAME),
                'details' => [(string) $row->getError()],
            ],
        };
    }

    /**
     * What a translation did with the header of the page, read from the
     * address the screen was reloaded at: the columns it wrote, the ones it
     * left as the editor wrote them, and why an address was not derived.
     * Nothing for an item, whose header is the core's.
     *
     * @return array{type: string, message: string, details: list<string>}|null
     */
    private function headerOutcome(Request $request, ContentSubject $subject): ?array
    {
        $outcome = null === $subject->target->item ? HeaderOutcome::fromQuery((string) $request->query->get(GenerationRunner::HEADER)) : null;

        if (!$outcome instanceof HeaderOutcome) {
            return null;
        }

        $columns = fn (array $named): string => implode(', ', array_map(fn (string $column): string => $this->translator->trans('Header field: '.$column, [], TheliaCMS::DOMAIN_NAME), $named));
        $written = [...$outcome->written, ...(HeaderOutcome::ADDRESS_WRITTEN === $outcome->address ? ['address'] : [])];
        $kept = [] === $outcome->kept ? null : $this->translator->trans('Kept as it was: %columns%.', ['%columns%' => $columns($outcome->kept)], TheliaCMS::DOMAIN_NAME);
        $details = [];

        if (HeaderOutcome::ADDRESS_RESERVED === $outcome->address) {
            $details[] = $this->translator->trans('No address was derived: “%title%” is a reserved path. Set one in the settings of the page.', ['%title%' => $subject->target->title], TheliaCMS::DOMAIN_NAME);
        }

        if ($outcome->parentNotAddressed) {
            $details[] = $this->translator->trans('The parent page has no address in this language yet: this page answers at the root until it has one.', [], TheliaCMS::DOMAIN_NAME);
        }

        if ([] === $written) {
            // Nothing was written: what the page already held is the news.
            return null === $kept && [] === $details ? null : ['type' => 'info', 'message' => $kept ?? $details[0], 'details' => null === $kept ? \array_slice($details, 1) : $details];
        }

        return [
            'type' => 'success',
            'message' => $this->translator->trans('Header written in this language: %columns%.', ['%columns%' => $columns($written)], TheliaCMS::DOMAIN_NAME),
            'details' => null === $kept ? $details : [$kept, ...$details],
        ];
    }

    private function kindLabel(string $kind): string
    {
        return $this->translator->trans(match ($kind) {
            GenerationRequest::KIND_BRIEF => 'Brief',
            GenerationRequest::KIND_REVISION => 'Change',
            GenerationRequest::KIND_SECTION => 'Section',
            GenerationRequest::KIND_TRANSLATION => 'Translation',
            GenerationRequest::KIND_TEXT => 'Text of a field',
            GenerationLog::KIND_MANUAL => 'By hand',
            default => $kind,
        }, [], TheliaCMS::DOMAIN_NAME);
    }

    /**
     * Why a web page was not read, in words: the code is for the ledger and the model.
     */
    private function readFailureLabel(string $code): string
    {
        return $this->translator->trans(match ($code) {
            'invalid_url' => 'The address is not valid',
            'url_not_allowed' => 'The address is not allowed',
            'blocked_address' => 'The address points to a private network',
            'url_not_accessible' => 'The page could not be reached',
            'unsupported_content_type' => 'This type of file cannot be read',
            'empty_page' => 'The page has no text',
            'too_many_redirects' => 'Too many redirects',
            'unreadable_pdf' => 'The PDF could not be read (damaged, protected or too large)',
            default => $code,
        }, [], TheliaCMS::DOMAIN_NAME);
    }

    /**
     * What a call needed, on one line: "2 corrections, 1 retry, read_url ×2,
     * effort high". Empty for a call that needed nothing of the kind.
     */
    private function detailsLabel(CmsPageGeneration $row): string
    {
        $parts = [];

        if ((int) $row->getCorrections() > 0) {
            $parts[] = $this->translator->trans('%count% correction(s)', ['%count%' => (int) $row->getCorrections()], TheliaCMS::DOMAIN_NAME);
        }

        if ((int) $row->getRetries() > 0) {
            $parts[] = $this->translator->trans('%count% retry(ies)', ['%count%' => (int) $row->getRetries()], TheliaCMS::DOMAIN_NAME);
        }

        foreach (GenerationLog::toolsOf($row) as $name => $count) {
            $parts[] = $count > 1 ? $name.' ×'.$count : $name;
        }

        if (null !== $row->getEffort() && '' !== $row->getEffort()) {
            $parts[] = $this->translator->trans('effort %effort%', ['%effort%' => $row->getEffort()], TheliaCMS::DOMAIN_NAME);
        }

        return implode(', ', $parts);
    }

    private function statusLabel(string $status): string
    {
        return $this->translator->trans(match ($status) {
            GenerationLog::STATUS_DONE => 'Written',
            GenerationLog::STATUS_REFUSED => 'Declined',
            GenerationLog::STATUS_INVALID => 'Refused by the site',
            GenerationLog::STATUS_FAILED => 'Failed',
            GenerationLog::STATUS_RUNNING => 'Running',
            GenerationLog::STATUS_INTERRUPTED => 'Interrupted',
            default => $status,
        }, [], TheliaCMS::DOMAIN_NAME);
    }
}
