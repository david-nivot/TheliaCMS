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

use TheliaCMS\TheliaCMS;

/**
 * What the site decided about the generator: how hard it thinks, how long an
 * answer may be, whether it reads the web pages a brief cites. The model, the
 * connection, the key and the budget are not here: they belong to the AI
 * settings of Thelia, whose range picks the model of each usage.
 */
final readonly class GeneratorSettings
{
    public const string EFFORT = 'generator_effort';
    public const string MAX_TOKENS = 'generator_max_tokens';
    public const string WEB_FETCH = 'generator_web_fetch';

    /** Whether the generator writing a page may make a component of the site when the catalogue lacks one. */
    public const string COMPONENT_MAKING = 'generator_component_making';

    // The sentence a visitor reads under a generated page. The machine-readable
    // mark does not depend on it: the core sets that one, usage by usage.
    public const string AI_LABEL = 'generator_ai_label';

    // A text written from a brief in a rich-text field: a paragraph does not
    // need the effort, nor the length, of a whole page.
    public const string TEXT_EFFORT = 'generator_text_effort';
    public const string TEXT_MAX_TOKENS = 'generator_text_max_tokens';

    public const string DEFAULT_EFFORT = 'high';

    /**
     * Thinking and the whole document of a long page count in it, and an
     * answer cut there is paid and thrown away. Only what is written is
     * billed: the ceiling costs nothing until it is reached, but the budget of
     * the AI layer reserves for it on every call (docs/adr/0008).
     */
    public const int DEFAULT_MAX_TOKENS = 48000;

    /** Below this, no page fits in the answer. */
    public const int MIN_MAX_TOKENS = 1024;

    public const array EFFORTS = ['low', 'medium', 'high', 'xhigh', 'max'];

    public const string DEFAULT_TEXT_EFFORT = 'low';
    public const int DEFAULT_TEXT_MAX_TOKENS = 2000;

    /** Below this, no paragraph fits in the answer, with its envelope. */
    public const int MIN_TEXT_MAX_TOKENS = 256;

    /**
     * Passed to the model as its reasoning effort: a native option of the AI
     * layer, read by Anthropic models and without effect on the others.
     */
    public function effort(): string
    {
        $effort = (string) TheliaCMS::getConfigValue(self::EFFORT, self::DEFAULT_EFFORT);

        return \in_array($effort, self::EFFORTS, true) ? $effort : self::DEFAULT_EFFORT;
    }

    public function maxTokens(): int
    {
        $maxTokens = (int) TheliaCMS::getConfigValue(self::MAX_TOKENS, (string) self::DEFAULT_MAX_TOKENS);

        return $maxTokens >= self::MIN_MAX_TOKENS ? $maxTokens : self::DEFAULT_MAX_TOKENS;
    }

    /**
     * Off unless the site turned it on: a page read leaves this server for
     * the provider of the AI range, which the site may not want.
     */
    public function webFetch(): bool
    {
        return '1' === (string) TheliaCMS::getConfigValue(self::WEB_FETCH, '0');
    }

    /**
     * Off by default: a component made while a page is written is a call of
     * its own, up to a minute and a few cents, that the brief asked for in
     * no explicit way.
     */
    public function componentMaking(): bool
    {
        return '1' === (string) TheliaCMS::getConfigValue(self::COMPONENT_MAKING, '0');
    }

    public function showsAiLabel(): bool
    {
        return '1' === (string) TheliaCMS::getConfigValue(self::AI_LABEL, '0');
    }

    public function saveAiLabel(bool $shown): void
    {
        TheliaCMS::setConfigValue(self::AI_LABEL, $shown ? '1' : '0');
    }

    public function textEffort(): string
    {
        $effort = (string) TheliaCMS::getConfigValue(self::TEXT_EFFORT, self::DEFAULT_TEXT_EFFORT);

        return \in_array($effort, self::EFFORTS, true) ? $effort : self::DEFAULT_TEXT_EFFORT;
    }

    public function textMaxTokens(): int
    {
        $maxTokens = (int) TheliaCMS::getConfigValue(self::TEXT_MAX_TOKENS, (string) self::DEFAULT_TEXT_MAX_TOKENS);

        return $maxTokens >= self::MIN_TEXT_MAX_TOKENS ? $maxTokens : self::DEFAULT_TEXT_MAX_TOKENS;
    }

    public function saveText(string $effort, int $maxTokens): void
    {
        TheliaCMS::setConfigValue(self::TEXT_EFFORT, \in_array($effort, self::EFFORTS, true) ? $effort : self::DEFAULT_TEXT_EFFORT);
        TheliaCMS::setConfigValue(self::TEXT_MAX_TOKENS, (string) ($maxTokens >= self::MIN_TEXT_MAX_TOKENS ? $maxTokens : self::DEFAULT_TEXT_MAX_TOKENS));
    }

    /**
     * Writes the settings, each brought back to its default when what
     * arrives makes no sense: an effort the range does not know, a length too
     * short for a page.
     */
    public function save(string $effort, int $maxTokens, bool $webFetch = false, bool $componentMaking = false): void
    {
        TheliaCMS::setConfigValue(self::EFFORT, \in_array($effort, self::EFFORTS, true) ? $effort : self::DEFAULT_EFFORT);
        TheliaCMS::setConfigValue(self::MAX_TOKENS, (string) ($maxTokens >= self::MIN_MAX_TOKENS ? $maxTokens : self::DEFAULT_MAX_TOKENS));
        TheliaCMS::setConfigValue(self::WEB_FETCH, $webFetch ? '1' : '0');
        TheliaCMS::setConfigValue(self::COMPONENT_MAKING, $componentMaking ? '1' : '0');
    }
}
