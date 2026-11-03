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

namespace TheliaCMS\Tests\Unit\Front;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouteCollection;
use TheliaCMS\Front\PageDocumentController;
use TheliaCMS\Http\ShownTags;
use TheliaCMS\Page\Document\PageDocumentRepository;
use TheliaCMS\Page\Document\PageDocumentStorage;
use TheliaCMS\Page\Document\PageDocumentUrl;

/**
 * What a download address may name, before any row is read.
 */
final class PageDocumentControllerTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function namesOutOfShape(): iterable
    {
        yield 'path' => ['..%2F..%2F.env'];
        yield 'parent' => ['../.env'];
        yield 'dotfile' => ['.htaccess'];
        yield 'extension out of the list' => ['page-0123abcd.html'];
        yield 'php' => ['page-0123abcd.php'];
        yield 'no suffix' => ['page.pdf'];
        yield 'upper case digits' => ['page-0123ABCD.pdf'];
        yield 'trailing dot' => ['page-0123abcd.pdf.'];
        yield 'double extension' => ['page-0123abcd.pdf.php'];
        yield 'empty' => [''];
    }

    #[DataProvider('namesOutOfShape')]
    public function testANameOutOfShapeAnswersNotFoundWithoutReadingTheDatabase(string $file): void
    {
        // The repository would reach for Propel, which a unit test has not booted:
        // the exception below is the only way out.
        $controller = new PageDocumentController(new PageDocumentRepository(), new PageDocumentStorage(sys_get_temp_dir()), new ShownTags(new RequestStack()));

        $this->expectException(NotFoundHttpException::class);

        $controller(new Request(), 1, $file);
    }

    public function testTheAddressNamesTheRowAndTheStoredName(): void
    {
        $routes = new RouteCollection();
        $routes->add(PageDocumentUrl::ROUTE, new \Symfony\Component\Routing\Route('/cms/documents/{id}/{file}'));

        $url = (new PageDocumentUrl(new UrlGenerator($routes, new RequestContext('', 'GET', 'shop.test', 'https'))))->forDocument(12, 'notice-0123abcd.pdf');

        self::assertSame('/cms/documents/12/notice-0123abcd.pdf', $url, 'A path relative to the site, never an absolute address.');
    }
}
