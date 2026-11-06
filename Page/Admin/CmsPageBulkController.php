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

namespace TheliaCMS\Page\Admin;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Thelia\Model\Lang;
use TheliaCMS\Http\Admin\AdminControllerSupport;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Model\CmsTag;
use TheliaCMS\Page\PageTagRepository;
use TheliaCMS\Security\CmsResources;
use Twig\Environment;

/**
 * Several pages of the list answered at once: shown or hidden, tagged, moved
 * to the bin, published or unpublished in the language of the list.
 *
 * The bin and the publication are asked again on a screen that says what will
 * happen for real, then reported page by page; the rest is done at once. The
 * list the selection came from is the one the editor lands back on.
 */
#[Route('/admin/cms/pages', name: 'admin.cms.pages.')]
final readonly class CmsPageBulkController
{
    private const string CONFIRM_TEMPLATE = '@TheliaCMSModule/backOffice/default-twig/pages/bulk_confirm.html.twig';

    /** The value of the tag select that takes the tag off. */
    public const string NO_TAG = 'none';

    public function __construct(
        private Environment $twig,
        private UrlGeneratorInterface $urls,
        private AdminControllerSupport $support,
        private CmsPageAdminRepository $pages,
        private CmsPageWriter $writer,
        private EditLanguage $languages,
        private PageTagRepository $tags,
        private PublicationRefusal $refusals,
    ) {
    }

    #[Route('/bulk', name: 'bulk', methods: ['POST'], priority: 1)]
    public function bulk(Request $request): Response
    {
        $lang = $this->languages->resolve($request);
        $action = PageBulkAction::tryFrom($request->request->getString('action'));

        if (null === $action) {
            return $this->backToList($request, $lang, 'warning', $this->support->translate('Choose what to do with the selected pages.'));
        }

        $this->support->denyUnless(CmsResources::PAGE, $action->access(), 'You are not allowed to change CMS pages.');

        $pages = $this->pages->selected(array_map(intval(...), $request->request->all('pages')), $lang->getLocale());

        if ([] === $pages) {
            return $this->backToList($request, $lang, 'warning', $this->support->translate('Select at least one page.'));
        }

        if ($action->isConfirmed() && !$request->request->getBoolean('confirmed')) {
            return $this->confirm($request, $lang, $action, $pages);
        }

        return match ($action) {
            PageBulkAction::Show, PageBulkAction::Hide => $this->setVisibility($request, $lang, $pages, PageBulkAction::Show === $action),
            PageBulkAction::Tag => $this->setTag($request, $lang, $pages),
            PageBulkAction::Trash => $this->trash($request, $lang, $pages),
            PageBulkAction::Publish => $this->publish($request, $lang, $pages),
            PageBulkAction::Unpublish => $this->unpublish($request, $lang, $pages),
        };
    }

    /**
     * @param non-empty-list<CmsPage> $pages
     */
    private function setVisibility(Request $request, Lang $lang, array $pages, bool $visible): RedirectResponse
    {
        $changed = \count($this->writer->setVisibility($pages, $visible));

        return 0 === $changed
            ? $this->nothingToChange($request, $lang)
            : $this->backToList($request, $lang, 'success', $this->support->translate(
                $visible ? '%count% page(s) shown on the site.' : '%count% page(s) hidden from the site.',
                ['%count%' => $changed],
            ));
    }

    /**
     * @param non-empty-list<CmsPage> $pages
     */
    private function setTag(Request $request, Lang $lang, array $pages): RedirectResponse
    {
        $asked = $request->request->getString('set_tag');
        $tag = self::NO_TAG === $asked ? null : $this->tags->find((int) $asked);

        if (self::NO_TAG !== $asked && !$tag instanceof CmsTag) {
            return $this->backToList($request, $lang, 'warning', $this->support->translate('Choose the tag to give.'));
        }

        $changed = \count($this->writer->setTag($pages, $tag?->getId()));

        if (0 === $changed) {
            return $this->nothingToChange($request, $lang);
        }

        return $this->backToList($request, $lang, 'success', null === $tag
            ? $this->support->translate('%count% page(s) no longer tagged.', ['%count%' => $changed])
            : $this->support->translate('%count% page(s) tagged “%tag%”.', ['%count%' => $changed, '%tag%' => (string) $tag->getName()]));
    }

    /**
     * Each page with what is under it; a page already gone with its parent is
     * not sent again.
     *
     * @param non-empty-list<CmsPage> $pages
     */
    private function trash(Request $request, Lang $lang, array $pages): RedirectResponse
    {
        $binned = [];

        foreach ($pages as $page) {
            if (isset($binned[(int) $page->getId()])) {
                continue;
            }

            foreach ($this->writer->moveToTrash($page) as $id) {
                $binned[$id] = true;
            }
        }

        return $this->backToList($request, $lang, 'success', $this->support->translate('%count% page(s) moved to the bin.', ['%count%' => \count($binned)]));
    }

    /**
     * Each page in its own transaction: what went online stays online when the
     * next one is refused, and the report names both.
     *
     * @param non-empty-list<CmsPage> $pages
     */
    private function publish(Request $request, Lang $lang, array $pages): RedirectResponse
    {
        $published = 0;

        foreach ($pages as $page) {
            $title = $this->titleOf($page, $lang->getLocale());

            try {
                $warnings = $this->writer->publish($page, $lang->getLocale());
                ++$published;

                foreach ($warnings as $warning) {
                    $this->support->flash($request, 'warning', $this->support->translate('%title%: %problem%', ['%title%' => $title, '%problem%' => $warning]));
                }
            } catch (\Throwable $throwable) {
                $this->support->flash($request, 'danger', $this->support->translate('“%title%” was not published: %reasons%', [
                    '%title%' => $title,
                    '%reasons%' => implode(' ', $this->refusals->messages($throwable) ?? throw $throwable),
                ]));
            }
        }

        return $this->backToList($request, $lang, 0 === $published ? 'warning' : 'success', $this->support->translate(
            '%count% page(s) published in “%language%”.',
            ['%count%' => $published, '%language%' => (string) $lang->getTitle()],
        ));
    }

    /**
     * @param non-empty-list<CmsPage> $pages
     */
    private function unpublish(Request $request, Lang $lang, array $pages): RedirectResponse
    {
        $online = $this->pages->onlineIn(array_map(static fn (CmsPage $page): int => (int) $page->getId(), $pages), $lang->getLocale());
        $unpublished = 0;

        foreach ($pages as $page) {
            if (isset($online[(int) $page->getId()])) {
                $this->writer->unpublish($page, $lang->getLocale());
                ++$unpublished;
            }
        }

        return 0 === $unpublished
            ? $this->nothingToChange($request, $lang)
            : $this->backToList($request, $lang, 'success', $this->support->translate(
                '%count% page(s) unpublished in “%language%”.',
                ['%count%' => $unpublished, '%language%' => (string) $lang->getTitle()],
            ));
    }

    /**
     * What the action will do, counted on the pages and not on the boxes: the
     * whole branches for the bin, the refusals of each page for a publication.
     *
     * @param non-empty-list<CmsPage> $pages
     */
    private function confirm(Request $request, Lang $lang, PageBulkAction $action, array $pages): Response
    {
        $locale = $lang->getLocale();
        $filters = PageFilters::fromRequest($request)->toQueryParams();
        $selected = array_map(static fn (CmsPage $page): int => (int) $page->getId(), $pages);
        $context = [
            'action' => $action->value,
            'page_ids' => $selected,
            'language' => (string) $lang->getTitle(),
            'edit_language_id' => (int) $lang->getId(),
            'confirm_url' => $this->urls->generate('admin.cms.pages.bulk', [EditLanguage::PARAMETER => $lang->getId(), ...$filters]),
            'cancel_url' => $this->urls->generate('admin.cms.pages.list', [EditLanguage::PARAMETER => $lang->getId(), ...$filters]),
        ];

        if (PageBulkAction::Trash === $action) {
            $branches = $this->writer->branchesOf($pages);
            $inside = array_values(array_diff($branches, $selected));

            return new Response($this->twig->render(self::CONFIRM_TEMPLATE, $context + [
                'selected' => array_map(fn (CmsPage $page): string => $this->titleOf($page, $locale), $pages),
                'inside' => array_values($this->pages->titles($inside, $locale)),
                'total' => \count($branches),
            ]));
        }

        $ready = [];
        $refused = [];

        foreach ($pages as $page) {
            try {
                $ready[] = ['title' => $this->titleOf($page, $locale), 'warnings' => $this->writer->assertPublishable($page, $locale)];
            } catch (\Throwable $throwable) {
                $refused[] = ['title' => $this->titleOf($page, $locale), 'reasons' => $this->refusals->messages($throwable) ?? throw $throwable];
            }
        }

        return new Response($this->twig->render(self::CONFIRM_TEMPLATE, $context + [
            'ready' => $ready,
            'refused' => $refused,
        ]));
    }

    private function titleOf(CmsPage $page, string $locale): string
    {
        $title = trim((string) $page->setLocale($locale)->getTitle());

        return '' === $title ? '#'.$page->getId() : $title;
    }

    private function nothingToChange(Request $request, Lang $lang): RedirectResponse
    {
        return $this->backToList($request, $lang, 'info', $this->support->translate('Nothing to change: the selected pages were already so.'));
    }

    /**
     * Back to the list the selection was made on: its filters travel in the
     * address of the form, like those of the buttons of a row.
     */
    private function backToList(Request $request, Lang $lang, string $type, string $message): RedirectResponse
    {
        $this->support->flash($request, $type, $message);

        return $this->support->redirectInLanguage('admin.cms.pages.list', (int) $lang->getId(), PageFilters::fromRequest($request)->toQueryParams());
    }
}
