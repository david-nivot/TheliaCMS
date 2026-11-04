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
 * Told what the generator is doing while it runs: a screen that streams the
 * progress to the editor implements it. Nothing it does changes the outcome.
 */
interface GenerationObserverInterface
{
    /**
     * The model answered a turn, and either called tools or handed a document.
     *
     * @param list<string> $tools the names of the tools called in this turn, empty for the answer
     */
    public function onTurn(int $turn, array $tools): void;

    /**
     * The model is writing: so many characters of its answer have arrived.
     */
    public function onText(int $characters): void;

    /**
     * The answer was cut half way and is asked again, once. What was written
     * of it is dropped: the text that follows starts over.
     */
    public function onCut(): void;

    /**
     * The document was refused by the site and sent back for correction.
     *
     * @param list<string> $problems
     */
    public function onCorrection(int $round, array $problems): void;
}
