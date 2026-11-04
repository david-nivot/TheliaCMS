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

namespace TheliaCMS\Content;

use Symfony\Contracts\Translation\TranslatorInterface;
use TheliaCMS\Locale\TranslatableText;

/**
 * A document that cannot be stored: wrong shape, unknown component, props a
 * component refuses. Every problem is listed, so whoever produced the document
 * — an editor, an import, a model — is told everything at once: in English for
 * the generator, the logs and the commands, in the language of the back office
 * for an editor.
 */
final class InvalidDocumentException extends \InvalidArgumentException
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
        return new self(array_map(
            static fn (string|TranslatableText $problem): TranslatableText => $problem instanceof TranslatableText ? $problem : TranslatableText::of($problem),
            [] === $problems ? ['The document is invalid.'] : $problems,
        ));
    }

    /**
     * @return list<string>
     */
    public function translated(TranslatorInterface $translator): array
    {
        return array_map(static fn (TranslatableText $text): string => $text->trans($translator), $this->texts);
    }
}
