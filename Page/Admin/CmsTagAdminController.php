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

namespace TheliaCMS\Page\Admin;

use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\SecurityContext;
use TheliaCMS\Model\CmsTag;
use TheliaCMS\Page\PageTagColor;
use TheliaCMS\Page\PageTagExistsException;
use TheliaCMS\Page\PageTagName;
use TheliaCMS\Page\PageTagRepository;
use TheliaCMS\Page\PageTagWriter;
use TheliaCMS\Security\CmsResources;
use TheliaCMS\TheliaCMS;
use Twig\Environment;

/**
 * The tags of the site: the labels a page may carry, one each.
 *
 * Under /admin/cms/tags, which CmsAdminGuard holds to the page permission:
 * whoever may edit a page may manage its labels.
 */
#[Route('/admin/cms/tags', name: 'admin.cms.tags.')]
final readonly class CmsTagAdminController
{
    private const string LIST_TEMPLATE = '@TheliaCMSModule/backOffice/default-twig/tags/list.html.twig';
    private const string EDIT_TEMPLATE = '@TheliaCMSModule/backOffice/default-twig/tags/edit.html.twig';

    public function __construct(
        private Environment $twig,
        private FormFactoryInterface $forms,
        private UrlGeneratorInterface $urls,
        private SecurityContext $securityContext,
        private TranslatorInterface $translator,
        private PageTagRepository $tags,
        private PageTagWriter $writer,
    ) {
    }

    #[Route('', name: 'list', methods: ['GET', 'POST'])]
    public function list(Request $request): Response
    {
        $form = $this->forms->create(PageTagType::class, null, [
            'action' => $this->urls->generate('admin.cms.tags.list'),
        ]);

        $form->handleRequest($request);

        // The right is checked on any submission, before the form is read: a
        // profile that may only look gets a refusal, not a validation error.
        if ($form->isSubmitted()) {
            $this->denyUnless(AccessManager::CREATE);
        }

        if ($form->isSubmitted() && $form->isValid()) {
            $added = $this->add($request, $form);

            if (null !== $added) {
                return $added;
            }
        }

        $rows = [];
        $pageCounts = $this->tags->pageCountsByTag();

        foreach ($this->tags->all() as $tag) {
            $color = PageTagColor::fromStored($tag->getColor(), $tag->getTextColor());
            $rows[] = [
                'id' => (int) $tag->getId(),
                'name' => (string) $tag->getName(),
                'badge' => PageTagColor::badgeClassOf($color),
                'badge_style' => PageTagColor::badgeStyleOf($color),
                'pages' => $pageCounts[(int) $tag->getId()] ?? 0,
            ];
        }

        return new Response($this->twig->render(self::LIST_TEMPLATE, [
            'tags' => $rows,
            'form' => $form->createView(),
            'may_create' => $this->isGranted(AccessManager::CREATE),
            'may_update' => $this->isGranted(AccessManager::UPDATE),
            'may_delete' => $this->isGranted(AccessManager::DELETE),
        ]));
    }

    #[Route('/{id}', name: 'edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, int $id): Response
    {
        $tag = $this->tagOrFail($id);

        $form = $this->forms->create(PageTagType::class, ['name' => $tag->getName(), 'color' => PageTagColor::fromStored($tag->getColor(), $tag->getTextColor())], [
            'action' => $this->urls->generate('admin.cms.tags.edit', ['id' => $id]),
        ]);

        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            $this->denyUnless(AccessManager::UPDATE);
        }

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->writer->rename($tag, (string) $form->get('name')->getData());
                $this->writer->recolor($tag, $form->get('color')->getData());
                $this->flash($request, 'success', $this->translate('The tag has been saved.'));

                return $this->backToList();
            } catch (PageTagExistsException) {
                $form->get('name')->addError(new FormError($this->translate('A tag with this name exists already.')));
            }
        }

        return new Response($this->twig->render(self::EDIT_TEMPLATE, [
            'tag' => ['id' => $id, 'name' => (string) $tag->getName(), 'pages' => $this->tags->countPagesOf($tag)],
            'form' => $form->createView(),
        ]));
    }

    #[Route('/{id}/delete', name: 'delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, int $id): Response
    {
        $this->denyUnless(AccessManager::DELETE);

        $detached = $this->writer->delete($this->tagOrFail($id));

        $this->flash($request, 'success', 0 === $detached
            ? $this->translate('The tag has been deleted.')
            : $this->translator->trans('The tag has been deleted; %count% page(s) no longer carry one.', ['%count%' => $detached], TheliaCMS::DOMAIN_NAME));

        return $this->backToList();
    }

    private function add(Request $request, FormInterface $form): ?RedirectResponse
    {
        $name = PageTagName::normalize((string) $form->get('name')->getData());

        if (null !== $this->tags->findByName($name)) {
            $form->get('name')->addError(new FormError($this->translate('A tag with this name exists already.')));

            return null;
        }

        $this->writer->add($name, $form->get('color')->getData());
        $this->flash($request, 'success', $this->translate('The tag has been added.'));

        return $this->backToList();
    }

    private function tagOrFail(int $id): CmsTag
    {
        $tag = $this->tags->find($id);

        if (!$tag instanceof CmsTag) {
            throw new NotFoundHttpException();
        }

        return $tag;
    }

    private function backToList(): RedirectResponse
    {
        return new RedirectResponse($this->urls->generate('admin.cms.tags.list'));
    }

    private function flash(Request $request, string $type, string $message): void
    {
        if (!$request->hasSession()) {
            return;
        }

        $session = $request->getSession();

        if (method_exists($session, 'getFlashBag')) {
            $session->getFlashBag()->add($type, $message);
        }
    }

    private function translate(string $message): string
    {
        return $this->translator->trans($message, [], TheliaCMS::DOMAIN_NAME);
    }

    private function isGranted(string $access): bool
    {
        return $this->securityContext->isGranted(['ADMIN'], [CmsResources::PAGE], [], [$access]);
    }

    private function denyUnless(string $access): void
    {
        if (!$this->isGranted($access)) {
            throw new AccessDeniedHttpException($this->translate('You are not allowed to change CMS pages.'));
        }
    }
}
