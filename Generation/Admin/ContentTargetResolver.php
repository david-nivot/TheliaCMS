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

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use TheliaCMS\Catalogue\Admin\ContentTargets;
use TheliaCMS\Catalogue\CatalogueItem;
use TheliaCMS\Catalogue\CatalogueItems;
use TheliaCMS\Catalogue\CatalogueItemType;
use TheliaCMS\Catalogue\ItemContentRepository;
use TheliaCMS\Content\Editing\ContentWriter;
use TheliaCMS\Content\Editing\TranslationFreshness;
use TheliaCMS\Content\Site\SiteComponentPermission;
use TheliaCMS\Generation\GenerationTargets;
use TheliaCMS\Http\Admin\AdminControllerSupport;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Page\Admin\CmsPageAdminRepository;
use TheliaCMS\Page\Admin\CmsPageWriter;
use TheliaCMS\Page\Admin\EditLanguage;
use TheliaCMS\Page\Admin\PageOnlineMessage;
use TheliaCMS\Page\PageTypeRepository;
use TheliaCMS\Preview\ItemPreviewLink;
use TheliaCMS\Preview\PreviewLink;

/**
 * Finds what the content screen is about from its address: a page by its id
 * alone, a catalogue item by its type and id, and answers 404 when there is
 * none. It resolves the language being edited too, in the order the lookups
 * need.
 */
final readonly class ContentTargetResolver
{
    public function __construct(
        private UrlGeneratorInterface $urls,
        private AdminControllerSupport $support,
        private EditLanguage $languages,
        private CmsPageAdminRepository $pages,
        private CmsPageWriter $pageWriter,
        private CatalogueItems $items,
        private ItemContentRepository $itemContents,
        private ContentWriter $writer,
        private ContentTargets $contentTargets,
        private GenerationTargets $generationTargets,
        private PreviewLink $pagePreviewLinks,
        private ItemPreviewLink $itemPreviewLinks,
        private TranslationSources $translationSources,
        private TranslationFreshness $freshness,
        private PageTypeRepository $pageTypes,
        private SiteComponentPermission $componentPermission,
        private PageOnlineMessage $onlineMessage,
    ) {
    }

    /**
     * @param string|null $type the type of the catalogue item; null for a page
     *
     * @throws NotFoundHttpException when the page or the item does not exist
     */
    public function resolve(Request $request, int $id, ?string $type = null): ContentScreenTarget
    {
        return null === $type ? $this->page($request, $id) : $this->item($request, CatalogueItemType::from($type), $id);
    }

    /**
     * The page is looked up before the language: resolving the language writes
     * it in the session, which a page that does not exist must not do.
     */
    private function page(Request $request, int $id): PageScreenTarget
    {
        $page = $this->pages->findLive($id);

        if (!$page instanceof CmsPage) {
            throw new NotFoundHttpException();
        }

        return new PageScreenTarget(
            $page,
            $this->languages->resolve($request),
            $this->urls,
            $this->support,
            $this->pages,
            $this->pageWriter,
            $this->writer,
            $this->contentTargets,
            $this->generationTargets,
            $this->pagePreviewLinks,
            $this->translationSources,
            $this->freshness,
            $this->pageTypes,
            $this->componentPermission,
            $this->onlineMessage,
        );
    }

    /**
     * The item is looked up after the language: its texts are read in it.
     */
    private function item(Request $request, CatalogueItemType $type, int $id): ItemScreenTarget
    {
        $lang = $this->languages->resolve($request);
        $item = $this->items->find($type, $id, (string) $lang->getLocale());

        if (!$item instanceof CatalogueItem) {
            throw new NotFoundHttpException();
        }

        return new ItemScreenTarget(
            $item,
            $lang,
            $this->urls,
            $this->support,
            $this->itemContents,
            $this->writer,
            $this->contentTargets,
            $this->generationTargets,
            $this->itemPreviewLinks,
            $this->translationSources,
            $this->freshness,
        );
    }
}
