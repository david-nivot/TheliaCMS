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

namespace TheliaCMS\Tests\Unit\Http\Admin;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\Forms;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\SecurityContext;
use Thelia\Core\Security\User\UserInterface;
use TheliaCMS\Http\Admin\AdminControllerSupport;
use TheliaCMS\Security\CmsResources;
use TheliaCMS\TheliaCMS;

final class AdminControllerSupportTest extends TestCase
{
    private const int VIEW_ONLY = 8;

    public function testTranslatesInTheModuleDomain(): void
    {
        self::assertSame(
            TheliaCMS::DOMAIN_NAME.': Page 12 saved.',
            $this->support()->translate('Page %id% saved.', ['%id%' => 12]),
        );
    }

    public function testLeavesAFlashMessageOfTheGivenType(): void
    {
        $request = $this->requestWithSession();

        $this->support()->flash($request, 'warning', 'Mind the gap.');

        self::assertSame(['warning' => ['Mind the gap.']], $this->flashes($request));
    }

    public function testDropsTheFlashMessageOfARequestWithoutSession(): void
    {
        $request = new Request();

        $this->support()->flash($request, 'success', 'Saved.');

        self::assertFalse($request->hasSession());
    }

    public function testFlashesEveryErrorOfASubmittedForm(): void
    {
        $form = Forms::createFormFactory()->createBuilder(FormType::class)->add('title', TextType::class)->getForm();
        $form->submit(['title' => 'x']);
        $form->addError(new FormError('The page is locked.'));
        $form->get('title')->addError(new FormError('The title is too short.'));
        $request = $this->requestWithSession();

        $this->support()->flashFormErrors($request, $form);

        self::assertSame(['danger' => ['The page is locked.', 'The title is too short.']], $this->flashes($request));
    }

    public function testSaysNothingWasSentForAFormThatWasNotSubmitted(): void
    {
        $form = Forms::createFormFactory()->createBuilder(FormType::class)->getForm();
        $request = $this->requestWithSession();

        $this->support()->flashFormErrors($request, $form);

        self::assertSame(['danger' => [TheliaCMS::DOMAIN_NAME.': Nothing was sent.']], $this->flashes($request));
    }

    public function testGrantsTheAccessTheAdministratorHoldsOnTheResource(): void
    {
        $support = $this->support($this->administrator([CmsResources::PAGE => new AccessManager(self::VIEW_ONLY)]));

        self::assertTrue($support->isGranted(CmsResources::PAGE, AccessManager::VIEW));
        self::assertFalse($support->isGranted(CmsResources::PAGE, AccessManager::UPDATE));
        self::assertFalse($support->isGranted(CmsResources::MENU, AccessManager::VIEW));
    }

    public function testGrantsNothingWithoutAnAdministrator(): void
    {
        self::assertFalse($this->support()->isGranted(CmsResources::PAGE, AccessManager::VIEW));
    }

    public function testRefusesWithTheTranslatedReason(): void
    {
        $support = $this->support($this->administrator([CmsResources::PAGE => new AccessManager(self::VIEW_ONLY)]));

        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage(TheliaCMS::DOMAIN_NAME.': You are not allowed to change CMS pages.');

        $support->denyUnless(CmsResources::PAGE, AccessManager::UPDATE, 'You are not allowed to change CMS pages.');
    }

    public function testLetsAGrantedAccessThrough(): void
    {
        $support = $this->support($this->administrator([CmsResources::PAGE => new AccessManager(self::VIEW_ONLY)]));

        $support->denyUnless(CmsResources::PAGE, AccessManager::VIEW, 'You are not allowed to change CMS pages.');

        $this->addToAssertionCount(1);
    }

    public function testRedirectsToARoute(): void
    {
        self::assertSame('/admin/cms/tags', $this->support()->redirect('admin.cms.tags.list')->getTargetUrl());
    }

    public function testRedirectsToATabOfAScreen(): void
    {
        self::assertSame(
            '/admin/cms/pages/7#tab-documents',
            $this->support()->redirect('admin.cms.pages.edit', ['id' => 7], 'tab-documents')->getTargetUrl(),
        );
    }

    public function testRedirectsInTheLanguageTheEditorWorksIn(): void
    {
        self::assertSame(
            '/admin/cms/pages/7?q=news&edit_language_id=3#tab-documents',
            $this->support()->redirectInLanguage('admin.cms.pages.edit', 3, ['id' => 7, 'q' => 'news', 'edit_language_id' => 1], 'tab-documents')->getTargetUrl(),
        );
    }

    private function support(?UserInterface $administrator = null): AdminControllerSupport
    {
        $requests = new RequestStack();
        $request = $this->requestWithSession();

        if (null !== $administrator) {
            $request->getSession()->set('thelia.admin_user', $administrator);
        }

        $requests->push($request);

        $routes = new RouteCollection();
        $routes->add('admin.cms.tags.list', new Route('/admin/cms/tags'));
        $routes->add('admin.cms.pages.edit', new Route('/admin/cms/pages/{id}'));

        return new AdminControllerSupport(
            new class implements TranslatorInterface {
                /**
                 * @param array<string, mixed> $parameters
                 */
                public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
                {
                    return $domain.': '.strtr($id, array_map(static fn (mixed $value): string => \is_scalar($value) ? (string) $value : '', $parameters));
                }

                public function getLocale(): string
                {
                    return 'en_US';
                }
            },
            new SecurityContext($requests),
            new UrlGenerator($routes, new RequestContext()),
        );
    }

    private function requestWithSession(): Request
    {
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));

        return $request;
    }

    /**
     * @return array<string, list<string>>
     */
    private function flashes(Request $request): array
    {
        $session = $request->getSession();
        self::assertInstanceOf(Session::class, $session);

        /** @var array<string, list<string>> $flashes */
        $flashes = $session->getFlashBag()->peekAll();

        return $flashes;
    }

    /**
     * An administrator as the session keeps it, without the database row
     * the security context would reload for a real Admin.
     *
     * @param array<string, AccessManager> $permissions
     */
    private function administrator(array $permissions): UserInterface
    {
        return new class($permissions) implements UserInterface {
            /**
             * @param array<string, AccessManager> $permissions
             */
            public function __construct(private readonly array $permissions)
            {
            }

            /**
             * @return array<string, AccessManager>
             */
            public function getPermissions(): array
            {
                return $this->permissions;
            }

            public function getId(): int
            {
                return 1;
            }

            public function getUsername(): string
            {
                return 'editor';
            }

            public function getPassword(): string
            {
                return '';
            }

            public function checkPassword(string $password): bool
            {
                return false;
            }

            /**
             * @return list<string>
             */
            public function getRoles(): array
            {
                return ['ADMIN'];
            }

            public function eraseCredentials(): void
            {
            }

            public function getToken(): string
            {
                return '';
            }

            public function setToken(string $token): void
            {
            }

            public function getSerial(): string
            {
                return '';
            }

            public function setSerial(string $serial): void
            {
            }

            public function getLocale(): string
            {
                return 'en_US';
            }
        };
    }
}
