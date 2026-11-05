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

namespace TheliaCMS\Settings\Admin;

use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Thelia\Core\Security\AccessManager;
use TheliaCMS\Generation\GeneratorSettings;
use TheliaCMS\Http\Admin\AdminControllerSupport;
use TheliaCMS\Http\CachePurger;
use TheliaCMS\Http\CacheTags;
use TheliaCMS\Http\ContentSecurityPolicyMode;
use TheliaCMS\Http\ContentSecurityPolicySettings;
use TheliaCMS\Menu\Admin\MenuTargetChoices;
use TheliaCMS\Page\Admin\CmsActivityLog;
use TheliaCMS\Page\Admin\EditLanguage;
use TheliaCMS\Security\CmsResources;
use TheliaCMS\Settings\CmsSettings;
use TheliaCMS\Settings\EditorProfileSeeder;
use TheliaCMS\Settings\SiteMode;
use Twig\Environment;

/**
 * The settings of the site: what it is, what it answers when an address does not
 * exist, and whether it is open.
 */
#[Route('/admin/cms/settings', name: 'admin.cms.settings.')]
final readonly class CmsSettingsController
{
    private const string TEMPLATE = '@TheliaCMSModule/backOffice/default-twig/settings/edit.html.twig';

    public function __construct(
        private Environment $twig,
        private FormFactoryInterface $forms,
        private AdminControllerSupport $support,
        private CmsSettings $settings,
        private MenuTargetChoices $choices,
        private EditorProfileSeeder $editorProfile,
        private CmsActivityLog $activityLog,
        private EditLanguage $languages,
        private CachePurger $httpCache,
        private GeneratorSettings $generator,
        private ContentSecurityPolicySettings $contentSecurityPolicy,
    ) {
    }

    #[Route('', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(Request $request): Response
    {
        $lang = $this->languages->resolve($request);

        $form = $this->forms->create(CmsSettingsType::class, [
            'siteMode' => $this->settings->siteMode()->value,
            'notFoundPageId' => $this->settings->notFoundPageId(),
            'maintenanceActive' => $this->settings->isMaintenanceActive(),
            'maintenancePageId' => $this->settings->maintenancePageId(),
            'maintenanceAllowlist' => implode("\n", $this->settings->maintenanceAllowlist()),
            'trashRetentionDays' => $this->settings->trashRetentionDays(),
            'httpCacheTtl' => $this->settings->httpCacheTtl(),
            'cspMode' => $this->contentSecurityPolicy->mode()->value,
            'cspHosts' => implode("\n", $this->contentSecurityPolicy->hosts()),
            'generatorEffort' => $this->generator->effort(),
            'generatorMaxTokens' => $this->generator->maxTokens(),
            'generatorWebFetch' => $this->generator->webFetch(),
            'generatorComponentMaking' => $this->generator->componentMaking(),
            'aiLabel' => $this->generator->showsAiLabel(),
            'textEffort' => $this->generator->textEffort(),
            'textMaxTokens' => $this->generator->textMaxTokens(),
        ], [
            'page_choices' => $this->choices->pages($lang->getLocale()),
        ]);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->denyUnlessUpdate();

            return $this->save($request, $form->getData());
        }

        return new Response($this->twig->render(self::TEMPLATE, [
            'form' => $form->createView(),
            'edit_locale' => $lang->getLocale(),
            'edit_language_id' => $lang->getId(),
            'is_showcase' => $this->settings->isShowcase(),
            'editor_profile_exists' => $this->editorProfile->exists(),
            'client_ip' => $request->getClientIp(),
            'retry_after' => CmsSettings::RETRY_AFTER,
        ]));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function save(Request $request, array $data): RedirectResponse
    {
        $mode = SiteMode::fromStorage($data['siteMode']);

        $this->settings->save(
            mode: $mode,
            notFoundPageId: null === $data['notFoundPageId'] ? null : (int) $data['notFoundPageId'],
            maintenanceActive: (bool) $data['maintenanceActive'],
            maintenanceAllowlist: (string) $data['maintenanceAllowlist'],
            maintenancePageId: null === $data['maintenancePageId'] ? null : (int) $data['maintenancePageId'],
            // Left empty means "the default"; a typed 0 means "keep them".
            trashRetentionDays: null === $data['trashRetentionDays'] ? null : (int) $data['trashRetentionDays'],
            httpCacheTtl: null === $data['httpCacheTtl'] ? null : (int) $data['httpCacheTtl'],
        );

        $this->contentSecurityPolicy->save(ContentSecurityPolicyMode::fromStorage($data['cspMode']), (string) $data['cspHosts']);

        $this->generator->save(
            effort: (string) $data['generatorEffort'],
            maxTokens: (int) $data['generatorMaxTokens'],
            webFetch: (bool) $data['generatorWebFetch'],
            componentMaking: (bool) $data['generatorComponentMaking'],
        );
        $this->generator->saveAiLabel((bool) $data['aiLabel']);
        $this->generator->saveText(
            effort: (string) $data['textEffort'],
            maxTokens: (int) $data['textMaxTokens'],
        );

        // A showcase site is handed to someone who is not an administrator of
        // the shop, so the profile they work under comes with the mode.
        if ($mode->isShowcase() && $this->editorProfile->seed()) {
            $this->flash($request, 'info', 'The "Editor" profile has been created. Assign it to the people who write the site under Configuration > Administrators.');
        }

        // These settings show on every page, so every cached page is stale.
        $this->httpCache->purge([CacheTags::SITE]);

        $this->activityLog->record('UPDATE', 0, \sprintf(
            'CMS settings saved (mode %s, maintenance %s)',
            $mode->value,
            $data['maintenanceActive'] ? 'on' : 'off',
        ), CmsResources::SETTINGS);
        $this->flash($request, 'success', 'Settings saved.');

        return $this->support->redirect('admin.cms.settings.edit');
    }

    private function flash(Request $request, string $type, string $message): void
    {
        $this->support->flash($request, $type, $this->support->translate($message));
    }

    private function denyUnlessUpdate(): void
    {
        $this->support->denyUnless(CmsResources::SETTINGS, AccessManager::UPDATE, 'You are not allowed to change these settings.');
    }
}
