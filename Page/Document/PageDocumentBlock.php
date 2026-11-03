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

namespace TheliaCMS\Page\Document;

use Symfony\Contracts\Translation\TranslatorInterface;
use TheliaCMS\TheliaCMS;

/**
 * What the template of the documents of a page receives.
 *
 * The texts are translated here, in the language of the page and not in the
 * one of the request (the Twig filter follows the request, a page follows its
 * own content), like every other front-office text of the module.
 */
final readonly class PageDocumentBlock
{
    public function __construct(private TranslatorInterface $translator)
    {
    }

    /**
     * @param list<PageDocumentLink> $links
     *
     * @return array{documents: list<array{id: int, title: string, description: ?string, url: string, extension: string, type: string, size: ?string, bytes: int, download: bool, originalName: string}>, heading: string}
     */
    public function context(array $links, string $locale): array
    {
        $units = [
            'byte' => $this->translate('B', $locale),
            'kilobyte' => $this->translate('KB', $locale),
            'megabyte' => $this->translate('MB', $locale),
        ];

        return [
            'documents' => array_map(
                static fn (PageDocumentLink $link): array => [
                    'id' => $link->id,
                    'title' => $link->title,
                    'description' => $link->description,
                    'url' => $link->url,
                    'extension' => $link->extension,
                    'type' => mb_strtoupper($link->extension),
                    'size' => PageDocumentSize::readable($link->size, $units),
                    'bytes' => $link->size,
                    // A pdf or a text opens in the tab, as the route serves it;
                    // the rest is saved.
                    'download' => !PageDocumentDelivery::isInline($link->extension),
                    'originalName' => $link->originalName,
                ],
                $links,
            ),
            'heading' => $this->translate('Documents to download', $locale),
        ];
    }

    private function translate(string $message, string $locale): string
    {
        return $this->translator->trans($message, [], TheliaCMS::DOMAIN_NAME, $locale);
    }
}
