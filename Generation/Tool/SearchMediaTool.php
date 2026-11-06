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

namespace TheliaCMS\Generation\Tool;

use TheliaCMS\Generation\GenerationRequest;
use TheliaCMS\Media\CmsMediaLibrary;
use TheliaCMS\Media\MediaKind;

/**
 * The images of the media library, by a part of their title or one of
 * their tags. The only source of an image id a document may hold.
 */
final readonly class SearchMediaTool implements GeneratorToolInterface
{
    private const int LIMIT = 30;

    public function __construct(
        private CmsMediaLibrary $library,
    ) {
    }

    public function name(): string
    {
        return 'search_media';
    }

    public function description(): string
    {
        return 'Finds the images of the media library of the site, by a part of their title, their description, their caption or a tag; an empty query lists the latest. The id is what an image prop takes; never use an id that did not come from here.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['query' => ['type' => 'string', 'description' => 'A word of the title, the description, the caption or a tag, or empty for the latest images.']],
            'required' => ['query'],
            'additionalProperties' => false,
        ];
    }

    public function run(array $input, GenerationRequest $request): string
    {
        $query = trim((string) ($input['query'] ?? ''));
        $images = $this->library->images('' === $query ? null : $query, self::LIMIT, MediaKind::Image);

        if ([] === $images) {
            return '' === $query ? 'The media library holds no image yet: leave the image props null.' : 'No image matches. Try another word, or leave the image prop null.';
        }

        $found = [];

        foreach ($images as $image) {
            $image->setLocale($request->locale);
            $found[] = [
                'id' => (int) $image->getId(),
                'title' => trim((string) $image->getTitle()),
                'width' => $image->getWidth(),
                'height' => $image->getHeight(),
            ];
        }

        return json_encode($found, \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
    }
}
