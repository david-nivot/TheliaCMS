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

namespace TheliaCMS\Skill\Admin;

use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Thelia\Core\Security\AccessManager;
use TheliaCMS\Http\Admin\AdminControllerSupport;
use TheliaCMS\Security\CmsResources;
use TheliaCMS\Skill\Skill;
use TheliaCMS\Skill\SkillRepository;
use Twig\Environment;

/**
 * The skills of the generator: what it reads before writing a page, edited
 * by the site.
 *
 * Under /admin/cms/skills, which CmsAdminGuard holds to the settings
 * permission: the skills steer every page the generator writes, the way the
 * settings do.
 */
#[Route('/admin/cms/skills', name: 'admin.cms.skills.')]
final readonly class CmsSkillAdminController
{
    private const string LIST_TEMPLATE = '@TheliaCMSModule/backOffice/default-twig/skills/list.html.twig';
    private const string EDIT_TEMPLATE = '@TheliaCMSModule/backOffice/default-twig/skills/edit.html.twig';

    public function __construct(
        private Environment $twig,
        private FormFactoryInterface $forms,
        private UrlGeneratorInterface $urls,
        private AdminControllerSupport $support,
        private SkillRepository $skills,
    ) {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(): Response
    {
        return new Response($this->twig->render(self::LIST_TEMPLATE, [
            'skills' => $this->skills->all(),
            'may_update' => $this->support->isGranted(CmsResources::SETTINGS, AccessManager::UPDATE),
        ]));
    }

    #[Route('/{code}', name: 'edit', requirements: ['code' => '[a-z0-9-]+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, string $code): Response
    {
        $skill = $this->skillOrFail($code);

        $form = $this->forms->create(CmsSkillType::class, [
            'body' => $skill->body,
            'enabled' => $skill->enabled,
            'position' => $skill->position,
        ], [
            'action' => $this->urls->generate('admin.cms.skills.edit', ['code' => $code]),
        ]);

        $form->handleRequest($request);

        // The right is checked on any submission, before the form is read: a
        // profile that may only look gets a refusal, not a validation error.
        if ($form->isSubmitted()) {
            $this->denyUnlessUpdate();
        }

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();

            $this->skills->save(
                $code,
                (string) $data['body'],
                (bool) $data['enabled'],
                null === $data['position'] ? null : (int) $data['position'],
            );
            $this->support->flash($request, 'success', $this->support->translate('The skill has been saved.'));

            return $this->backToList();
        }

        return new Response($this->twig->render(self::EDIT_TEMPLATE, [
            'skill' => $skill,
            'form' => $form->createView(),
            'may_update' => $this->support->isGranted(CmsResources::SETTINGS, AccessManager::UPDATE),
        ]));
    }

    #[Route('/{code}/restore', name: 'restore', requirements: ['code' => '[a-z0-9-]+'], methods: ['POST'])]
    public function restore(Request $request, string $code): Response
    {
        $this->denyUnlessUpdate();

        if (null === $this->skillOrFail($code)->shippedBody) {
            throw new NotFoundHttpException();
        }

        $this->skills->restore($code);
        $this->support->flash($request, 'success', $this->support->translate('The shipped text of the skill has been put back.'));

        return $this->backToList();
    }

    /**
     * One slot up or down in the order the generator reads the skills in,
     * from the arrows of the list.
     */
    #[Route('/{code}/move/{direction}', name: 'move', requirements: ['code' => '[a-z0-9-]+', 'direction' => 'up|down'], methods: ['POST'])]
    public function move(Request $request, string $code, string $direction): Response
    {
        $this->denyUnlessUpdate();
        $this->skillOrFail($code);

        $this->skills->move($code, 'up' === $direction ? -1 : 1);
        $this->support->flash($request, 'success', $this->support->translate('The order of the skills has been saved.'));

        return $this->backToList();
    }

    private function skillOrFail(string $code): Skill
    {
        $skill = $this->skills->find($code);

        if (!$skill instanceof Skill) {
            throw new NotFoundHttpException();
        }

        return $skill;
    }

    private function backToList(): RedirectResponse
    {
        return $this->support->redirect('admin.cms.skills.list');
    }

    private function denyUnlessUpdate(): void
    {
        $this->support->denyUnless(CmsResources::SETTINGS, AccessManager::UPDATE, 'You are not allowed to change the skills.');
    }
}
