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

namespace TheliaCMS\Page\Field;

use Symfony\Contracts\Translation\TranslatorInterface;
use TheliaCMS\Locale\TranslatableText;

/**
 * A definition of fields, or values for them, the site cannot store. Every
 * problem is listed, each one a sentence an editor or the generator can act
 * on: in English for the generator, in the language of the back office for
 * an editor.
 */
final class InvalidFieldsException extends \InvalidArgumentException
{
    /** @var list<string> */
    public readonly array $problems;

    /**
     * @param list<TranslatableText> $texts
     */
    private function __construct(
        public readonly array $texts,
    ) {
        $this->problems = array_map(static fn (TranslatableText $text): string => (string) $text, $texts);

        parent::__construct(implode(' ', $this->problems));
    }

    /**
     * @param list<string|TranslatableText> $problems a string is a sentence
     *                                                without values
     */
    public static function because(array $problems): self
    {
        return new self(array_map(static fn (string|TranslatableText $problem): TranslatableText => $problem instanceof TranslatableText ? $problem : TranslatableText::of($problem), $problems));
    }

    /**
     * @return list<string>
     */
    public function translated(TranslatorInterface $translator): array
    {
        return array_map(static fn (TranslatableText $text): string => $text->trans($translator), $this->texts);
    }
}
