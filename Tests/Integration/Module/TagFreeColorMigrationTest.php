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

namespace TheliaCMS\Tests\Integration\Module;

use Propel\Runtime\Connection\ConnectionInterface;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;
use TheliaCMS\TheliaCMS;

/**
 * A site on 2.6 whose tags are colored with names of the palette moving to
 * free colors: the text column appears, each name becomes the codes of the
 * badge it gave, a code or a stranger is left alone, and the update can be
 * played twice.
 *
 * Runs outside a transaction, as TagColorMigrationTest does and for the same
 * reasons, the column dropped with ALGORITHM=COPY included.
 */
final class TagFreeColorMigrationTest extends CmsIntegrationTestCase
{
    protected bool $useTransaction = false;

    /** @var list<int> */
    private array $tagIds = [];

    protected function tearDown(): void
    {
        $connection = $this->getPropelConnection();

        (new TheliaCMS())->update('2.6.3', '2.7.0', $connection);

        foreach ($this->tagIds as $id) {
            $connection->exec(\sprintf('DELETE FROM cms_tag WHERE id = %d', $id));
        }

        parent::tearDown();
    }

    public function testTheNamesOfThePaletteBecomeTheCodesOfTheirBadgeAndTheUpdateCanBePlayedTwice(): void
    {
        $connection = $this->getPropelConnection();
        $connection->exec('ALTER TABLE cms_tag DROP COLUMN text_color, ALGORITHM=COPY');
        $cyan = $this->tag($connection, 'Étiquette cyan', 'cyan');
        $red = $this->tag($connection, 'Étiquette rouge', 'red');
        $none = $this->tag($connection, 'Étiquette sans couleur', null);
        $stranger = $this->tag($connection, 'Étiquette écrite à la main', 'pink');

        $module = new TheliaCMS();
        $module->update('2.6.3', '2.7.0', $connection);
        $module->update('2.6.3', '2.7.0', $connection);

        $column = $this->rows("SELECT IS_NULLABLE AS is_nullable, CHARACTER_MAXIMUM_LENGTH AS size FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'cms_tag' AND column_name = 'text_color'");
        self::assertSame('YES', $column[0]['is_nullable'] ?? null);
        self::assertSame(16, (int) $column[0]['size']);

        self::assertSame(['color' => '#0dcaf0', 'text_color' => '#000000'], $this->colorsOf($cyan));
        self::assertSame(['color' => '#dc3545', 'text_color' => '#ffffff'], $this->colorsOf($red));
        self::assertSame(['color' => null, 'text_color' => null], $this->colorsOf($none));
        self::assertSame(['color' => 'pink', 'text_color' => null], $this->colorsOf($stranger));
    }

    private function tag(ConnectionInterface $connection, string $name, ?string $color): int
    {
        $statement = $connection->prepare('INSERT INTO cms_tag (name, color, created_at, updated_at) VALUES (?, ?, NOW(), NOW())');
        $statement->execute([$name, $color]);
        $id = (int) $connection->lastInsertId();
        $this->tagIds[] = $id;

        return $id;
    }

    /**
     * @return array<string, mixed>
     */
    private function colorsOf(int $id): array
    {
        return $this->rows(\sprintf('SELECT color, text_color FROM cms_tag WHERE id = %d', $id))[0];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql): array
    {
        return $this->getPropelConnection()->query($sql)->fetchAll(\PDO::FETCH_ASSOC);
    }
}
