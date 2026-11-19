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

namespace TheliaCMS\Tests\Integration\Content;

use Propel\Runtime\Connection\ConnectionManagerSingle;
use Propel\Runtime\Propel;
use Propel\Runtime\ServiceContainer\StandardServiceContainer;
use TheliaCMS\Content\Document;
use TheliaCMS\Content\Editing\StaleDraftException;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Page\Admin\DraftRendering;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;

/**
 * Writes of one draft from several processes at once, each on its own
 * connection: what one PHP process cannot show, running one query at a time.
 * Built on the model of `SenderBurstTest`.
 */
final class DraftRaceTest extends CmsIntegrationTestCase
{
    private const int EDITORS = 6;

    protected bool $useTransaction = false;

    private string $results = '';

    protected function setUp(): void
    {
        parent::setUp();

        if (!\function_exists('pcntl_fork') || !\function_exists('posix_kill')) {
            self::markTestSkipped('Needs pcntl and posix to write from several processes at once.');
        }

        $this->results = sys_get_temp_dir().'/cms-draft-race-'.bin2hex(random_bytes(6));
        mkdir($this->results);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->results.'/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->results);

        parent::tearDown();
    }

    public function testSavesMadeAtOnceOnTheSameVersionLetExactlyOneThrough(): void
    {
        $page = $this->createPage('Course', published: false);
        $read = $this->version($page);
        $configuration = $this->connectionConfiguration();
        // Every process waits for the same instant, so the saves hit the
        // database together rather than in the order they were forked.
        $start = microtime(true) + 1.0;
        $children = [];

        for ($editor = 0; $editor < self::EDITORS; ++$editor) {
            $pid = pcntl_fork();
            self::assertNotSame(-1, $pid, 'Could not fork.');

            if (0 === $pid) {
                $this->inAChild($configuration, (string) $editor, function () use ($page, $read, $start, $editor): string {
                    time_nanosleep(0, max(0, (int) (($start - microtime(true)) * 1e9)));

                    try {
                        $this->writer()->saveContent($page, $this->locale(), Document::richText(\sprintf('<p>Rédacteur %d.</p>', $editor)), null, $read);

                        return 'saved';
                    } catch (StaleDraftException) {
                        return 'refused';
                    }
                });
            }

            $children[] = $pid;
        }

        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
        }

        $answers = $this->answers();

        self::assertCount(self::EDITORS, $answers, 'A process died before answering: '.implode(', ', $answers));
        self::assertSame([], array_values(array_filter($answers, static fn (string $answer): bool => !\in_array($answer, ['saved', 'refused'], true))));
        self::assertCount(1, array_keys($answers, 'saved', true), 'Saves made on the same version: '.implode(', ', $answers));
        self::assertSame($read + 1, $this->version($page));
    }

    public function testRenderingADraftAgainLeavesASaveThatLandedWhileItRenderedAsItIs(): void
    {
        $page = $this->createPage('Rendu', published: false);
        $id = (int) $this->getPropelConnection()->query(\sprintf("SELECT id FROM cms_page_content WHERE page_id = %d AND locale = '%s'", $page->getId(), $this->locale()))->fetchColumn();
        // A rendering older than the one the renderer draws now: what makes it write.
        $this->getPropelConnection()->exec(\sprintf("UPDATE cms_page_content SET draft_html = '<p>Rendu ancien.</p>' WHERE id = %d", $id));
        $configuration = $this->connectionConfiguration();

        $pid = pcntl_fork();
        self::assertNotSame(-1, $pid, 'Could not fork.');

        if (0 === $pid) {
            // A save in flight: written, not committed, while the parent reads
            // and renders; committed once the parent waits on the row.
            $this->inAChild($configuration, 'save', function () use ($id): string {
                $connection = Propel::getConnection('TheliaMain');
                $connection->beginTransaction();
                $connection->exec(\sprintf("UPDATE cms_page_content SET draft_html = '<p>Enregistré entre-temps.</p>', draft_version = draft_version + 1 WHERE id = %d", $id));
                file_put_contents($this->results.'/written', '1');
                sleep(2);
                $connection->commit();

                return 'committed';
            });
        }

        $deadline = microtime(true) + 10;

        while (!is_file($this->results.'/written') && microtime(true) < $deadline) {
            usleep(20000);
        }

        self::assertFileExists($this->results.'/written', 'The child never wrote.');

        $outcome = $this->writer()->renderDraftAgain($page, $this->locale());
        pcntl_waitpid($pid, $status);

        self::assertSame(DraftRendering::ChangedMeanwhile, $outcome);
        self::assertSame('<p>Enregistré entre-temps.</p>', $this->getPropelConnection()->query(\sprintf('SELECT draft_html FROM cms_page_content WHERE id = %d', $id))->fetchColumn());
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
     * @param \Closure(): string   $work
     */
    private function inAChild(array $configuration, string $name, \Closure $work): never
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

            $answer = $work();
        } catch (\Throwable $throwable) {
            $answer = $throwable::class.': '.$throwable->getMessage();
        }

        file_put_contents($this->results.'/answer-'.$name, $answer);

        posix_kill(getmypid(), \SIGKILL);

        exit(1);
    }

    /**
     * @return list<string>
     */
    private function answers(): array
    {
        return array_map(static fn (string $file): string => (string) file_get_contents($file), glob($this->results.'/answer-*') ?: []);
    }

    private function version(CmsPage $page): int
    {
        return (int) $this->getPropelConnection()->query(\sprintf(
            "SELECT draft_version FROM cms_page_content WHERE page_id = %d AND locale = '%s'",
            $page->getId(),
            $this->locale(),
        ))->fetchColumn();
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
}
