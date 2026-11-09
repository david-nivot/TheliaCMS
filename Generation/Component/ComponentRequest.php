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

namespace TheliaCMS\Generation\Component;

use TheliaCMS\Content\Site\SiteComponent;
use TheliaCMS\Generation\ReferenceFile;

/**
 * What an editor asks of the generator of components: a new one, or a change
 * of the one they are looking at, kept already or still a candidate. A file
 * may come with it, read as material the request gives, never instructions.
 */
final readonly class ComponentRequest
{
    public const string KIND_NEW = 'component';

    public const string KIND_CHANGE = 'component_change';

    /**
     * @param SiteComponent|null $current     the component to change, kept or still a candidate; null for a new one
     * @param int|null           $componentId the row of the component to change, when it is kept
     * @param ReferenceFile|null $reference   the file joined to the request
     */
    public function __construct(
        public string $text,
        public string $locale,
        public ?SiteComponent $current = null,
        public ?int $componentId = null,
        public ?ReferenceFile $reference = null,
    ) {
        if ('' === trim($text)) {
            throw new \InvalidArgumentException('A request says something.');
        }

        if (null === $current && null !== $componentId) {
            throw new \InvalidArgumentException('A change of a kept component names the component.');
        }
    }

    public function kind(): string
    {
        return null === $this->current ? self::KIND_NEW : self::KIND_CHANGE;
    }
}
