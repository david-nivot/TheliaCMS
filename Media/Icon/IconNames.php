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

namespace TheliaCMS\Media\Icon;

use Propel\Runtime\Connection\ConnectionInterface;
use Propel\Runtime\Propel;
use Symfony\Contracts\Service\ResetInterface;
use TheliaLibrary\TheliaLibrary;

/**
 * The names the SVGs of the library answer to as icons (`cms_icon`).
 *
 * Raw SQL through the connection, like CmsMediaLibrary: every read joins
 * `library_image`, a table of another module that the generated models do not
 * know. A row whose image is gone (TheliaLibrary deletes without any event) is
 * ignored when read, and replaced by the next image that takes its name.
 *
 * The live names and their files are read once and kept, like the icons of
 * the theme (`ThemeIcons`): `KnownIcons::isKnown()` is asked for every icon of
 * every page drawn, and a query each time would cost more than the drawing.
 * The memory lives as long as the service: one request under PHP-FPM, and
 * until the next `reset()` in a worker, which the container calls between two
 * messages. Every write of the class empties it.
 *
 * Every write that changes what `cms:<name>` draws empties its entry of the
 * cache of ux-icons.
 */
final class IconNames implements IconFileFinder, ResetInterface
{
    /** SQLSTATE of a duplicate key, among others. */
    private const string INTEGRITY_CONSTRAINT_VIOLATION = '23000';

    /** @var array<string, string>|null */
    private ?array $files = null;

    public function __construct(private readonly IconCacheInvalidator $cache)
    {
    }

    public function fileOf(string $name): ?string
    {
        if (!IconName::isValid($name)) {
            return null;
        }

        return $this->filesByName()[$name] ?? null;
    }

    /**
     * Whether an image of the library answers to the name: a row whose image
     * is gone does not hold its name, as for `fileOf`.
     */
    public function isTaken(string $name): bool
    {
        return isset($this->filesByName()[$name]);
    }

    /**
     * @return list<string> names of the icons whose image is still in the library, alphabetical
     */
    public function names(): array
    {
        return array_map(strval(...), array_keys($this->filesByName()));
    }

    /**
     * @return array<string, string> the file of each icon whose image is still in the library, by name
     */
    public function filesByName(): array
    {
        if (null === $this->files) {
            $statement = $this->connection()->query(
                'SELECT icon.name, image.file_name FROM cms_icon AS icon INNER JOIN library_image AS image ON image.id = icon.image_id ORDER BY icon.name',
            );
            $files = [];

            foreach ($statement->fetchAll(\PDO::FETCH_NUM) as $row) {
                if ('' !== (string) $row[1]) {
                    $files[(string) $row[0]] = TheliaLibrary::getImageDirectory().$row[1];
                }
            }

            $this->files = $files;
        }

        return $this->files;
    }

    public function reset(): void
    {
        $this->files = null;
    }

    /**
     * @return array<int, string> the name of each image of the library that has one, by image id
     */
    public function namesByImage(): array
    {
        $statement = $this->connection()->query(
            'SELECT icon.image_id, icon.name FROM cms_icon AS icon INNER JOIN library_image AS image ON image.id = icon.image_id',
        );
        $names = [];

        foreach ($statement->fetchAll(\PDO::FETCH_NUM) as $row) {
            $names[(int) $row[0]] = (string) $row[1];
        }

        return $names;
    }

    public function nameOf(int $imageId): ?string
    {
        $statement = $this->connection()->prepare('SELECT name FROM cms_icon WHERE image_id = :image');
        $statement->execute(['image' => $imageId]);
        $name = $statement->fetchColumn();

        // The Propel statement answers an empty string where PDO answers false.
        return \is_string($name) && '' !== $name ? $name : null;
    }

    /**
     * Gives a name to an image, and takes away the one it had.
     *
     * @throws InvalidIconNameException
     * @throws IconNameTakenException   when another image of the library answers to the name
     */
    public function assign(int $imageId, string $name): void
    {
        if (!IconName::isValid($name)) {
            throw new InvalidIconNameException(\sprintf('"%s" is not a valid icon name.', $name));
        }

        $connection = $this->connection();
        $this->files = null;

        // A row whose image is gone does not hold its name.
        $connection->prepare('DELETE FROM cms_icon WHERE name = :name AND image_id NOT IN (SELECT id FROM library_image)')
            ->execute(['name' => $name]);

        $holder = $connection->prepare('SELECT image_id FROM cms_icon WHERE name = :name');
        $holder->execute(['name' => $name]);
        $holderId = $holder->fetchColumn();

        // The Propel statement answers an empty string where PDO answers false.
        if (\is_string($holderId) && '' !== $holderId && (int) $holderId !== $imageId) {
            throw new IconNameTakenException(\sprintf('The icon name "%s" belongs to another image.', $name));
        }

        $previousName = $this->nameOf($imageId);

        if ($previousName === $name) {
            return;
        }

        try {
            if (null === $previousName) {
                $connection->prepare('INSERT INTO cms_icon (name, image_id, created_at, updated_at) VALUES (:name, :image, NOW(), NOW())')
                    ->execute(['name' => $name, 'image' => $imageId]);
            } else {
                $connection->prepare('UPDATE cms_icon SET name = :name, updated_at = NOW() WHERE image_id = :image')
                    ->execute(['name' => $name, 'image' => $imageId]);
                $this->cache->forget($previousName);
            }
        } catch (\PDOException $exception) {
            // Another answer took the name between the check above and this write.
            if (self::INTEGRITY_CONSTRAINT_VIOLATION === (string) $exception->getCode()) {
                throw new IconNameTakenException(\sprintf('The icon name "%s" belongs to another image.', $name), 0, $exception);
            }

            throw $exception;
        }

        $this->files = null;
        $this->cache->forget($name);
    }

    public function remove(int $imageId): void
    {
        $name = $this->nameOf($imageId);

        if (null === $name) {
            return;
        }

        $this->connection()->prepare('DELETE FROM cms_icon WHERE image_id = :image')->execute(['image' => $imageId]);
        $this->files = null;
        $this->cache->forget($name);
    }

    private function connection(): ConnectionInterface
    {
        return Propel::getConnection('TheliaMain');
    }
}
