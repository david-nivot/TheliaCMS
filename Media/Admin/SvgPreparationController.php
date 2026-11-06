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

namespace TheliaCMS\Media\Admin;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Thelia\Core\Security\AccessManager;
use Thelia\Model\Lang;
use TheliaCMS\Http\Admin\AdminControllerSupport;
use TheliaCMS\Media\CmsMediaLibrary;
use TheliaCMS\Media\Svg\Color\SvgColoring;
use TheliaCMS\Media\Svg\Color\SvgColorPreset;
use TheliaCMS\Media\Svg\Color\SvgPreviewTint;
use TheliaCMS\Media\Svg\Optimization\SvgOptimizationLevels;
use TheliaCMS\Media\Svg\PreparedSvgFile;
use TheliaCMS\Media\Svg\PreparedSvgStore;
use TheliaCMS\Media\Svg\SvgChangeWording;
use TheliaCMS\Media\Svg\SvgPreparation;
use TheliaCMS\Page\Admin\EditLanguage;
use TheliaCMS\Page\Admin\PageDocumentSize;
use TheliaCMS\Security\CmsResources;
use TheliaLibrary\Exception\UnreadableSvgException;
use TheliaLibrary\Exception\UnsupportedLibraryImageException;
use TheliaLibrary\Model\LibraryImage;
use Twig\Environment;

/**
 * The screen an SVG goes through before it enters the media library: what the
 * cleaning removed, what the optimization saves, the image before and after.
 *
 * It works on one file at a time. The files of one sending wait behind their
 * tokens, in the address, and each answer leads to the next one; once the last
 * is answered, the editor is sent to the sheet of the image, or to the queue of
 * the images to describe. An SVG that replaces the file of an image comes with
 * the id of that image.
 */
#[Route('/admin/cms/media/svg', name: 'admin.cms.media.svg.')]
final readonly class SvgPreparationController
{
    private const string SHOW_TEMPLATE = '@TheliaCMSModule/backOffice/default-twig/media/prepare.html.twig';

    private const string PICKED_TEMPLATE = '@TheliaCMSModule/backOffice/default-twig/media/svg_picked.html.twig';

    /** A token is 32 characters of [a-f0-9]; the list builds the address of this screen with this one, then swaps it. */
    public const string TOKEN_PLACEHOLDER = '00000000000000000000000000000000';

    private const string TOKEN_REQUIREMENT = '[a-f0-9]{32}';

    public function __construct(
        private Environment $twig,
        private AdminControllerSupport $support,
        private PreparedSvgStore $store,
        private SvgPreparation $preparation,
        private SvgOptimizationLevels $levels,
        private SvgChangeWording $wording,
        private CmsMediaWriter $writer,
        private CmsMediaLibrary $library,
        private EditLanguage $languages,
    ) {
    }

    /**
     * The address of the screen of the first of the files, with the others
     * and what the way has stored so far behind it.
     *
     * @param list<string> $tokens   the files to answer, the first one is shown
     * @param list<int>    $imageIds the images already stored, for the end of the way
     *
     * @return array<string, mixed>
     */
    public static function parameters(array $tokens, array $imageIds, ?int $replace, int $languageId, ?int $total = null, ?string $level = null, bool $picker = false, ?SvgColoring $coloring = null): array
    {
        $rest = \array_slice($tokens, 1);
        $total ??= \count($tokens);

        return array_filter([
            ...($coloring?->withoutMain()->values() ?? []),
            'token' => $tokens[0] ?? '',
            'queue' => implode(',', $rest),
            'images' => implode(',', $imageIds),
            'of' => $total > 1 ? $total : null,
            'replace' => $replace,
            'level' => $level,
            'picker' => $picker ? '1' : null,
            EditLanguage::PARAMETER => $languageId,
        ], static fn (mixed $value): bool => null !== $value && '' !== $value);
    }

    #[Route('/{token}', name: 'show', requirements: ['token' => self::TOKEN_REQUIREMENT], methods: ['GET'])]
    public function show(Request $request, string $token): Response
    {
        $lang = $this->languages->resolve($request);
        $way = SvgWay::fromRequest($request->query->all(), $token);
        $file = $this->store->find($token);

        if (!$file instanceof PreparedSvgFile) {
            return $this->afterExpired($request, $lang, $way);
        }

        $level = $this->levels->get($way->level ?? '')?->name();

        try {
            $result = $this->preparation->prepare($file->content, $level, $way->coloring);
        } catch (UnreadableSvgException $exception) {
            return $this->refused($request, $lang, $file, $exception, $way->picker);
        }

        // The preview and the answer read it back instead of preparing again.
        $this->store->remember($token, self::setting($level, $result->coloring), $result->content);
        $locale = $lang->getLocale();

        return new Response($this->twig->render(self::SHOW_TEMPLATE, [
            'file' => $file,
            'position' => $way->position(),
            'total' => $way->total,
            'has_following' => [] !== $way->queue,
            'replace' => $way->replace,
            'picker' => $way->picker,
            'result' => $result,
            'entries' => $this->wording->entries($result->changes),
            'original_weight' => PageDocumentSize::human($result->originalBytes, $locale),
            'result_weight' => PageDocumentSize::human($result->resultBytes, $locale),
            'saved_weight' => PageDocumentSize::human(abs($result->savedBytes()), $locale),
            'levels' => $this->levels->all(),
            'level' => $level ?? '',
            'presets' => SvgColorPreset::cases(),
            'coloring' => $result->coloring,
            'color_values' => $result->coloring->values(),
            'tints' => SvgPreviewTint::cases(),
            'route_parameters' => $way->parameters($lang->getId()),
        ]));
    }

    /**
     * Add the file as prepared, or pass it; either way the next file comes.
     */
    #[Route('/{token}', name: 'submit', requirements: ['token' => self::TOKEN_REQUIREMENT], methods: ['POST'])]
    public function submit(Request $request, string $token): Response
    {
        $lang = $this->languages->resolve($request);
        $way = SvgWay::fromRequest($request->query->all(), $token);
        $file = $this->store->find($token);

        if (!$file instanceof PreparedSvgFile) {
            return $this->afterExpired($request, $lang, $way);
        }

        // Leaving a file out drops it from the disk: the same right as adding it.
        $this->support->denyUnless(
            CmsResources::MEDIA,
            null === $way->replace ? AccessManager::CREATE : AccessManager::UPDATE,
            'You are not allowed to change CMS media.',
        );

        if ('skip' === $request->request->getString('action')) {
            $this->store->forget($token);

            return $this->next($request, $lang, $way, null, null, null);
        }

        $level = $this->levels->get($request->request->getString('level'))?->name();
        $coloring = SvgColoring::fromValues($request->request->all());
        $locale = $lang->getLocale();
        $kept = $this->store->result($token, self::setting($level, $coloring));

        // A second answer sent before the first one ended (a double click)
        // finds the file taken and stores nothing.
        if (!$this->store->claim($token) instanceof PreparedSvgFile) {
            return $this->afterExpired($request, $lang, $way);
        }

        try {
            $svg = $kept ?? $this->preparation->prepare($file->content, $level, $coloring)->content;

            if (null === $way->replace) {
                $added = (int) $this->writer->addPreparedSvg($svg, $file->name, $locale)->getId();
            } else {
                $this->writer->replaceWithPreparedSvg($this->imageToReplace($way->replace), $svg, $file->name, $locale);
                $added = null;
            }
        } catch (UnreadableSvgException $exception) {
            return $this->refused($request, $lang, $file, $exception, $way->picker);
        } catch (UnsupportedLibraryImageException) {
            $this->store->forget($token);
            $refusal = $this->support->translate('Not added: %files%.', [
                '%files%' => $file->name.' ('.$this->support->translate(MediaUploadRefusal::Unreadable->message()).')',
            ]);

            if ($way->picker) {
                return $this->picked(null, $refusal);
            }

            $this->support->flash($request, 'warning', $refusal);

            return $this->backToList($lang);
        }

        $this->store->forget($token);

        // The setting is carried to the next files only when the editor says so.
        $carried = '1' === $request->request->getString('apply_to_following');

        return $this->next($request, $lang, $way, $added, $carried ? $level : null, $carried ? $coloring : null);
    }

    #[Route('/{token}/original', name: 'original', requirements: ['token' => self::TOKEN_REQUIREMENT], methods: ['GET'])]
    public function original(string $token): Response
    {
        $file = $this->store->find($token) ?? throw new NotFoundHttpException();

        return self::image($file->content);
    }

    #[Route('/{token}/result', name: 'result', requirements: ['token' => self::TOKEN_REQUIREMENT], methods: ['GET'])]
    public function result(Request $request, string $token): Response
    {
        $file = $this->store->find($token) ?? throw new NotFoundHttpException();
        $level = $this->levels->get($request->query->getString('level'))?->name();
        $coloring = SvgColoring::fromValues($request->query->all());
        $tint = SvgPreviewTint::tryFrom($request->query->getString(SvgPreviewTint::PARAMETER));

        try {
            $svg = $this->store->result($token, self::setting($level, $coloring)) ?? $this->preparation->prepare($file->content, $level, $coloring)->content;
        } catch (UnreadableSvgException) {
            throw new NotFoundHttpException();
        }

        return self::image(null === $tint ? $svg : $tint->apply($svg));
    }

    /** The key a prepared result is kept under: the level, or the base optimization alone, then the colors. */
    private static function setting(?string $level, SvgColoring $coloring): string
    {
        $key = null === $level ? 'base' : 'level-'.$level;

        return $coloring->changesNothing() ? $key : $key.'-'.$coloring->key();
    }

    /**
     * An SVG served to be drawn in an `img`, nothing else: it is a document
     * that may carry script, so it is served as an image, sandboxed, never
     * guessed at by the browser and never kept.
     */
    private static function image(string $svg): Response
    {
        return new Response($svg, Response::HTTP_OK, [
            'Content-Type' => 'image/svg+xml',
            'Content-Security-Policy' => 'sandbox',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store',
        ]);
    }

    private function imageToReplace(int $id): LibraryImage
    {
        return $this->library->ownedImage($id) ?? throw new NotFoundHttpException();
    }

    /**
     * The next file of the way that is still there, or the end of the way.
     * A file that waited too long is said, and the way goes on without it.
     */
    private function next(Request $request, Lang $lang, SvgWay $way, ?int $added, ?string $level, ?SvgColoring $coloring): Response
    {
        $images = null === $added ? $way->images : [...$way->images, $added];
        $queue = $way->queue;

        while ([] !== $queue) {
            $token = array_shift($queue);

            if ($this->store->find($token) instanceof PreparedSvgFile) {
                return $this->support->redirect('admin.cms.media.svg.show', self::parameters([$token, ...$queue], $images, $way->replace, $lang->getId(), $way->total, $level, $way->picker, $coloring));
            }

            $this->support->flash($request, 'warning', $this->support->translate('This file has expired, send it again.'));
        }

        // The picker asked for one file: what it gets back is that file, added or not.
        return $way->picker ? $this->picked($added, null) : $this->end($request, $lang, $images, $way->replace);
    }

    /**
     * @param list<int> $images the images stored along the way
     */
    private function end(Request $request, Lang $lang, array $images, ?int $replace): Response
    {
        if (null !== $replace) {
            return $this->support->redirect('admin.cms.media.edit', ['id' => $replace, EditLanguage::PARAMETER => $lang->getId()]);
        }

        if ([] === $images) {
            return $this->backToList($lang);
        }

        if (1 === \count($images)) {
            return $this->support->redirect('admin.cms.media.edit', ['id' => $images[0], EditLanguage::PARAMETER => $lang->getId()]);
        }

        $this->support->flash($request, 'success', $this->support->translate(
            '%count% images added. Describe each of them before using it in a page.',
            ['%count%' => \count($images)],
        ));

        return $this->support->redirect('admin.cms.media.describe', [
            EditLanguage::PARAMETER => $lang->getId(),
            'images' => implode(',', $images),
        ]);
    }

    private function afterExpired(Request $request, Lang $lang, SvgWay $way): Response
    {
        if ($way->picker) {
            return $this->picked(null, $this->support->translate('This file has expired, send it again.'));
        }

        $this->support->flash($request, 'warning', $this->support->translate('This file has expired, send it again.'));

        return $this->next($request, $lang, $way, null, $way->level, $way->coloring);
    }

    /**
     * A file the library cannot read is told, and the way stops there: back to
     * the list, where the editor sends it again.
     */
    private function refused(Request $request, Lang $lang, PreparedSvgFile $file, UnreadableSvgException $exception, bool $picker): Response
    {
        $this->store->forget($file->token);
        $refusal = $this->support->translate('Not added: %files%.', [
            '%files%' => $file->name.' ('.$this->wording->refusal($exception->reason).')',
        ]);

        if ($picker) {
            return $this->picked(null, $refusal);
        }

        $this->support->flash($request, 'warning', $refusal);

        return $this->backToList($lang);
    }

    /**
     * The end of a way opened from the image picker, in its frame: a page that
     * hands the image added, or why there is none, to the picker around it.
     */
    private function picked(?int $image, ?string $message): Response
    {
        return new Response($this->twig->render(self::PICKED_TEMPLATE, [
            'image' => $image,
            'message' => $message,
        ]));
    }

    private function backToList(Lang $lang): RedirectResponse
    {
        return $this->support->redirect('admin.cms.media.list', [EditLanguage::PARAMETER => $lang->getId()]);
    }
}
