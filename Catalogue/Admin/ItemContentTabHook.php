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

namespace TheliaCMS\Catalogue\Admin;

use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Thelia\Core\Event\Hook\HookRenderBlockEvent;
use Thelia\Core\Hook\BaseHook;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\SecurityContext;
use Thelia\Core\Template\Parser\ParserResolver;
use Thelia\Model\Lang;
use Thelia\Model\LangQuery;
use TheliaCMS\Catalogue\CatalogueItemType;
use TheliaCMS\Catalogue\ItemContentRepository;
use TheliaCMS\Page\Admin\EditLanguage;
use TheliaCMS\Security\CmsResources;
use TheliaCMS\TheliaCMS;
use Twig\Environment;

/**
 * A tab on the edit screen of a product, a category, a content, a folder and a
 * store of the Dealer module:
 * where the document of the item stands in each language, and the way to the
 * content screen that writes it. The editor itself stays on its own screen,
 * under the guard of the CMS.
 */
class ItemContentTabHook extends BaseHook
{
    /** Not the id of the tab of TheliaBlocks, which a site may run beside. */
    public const string TAB_ID = 'cms_item_content';

    public function __construct(
        private readonly SecurityContext $securityContext,
        private readonly UrlGeneratorInterface $urls,
        private readonly Environment $twig,
        private readonly ItemContentRepository $contents,
        ?EventDispatcherInterface $dispatcher = null,
        ?ParserResolver $parserResolver = null,
    ) {
        parent::__construct($dispatcher, $parserResolver);
    }

    public static function getSubscribedHooks(): array
    {
        return [
            'product.tab' => [['type' => 'back', 'method' => 'onProductTab']],
            'category.tab' => [['type' => 'back', 'method' => 'onCategoryTab']],
            'content.tab' => [['type' => 'back', 'method' => 'onContentTab']],
            'folder.tab' => [['type' => 'back', 'method' => 'onFolderTab']],
            // The Dealer module named the tab hook of its store screen this way.
            'dealer.additional' => [['type' => 'back', 'method' => 'onDealerTab']],
        ];
    }

    public function onProductTab(HookRenderBlockEvent $event): void
    {
        $this->addTab($event, CatalogueItemType::Product);
    }

    public function onCategoryTab(HookRenderBlockEvent $event): void
    {
        $this->addTab($event, CatalogueItemType::Category);
    }

    public function onContentTab(HookRenderBlockEvent $event): void
    {
        $this->addTab($event, CatalogueItemType::Content);
    }

    public function onFolderTab(HookRenderBlockEvent $event): void
    {
        $this->addTab($event, CatalogueItemType::Folder);
    }

    public function onDealerTab(HookRenderBlockEvent $event): void
    {
        $this->addTab($event, CatalogueItemType::Dealer);
    }

    private function addTab(HookRenderBlockEvent $event, CatalogueItemType $type): void
    {
        $id = (int) $event->getArgument('id');

        if ($id <= 0 || !$this->securityContext->isGranted(['ADMIN'], [CmsResources::PAGE], [], [AccessManager::VIEW])) {
            return;
        }

        $event->add([
            'id' => self::TAB_ID,
            'title' => $this->trans('CMS content', [], TheliaCMS::DOMAIN_NAME),
            'content' => $this->twig->render('@TheliaCMSModule/backOffice/default-twig/catalogue/tab.html.twig', [
                'languages' => $this->languages($type, $id),
                'may_publish' => $this->securityContext->isGranted(['ADMIN'], [CmsResources::PAGE_PUBLISH], [], [AccessManager::UPDATE]),
            ]),
        ]);
    }

    /**
     * @return list<array{title: string, status: ItemContentStatus, edit_url: string, unpublish_url: string}>
     */
    private function languages(CatalogueItemType $type, int $id): array
    {
        $rows = [];

        /** @var Lang $lang */
        foreach (LangQuery::create()->filterByActive(1)->orderByPosition()->find() as $lang) {
            $parameters = ['type' => $type->value, 'id' => $id, EditLanguage::PARAMETER => $lang->getId()];

            $rows[] = [
                'title' => (string) $lang->getTitle(),
                'status' => ItemContentStatus::resolve($this->contents->find($type, $id, (string) $lang->getLocale())),
                'edit_url' => $this->urls->generate('admin.cms.catalogue.content.edit', $parameters),
                'unpublish_url' => $this->urls->generate('admin.cms.catalogue.content.unpublish', $parameters),
            ];
        }

        return $rows;
    }
}
