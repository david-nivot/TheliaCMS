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

namespace TheliaCMS\Page;

use Propel\Runtime\ActiveQuery\Criteria;
use Thelia\Model\LangQuery;
use TheliaCMS\Model\CmsPageQuery;

/**
 * Gives the pages a warning lists the title and the language a reader
 * recognises.
 */
final readonly class FoundPageNamer
{
    /**
     * Each page with the title and the language a reader recognises.
     *
     * The language is named and carries its identifier, not just its locale: a
     * page can be written in one language and still be the sample text in
     * another, so the link has to open the editor on the one that needs writing,
     * and `fr_FR` is not what somebody editing a site calls French.
     *
     * @param list<array{id: int, locale: string}> $found
     *
     * @return list<array{id: int, locale: string, language: string, language_id: int, title: string}>
     */
    public function named(array $found, string $locale): array
    {
        if ([] === $found) {
            return [];
        }

        $pages = CmsPageQuery::create()
            ->filterById(array_column($found, 'id'), Criteria::IN)
            ->find();

        // The title of the language the page was found in, the one the link
        // opens; the language of the screen when the page has none there.
        $titles = [];
        $pagesById = [];

        foreach ($pages as $page) {
            $pagesById[(int) $page->getId()] = $page;
        }

        foreach ($found as $row) {
            $page = $pagesById[$row['id']] ?? null;

            if (null === $page) {
                continue;
            }

            foreach ([$row['locale'], $locale] as $titleLocale) {
                $title = trim((string) $page->setLocale($titleLocale)->getTitle());

                if ('' !== $title) {
                    $titles[$row['id'].'|'.$row['locale']] = $title;

                    break;
                }
            }
        }

        $languages = [];

        foreach (LangQuery::create()->find() as $lang) {
            $languages[(string) $lang->getLocale()] = [
                'id' => (int) $lang->getId(),
                'title' => trim((string) $lang->getTitle()),
            ];
        }

        $named = [];

        foreach ($found as $row) {
            $title = $titles[$row['id'].'|'.$row['locale']] ?? '';
            $language = $languages[$row['locale']] ?? null;

            $named[] = [
                'id' => $row['id'],
                'locale' => $row['locale'],
                'language' => $language['title'] ?? $row['locale'],
                'language_id' => $language['id'] ?? 0,
                'title' => '' === $title ? '#'.$row['id'] : $title,
            ];
        }

        return $named;
    }
}
