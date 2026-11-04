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

namespace TheliaCMS\Tests\Integration\Form;

use Propel\Runtime\Connection\ConnectionManagerSingle;
use Propel\Runtime\Propel;
use Propel\Runtime\ServiceContainer\StandardServiceContainer;
use Thelia\Model\ConfigQuery;
use TheliaCMS\Form\SubmissionRateLimiter;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;

/**
 * Twenty messages posted at the same instant by one sender who never posted
 * before: the rate limit lets through what it lets through one at a time.
 *
 * A check followed by a write lets every request that read the empty counter
 * through, which is exactly what a script sending in parallel does. Only real
 * processes, each on its own connection, show it: one process runs one query
 * at a time. Runs outside a transaction, which would hide the rows of one
 * connection from the others, and cleans up by hand.
 */
final class SenderBurstTest extends CmsIntegrationTestCase
{
    private const int SENDERS = 20;

    private const int ALLOWED = 6;

    private const string CODE = 'contact-test-burst';

    private const string ADDRESS = '192.0.2.77';

    protected bool $useTransaction = false;

    private string $results = '';

    protected function setUp(): void
    {
        parent::setUp();

        if (!\function_exists('pcntl_fork') || !\function_exists('posix_kill')) {
            self::markTestSkipped('Needs pcntl and posix to post from several processes at once.');
        }

        ConfigQuery::write('form_firewall_active', '1');
        ConfigQuery::write('form_firewall_attempts', (string) self::ALLOWED);
        ConfigQuery::write('form_firewall_time_to_wait', '60');
        $this->forgetTheSender();

        $this->results = sys_get_temp_dir().'/cms-burst-'.bin2hex(random_bytes(6));
        mkdir($this->results);
    }

    protected function tearDown(): void
    {
        $this->forgetTheSender();

        foreach (glob($this->results.'/*') ?: [] as $file) {
            unlink($file);
        }

        if (is_dir($this->results)) {
            rmdir($this->results);
        }

        ConfigQuery::resetCache();

        parent::tearDown();
    }

    public function testABurstFromOneSenderGetsNoMoreThroughThanTheLimit(): void
    {
        $configuration = $this->connectionConfiguration();
        // Every process waits for the same instant, so the posts hit the
        // database together rather than in the order they were forked.
        $start = microtime(true) + 1.0;
        $children = [];

        for ($sender = 0; $sender < self::SENDERS; ++$sender) {
            $pid = pcntl_fork();
            self::assertNotSame(-1, $pid, 'Could not fork.');

            if (0 === $pid) {
                $this->postFromAChild($configuration, $start, $sender);
            }

            $children[] = $pid;
        }

        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
        }

        $answers = array_map(static fn (string $file): string => (string) file_get_contents($file), glob($this->results.'/*') ?: []);

        self::assertCount(self::SENDERS, $answers, 'A process died before answering: '.implode(', ', $answers));
        self::assertSame([], array_values(array_filter($answers, static fn (string $answer): bool => !\in_array($answer, ['allowed', 'refused'], true))));
        self::assertLessThanOrEqual(self::ALLOWED, \count(array_keys($answers, 'allowed', true)));
        self::assertGreaterThan(0, \count(array_keys($answers, 'allowed', true)), 'Nothing went through: the test proves nothing.');
    }

    /**
     * Runs in the forked process and never returns.
     *
     * The connection of the parent is shared with the child: closing it here
     * would close it for the test as well. The child opens its own, keeps the
     * inherited one referenced, and ends with SIGKILL so that no destructor and
     * no shutdown function of the parent runs on its behalf.
     *
     * @param array<string, mixed> $configuration
     */
    private function postFromAChild(array $configuration, float $start, int $sender): never
    {
        // Held in this frame until the SIGKILL: dropped, its destructor would
        // close the socket the parent is using.
        $inherited = Propel::getServiceContainer()->getWriteConnection('TheliaMain');

        try {
            $container = Propel::getServiceContainer();
            self::assertInstanceOf(StandardServiceContainer::class, $container);
            $manager = new ConnectionManagerSingle('TheliaMain');
            $manager->setConfiguration($configuration);
            $container->setConnectionManager($manager);

            $limiter = $this->getService(SubmissionRateLimiter::class);
            time_nanosleep(0, max(0, (int) (($start - microtime(true)) * 1e9)));

            $answer = $limiter->allows(self::CODE, self::ADDRESS) ? 'allowed' : 'refused';
        } catch (\Throwable $throwable) {
            $answer = $throwable::class.': '.$throwable->getMessage();
        }

        file_put_contents($this->results.'/'.$sender, $answer);

        posix_kill(getmypid(), \SIGKILL);

        exit(1);
    }

    /**
     * @return array<string, mixed>
     */
    private function connectionConfiguration(): array
    {
        $manager = Propel::getServiceContainer()->getConnectionManager('TheliaMain');
        $configuration = $manager instanceof ConnectionManagerSingle ? $manager->getConfiguration() : [];

        if ([] === $configuration) {
            self::markTestSkipped('The connection of the suite was not built from a configuration a child could reuse.');
        }

        return $configuration;
    }

    /**
     * Whatever table the limiter keeps its counts in.
     */
    private function forgetTheSender(): void
    {
        $connection = $this->getPropelConnection();
        $connection->exec("DELETE FROM form_firewall WHERE form_name = 'cms_form_".self::CODE."'");

        if (0 < (int) $connection->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'cms_form_throttle'")->fetchColumn()) {
            $connection->exec("DELETE FROM cms_form_throttle WHERE scope = 'sender'");
        }
    }
}
