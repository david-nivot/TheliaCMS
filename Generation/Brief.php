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
 * What an editor asks for: the page in their own words, what they know
 * about tone and audience when they care to say it, and a file they join
 * as material for the page when they have one.
 */
final readonly class Brief
{
    private const string PICTURE = '~\\.(jpe?g|png|webp|gif|svg|avif)$~i';

    public function __construct(
        public string $text,
        public ?string $tone = null,
        public ?string $audience = null,
        public ?ReferenceFile $reference = null,
    ) {
        if ('' === trim($text)) {
            throw new \InvalidArgumentException('A brief says something.');
        }
    }

    /**
     * The web pages the brief cites, in the order it cites them: the model
     * may read them before it writes. The address of a picture is not a page:
     * it goes as it is into an image prop, never read.
     *
     * @return list<string>
     */
    public function addresses(): array
    {
        preg_match_all('~https?://[^\s<>"\'`]+~i', $this->text, $matches);

        // An address ending a sentence carries its punctuation: it is not part of it.
        $addresses = array_map(static fn (string $address): string => rtrim($address, '.,;:!?)]}'), $matches[0]);

        $pages = array_filter($addresses, static fn (string $address): bool => 1 !== preg_match(self::PICTURE, (string) parse_url($address, \PHP_URL_PATH)));

        return array_values(array_unique($pages));
    }
}
