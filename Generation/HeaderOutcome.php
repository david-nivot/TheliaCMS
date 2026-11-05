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

namespace TheliaCMS\Generation;

/**
 * What a translation did with the header of a page: the columns it wrote,
 * the ones the page already held in that language and that were left as they
 * were, and what became of the address.
 *
 * It travels in the address of the screen the editor comes back to, a stream
 * being unable to leave a flash message behind: read back, it only ever names
 * columns of the header and states it knows, whatever the address says.
 */
final readonly class HeaderOutcome
{
    /** The language had no address and the title gave it one. */
    public const string ADDRESS_WRITTEN = 'written';

    /** The language had an address (or a slug, or a fixed one): it was not touched. */
    public const string ADDRESS_KEPT = 'kept';

    /** The translated title is a reserved path: the texts are written, the address is not. */
    public const string ADDRESS_RESERVED = 'reserved';

    private const array ADDRESSES = [self::ADDRESS_WRITTEN, self::ADDRESS_KEPT, self::ADDRESS_RESERVED];

    /** The address was derived under a parent that has none in this language. */
    private const string PARENT = 'p';

    /**
     * @param list<string> $written columns of the header written, in the order of `PageHeader::COLUMNS`
     * @param list<string> $kept    columns proposed and left as the page held them
     * @param string|null  $address one of the `ADDRESS_*` states; null when no address was looked at
     */
    public function __construct(
        public array $written,
        public array $kept,
        public ?string $address,
        public bool $parentNotAddressed = false,
    ) {
    }

    public function isEmpty(): bool
    {
        return [] === $this->written && [] === $this->kept && null === $this->address && !$this->parentNotAddressed;
    }

    public function toQuery(): string
    {
        $tokens = [
            ...array_map(static fn (string $column): string => 'w-'.$column, $this->written),
            ...array_map(static fn (string $column): string => 'k-'.$column, $this->kept),
        ];

        if (null !== $this->address) {
            $tokens[] = 'a-'.$this->address;
        }

        if ($this->parentNotAddressed) {
            $tokens[] = self::PARENT;
        }

        return implode(',', $tokens);
    }

    public static function fromQuery(string $query): ?self
    {
        $written = [];
        $kept = [];
        $address = null;
        $parent = false;

        foreach (explode(',', $query) as $token) {
            if (self::PARENT === $token) {
                $parent = true;
            } elseif (str_starts_with($token, 'a-') && \in_array(substr($token, 2), self::ADDRESSES, true)) {
                $address = substr($token, 2);
            } elseif (str_starts_with($token, 'w-') && \in_array(substr($token, 2), PageHeader::COLUMNS, true)) {
                $written[] = substr($token, 2);
            } elseif (str_starts_with($token, 'k-') && \in_array(substr($token, 2), PageHeader::COLUMNS, true)) {
                $kept[] = substr($token, 2);
            }
        }

        $outcome = new self(self::inOrder($written), self::inOrder($kept), $address, $parent);

        return $outcome->isEmpty() ? null : $outcome;
    }

    /**
     * @param list<string> $columns
     *
     * @return list<string>
     */
    private static function inOrder(array $columns): array
    {
        return array_values(array_intersect(PageHeader::COLUMNS, $columns));
    }
}
