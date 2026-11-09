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

namespace TheliaCMS\Catalogue;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The published documents of catalogue items a response printed, kept on the
 * main request while the page renders.
 *
 * Whatever is drawn after the content (the bottom of the body) can then tell
 * whether the page shows the document of its own item. A draft is never
 * recorded: it is only printed for the preview of the content screen, which a
 * visitor never sees.
 */
final readonly class PrintedDocuments
{
    private const string ATTRIBUTE = '_cms_printed_documents';

    public function __construct(
        private RequestStack $requests,
    ) {
    }

    public function record(CatalogueItemType $type, int $id): void
    {
        $request = $this->requests->getMainRequest();

        if (null === $request) {
            return;
        }

        $printed = $request->attributes->get(self::ATTRIBUTE);
        $printed = \is_array($printed) ? $printed : [];
        $printed[$type->value][$id] = true;

        $request->attributes->set(self::ATTRIBUTE, $printed);
    }

    /**
     * The item the page is about, when its document was printed on it: the
     * one named by the view of the request and its id. A document printed in
     * a list on the page of another item does not count.
     *
     * @return array{CatalogueItemType, int}|null
     */
    public function itemOfThePage(Request $request): ?array
    {
        $type = CatalogueItemType::tryFrom((string) $request->attributes->get('_view', ''));

        if (null === $type) {
            return null;
        }

        // The rewriting router writes `<view>_id` into the query, as it does
        // for a CMS page.
        $id = $request->query->getInt($type->value.'_id');
        $printed = $request->attributes->get(self::ATTRIBUTE);

        if ($id <= 0 || !\is_array($printed) || !isset($printed[$type->value][$id])) {
            return null;
        }

        return [$type, $id];
    }
}
