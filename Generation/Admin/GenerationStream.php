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

namespace TheliaCMS\Generation\Admin;

use TheliaCMS\Generation\GenerationObserverInterface;
use TheliaCMS\Generation\Tool\MakeComponentTool;

/**
 * The progress of a generation, written to the browser as it happens, as
 * server-sent events: `start`, one `turn` per answer of the model, a
 * `correction` when a document was sent back, `cut` when a stream cut half way is asked again, then `done` or `error`.
 *
 * A generation takes half a minute; a screen that shows nothing for half a
 * minute is a screen that looks broken.
 */
final class GenerationStream implements GenerationObserverInterface
{
    private int $announced = 0;

    /**
     * @param \Closure(string, array<string, string>): string $translate
     * @param array{answered?: string, refused?: string}      $messages  what the model answering and a refusal of the site are called, for what is written: a document by default
     */
    public function __construct(
        private readonly \Closure $translate,
        private readonly array $messages = [],
    ) {
    }

    public function start(string $message): void
    {
        $this->emit('start', ['message' => $message]);
    }

    public function onTurn(int $turn, array $tools): void
    {
        $this->announced = 0;
        $this->emit('turn', [
            'turn' => $turn,
            'tools' => $tools,
            'message' => match (true) {
                [] === $tools => ($this->translate)($this->messages['answered'] ?? 'The model answered: the site is checking the document.', []),
                \in_array(MakeComponentTool::NAME, $tools, true) => ($this->translate)('The model is making a component of the site: it takes up to a minute.', []),
                default => ($this->translate)('The model is looking through the site (%tools%).', ['%tools%' => implode(', ', $tools)]),
            },
        ]);
    }

    /**
     * Told at every delta; written every few hundred characters, which is
     * what a reader can follow and what a proxy forwards without choking.
     */
    public function onText(int $characters): void
    {
        if ($characters - $this->announced < 300) {
            return;
        }

        $this->announced = $characters;
        $this->emit('delta', ['characters' => $characters, 'message' => ($this->translate)('The model is writing: %characters% characters so far.', ['%characters%' => (string) $characters])]);
    }

    public function onCorrection(int $round, array $problems): void
    {
        $this->emit('correction', [
            'round' => $round,
            'problems' => $problems,
            'message' => ($this->translate)($this->messages['refused'] ?? 'The document was refused by the site and sent back for correction.', []),
        ]);
    }

    public function onCut(): void
    {
        $this->announced = 0;
        $this->emit('cut', ['message' => ($this->translate)('The answer was cut half way: the model is asked again.', [])]);
    }

    public function done(string $redirect, string $message): void
    {
        $this->emit('done', ['redirect' => $redirect, 'message' => $message]);
    }

    public function error(string $message, ?string $redirect = null): void
    {
        $this->emit('error', ['message' => $message, 'redirect' => $redirect]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function emit(string $event, array $data): void
    {
        echo 'event: ', $event, "\n", 'data: ', json_encode($data, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR), "\n\n";

        self::flush();
    }

    /**
     * Pushes the event through every output buffer to the client. On the
     * command line there is no client, and the buffers belong to whoever runs
     * the kernel: the tests read the whole stream once it is finished.
     */
    private static function flush(): void
    {
        if (\in_array(\PHP_SAPI, ['cli', 'phpdbg'], true)) {
            return;
        }

        while (ob_get_level() > 0) {
            ob_end_flush();
        }

        flush();
    }
}
