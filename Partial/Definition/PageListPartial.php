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

namespace TheliaCMS\Partial\Definition;

use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Domain\Localization\Service\LangService;
use TheliaCMS\Page\Field\FieldFormatter;
use TheliaCMS\Page\PageCard;
use TheliaCMS\Page\PageListQuery;
use TheliaCMS\Page\PageTypeCode;
use TheliaCMS\Page\PageTypeRepository;
use TheliaCMS\Page\PublishedPageRepository;
use TheliaCMS\Partial\DescribedPartialInterface;
use TheliaCMS\Partial\PartialDefinitionInterface;
use TheliaCMS\Partial\PartialProp;
use TheliaCMS\TheliaCMS;

/**
 * The pages online of a type, placed inside a page: the recipes of the site,
 * the latest case studies. The page stores the type and the order; the list
 * is read when the page is served, so a recipe published tomorrow appears
 * without anyone publishing the page that lists it.
 */
final readonly class PageListPartial implements PartialDefinitionInterface, DescribedPartialInterface
{
    public const string NAME = 'cms-page-list';

    /** The order a list runs in when it names a field the type no longer has. */
    private const string SORT_FIELD = 'field';

    public function __construct(
        private PageTypeRepository $types,
        private PublishedPageRepository $pages,
        private FieldFormatter $formatter,
        private TranslatorInterface $translator,
        private LangService $langService,
    ) {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function label(): string
    {
        return $this->trans('Pages of a type');
    }

    public function description(): string
    {
        return $this->trans('The pages of a type, listed as they are on every visit.');
    }

    public function themeTemplate(): string
    {
        return 'cms/partials/page-list';
    }

    public function fallbackTemplate(): string
    {
        return '@TheliaCMSModule/front/partials/page-list.html.twig';
    }

    public function props(): array
    {
        $locale = $this->langService->getLocale();
        $types = [];

        foreach ($this->types->allDetails() as $code => $details) {
            $title = $details->title($locale);
            $types[$code] = $title === $code ? $code : \sprintf('%s (%s)', $title, $code);
        }

        return [
            PartialProp::choice('type', $this->trans('Page template'), $types, PageTypeCode::DEFAULT),
            PartialProp::text('title', $this->trans('Heading'), max: 120, help: $this->trans('Shown above the list. Leave empty for none.')),
            PartialProp::choice('sort', $this->trans('Order'), [
                PageListQuery::SORT_RECENT => $this->trans('Most recent first'),
                PageListQuery::SORT_OLDEST => $this->trans('Oldest first'),
                PageListQuery::SORT_TITLE => $this->trans('By title'),
                PageListQuery::SORT_POSITION => $this->trans('By position in the tree'),
                self::SORT_FIELD => $this->trans('By a field of the type'),
            ], PageListQuery::SORT_RECENT),
            PartialProp::text('field', $this->trans('Field to order by'), max: 40, help: $this->trans('The code of a field of the type, when the order is by a field.')),
            PartialProp::choice('direction', $this->trans('Direction'), [
                'asc' => $this->trans('Ascending'),
                'desc' => $this->trans('Descending'),
            ], 'asc'),
            PartialProp::integer('limit', $this->trans('Number of pages'), 6, 1, 48),
        ];
    }

    public function context(array $props, string $locale): array
    {
        $type = PageTypeCode::orDefault((string) ($props['type'] ?? ''));
        $sort = (string) ($props['sort'] ?? PageListQuery::SORT_RECENT);

        if (self::SORT_FIELD === $sort) {
            $sort = '' !== trim((string) ($props['field'] ?? '')) ? trim((string) $props['field']) : PageListQuery::SORT_RECENT;
        }

        $cards = $this->pages->ofType($type, $locale, new PageListQuery(
            sort: $sort,
            descending: 'desc' === ($props['direction'] ?? 'asc'),
            limit: max(1, (int) ($props['limit'] ?? 6)),
        ));
        $fields = $this->types->fieldsOf($type);
        $details = $this->types->details($type);

        return [
            'type' => ['code' => $type, 'title' => $details->title($locale), 'description' => $details->description($locale)],
            'title' => '' !== trim((string) ($props['title'] ?? '')) ? trim((string) $props['title']) : null,
            'pages' => array_map(fn (PageCard $card): array => [
                'card' => $card,
                'fields' => $this->formatter->rows($fields, $card->fields, $locale),
            ], $cards),
            'empty_message' => $this->trans('No page to show yet.', $locale),
        ];
    }

    /**
     * Kept a few minutes: the writer drops it when a page goes online or
     * offline, and the lifetime catches a page whose publication date comes
     * without anyone touching it.
     */
    public function cacheTtl(): ?int
    {
        return 300;
    }

    private function trans(string $message, ?string $locale = null): string
    {
        return $this->translator->trans($message, [], TheliaCMS::DOMAIN_NAME, $locale);
    }
}
