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

namespace TheliaCMS\Media\Svg\Optimization;

/**
 * Removes the identifiers no reference cites. A file is rarely embedded
 * with outside styles pointing at its identifiers, and a kept one only
 * weighs a few bytes, so any doubt keeps it: the root, the symbols of a
 * sprite and the views are named from other files (`file.svg#icon`), never
 * from inside, and keep theirs.
 */
final readonly class UnreferencedIdRemover
{
    public function __construct(private SvgReferences $references)
    {
    }

    private const array NAMED_FROM_OUTSIDE = ['symbol', 'view'];

    public function remove(\DOMDocument $document): bool
    {
        $references = $this->references->in($document);
        $changed = false;

        foreach ($document->getElementsByTagName('*') as $element) {
            if (!$element->hasAttribute('id') || $element === $document->documentElement || \in_array($element->localName, self::NAMED_FROM_OUTSIDE, true)) {
                continue;
            }

            if ($this->references->cites($references, $element->getAttribute('id'))) {
                continue;
            }

            $element->removeAttribute('id');
            $changed = true;
        }

        return $changed;
    }
}
