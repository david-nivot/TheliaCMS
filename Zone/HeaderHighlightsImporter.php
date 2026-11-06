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

namespace TheliaCMS\Zone;

use Propel\Runtime\Connection\ConnectionInterface;
use Propel\Runtime\Propel;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\File;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Lang;
use Thelia\Model\LangQuery;
use TheliaCMS\Content\Document;
use TheliaCMS\Content\DocumentValidator;
use TheliaCMS\Content\HighlightsSection;
use TheliaCMS\Content\InvalidDocumentException;
use TheliaCMS\Content\Section;
use TheliaCMS\Http\ConsoleRequest;
use TheliaCMS\Media\CmsMediaLibrary;
use TheliaCMS\Model\CmsPageContentQuery;
use TheliaCMS\Page\Admin\CmsPageWriter;
use TheliaLibrary\Service\LibraryImageService;
use TheliaLibrary\TheliaLibrary;

/**
 * Copies the banner of the HeaderHighlights module into the header zone, as a
 * draft in each language: a `cms:highlights` section, its images in the media
 * library. Nothing is published and HeaderHighlights is left as it is: the
 * editor reads the draft, publishes it, then removes the module.
 *
 * The tables are read with SQL, not through the models of the module: the
 * module may have been removed since, its tables still there.
 *
 * What HeaderHighlights holds, and where it goes:
 * - a desktop row of `header_highlights` is a block, ordered by `image_block`
 *   (1 is the lead); its i18n gives `title`, `catchphrase` (`text`),
 *   `call_to_action` (`button_label`) and `url` (`button_url`) per language;
 * - its image (`header_highlights_image.file`, the same in every language)
 *   becomes `image`; the image of the mobile row of the same number becomes
 *   `image_mobile`, none when there is no such row or when it is the same file;
 * - an image is matched to the library by the SHA-1 of its content, whatever
 *   its name: run again, nothing is copied twice;
 * - nothing else is carried: the texts of a mobile row that differ from the
 *   desktop ones, a second image of a block, a block after the third are
 *   reported, not invented.
 */
final readonly class HeaderHighlightsImporter
{
    private const int MAX_BLOCKS = 3;

    private const array TABLES = ['header_highlights', 'header_highlights_i18n', 'header_highlights_image'];

    private const string SECTION_ID = 'highlights';

    public function __construct(
        private HeaderZone $zones,
        private CmsPageWriter $writer,
        private CmsMediaLibrary $library,
        private LibraryImageService $images,
        private DocumentValidator $validator,
        private ConsoleRequest $request,
    ) {
    }

    /**
     * The directory HeaderHighlights stores its image files in.
     */
    public function uploadDirectory(): string
    {
        $path = ConfigQuery::read('images_library_path');
        $base = null === $path ? THELIA_LOCAL_DIR.'media'.\DIRECTORY_SEPARATOR.'images' : THELIA_ROOT.$path;

        return $base.\DIRECTORY_SEPARATOR.'headerHighlights';
    }

    public function import(bool $dryRun = false, bool $replace = false, ?string $url = null): HeaderHighlightsImport
    {
        $connection = Propel::getConnection('TheliaMain');

        if (!$this->tablesExist($connection)) {
            return new HeaderHighlightsImport(false);
        }

        $problems = [];
        $blocks = $this->blocks($connection, $problems);

        if ([] === $blocks) {
            return new HeaderHighlightsImport(false, problems: $problems);
        }

        /** @var list<Lang> $languages */
        $languages = iterator_to_array(LangQuery::create()->filterByActive(true)->orderByByDefault('desc')->find(), false);
        $zone = $this->zones->find();
        $outcomes = [];
        $plans = [];

        foreach ($languages as $language) {
            $locale = (string) $language->getLocale();
            $items = $this->itemsOf($blocks, $locale, $problems);

            if (!$this->hasText($items)) {
                $outcomes[$locale] = ['status' => HeaderHighlightsImport::NO_TEXT, 'blocks' => 0];

                continue;
            }

            if (!$replace && null !== $zone && $this->holdsADraft((int) $zone->getId(), $locale)) {
                $outcomes[$locale] = ['status' => HeaderHighlightsImport::KEPT, 'blocks' => \count($items)];

                continue;
            }

            $plans[$locale] = $items;
            $outcomes[$locale] = ['status' => HeaderHighlightsImport::WRITTEN, 'blocks' => \count($items)];
        }

        $titles = $this->imageTitles($blocks, $languages);
        $mediaIds = [];
        $created = 0;
        $reused = 0;

        if ([] !== $plans) {
            [$mediaIds, $created, $reused] = $this->media($plans, $titles, (string) $languages[0]->getLocale(), $dryRun, $problems);
        }

        $failures = [];

        if (!$dryRun && [] !== $plans) {
            $zone = $this->zones->ensure();

            foreach ($plans as $locale => $items) {
                try {
                    $document = $this->document($items, $mediaIds);
                    $this->request->around(function () use ($zone, $locale, $document, $replace): void {
                        if ($replace) {
                            // What the draft held is kept as a revision, like the content screen does before a generation.
                            $this->writer->keepDraft($zone, $locale);
                        }

                        $this->writer->saveContent($zone, $locale, $document);
                    }, $url);
                } catch (InvalidDocumentException $exception) {
                    $failures[] = \sprintf('%s: %s', $locale, $exception->getMessage());
                    $outcomes[$locale]['status'] = HeaderHighlightsImport::FAILED;
                }
            }
        } elseif ($dryRun) {
            foreach ($plans as $locale => $items) {
                try {
                    $this->validator->validate($this->document($items, $mediaIds));
                } catch (InvalidDocumentException $exception) {
                    $failures[] = \sprintf('%s: %s', $locale, $exception->getMessage());
                }
            }
        }

        return new HeaderHighlightsImport(true, $outcomes, $created, $reused, array_values(array_unique($problems)), $failures);
    }

    private function tablesExist(ConnectionInterface $connection): bool
    {
        $statement = $connection->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (?, ?, ?)');
        $statement->execute(self::TABLES);

        return \count(self::TABLES) === (int) $statement->fetchColumn();
    }

    /**
     * The desktop blocks by number, each with its mobile twin when there is
     * one, its texts per language and its image file.
     *
     * @param list<string> $problems
     *
     * @return array<int, array{desktop: array{texts: array<string, array<string, string>>, file: ?string}, mobile: ?array{texts: array<string, array<string, string>>, file: ?string}}>
     */
    private function blocks(ConnectionInterface $connection, array &$problems): array
    {
        $rows = $connection->query('SELECT id, image_block, display_type FROM header_highlights ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC);
        $found = ['desktop' => [], 'mobile' => []];

        foreach ($rows as $row) {
            $type = (string) $row['display_type'];
            $number = (string) $row['image_block'];

            if (!isset($found[$type])) {
                $problems[] = \sprintf('Row #%d has the display type "%s": left out.', $row['id'], $type);

                continue;
            }

            if (1 !== preg_match('/^\d+$/', $number) || (int) $number < 1 || (int) $number > self::MAX_BLOCKS) {
                // An empty row (no text, no image) is a seed of the module, not worth a line.
                if ($this->isFilled($connection, (int) $row['id'])) {
                    $problems[] = \sprintf('Row #%d is block "%s" (%s): the section holds blocks 1 to %d, left out.', $row['id'], $number, $type, self::MAX_BLOCKS);
                }

                continue;
            }

            if (isset($found[$type][(int) $number])) {
                $problems[] = \sprintf('Block %d (%s) is in two rows: row #%d is left out.', (int) $number, $type, $row['id']);

                continue;
            }

            $found[$type][(int) $number] = $this->rowDetails($connection, (int) $row['id'], $type, (int) $number, $problems);
        }

        $blocks = [];

        foreach ($found['desktop'] as $number => $desktop) {
            $blocks[$number] = ['desktop' => $desktop, 'mobile' => $found['mobile'][$number] ?? null];
        }

        foreach (array_diff_key($found['mobile'], $found['desktop']) as $number => $mobile) {
            if ($this->rowIsFilled($mobile)) {
                $problems[] = \sprintf('Block %d exists on mobile only: HeaderHighlights draws nothing for it, left out.', $number);
            }
        }

        ksort($blocks);

        return $blocks;
    }

    /**
     * @param list<string> $problems
     *
     * @return array{texts: array<string, array<string, string>>, file: ?string}
     */
    private function rowDetails(ConnectionInterface $connection, int $id, string $type, int $number, array &$problems): array
    {
        $texts = [];
        $statement = $connection->prepare('SELECT locale, title, catchphrase, call_to_action, url FROM header_highlights_i18n WHERE id = ?');
        $statement->execute([$id]);

        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $texts[(string) $row['locale']] = [
                'title' => trim((string) $row['title']),
                'text' => trim((string) $row['catchphrase']),
                'button_label' => trim((string) $row['call_to_action']),
                'button_url' => trim((string) $row['url']),
            ];
        }

        $statement = $connection->prepare('SELECT file FROM header_highlights_image WHERE header_highlights_id = ? AND file <> \'\' ORDER BY visible DESC, position IS NULL, position, id');
        $statement->execute([$id]);
        $files = $statement->fetchAll(\PDO::FETCH_COLUMN);

        if (\count($files) > 1) {
            $problems[] = \sprintf('Block %d (%s) has %d images: the first one is kept, the others left out.', $number, $type, \count($files));
        }

        return ['texts' => $texts, 'file' => [] === $files ? null : (string) $files[0]];
    }

    private function isFilled(ConnectionInterface $connection, int $id): bool
    {
        $ignored = [];
        $details = $this->rowDetails($connection, $id, '', 0, $ignored);

        return $this->rowIsFilled($details);
    }

    /**
     * @param array{texts: array<string, array<string, string>>, file: ?string} $row
     */
    private function rowIsFilled(array $row): bool
    {
        if (null !== $row['file']) {
            return true;
        }

        foreach ($row['texts'] as $texts) {
            if ('' !== implode('', $texts)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The blocks of one language, in order: those with a text in it or with an
     * image. A mobile row whose texts differ from the desktop ones is reported.
     *
     * @param array<int, array{desktop: array{texts: array<string, array<string, string>>, file: ?string}, mobile: ?array{texts: array<string, array<string, string>>, file: ?string}}> $blocks
     * @param list<string>                                                                                                                                                              $problems
     *
     * @return list<array{number: int, props: array<string, string>, file: ?string, mobileFile: ?string}>
     */
    private function itemsOf(array $blocks, string $locale, array &$problems): array
    {
        $items = [];

        foreach ($blocks as $number => $block) {
            $props = $block['desktop']['texts'][$locale] ?? ['title' => '', 'text' => '', 'button_label' => '', 'button_url' => ''];
            $mobileTexts = array_filter($block['mobile']['texts'][$locale] ?? []);

            if ([] !== $mobileTexts && array_intersect_key($mobileTexts, $props) !== array_intersect_key($props, $mobileTexts)) {
                $problems[] = \sprintf('Block %d, %s: the texts of the mobile row differ from the desktop ones; the section has one set of texts, the desktop ones are kept.', $number, $locale);
            }

            if ('' !== $props['button_label'] xor '' !== $props['button_url']) {
                $problems[] = \sprintf('Block %d, %s: a button needs its words and its address, one is missing: the button is not drawn.', $number, $locale);
            }

            if ('' === implode('', $props) && null === $block['desktop']['file'] && null === ($block['mobile']['file'] ?? null)) {
                continue;
            }

            $items[] = ['number' => $number, 'props' => $props, 'file' => $block['desktop']['file'], 'mobileFile' => $block['mobile']['file'] ?? null];
        }

        return $items;
    }

    /**
     * @param list<array{number: int, props: array<string, string>, file: ?string, mobileFile: ?string}> $items
     */
    private function hasText(array $items): bool
    {
        foreach ($items as $item) {
            if ('' !== implode('', $item['props'])) {
                return true;
            }
        }

        return false;
    }

    private function holdsADraft(int $zoneId, string $locale): bool
    {
        $content = CmsPageContentQuery::create()->filterByPageId($zoneId)->filterByLocale($locale)->findOne();
        $document = null === $content ? null : Document::fromJson($content->getDraftDocument());

        return null !== $document && !$document->isEmpty();
    }

    /**
     * The title of each block's images: the block title in the default
     * language, or in the first language that has one.
     *
     * @param array<int, array{desktop: array{texts: array<string, array<string, string>>, file: ?string}, mobile: ?array{texts: array<string, array<string, string>>, file: ?string}}> $blocks
     * @param list<Lang>                                                                                                                                                                $languages default first
     *
     * @return array<int, string>
     */
    private function imageTitles(array $blocks, array $languages): array
    {
        $titles = [];

        foreach ($blocks as $number => $block) {
            foreach ($languages as $language) {
                $title = $block['desktop']['texts'][(string) $language->getLocale()]['title'] ?? '';

                if ('' !== $title) {
                    $titles[$number] = $title;

                    break;
                }
            }
        }

        return $titles;
    }

    /**
     * The library id of every image file the plans use, copying the ones the
     * library does not hold. A dry run copies nothing: it counts.
     *
     * @param array<string, list<array{number: int, props: array<string, string>, file: ?string, mobileFile: ?string}>> $plans
     * @param array<int, string>                                                                                        $titles
     * @param list<string>                                                                                              $problems
     *
     * @return array{0: array<string, int>, 1: int, 2: int} ids by file name, images created, images reused
     */
    private function media(array $plans, array $titles, string $locale, bool $dryRun, array &$problems): array
    {
        $known = $this->libraryByContent();
        $ids = [];
        $created = 0;
        $reused = 0;
        $seen = [];

        foreach ($plans as $items) {
            foreach ($items as $item) {
                foreach ([$item['file'], $item['mobileFile']] as $file) {
                    if (null === $file || isset($seen[$file])) {
                        continue;
                    }

                    $seen[$file] = true;
                    $path = $this->uploadDirectory().\DIRECTORY_SEPARATOR.$file;

                    if (!is_file($path)) {
                        $problems[] = \sprintf('Block %d: the image file %s is not on disk: the block is written without it.', $item['number'], $path);

                        continue;
                    }

                    $fingerprint = (string) sha1_file($path);

                    if (isset($known[$fingerprint])) {
                        $ids[$file] = $known[$fingerprint];
                        ++$reused;

                        continue;
                    }

                    ++$created;

                    if (!$dryRun) {
                        $ids[$file] = $known[$fingerprint] = $this->copy($path, $titles[$item['number']] ?? $file, $locale);
                    }
                }
            }
        }

        return [$ids, $created, $reused];
    }

    private function copy(string $path, string $title, string $locale): int
    {
        // The library moves the file it is given into its own directory: it gets a copy, HeaderHighlights keeps its file.
        $copy = sys_get_temp_dir().\DIRECTORY_SEPARATOR.'theliacms-hh-'.bin2hex(random_bytes(6)).'-'.basename($path);
        (new Filesystem())->copy($path, $copy);

        try {
            $image = $this->images->createImage(new File($copy), $title, $locale);
        } finally {
            (new Filesystem())->remove($copy);
        }

        $this->library->tag($image);

        return (int) $image->getId();
    }

    /**
     * @return array<string, int> library image ids by SHA-1 of the file
     */
    private function libraryByContent(): array
    {
        $known = [];

        foreach ($this->library->images(limit: \PHP_INT_MAX) as $image) {
            $fileName = $image->getFileName();
            $path = null === $fileName ? null : TheliaLibrary::getImageDirectory().$fileName;

            if (null !== $path && is_file($path)) {
                $known[(string) sha1_file($path)] ??= (int) $image->getId();
            }
        }

        return $known;
    }

    /**
     * @param list<array{number: int, props: array<string, string>, file: ?string, mobileFile: ?string}> $items
     * @param array<string, int>                                                                         $mediaIds
     */
    private function document(array $items, array $mediaIds): Document
    {
        $blocks = [];

        foreach ($items as $item) {
            $image = null === $item['file'] ? null : ($mediaIds[$item['file']] ?? null);
            $mobile = null === $item['mobileFile'] ? null : ($mediaIds[$item['mobileFile']] ?? null);
            $block = $item['props'];

            // The desktop picture is drawn on every screen when there is no other: the same file twice adds nothing.
            if (null !== ($image ?? $mobile)) {
                $block['image'] = $image ?? $mobile;
            }

            if (null !== $image && null !== $mobile && $mobile !== $image) {
                $block['image_mobile'] = $mobile;
            }

            $blocks[] = $block;
        }

        return new Document([new Section(self::SECTION_ID, HighlightsSection::NAME, ['blocks' => $blocks])]);
    }
}
