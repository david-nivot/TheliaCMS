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

namespace TheliaCMS\Tests\Integration;

use Propel\Runtime\Connection\ConnectionWrapper;
use Psr\Log\AbstractLogger;
use Thelia\Tools\URL;

/**
 * What a read costs, counted rather than assumed.
 *
 * The counting comes from Propel itself: a connection put in debug mode counts the
 * statements it runs, and hands each one to its logger.
 *
 * Two things make a second read cheaper than the first, and so hide a query asked
 * once per row: Propel keeps the objects it has read (with their translations), and
 * the core keeps the addresses it has resolved. A measure that has to see the cost
 * of a cold request forgets both first, with `forgetWhatWasRead()`.
 */
trait CountsStatements
{
    /**
     * How many statements the given read sends to the database.
     */
    private function statementsFor(callable $read): int
    {
        $connection = $this->countingConnection();

        $wasDebugging = $connection->isInDebugMode();
        $connection->useDebug(true);
        $before = $connection->getQueryCount();

        try {
            $read();
        } finally {
            // Leaving the debug mode resets the counter: read it first.
            $after = $connection->getQueryCount();
            $connection->useDebug($wasDebugging);
        }

        return $after - $before;
    }

    /**
     * The statements the given read sends to the database, as Propel writes them.
     *
     * @return list<string>
     */
    private function statementsSentBy(callable $read): array
    {
        $connection = $this->countingConnection();
        $previous = $connection->getLogger();
        $recorder = new class extends AbstractLogger {
            /** @var list<string> */
            public array $statements = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->statements[] = (string) $message;
            }
        };

        $wasDebugging = $connection->isInDebugMode();
        $connection->useDebug(true);
        $connection->setLogger($recorder);

        try {
            $read();
        } finally {
            $connection->setLogger($previous);
            $connection->useDebug($wasDebugging);
        }

        return $recorder->statements;
    }

    /**
     * Forgets the objects Propel keeps and the addresses the core keeps, so that the
     * next read costs what it costs on a fresh request.
     *
     * @param list<class-string> $tableMaps the generated maps whose objects to forget
     */
    private function forgetWhatWasRead(array $tableMaps): void
    {
        foreach ($tableMaps as $tableMap) {
            $tableMap::clearInstancePool();
        }

        URL::getInstance()->clearRewritingUrlCache();
    }

    private function countingConnection(): ConnectionWrapper
    {
        $connection = $this->getPropelConnection();

        if (!$connection instanceof ConnectionWrapper) {
            self::markTestSkipped('this connection cannot count what it runs');
        }

        return $connection;
    }
}
