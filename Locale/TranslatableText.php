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

namespace TheliaCMS\Locale;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use TheliaCMS\TheliaCMS;

/**
 * A sentence the module shows an editor, kept as its English template and
 * the values that fill it. Formatted first, a sentence matches no key of the
 * catalogue: the template is translated, then the values are put in.
 *
 * Read as a string, it is the English sentence, which the generator, the
 * logs and the commands keep using.
 */
final readonly class TranslatableText implements TranslatableInterface, \Stringable
{
    /**
     * @param list<string|int|float|self> $values
     */
    private function __construct(
        public string $template,
        public array $values,
    ) {
    }

    public static function of(string $template, string|int|float|self ...$values): self
    {
        return new self($template, array_values($values));
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $this->fill(
            $translator->trans($this->template, [], TheliaCMS::DOMAIN_NAME, $locale),
            array_map(static fn (string|int|float|self $value): string|int|float => $value instanceof self ? $value->trans($translator, $locale) : $value, $this->values),
        );
    }

    public function __toString(): string
    {
        return $this->fill(
            $this->template,
            array_map(static fn (string|int|float|self $value): string|int|float => $value instanceof self ? (string) $value : $value, $this->values),
        );
    }

    /**
     * A sentence without values is left as it is: a "%" it holds is a
     * character, not a placeholder.
     *
     * @param list<string|int|float> $values
     */
    private function fill(string $sentence, array $values): string
    {
        return [] === $values ? $sentence : vsprintf($sentence, $values);
    }
}
