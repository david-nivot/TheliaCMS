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

namespace TheliaCMS\Generation\Text\Admin;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\SecurityContext;
use TheliaCMS\Generation\Brief;
use TheliaCMS\Generation\GenerationException;
use TheliaCMS\Generation\GenerationRefusedException;
use TheliaCMS\Generation\GenerationRequest;
use TheliaCMS\Generation\GenerationTarget;
use TheliaCMS\Generation\GenerationUnavailableException;
use TheliaCMS\Generation\InvalidGenerationException;
use TheliaCMS\Generation\Text\TextField;
use TheliaCMS\Generation\Text\TextGenerator;
use TheliaCMS\Generation\Text\TextOutcomes;
use TheliaCMS\Generation\Text\TextRequest;
use TheliaCMS\Http\Admin\AdminControllerSupport;
use TheliaCMS\Locale\ShopLanguages;
use TheliaCMS\Security\CmsResources;

/**
 * Writes the text of one rich-text field from a brief, for the script beside
 * the field: the text comes back as JSON and nothing is saved but the row of
 * the ledger. The field holds it once the editor chose to put it there, and
 * the form saves it as if they had typed it.
 *
 * A call carries an id the screen made: what it answered is kept under it a
 * few minutes, and `outcome` gives it back when the connection was cut before
 * the answer arrived, rather than having the text written and billed twice.
 */
#[Route('/admin/cms/text', name: 'admin.cms.text.')]
final readonly class TextBriefController
{
    /** The objects whose fields the button sits beside. */
    public const array TYPES = ['page', 'product', 'category', 'brand', 'folder', 'content'];

    private const int BRIEF_LENGTH = 20000;

    private const int CURRENT_LENGTH = 100000;

    public function __construct(
        private TextGenerator $generator,
        private SecurityContext $securityContext,
        private AdminControllerSupport $support,
        private ShopLanguages $languages,
        private TextOutcomes $outcomes,
    ) {
    }

    #[Route('/generate', name: 'generate', methods: ['POST'])]
    public function generate(Request $request): JsonResponse
    {
        $this->denyUnlessMayGenerate();

        // The text is written to the end and kept even when the browser stops
        // waiting: it was asked for, and it is billed.
        ignore_user_abort(true);
        set_time_limit(0);

        [$status, $answer] = $this->answer($request);
        $this->outcomes->keep($this->adminId(), (string) $request->request->get('request', ''), $status, $answer);

        return new JsonResponse($answer, $status);
    }

    /**
     * What a call answered, read back by the id the screen gave it: 404 while
     * the text is still being written, or when nothing is kept under that id.
     */
    #[Route('/outcome', name: 'outcome', methods: ['POST'])]
    public function outcome(Request $request): JsonResponse
    {
        $this->denyUnlessMayGenerate();

        $outcome = $this->outcomes->find($this->adminId(), (string) $request->request->get('request', ''));

        if (null === $outcome) {
            return new JsonResponse(['pending' => true], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse($outcome['answer'], $outcome['status']);
    }

    /**
     * @return array{0: int, 1: array<string, string>}
     */
    private function answer(Request $request): array
    {
        $type = (string) $request->request->get('type', '');
        $field = TextField::tryFrom((string) $request->request->get('field', ''));
        $locale = (string) $request->request->get('locale', '');
        $brief = trim((string) $request->request->get('brief', ''));
        $current = (string) $request->request->get('current', '');

        if (!\in_array($type, self::TYPES, true) || !$field instanceof TextField || !$this->languages->isActive($locale)) {
            return [Response::HTTP_BAD_REQUEST, ['error' => $this->support->translate('This field cannot be written from a brief.')]];
        }

        if ('' === $brief) {
            return [Response::HTTP_UNPROCESSABLE_ENTITY, ['error' => $this->support->translate('Say in the brief what the text should say.')]];
        }

        if (mb_strlen($brief) > self::BRIEF_LENGTH || \strlen($current) > self::CURRENT_LENGTH) {
            return [Response::HTTP_UNPROCESSABLE_ENTITY, ['error' => $this->support->translate('The brief or the text of the field is too long.')]];
        }

        $target = GenerationTarget::field($type, max(0, $request->request->getInt('id')), (string) $request->request->get('title', ''), $locale);
        $generation = GenerationRequest::text($target, $locale, new Brief(
            $brief,
            self::optional($request->request->get('tone')),
            self::optional($request->request->get('audience')),
        ));

        try {
            $result = $this->generator->generate(new TextRequest($generation, $field, $current));
        } catch (GenerationUnavailableException $exception) {
            return [Response::HTTP_SERVICE_UNAVAILABLE, ['error' => $exception->getMessage()]];
        } catch (GenerationRefusedException|InvalidGenerationException $exception) {
            return [Response::HTTP_UNPROCESSABLE_ENTITY, ['error' => $exception->getMessage()]];
        } catch (GenerationException $exception) {
            return [Response::HTTP_BAD_GATEWAY, ['error' => $exception->getMessage()]];
        }

        return [Response::HTTP_OK, [
            'html' => $result->html,
            'cost' => number_format($result->costUsd, 3, '.', ''),
        ]];
    }

    private function denyUnlessMayGenerate(): void
    {
        $this->support->denyUnless(CmsResources::GENERATE, AccessManager::UPDATE, 'You are not allowed to use the generator.');
    }

    private function adminId(): int
    {
        return (int) $this->securityContext->getAdminUser()?->getId();
    }

    private static function optional(mixed $value): ?string
    {
        $value = \is_string($value) ? trim($value) : '';

        return '' === $value ? null : mb_substr($value, 0, 200);
    }
}
