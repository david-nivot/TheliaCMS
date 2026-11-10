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

namespace TheliaCMS\Tests\Integration\Media;

use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Model\LangQuery;
use Thelia\Test\FixtureFactory;
use TheliaCMS\Media\Admin\CmsMediaWriter;
use TheliaCMS\Media\CmsMediaLibrary;
use TheliaCMS\Media\Icon\IconNames;
use TheliaCMS\Media\Svg\Color\SvgPaints;
use TheliaCMS\Media\Svg\PreparedSvgStore;
use TheliaCMS\Security\CmsResources;
use TheliaCMS\Tests\Integration\Security\AdminScreenTestCase;
use TheliaCMS\TheliaCMS;
use TheliaLibrary\Model\LibraryImage;
use TheliaLibrary\Model\LibraryImageQuery;
use TheliaLibrary\TheliaLibrary;

/**
 * An SVG never enters the library by its sending: it goes through the screen
 * where the editor sees what the cleaning removed, one file at a time, and the
 * images of the same sending follow it to the end of the way.
 */
final class SvgPreparationScreenTest extends AdminScreenTestCase
{
    private const string LIST = '/admin/cms/media';

    private const string CLEAN = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M0 0h24v24H0z"/></svg>';

    /** Four dark shapes, a green circle and a white check. */
    private const string COLORED = '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24">'
        .'<path d="M2 2h10v10H2z" fill="#1C1C1C"/><path d="M4 14h6v6H4z" fill="#1c1c1c"/><path d="M14 4h6v4h-6z" fill="#1C1C1C"/>'
        .'<circle cx="17" cy="17" r="5" fill="#22A06B"/><path d="M15 17l1.5 1.5L19 16" fill="none" stroke="#fff" stroke-width="1.5"/></svg>';

    /** A gradient: the result is fit for an image, not for an icon. */
    private const string GRADIENT = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><defs><linearGradient id="g"><stop offset="0" stop-color="#000"/><stop offset="1" stop-color="#fff"/></linearGradient></defs><path d="M0 0h24v24H0z" fill="url(#g)"/></svg>';

    private const string TRAPPED = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" onload="alert(1)"><script>alert(2)</script><path d="M0 0h24v24H0z"/></svg>';

    /** @var list<string> */
    private array $files = [];

    /** @var list<string> */
    private array $tokens = [];

    protected function tearDown(): void
    {
        foreach ($this->getService(CmsMediaLibrary::class)->images() as $image) {
            $this->files[] = TheliaLibrary::getImageDirectory().$image->getFileName();
        }

        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        foreach ($this->tokens as $token) {
            $this->getService(PreparedSvgStore::class)->forget($token);
        }

        parent::tearDown();
    }

    public function testAnSvgSentAloneIsNotStoredAndOpensItsScreen(): void
    {
        $this->upload([$this->svg('logo.svg', self::CLEAN)]);

        $response = $this->client->getResponse();
        self::assertSame(302, $response->getStatusCode());
        self::assertSame([], $this->getService(CmsMediaLibrary::class)->images(), 'Nothing enters the library by its sending.');

        $location = (string) $response->headers->get('Location');
        self::assertMatchesRegularExpression('#/admin/cms/media/svg/[a-f0-9]{32}\?#', $location);
        $this->remember($location);

        $screen = $this->screen($location);
        self::assertSame('logo.svg', trim($screen->filter('[data-testid="cms-svg-name"]')->text()));
        self::assertSame(0, $screen->filter('[data-testid="cms-svg-position"]')->count(), 'One file: no "File 1 of 1".');
        self::assertSame(1, $screen->filter('[data-testid="cms-svg-nothing-removed"]')->count(), 'A clean file says there was nothing to remove.');
        self::assertSame(0, $screen->filter('[data-testid="cms-svg-levels"]')->count(), 'No level of optimization exists: none is offered.');
        self::assertSame(0, $screen->filter('[data-testid="cms-svg-apply-following"]')->count());
        self::assertStringContainsString('/svg/', (string) $screen->filter('[data-testid="cms-svg-original"]')->attr('src'));
        self::assertStringContainsString('/result', (string) $screen->filter('[data-testid="cms-svg-result"]')->attr('src'));
        self::assertNotNull($this->getService(PreparedSvgStore::class)->result($this->tokenOf($location), 'base'), 'The preview and the answer read the result prepared for the screen.');
        self::assertStringContainsString($this->said('nothing to remove'), $screen->filter('[data-testid="cms-svg-cleaning"] summary')->first()->text());
        self::assertSame('fit', $screen->filter('[data-testid="cms-svg-zoom"] input:checked')->attr('value'), 'A small drawing opens fitted to its frame too.');
        self::assertSame(1, $screen->filter('[data-testid="cms-svg-only-method"]')->count(), 'No level exists: the base is said to be the only method.');
    }

    public function testADrawingLargerThanItsFrameOpensFittedAndCanStillBeZoomed(): void
    {
        $this->upload([$this->svg('plan.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1600 900"><path d="M0 0h1600v900H0z"/></svg>')]);
        $screen = $this->screen($this->remember((string) $this->client->getResponse()->headers->get('Location')));

        self::assertSame('fit', $screen->filter('[data-testid="cms-svg-zoom"] input:checked')->attr('value'));
        self::assertSame(['fit', '1', '4', '10'], $screen->filter('[data-testid="cms-svg-zoom"] input')->each(static fn (Crawler $input): string => (string) $input->attr('value')));
        self::assertSame(2, $screen->filter('[data-cms-svg-frame]')->count(), 'Both previews sit in a frame that scrolls.');
        self::assertSame(1, $screen->filter('[data-cms-svg-frame] .cms-svg-canvas [data-testid="cms-svg-original"]')->count());
        self::assertSame(3, $screen->filter('[data-cms-svg-frame] .cms-svg-canvas .cms-svg-result')->count(), 'The result once per ground, the visible one chosen by the ground.');
    }

    public function testAnSvgSentFromThePickerIsPreparedInItsFrameAndHandedBackToIt(): void
    {
        $this->upload([$this->svg('picto.svg', self::TRAPPED)], json: true, query: '&picker=1');
        $answer = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        self::assertSame([], $answer['stored'], 'Nothing enters the library by its sending.');
        self::assertStringContainsString('picker=1', (string) $answer['prepare']);
        $screen = $this->screen($this->remember((string) $answer['prepare']));

        self::assertSame(0, $screen->filter('[data-testid="bo-main-nav"]')->count(), 'In the frame, the menus of the back office stay outside.');
        self::assertSame($this->said('Add and choose'), trim($screen->filter('[data-testid="cms-svg-add"]')->text()));

        $framed = $this->client->request('GET', (string) $answer['prepare'], [], [], ['HTTP_SEC_FETCH_DEST' => 'iframe']);
        self::assertSame('_blank', $framed->filter('[data-testid="cms-documentation-link"]')->attr('target'), 'In the frame, the help opens outside it.');
        self::assertSame('_blank', $screen->filter('[data-testid="cms-documentation-link"]')->attr('target'), 'A browser that does not say it frames the screen: the address of the picker does.');

        $handed = $this->handedBack('add', $screen);
        $stored = $this->getService(CmsMediaLibrary::class)->images();

        self::assertCount(1, $stored);
        self::assertSame(['type' => 'cms-svg-picked', 'image' => (int) $stored[0]->getId(), 'message' => null], $handed);
        self::assertStringNotContainsString('onload', (string) file_get_contents(TheliaLibrary::getImageDirectory().$stored[0]->getFileName()), 'Stored cleaned, as from the list.');
    }

    public function testAnSvgLeftOutInThePickerHandsBackNoImage(): void
    {
        $this->upload([$this->svg('picto.svg', self::CLEAN)], json: true, query: '&picker=1');
        $answer = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        $screen = $this->screen($this->remember((string) $answer['prepare']));

        self::assertNull($this->handedBack('skip', $screen)['image']);
        self::assertSame([], $this->getService(CmsMediaLibrary::class)->images());
    }

    public function testAnSvgSentFromTheListIsNotInThePickerWay(): void
    {
        $this->upload([$this->svg('logo.svg', self::CLEAN)], json: true);
        $answer = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        $this->remember((string) $answer['prepare']);

        self::assertStringNotContainsString('picker=', (string) $answer['prepare']);
        $screen = $this->screen((string) $answer['prepare']);
        self::assertSame(1, $screen->filter('[data-testid="bo-main-nav"]')->count());
        self::assertNull($screen->filter('[data-testid="cms-documentation-link"]')->attr('target'), 'A screen of its own keeps its help in place.');
    }

    public function testAnEditorWhoMayOnlyReadTheMediaCanNeitherAddNorLeaveOut(): void
    {
        $this->upload([$this->svg('lecture.svg', self::CLEAN)]);
        $location = $this->remember((string) $this->client->getResponse()->headers->get('Location'));
        $this->logInAs((new FixtureFactory($this->getPropelConnection()))->restrictedAdmin([
            CmsResources::MEDIA => [AccessManager::VIEW],
        ]));
        $form = $this->screen($location)->filter('[data-testid="cms-svg-form"]');

        foreach (['skip', 'add'] as $action) {
            $this->client->request('POST', (string) $form->attr('action'), [
                '_token' => (string) $form->filter('input[name="_token"]')->attr('value'),
                'action' => $action,
            ], [], self::SAME_SITE);

            self::assertSame(403, $this->client->getResponse()->getStatusCode(), $action);
        }

        self::assertNotNull($this->getService(PreparedSvgStore::class)->find($this->tokenOf($location)), 'The file still waits for someone allowed.');
    }

    public function testAddingTheFileStoresItAndOpensItsSheet(): void
    {
        $this->upload([$this->svg('logo.svg', self::CLEAN)]);
        $screen = $this->screen($this->remember((string) $this->client->getResponse()->headers->get('Location')));

        $this->answer($screen, 'add');

        $stored = $this->getService(CmsMediaLibrary::class)->images();
        self::assertCount(1, $stored);
        self::assertSame('logo.svg', (string) $stored[0]->setLocale($this->locale())->getTitle(), 'Named after the file it came in.');
        self::assertStringEndsWith('.svg', $stored[0]->getFileName());
        self::assertStringEndsWith(\sprintf('/admin/cms/media/%d?edit_language_id=%d', $stored[0]->getId(), $this->languageId()), (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testAMixedSendingGoesThroughEachSvgThenTheQueueOfAllThreeImages(): void
    {
        $this->upload([$this->png('photo.png'), $this->svg('un.svg', self::CLEAN), $this->svg('deux.svg', self::CLEAN)]);

        $png = $this->getService(CmsMediaLibrary::class)->images();
        self::assertCount(1, $png, 'The matrix image is stored at once, as before.');

        $first = $this->remember((string) $this->client->getResponse()->headers->get('Location'));
        self::assertStringContainsString('queue=', $first);
        self::assertStringContainsString('images='.$png[0]->getId(), $first);

        $screen = $this->screen($first);
        self::assertSame($this->said('File %position% of %total%', ['%position%' => 1, '%total%' => 2]), $this->normalize($screen->filter('[data-testid="cms-svg-position"]')->text()));
        self::assertSame('un.svg', trim($screen->filter('[data-testid="cms-svg-name"]')->text()));
        self::assertSame(1, $screen->filter('[data-testid="cms-svg-band"] [data-testid="cms-svg-position"]')->count(), 'Where the editor stands is said in the band of the file.');
        self::assertSame(0, $screen->filter('[data-testid="bo-language-switcher"]')->count(), 'Nothing is written in a language on this screen.');

        $this->answer($screen, 'add');
        $second = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringContainsString('/admin/cms/media/svg/', $second);

        $screen = $this->screen($second);
        self::assertSame($this->said('File %position% of %total%', ['%position%' => 2, '%total%' => 2]), $this->normalize($screen->filter('[data-testid="cms-svg-position"]')->text()));
        self::assertSame('deux.svg', trim($screen->filter('[data-testid="cms-svg-name"]')->text()));

        $this->answer($screen, 'add');
        $queue = (string) $this->client->getResponse()->headers->get('Location');

        self::assertStringContainsString('/admin/cms/media/describe', $queue);
        parse_str((string) parse_url($queue, \PHP_URL_QUERY), $query);
        $ids = array_map('intval', explode(',', (string) $query['images']));
        self::assertCount(3, $ids);
        $inLibrary = array_map(static fn (LibraryImage $image): int => (int) $image->getId(), $this->getService(CmsMediaLibrary::class)->images());
        sort($inLibrary);
        sort($ids);
        self::assertSame($inLibrary, $ids, 'The three ids are the three images of the library.');
        self::assertContains((int) $png[0]->getId(), $ids);
    }

    public function testAnSvgWithAHandlerAndAScriptSaysWhatLeavesAndIsStoredWithoutIt(): void
    {
        $this->upload([$this->svg('piege.svg', self::TRAPPED)]);
        $screen = $this->screen($this->remember((string) $this->client->getResponse()->headers->get('Location')));

        $changes = $screen->filter('[data-testid="cms-svg-changes"] li')->each(static fn (Crawler $item): string => trim($item->text()));
        self::assertCount(2, $changes);
        self::assertContains($this->said('Attribute %name% removed: event handler.', ['%name%' => 'onload']), $changes);
        self::assertContains($this->said('Element %name% removed: script.', ['%name%' => '<script>']), $changes);
        self::assertSame(0, $screen->filter('[data-testid="cms-svg-nothing-removed"]')->count());
        $summary = $this->normalize($screen->filter('[data-testid="cms-svg-cleaning"] summary')->first()->text());
        self::assertStringContainsString($this->said('%count% change · required|%count% changes · required', ['%count%' => 2]), $summary);
        self::assertStringNotContainsString('|', $summary, 'The plural is chosen, not shown both ways.');
        self::assertSame(2, $screen->filter('[data-testid="cms-svg-changes"] li.text-danger-emphasis')->count(), 'What left the file is drawn as removed.');

        $this->answer($screen, 'add');

        $image = $this->getService(CmsMediaLibrary::class)->images()[0];
        $stored = (string) file_get_contents(TheliaLibrary::getImageDirectory().$image->getFileName());
        self::assertStringNotContainsString('onload', $stored);
        self::assertStringNotContainsString('<script', $stored);
        self::assertStringContainsString('<path', $stored, 'The drawing stays.');
    }

    public function testADoctypeWithAnInternalSubsetIsRefusedAtOnceWithItsReason(): void
    {
        $doctype = '<?xml version="1.0"?><!DOCTYPE svg [<!ELEMENT svg ANY>]><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"/>';

        $this->upload([$this->svg('doctype.svg', $doctype), $this->svg('ok.svg', self::CLEAN)], json: true);
        $answer = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertSame('doctype.svg', $answer['refused'][0]['name']);
        self::assertSame($this->said('The SVG declares entities or an internal subset, which is refused. Export it again without them.'), $answer['refused'][0]['reason']);
        self::assertCount(1, $answer['prepared'], 'The other file goes on.');
        self::assertSame('ok.svg', $answer['prepared'][0]['name']);
        $this->tokens[] = $answer['prepared'][0]['token'];
        self::assertSame([], $answer['stored']);
    }

    public function testASimpleDoctypeAndAMissingNamespaceAreSaidAndTheFileGoesOn(): void
    {
        $illustrator = '<?xml version="1.0"?><!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1//EN" "http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd"><svg viewBox="0 0 16 16" onclick="x()"><path d="M0 0h16v16z"/></svg>';

        $this->upload([$this->svg('illustrator.svg', $illustrator)]);
        $screen = $this->screen($this->remember((string) $this->client->getResponse()->headers->get('Location')));

        $changes = $screen->filter('[data-testid="cms-svg-changes"] li')->each(static fn (Crawler $item): string => trim($item->text()));
        self::assertContains($this->said('DOCTYPE declaration removed.'), $changes);
        self::assertContains($this->said('SVG namespace added: without it the file would not be displayed.'), $changes);
        self::assertContains($this->said('Attribute %name% removed: event handler.', ['%name%' => 'onclick']), $changes);
    }

    public function testARefusedSvgSentFromTheFormIsSaidOnTheList(): void
    {
        $this->upload([$this->svg('casse.svg', '<svg xmlns="http://www.w3.org/2000/svg"><g></svg>')]);

        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        $list = $this->screen((string) $this->client->getResponse()->headers->get('Location'))->html();
        self::assertStringContainsString('casse.svg', $list);
        self::assertSame([], $this->getService(CmsMediaLibrary::class)->images());
    }

    public function testTheScriptIsGivenTheTokensOfTheFilesToPrepare(): void
    {
        $this->upload([$this->svg('json.svg', self::CLEAN)], json: true);
        $answer = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->tokens[] = $answer['prepared'][0]['token'];

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame('json.svg', $answer['prepared'][0]['name']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $answer['prepared'][0]['token']);
        self::assertSame([], $answer['stored']);
        self::assertSame([], $answer['refused']);
    }

    public function testTheListOffersTheScriptTheAddressOfTheScreen(): void
    {
        $form = $this->screen(self::LIST)->filter('form[data-cms-media-upload]');

        self::assertSame('image/jpeg,image/png,image/webp,image/svg+xml,.svg,video/mp4', $form->attr('data-accepted'));
        self::assertSame('00000000000000000000000000000000', $form->attr('data-token-placeholder'));
        self::assertStringContainsString('/admin/cms/media/svg/00000000000000000000000000000000', (string) $form->attr('data-prepare-url'));
        self::assertSame('image/jpeg,image/png,image/webp,image/svg+xml,.svg,video/mp4', $this->screen(self::LIST)->filter('input[type="file"]')->attr('accept'));
    }

    public function testAFileThatExpiredIsSaidAndTheWayGoesOnWithTheNextOne(): void
    {
        $this->upload([$this->svg('perime.svg', self::CLEAN), $this->svg('vivant.svg', self::CLEAN)]);
        $location = $this->remember((string) $this->client->getResponse()->headers->get('Location'));
        parse_str((string) parse_url($location, \PHP_URL_QUERY), $query);
        $this->tokens[] = $second = (string) $query['queue'];
        $first = $this->tokenOf($location);

        $this->getService(PreparedSvgStore::class)->forget($first);

        $this->client->request('GET', $location);
        $response = $this->client->getResponse();
        self::assertSame(302, $response->getStatusCode());
        self::assertStringContainsString('/admin/cms/media/svg/'.$second, (string) $response->headers->get('Location'), 'The next file comes.');

        $screen = $this->screen((string) $response->headers->get('Location'));
        self::assertStringContainsString($this->said('This file has expired, send it again.'), $screen->html());
    }

    public function testAFileThatExpiredWithNothingAfterItSendsBackToTheList(): void
    {
        $this->client->request('GET', '/admin/cms/media/svg/'.str_repeat('a', 32));

        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        $list = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringContainsString('/admin/cms/media?', $list);
        self::assertStringContainsString($this->said('This file has expired, send it again.'), $this->screen($list)->html());
    }

    public function testASkippedFileIsNotStoredAndTheWayGoesOn(): void
    {
        $this->upload([$this->png('photo.png'), $this->svg('passe.svg', self::CLEAN)]);
        $png = $this->getService(CmsMediaLibrary::class)->images();
        $screen = $this->screen($this->remember((string) $this->client->getResponse()->headers->get('Location')));

        $this->answer($screen, 'skip');

        self::assertCount(1, $this->getService(CmsMediaLibrary::class)->images(), 'The skipped file is not stored.');
        self::assertStringEndsWith(\sprintf('/admin/cms/media/%d?edit_language_id=%d', $png[0]->getId(), $this->languageId()), (string) $this->client->getResponse()->headers->get('Location'), 'One image of the sending: its sheet.');
    }

    public function testThePreviewsAreServedAsImagesAndNothingElse(): void
    {
        $this->upload([$this->svg('piege.svg', self::TRAPPED)]);
        $token = $this->tokenOf($this->remember((string) $this->client->getResponse()->headers->get('Location')));

        foreach (['original', 'result'] as $preview) {
            $this->client->request('GET', \sprintf('/admin/cms/media/svg/%s/%s', $token, $preview));
            $response = $this->client->getResponse();

            self::assertSame(200, $response->getStatusCode(), $preview);
            self::assertStringStartsWith('image/svg+xml', (string) $response->headers->get('Content-Type'), $preview);
            self::assertSame('sandbox', $response->headers->get('Content-Security-Policy'), $preview);
            self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'), $preview);
            self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'), $preview);
        }

        $this->client->request('GET', '/admin/cms/media/svg/'.$token.'/original');
        self::assertStringContainsString('<script>', (string) $this->client->getResponse()->getContent(), 'The original is served as it was sent.');
        $this->client->request('GET', '/admin/cms/media/svg/'.$token.'/result');
        self::assertStringNotContainsString('<script', (string) $this->client->getResponse()->getContent(), 'The result is the cleaned file.');

        $this->client->request('GET', '/admin/cms/media/svg/'.str_repeat('b', 32).'/original');
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testReplacingTheFileOfAnImageGoesThroughTheScreenAndBackToItsSheet(): void
    {
        $image = $this->image('À remplacer');
        $sheet = \sprintf('/admin/cms/media/%d', $image->getId());
        $this->postSheet($sheet, ['decorative' => '1', 'title' => 'Nouveau nom'], $this->svg('neuf.svg', self::TRAPPED));

        $response = $this->client->getResponse();
        self::assertSame(302, $response->getStatusCode());
        $location = $this->remember((string) $response->headers->get('Location'));
        self::assertStringContainsString('/admin/cms/media/svg/', $location);
        self::assertStringContainsString('replace='.$image->getId(), $location);
        self::assertSame('Nouveau nom', (string) $this->reloaded($image)?->setLocale($this->locale())->getTitle(), 'The other fields are saved.');
        self::assertStringEndsNotWith('.svg', (string) $this->reloaded($image)?->getFileName(), 'The file waits for the screen.');

        $screen = $this->screen($location);
        self::assertSame(1, $screen->filter('[data-testid="cms-svg-replacing"]')->count());
        $this->answer($screen, 'add');

        self::assertStringEndsWith(\sprintf('/admin/cms/media/%d?edit_language_id=%d', $image->getId(), $this->languageId()), (string) $this->client->getResponse()->headers->get('Location'));
        $replaced = $this->reloaded($image);
        self::assertNotNull($replaced);
        self::assertStringEndsWith('.svg', $replaced->getFileName());
        self::assertCount(1, $this->getService(CmsMediaLibrary::class)->images(), 'The row is kept: no second image.');
        self::assertStringNotContainsString('onload', (string) file_get_contents(TheliaLibrary::getImageDirectory().$replaced->getFileName()));
    }

    public function testAReplacedImageWithoutATitleInTheLanguageIsNamedAfterTheFileSent(): void
    {
        $image = $this->image('Titre dans une langue');
        $other = 'en_US' === $this->locale() ? 'fr_FR' : 'en_US';

        $this->getService(CmsMediaWriter::class)->replaceWithPreparedSvg($image, self::CLEAN, 'plan-du-magasin.svg', $other);

        self::assertSame('plan-du-magasin.svg', (string) $this->reloaded($image)?->setLocale($other)->getTitle(), 'Not the name of a temporary file.');
        self::assertSame('Titre dans une langue', (string) $this->reloaded($image)?->setLocale($this->locale())->getTitle(), 'A title already given stays.');
    }

    public function testReplacingWithAnSvgTheLibraryCannotReadSaysWhyAndKeepsTheImage(): void
    {
        $image = $this->image('Garder');
        $sheet = \sprintf('/admin/cms/media/%d', $image->getId());
        $entity = '<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY x "y">]><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"/>';

        $this->postSheet($sheet, ['decorative' => '1'], $this->svg('refuse.svg', $entity));

        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        $back = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringContainsString(\sprintf('/admin/cms/media/%d', $image->getId()), $back);
        self::assertStringNotContainsString('/svg/', $back);
        self::assertStringEndsNotWith('.svg', (string) $this->reloaded($image)?->getFileName());
        self::assertStringContainsString($this->said('The SVG declares entities, which are refused. Export it again without them.'), $this->screen($back)->html());
    }

    public function testAFitDrawingIsOfferedAsAnIconUnderTheNameOfItsFile(): void
    {
        $screen = $this->opened($this->svg('Mon Écran #2.svg', self::CLEAN));

        self::assertSame(1, $screen->filter('[data-testid="cms-svg-usage"]')->count());
        self::assertSame('image', $screen->filter('[name="usage"]:checked')->attr('value'), 'An image stays the default.');
        self::assertNull($screen->filter('#cms-svg-usage-icon')->attr('disabled'));
        self::assertSame('mon-ecran-2', $screen->filter('[data-testid="cms-svg-icon-name"]')->attr('value'));
        self::assertSame('cms:', trim($screen->filter('[data-cms-svg-icon-name-wrap] .input-group-text')->text()));
        self::assertSame(0, $screen->filter('[data-testid="cms-svg-icon-obstacles"]')->count());
    }

    public function testTheUsageAndTheNameTypedFollowAPreviewOfTheSettings(): void
    {
        $this->opened($this->svg('flèche.svg', self::COLORED));
        $token = $this->tokens[array_key_last($this->tokens)];

        $preview = $this->screen('/admin/cms/media/svg/'.$token.'?usage=icon&icon_name=ma-fleche&edit_language_id='.$this->languageId());

        self::assertSame('icon', $preview->filter('[name="usage"]:checked')->attr('value'), 'A usage in the address is the one shown.');
        self::assertSame('ma-fleche', $preview->filter('[data-testid="cms-svg-icon-name"]')->attr('value'), 'So is the name typed.');
        self::assertSame(['icon', 'ma-fleche'], [
            $preview->filter('[data-testid="cms-svg-presets"] [data-cms-svg-carry="usage"]')->attr('value'),
            $preview->filter('[data-testid="cms-svg-presets"] [data-cms-svg-carry="icon_name"]')->attr('value'),
        ], 'The forms that preview a setting carry them.');
    }

    public function testAnSvgIsAddedAsAnIconUnderTheNameTyped(): void
    {
        $screen = $this->opened($this->svg('flèche.svg', self::CLEAN));

        $this->answer($screen, 'add', ['usage' => 'icon', 'icon_name' => 'ma-fleche']);

        $images = $this->getService(CmsMediaLibrary::class)->images();
        self::assertCount(1, $images);
        self::assertSame('ma-fleche', $this->getService(IconNames::class)->nameOf((int) $images[0]->getId()));
        self::assertStringContainsString('/admin/cms/media/'.$images[0]->getId(), (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testThePrefixTypedWithTheNameIsDroppedAsTheSheetDoesIt(): void
    {
        $screen = $this->opened($this->svg('flèche.svg', self::CLEAN));

        $this->answer($screen, 'add', ['usage' => 'icon', 'icon_name' => ' cms:ma-fleche ']);

        $images = $this->getService(CmsMediaLibrary::class)->images();
        self::assertCount(1, $images);
        self::assertSame('ma-fleche', $this->getService(IconNames::class)->nameOf((int) $images[0]->getId()), 'The prefix is part of what the editor sees, not of the name.');
    }

    public function testAnSvgAddedAsAnImageHasNoIconName(): void
    {
        $screen = $this->opened($this->svg('flèche.svg', self::CLEAN));

        $this->answer($screen, 'add', ['usage' => 'image', 'icon_name' => 'ma-fleche']);

        $images = $this->getService(CmsMediaLibrary::class)->images();
        self::assertCount(1, $images);
        self::assertNull($this->getService(IconNames::class)->nameOf((int) $images[0]->getId()), 'A name typed for an image that is not an icon is dropped.');
        self::assertSame([], $this->getService(IconNames::class)->names());
    }

    public function testADrawingAPageWouldNotKeepWholeIsNotOfferedAsAnIconAndSaysWhy(): void
    {
        $screen = $this->opened($this->svg('degrade.svg', self::GRADIENT));

        self::assertNotNull($screen->filter('#cms-svg-usage-icon')->attr('disabled'));
        self::assertSame('image', $screen->filter('[name="usage"]:checked')->attr('value'));
        $obstacles = $this->normalize($screen->filter('[data-testid="cms-svg-icon-obstacles"]')->text());
        self::assertStringContainsString($this->saidWith('a block of definitions (%s)', 'defs'), $obstacles);
        self::assertStringContainsString($this->saidWith('a gradient or a pattern as paint (%s)', 'fill'), $obstacles);
        self::assertSame(0, $screen->filter('[data-testid="cms-svg-icon-name"]')->count(), 'No name to give: the drawing cannot be an icon.');
    }

    public function testTheServerRefusesAnIconThatCannotBeOneEvenIfTheFormAsksForIt(): void
    {
        $screen = $this->opened($this->svg('degrade.svg', self::GRADIENT));

        $answer = $this->submit($screen, 'add', ['usage' => 'icon', 'icon_name' => 'degrade']);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertSame([], $this->getService(CmsMediaLibrary::class)->images(), 'Nothing is stored.');
        self::assertSame([], $this->getService(IconNames::class)->names());
        self::assertStringContainsString($this->said('This file cannot be added as an icon: see below what a page would not keep. It can still be added as an image.'), $this->normalize($answer->filter('[data-testid="cms-svg-icon-error"]')->text()));
        self::assertNotNull($this->getService(PreparedSvgStore::class)->find($this->tokens[array_key_last($this->tokens)]), 'The file waits: the editor can add it as an image.');

        $this->answer($this->screen('/admin/cms/media/svg/'.$this->tokens[array_key_last($this->tokens)].'?edit_language_id='.$this->languageId()), 'add', ['usage' => 'image']);
        self::assertCount(1, $this->getService(CmsMediaLibrary::class)->images());
    }

    public function testAnInvalidIconNameIsRefusedAndNothingIsStored(): void
    {
        $screen = $this->opened($this->svg('fleche.svg', self::CLEAN));

        foreach (['Ma Flèche', '', 'Fleche', str_repeat('a', 65), 'a--b'] as $name) {
            $answer = $this->submit($screen, 'add', ['usage' => 'icon', 'icon_name' => $name]);

            self::assertSame(422, $this->client->getResponse()->getStatusCode(), \sprintf('"%s" is refused.', $name));
            self::assertSame([], $this->getService(CmsMediaLibrary::class)->images(), 'Nothing is stored.');
            self::assertStringContainsString($this->saidWith('The icon name is made of lowercase letters and digits, separated by single hyphens, %d characters at most.', '64'), $this->normalize($answer->filter('[data-testid="cms-svg-icon-error"]')->text()));
            self::assertSame($name, $answer->filter('[data-testid="cms-svg-icon-name"]')->attr('value'), 'The name typed is given back.');
            self::assertSame('icon', $answer->filter('[name="usage"]:checked')->attr('value'), 'So is the usage.');
        }
    }

    public function testATakenIconNameIsRefusedAndNothingIsStored(): void
    {
        $holder = $this->image('Détient le nom');
        $this->getService(IconNames::class)->assign((int) $holder->getId(), 'deja-pris');
        $screen = $this->opened($this->svg('fleche.svg', self::CLEAN));

        $answer = $this->submit($screen, 'add', ['usage' => 'icon', 'icon_name' => 'deja-pris']);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertCount(1, $this->getService(CmsMediaLibrary::class)->images(), 'Only the holder of the name is in the library.');
        self::assertStringContainsString($this->saidWith('The icon name "%s" is already used by another image.', 'cms:deja-pris'), $this->normalize($answer->filter('[data-testid="cms-svg-icon-error"]')->text()));
        self::assertSame((int) $holder->getId(), array_key_first($this->getService(IconNames::class)->namesByImage()));
    }

    public function testTheUsageIsCarriedToTheFollowingFilesAndTheWayStopsWhereAFileCannotTakeIt(): void
    {
        $screen = $this->opened(
            $this->svg('premiere.svg', self::CLEAN),
            $this->svg('Partagé.svg', self::CLEAN),
            $this->svg('degrade.svg', self::GRADIENT),
        );

        $this->answer($screen, 'add', ['usage' => 'icon', 'icon_name' => 'partage', 'apply_to_following' => '1']);
        $second = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringContainsString('usage=icon', $second);

        // The second file draws its name from its own file name, which the first took.
        $screen = $this->screen($second);
        self::assertSame('icon', $screen->filter('[name="usage"]:checked')->attr('value'));
        self::assertSame('partage', $screen->filter('[data-testid="cms-svg-icon-name"]')->attr('value'));
        self::assertStringContainsString($this->saidWith('The icon name "%s" is already used by another image.', 'cms:partage'), $this->normalize($screen->filter('[data-testid="cms-svg-icon-error"]')->text()), 'The way stops on it, with the reason, instead of storing it.');
        self::assertCount(1, $this->getService(CmsMediaLibrary::class)->images());

        $this->submit($screen, 'add', ['usage' => 'icon', 'icon_name' => 'partage']);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertCount(1, $this->getService(CmsMediaLibrary::class)->images());

        $this->answer($screen, 'add', ['usage' => 'icon', 'icon_name' => 'partage-2', 'apply_to_following' => '1']);
        $third = $this->screen((string) $this->client->getResponse()->headers->get('Location'));

        // The third cannot be an icon: it is told so and stays an image.
        self::assertNotNull($third->filter('#cms-svg-usage-icon')->attr('disabled'));
        self::assertSame('image', $third->filter('[name="usage"]:checked')->attr('value'));
        self::assertSame(1, $third->filter('[data-testid="cms-svg-icon-obstacles"]')->count());

        $this->answer($third, 'add', ['usage' => 'image']);
        self::assertSame(['partage', 'partage-2'], $this->getService(IconNames::class)->names());
        self::assertCount(3, $this->getService(CmsMediaLibrary::class)->images());
    }

    public function testTheUsageIsNotCarriedWhenTheEditorDoesNotAskForIt(): void
    {
        $screen = $this->opened($this->svg('un.svg', self::CLEAN), $this->svg('deux.svg', self::CLEAN));

        $this->answer($screen, 'add', ['usage' => 'icon', 'icon_name' => 'un']);

        self::assertStringNotContainsString('usage=', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testTheUsageIsTheFirstStepAndNumberedOneLikeTheOthers(): void
    {
        $screen = $this->opened($this->svg('check.svg', self::COLORED));

        self::assertSame(
            ['cms-svg-usage', 'cms-svg-cleaning', 'cms-svg-optimization', 'cms-svg-colors'],
            $screen->filter('.col-lg-5 > details.card')->each(static fn (Crawler $card): string => (string) $card->attr('data-testid')),
            'The usage comes first, in a card like the others.',
        );
        self::assertSame(
            ['1.', '2.', '3.', '4.'],
            $screen->filter('.col-lg-5 > details.card > summary')->each(fn (Crawler $summary): string => explode(' ', $this->normalize($summary->text()))[0]),
        );
        self::assertSame(0, $screen->filter('[data-testid="cms-svg-form"] [data-testid="cms-svg-usage"], [data-testid="cms-svg-form"] input[name="usage"][type="radio"]')->count(), 'The form of the file no longer holds the usage.');
    }

    public function testTheUsageStaysTheFirstStepReadOnlyWhereItCannotBeChosen(): void
    {
        $image = $this->image('À remplacer');
        $this->postSheet(\sprintf('/admin/cms/media/%d', $image->getId()), ['decorative' => '1'], $this->svg('neuf.svg', self::COLORED));
        $screen = $this->screen($this->remember((string) $this->client->getResponse()->headers->get('Location')));

        self::assertSame(['1.', '2.', '3.', '4.'], $screen->filter('.col-lg-5 > details.card > summary')->each(fn (Crawler $summary): string => explode(' ', $this->normalize($summary->text()))[0]), 'The steps keep their numbers.');
        self::assertSame(1, $screen->filter('[data-testid="cms-svg-usage"] [data-testid="cms-svg-usage-read-only"]')->count(), 'Said, not asked.');
        self::assertSame(0, $screen->filter('[data-testid="cms-svg-usage"] input[name="usage"]')->count());
    }

    public function testChoosingAnIconPresetsTheColorsUntilTheEditorSetsThemAndImageGivesThemBack(): void
    {
        $screen = $this->opened($this->svg('check.svg', self::COLORED));
        self::assertSame('keep', $screen->filter('[data-testid="cms-svg-presets"] input[name="colors"]:checked')->attr('value'));
        self::assertSame(0, $screen->filter('[data-testid="cms-svg-icon-advice"]')->count(), 'No tips for an image.');

        $icon = $this->withUsage($screen, 'icon');
        self::assertSame('main', $icon->filter('[data-testid="cms-svg-presets"] input[name="colors"]:checked')->attr('value'), 'An icon takes the main color as the color of the text.');
        self::assertSame('', $icon->filter('[data-testid="cms-svg-others"] input:checked')->attr('value'), 'Without the lighter option.');
        $this->client->request('GET', (string) $icon->filter('[data-testid="cms-svg-result"]')->attr('src'));
        self::assertSame(3, substr_count((string) $this->client->getResponse()->getContent(), 'currentColor'), 'The preview shows the preset: the three dark shapes follow the text.');
        self::assertSame('0', $icon->filter('[data-testid="cms-svg-form"] input[name="colors_touched"]')->attr('value'));

        $image = $this->withUsage($icon, 'image');
        self::assertSame('keep', $image->filter('[data-testid="cms-svg-presets"] input[name="colors"]:checked')->attr('value'), 'Image gives the colors back.');

        $this->answer($icon, 'add', ['icon_name' => 'coche']);
        $stored = (string) file_get_contents(TheliaLibrary::getImageDirectory().$this->getService(CmsMediaLibrary::class)->images()[0]->getFileName());
        self::assertSame(3, substr_count($stored, 'currentColor'), 'The preset is what is stored.');
        self::assertSame(['coche'], $this->getService(IconNames::class)->names());
    }

    public function testTheTipsShowWithTheIconUsage(): void
    {
        $screen = $this->withUsage($this->opened($this->svg('check.svg', self::COLORED)), 'icon');

        $tips = $screen->filter('[data-testid="cms-svg-icon-advice"] li');
        self::assertCount(3, $tips);
        self::assertStringContainsString($this->said('An icon takes the color of the text around it.'), $tips->first()->text());
    }

    public function testTheColorsTheEditorSetsAreKeptWhateverTheUsage(): void
    {
        $screen = $this->opened($this->svg('check.svg', self::COLORED));

        $monochrome = $this->withColors($screen, ['colors' => 'monochrome']);
        $icon = $this->withUsage($monochrome, 'icon');
        self::assertSame('monochrome', $icon->filter('[data-testid="cms-svg-presets"] input[name="colors"]:checked')->attr('value'), 'The choice of the editor is not changed by the usage.');
        self::assertSame('monochrome', $this->withUsage($icon, 'image')->filter('[data-testid="cms-svg-presets"] input[name="colors"]:checked')->attr('value'));

        // Keeping the colors is a choice too.
        $kept = $this->withColors($screen, ['colors' => 'keep']);
        self::assertSame('keep', $this->withUsage($kept, 'icon')->filter('[data-testid="cms-svg-presets"] input[name="colors"]:checked')->attr('value'));

        $this->answer($icon, 'add', ['icon_name' => 'mono']);
        $stored = (string) file_get_contents(TheliaLibrary::getImageDirectory().$this->getService(CmsMediaLibrary::class)->images()[0]->getFileName());
        self::assertStringNotContainsStringIgnoringCase('#22a06b', $stored, 'The monochrome preset is stored, not the preselection.');
    }

    public function testTheServerPresetsTheColorsOfAnIconSentWithoutThePreview(): void
    {
        $screen = $this->opened($this->svg('check.svg', self::COLORED));

        $this->answer($screen, 'add', ['usage' => 'icon', 'icon_name' => 'direct']);

        $stored = (string) file_get_contents(TheliaLibrary::getImageDirectory().$this->getService(CmsMediaLibrary::class)->images()[0]->getFileName());
        self::assertSame(3, substr_count($stored, 'currentColor'));
    }

    public function testTheUsageAndTheColorsAsTheyStandAreCarriedToTheFollowingFiles(): void
    {
        $screen = $this->withUsage($this->opened($this->svg('un.svg', self::COLORED), $this->svg('deux.svg', self::COLORED)), 'icon');

        $this->answer($screen, 'add', ['icon_name' => 'un', 'apply_to_following' => '1']);
        $next = (string) $this->client->getResponse()->headers->get('Location');

        self::assertStringContainsString('usage=icon', $next);
        self::assertStringContainsString('colors=main', $next, 'The preselected preset goes on.');
        $second = $this->screen($next);
        self::assertSame('main', $second->filter('[data-testid="cms-svg-presets"] input[name="colors"]:checked')->attr('value'));
        self::assertSame('icon', $second->filter('input[name="usage"]:checked')->attr('value'));
    }

    public function testAnIconThatCannotBeOneKeepsItsColorsWhateverThePreselection(): void
    {
        $screen = $this->withUsage($this->opened($this->svg('degrade.svg', self::GRADIENT)), 'icon');

        self::assertSame('image', $screen->filter('input[name="usage"]:checked')->attr('value'));
        self::assertSame('keep', $screen->filter('[data-testid="cms-svg-presets"] input[name="colors"]:checked')->attr('value'));
        self::assertSame(1, $screen->filter('[data-testid="cms-svg-icon-obstacles"]')->count());
    }

    public function testNoUsageIsOfferedToAFileThatReplacesAnImageNorToTheEditorsPicker(): void
    {
        $image = $this->image('À remplacer');
        $this->postSheet(\sprintf('/admin/cms/media/%d', $image->getId()), ['decorative' => '1'], $this->svg('neuf.svg', self::CLEAN));
        $replacing = $this->screen($this->remember((string) $this->client->getResponse()->headers->get('Location')));

        self::assertSame(0, $replacing->filter('[data-testid="cms-svg-usage"] input[name="usage"]')->count(), 'Nothing to choose: the usage is only said.');

        $this->upload([$this->svg('picto.svg', self::CLEAN)], json: true, query: '&picker=1');
        $answer = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        $picker = $this->screen($this->remember((string) $answer['prepare']));
        self::assertSame(0, $picker->filter('[data-testid="cms-svg-usage"] input[name="usage"]')->count());
        self::assertSame(1, $picker->filter('[data-testid="cms-svg-usage-read-only"]')->count());

        // A form that asks for an icon all the same stores an image.
        $this->handedBackWith('add', $picker, ['usage' => 'icon', 'icon_name' => 'picto']);
        self::assertSame([], $this->getService(IconNames::class)->names());
    }

    public function testAForgedIconUsageOnAReplacementGivesTheImageNoIconName(): void
    {
        $image = $this->image('À remplacer');
        $this->postSheet(\sprintf('/admin/cms/media/%d', $image->getId()), ['decorative' => '1'], $this->svg('neuf.svg', self::CLEAN));
        $replacing = $this->screen($this->remember((string) $this->client->getResponse()->headers->get('Location')));
        $count = \count($this->getService(CmsMediaLibrary::class)->images());

        $this->answer($replacing, 'add', ['usage' => 'icon', 'icon_name' => 'forge']);

        self::assertNull($this->getService(IconNames::class)->nameOf((int) $image->getId()), 'The usage is not offered to a replacement: the answer cannot make an icon of it.');
        self::assertSame([], $this->getService(IconNames::class)->names());
        self::assertCount($count, $this->getService(CmsMediaLibrary::class)->images(), 'No other image is added.');
        self::assertStringEndsWith('.svg', (string) $this->reloaded($image)?->getFileName(), 'The file is replaced as asked.');
    }

    public function testReplacingTheFileOfAnIconWithADrawingThatCannotBeOneIsRefused(): void
    {
        $icon = $this->getService(CmsMediaWriter::class)->addPreparedSvg(self::CLEAN, 'marque.svg', $this->locale(), 'marque');
        $fileName = $icon->getFileName();
        $sheet = \sprintf('/admin/cms/media/%d', $icon->getId());
        // The sheet of an icon asks no alternative text: nothing but the file is sent.
        $this->postSheet($sheet, [], $this->svg('degrade.svg', self::GRADIENT));
        $screen = $this->screen($this->remember((string) $this->client->getResponse()->headers->get('Location')));

        self::assertStringContainsString('cms:marque', $this->normalize($screen->filter('[data-testid="cms-svg-icon-replaced-obstacles"]')->text()), 'It is told before the answer.');
        self::assertSame(0, $screen->filter('[data-testid="cms-svg-usage-read-only"]')->count(), 'The card does not say the file replaces the icon it cannot replace.');

        $answer = $this->submit($screen, 'add');

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString($this->saidWith('The image is the icon %s: the new file would not be kept whole in a page, so it does not replace the old one.', 'cms:marque'), $this->normalize($answer->filter('[data-testid="cms-svg-icon-error"]')->text()));
        self::assertSame($fileName, $this->reloaded($icon)?->getFileName(), 'The file of the image is the old one.');
        self::assertSame('marque', $this->getService(IconNames::class)->nameOf((int) $icon->getId()));
    }

    public function testReplacingTheFileOfAnIconWithAFitDrawingKeepsItsName(): void
    {
        $icon = $this->getService(CmsMediaWriter::class)->addPreparedSvg(self::CLEAN, 'marque.svg', $this->locale(), 'marque');
        $this->postSheet(\sprintf('/admin/cms/media/%d', $icon->getId()), [], $this->svg('neuve.svg', self::COLORED));
        $screen = $this->screen($this->remember((string) $this->client->getResponse()->headers->get('Location')));

        $this->answer($screen, 'add');

        self::assertSame('marque', $this->getService(IconNames::class)->nameOf((int) $icon->getId()));
        self::assertStringContainsString('#22a06b', strtolower((string) file_get_contents(TheliaLibrary::getImageDirectory().$this->reloaded($icon)?->getFileName())));
    }

    public function testReplacingTheFileOfAnImageThatIsNotAnIconTakesAnyDrawing(): void
    {
        $image = $this->image('Pas une icône');
        $this->postSheet(\sprintf('/admin/cms/media/%d', $image->getId()), ['decorative' => '1'], $this->svg('degrade.svg', self::GRADIENT));
        $screen = $this->screen($this->remember((string) $this->client->getResponse()->headers->get('Location')));

        $this->answer($screen, 'add');

        self::assertStringEndsWith('.svg', (string) $this->reloaded($image)?->getFileName());
    }

    public function testThePresetsComeFirstAndTheColorsOnlyWhereAMainOneIsChosen(): void
    {
        $this->upload([$this->svg('check.svg', self::COLORED)]);
        $screen = $this->screen($this->remember((string) $this->client->getResponse()->headers->get('Location')));

        self::assertSame('keep', $screen->filter('[data-testid="cms-svg-presets"] input[name="colors"]:checked')->attr('value'), 'The colors are kept until the editor says otherwise.');
        self::assertSame(['keep', 'monochrome', 'main'], $screen->filter('input[name="colors"]')->each(static fn (Crawler $input): string => (string) $input->attr('value')));
        self::assertSame(0, $screen->filter('[data-testid="cms-svg-main"], [data-testid="cms-svg-others"], [data-testid="cms-svg-white"]')->count(), 'Kept colors: nothing more to say.');
        self::assertSame(0, $screen->filter('[data-testid="cms-svg-presets"] button')->count(), 'A choice shows its preview at once: no button for it.');
        self::assertSame(3, $screen->filter('[data-testid="cms-svg-tints"] img')->count());

        $main = $this->withColors($screen, ['colors' => 'main']);
        self::assertSame(['#1c1c1c', '#22a06b', '#ffffff'], $main->filter('[data-testid="cms-svg-main"] input[name="main"]')->each(static fn (Crawler $input): string => (string) $input->attr('value')), 'The most used first, the white of a stroke included.');
        self::assertSame('', $main->filter('[data-testid="cms-svg-others"] input:checked')->attr('value'), 'The others stay as they are at first.');
        self::assertSame(0, $main->filter('[data-testid="cms-svg-white"]')->count(), 'The others kept: the white is no question.');

        $softened = $this->withColors($main, ['colors' => 'main', 'others' => 'soft']);
        self::assertSame('soft', $softened->filter('[data-testid="cms-svg-others"] input:checked')->attr('value'));
        self::assertSame(1, $softened->filter('[data-testid="cms-svg-white"]')->count(), 'The others lighter: the white may follow them.');
        $this->client->request('GET', (string) $softened->filter('[data-testid="cms-svg-result"]')->attr('src'));
        self::assertStringContainsString('fill-opacity="0.4"', (string) $this->client->getResponse()->getContent());
    }

    public function testAPresetIsPreviewedAndStoredAsChosen(): void
    {
        $this->upload([$this->svg('check.svg', self::COLORED)]);
        $screen = $this->withColors($this->screen($this->remember((string) $this->client->getResponse()->headers->get('Location'))), ['colors' => 'monochrome', 'white' => 'keep']);

        self::assertSame(1, $screen->filter('[data-testid="cms-svg-white"]')->count(), 'Monochrome asks about the white of the drawing.');
        self::assertSame('keep', $screen->filter('[data-testid="cms-svg-white"] input:checked')->attr('value'));

        $this->client->request('GET', (string) $screen->filter('[data-testid="cms-svg-result"]')->attr('src'));
        $preview = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('currentColor', $preview);
        self::assertStringNotContainsStringIgnoringCase('#22a06b', $preview);
        self::assertStringContainsString('#fff', $preview, 'The white was kept.');

        $this->client->request('GET', (string) $screen->filter('[data-testid="cms-svg-tint-dark"]')->attr('src'));
        self::assertStringContainsString('color="#f8f9fa"', (string) $this->client->getResponse()->getContent(), 'The preview on a dark ground draws the text color it would take.');

        // The large preview is drawn once per text color: the theme, black, white.
        self::assertSame(['theme', 'black', 'white'], $screen->filter('[data-testid="cms-svg-text-color"] input')->each(static fn (Crawler $input): string => (string) $input->attr('value')));
        self::assertSame('theme', $screen->filter('[data-testid="cms-svg-text-color"] input:checked')->attr('value'));
        foreach (['cms-svg-result' => '#f26041', 'cms-svg-result-black' => '#212529', 'cms-svg-result-white' => '#f8f9fa'] as $testId => $color) {
            $this->client->request('GET', (string) $screen->filter('[data-testid="'.$testId.'"]')->attr('src'));
            self::assertStringContainsString('color="'.$color.'"', (string) $this->client->getResponse()->getContent(), $testId);
        }

        $this->client->request('GET', (string) $screen->filter('[data-testid="cms-svg-tint-accent"]')->attr('src'));
        self::assertStringContainsString('color="#f26041"', (string) $this->client->getResponse()->getContent(), 'A colored text takes the primary color of the back office.');

        $this->answer($screen, 'add');

        $stored = (string) file_get_contents(TheliaLibrary::getImageDirectory().$this->getService(CmsMediaLibrary::class)->images()[0]->getFileName());
        self::assertSame(str_replace(' color="#f26041"', '', $preview), $stored, 'What was previewed is what is stored, but the tint of its ground.');
        self::assertStringNotContainsString('color=', $stored, 'The tint of a preview never reaches the file.');
    }

    public function testTheMainColorIsTheEditorsAndIsNotCarriedToTheNextFile(): void
    {
        $this->upload([$this->svg('un.svg', self::COLORED), $this->svg('deux.svg', self::COLORED)]);
        $screen = $this->screen($this->remember((string) $this->client->getResponse()->headers->get('Location')));
        $screen = $this->withColors($screen, ['colors' => 'main']);

        self::assertSame('#1c1c1c', $screen->filter('input[name="main"]:checked')->attr('value'), 'The color of the most elements comes first.');
        $screen = $this->withColors($screen, ['colors' => 'main', 'main' => '#22a06b']);
        self::assertSame('#22a06b', $screen->filter('input[name="main"]:checked')->attr('value'));

        $this->answer($screen, 'add', ['apply_to_following' => '1']);
        $next = (string) $this->client->getResponse()->headers->get('Location');

        $stored = (string) file_get_contents(TheliaLibrary::getImageDirectory().$this->getService(CmsMediaLibrary::class)->images()[0]->getFileName());
        self::assertSame(1, substr_count($stored, 'currentColor'), 'Only the green circle follows the text.');
        self::assertStringContainsString('colors=main', $next, 'The preset goes on to the next file.');
        self::assertStringNotContainsString('main=%23', $next, 'The main color is the one of each drawing.');
        self::assertSame('main', $this->screen($next)->filter('input[name="colors"]:checked')->attr('value'));
    }

    public function testTheMainColorIsChosenInAGridWhereTheLeastUsedFold(): void
    {
        $shapes = '';

        for ($index = 0; $index < 15; ++$index) {
            $shapes .= \sprintf('<path d="M%1$d 0h1v1H%1$dz" fill="hsl(%2$d, 70%%, 50%%)"/>', $index, $index * 24);
        }

        $this->upload([$this->svg('nuancier.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 15 1">'.$shapes.'</svg>')]);
        $screen = $this->screen($this->remember((string) $this->client->getResponse()->headers->get('Location')));

        self::assertSame(0, $screen->filter('[data-testid="cms-svg-main"]')->count(), 'Kept colors: no color to choose.');
        self::assertSame(0, $this->withColors($screen, ['colors' => 'monochrome'])->filter('[data-testid="cms-svg-main"]')->count(), 'Monochrome: no color to choose either.');

        $grid = $this->withColors($screen, ['colors' => 'main'])->filter('[data-testid="cms-svg-main"]');
        self::assertSame(15, $grid->filter('input[name="main"]')->count(), 'A folded color can still be the main one.');
        self::assertSame(3, $grid->filter('[data-testid="cms-svg-main-more"] input[name="main"]')->count(), 'Past twelve, the others fold.');
        self::assertSame($this->said('%count% other color|%count% other colors', ['%count%' => 3]), trim($grid->filter('summary')->text()));
        self::assertStringStartsWith('#', (string) $grid->filter('label')->first()->attr('title'), 'Its code and its use show on hovering.');
    }

    public function testADrawingTooComplexToReadKeepsItsColorsAndSaysWhy(): void
    {
        // A group drawn again and again: more shapes drawn than are read.
        $group = str_repeat('<path d="M0 0h1v1H0z" fill="#f00"/>', 1000);
        $uses = str_repeat('<use href="#g"/>', intdiv(SvgPaints::MAXIMUM_DRAWINGS, 1000) + 1);
        $this->upload([$this->svg('motif.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 4 4"><defs><g id="g">'.$group.'</g></defs>'.$uses.'</svg>')]);
        $screen = $this->screen($this->remember((string) $this->client->getResponse()->headers->get('Location')));

        self::assertSame($this->said('The drawing has too many shapes or style rules to change its colors: they stay as they are.'), trim($screen->filter('[data-testid="cms-svg-too-complex"]')->text()));
        self::assertSame(0, $screen->filter('[data-testid="cms-svg-presets"]')->count());
    }

    public function testADrawingThatAlreadyFollowsTheTextSaysSo(): void
    {
        $this->upload([$this->svg('icone.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16"><path d="M0 0h16v16H0z" fill="currentColor"/></svg>')]);
        $screen = $this->screen($this->remember((string) $this->client->getResponse()->headers->get('Location')));

        self::assertSame($this->said('The drawing already takes the color of the text around it.'), trim($screen->filter('[data-testid="cms-svg-no-color"]')->text()));
        self::assertSame(0, $screen->filter('[data-testid="cms-svg-presets"]')->count());
    }

    /**
     * @param list<UploadedFile> $files
     */
    private function upload(array $files, bool $json = false, string $query = ''): void
    {
        $token = $this->tokenOfTheForm($this->screen(self::LIST), self::LIST);
        $server = $json ? [...self::XHR, 'HTTP_ACCEPT' => 'application/json'] : self::SAME_SITE;

        $this->client->request('POST', self::LIST.'?edit_language_id='.$this->languageId().$query, ['_token' => $token], ['files' => $files], $server);
    }

    /**
     * What the page ending a way opened from the picker hands to it.
     *
     * @return array{type: string, image: int|null, message: string|null}
     */
    private function handedBack(string $action, Crawler $screen): array
    {
        return $this->handedBackWith($action, $screen, []);
    }

    /**
     * @param array<string, string> $extra
     *
     * @return array{type: string, image: int|null, message: string|null}
     */
    private function handedBackWith(string $action, Crawler $screen, array $extra): array
    {
        $form = $screen->filter('[data-testid="cms-svg-form"]');
        $this->client->request('POST', (string) $form->attr('action'), [
            '_token' => (string) $form->filter('input[name="_token"]')->attr('value'),
            'action' => $action,
            ...$extra,
        ], [], self::SAME_SITE);

        self::assertSame(200, $this->client->getResponse()->getStatusCode(), 'The frame is not sent elsewhere: it answers the picker.');
        $page = new Crawler((string) $this->client->getResponse()->getContent());

        return json_decode($page->filter('script[data-cms-svg-picked]')->text(), true, flags: \JSON_THROW_ON_ERROR);
    }

    /**
     * Sends the sheet of an image as its own form gives it (with its token),
     * the given fields and file put over it.
     *
     * @param array<string, string> $fields
     */
    private function postSheet(string $sheet, array $fields, UploadedFile $file): void
    {
        $form = $this->screen($sheet)->filter('form:not([data-cms-media-delete])')->reduce(
            static fn (Crawler $candidate): bool => $candidate->filter('[name="cms_media[title]"]')->count() > 0,
        )->first()->form();
        $values = $form->getPhpValues();
        $values['cms_media'] = [...$values['cms_media'], ...$fields];

        $this->client->request('POST', $sheet, $values, ['cms_media' => ['file' => $file]], self::SAME_SITE);
    }

    /**
     * Answers the form of the screen: "add" or "skip".
     */
    /**
     * @param array<string, string> $extra fields the editor set on top of the hidden ones
     */
    private function answer(Crawler $screen, string $action, array $extra = []): void
    {
        $this->submit($screen, $action, $extra);

        self::assertSame(302, $this->client->getResponse()->getStatusCode());
    }

    /**
     * Sends the form of the screen and leaves the answer, whatever it is, to the test.
     *
     * @param array<string, string> $extra
     */
    private function submit(Crawler $screen, string $action, array $extra = []): Crawler
    {
        $form = $screen->filter('[data-testid="cms-svg-form"]');
        self::assertSame(1, $form->count());

        return $this->client->request('POST', (string) $form->attr('action'), [
            ...$this->fieldsOf($form),
            'action' => $action,
            ...$extra,
        ], [], self::SAME_SITE);
    }

    /** The screen of the first file of a sending, opened. */
    private function opened(UploadedFile ...$files): Crawler
    {
        $this->upload($files);

        return $this->screen($this->remember((string) $this->client->getResponse()->headers->get('Location')));
    }

    /** A sentence with its values, as the screen says it. */
    private function saidWith(string $english, string ...$values): string
    {
        return vsprintf($this->said($english), $values);
    }

    /**
     * The hidden fields of a form, as the browser sends them.
     *
     * @return array<string, string>
     */
    private function fieldsOf(Crawler $form): array
    {
        $fields = [];

        foreach ($form->filter('input[type="hidden"]') as $input) {
            \assert($input instanceof \DOMElement);
            $fields[$input->getAttribute('name')] = $input->getAttribute('value');
        }

        return $fields;
    }

    /**
     * The screen as the color form of the screen asks for it, with the values given.
     *
     * @param array<string, string> $values
     */
    private function withColors(Crawler $screen, array $values): Crawler
    {
        $form = $screen->filter('[data-testid="cms-svg-presets"]');
        self::assertSame(1, $form->count());

        return $this->screen((string) $form->attr('action').'?'.http_build_query([...$this->fieldsOf($form), ...$values]));
    }

    /**
     * The screen as the usage form of the screen asks for it, with the usage given.
     */
    private function withUsage(Crawler $screen, string $usage): Crawler
    {
        $form = $screen->filter('[data-testid="cms-svg-usage-form"]');
        self::assertSame(1, $form->count());

        return $this->screen((string) $form->attr('action').'?'.http_build_query([...$this->fieldsOf($form), 'usage' => $usage]));
    }

    private function reloaded(LibraryImage $image): ?LibraryImage
    {
        return LibraryImageQuery::create()->findPk($image->getId());
    }

    private function remember(string $location): string
    {
        $this->tokens[] = $this->tokenOf($location);

        return $location;
    }

    private function tokenOf(string $location): string
    {
        self::assertSame(1, preg_match('#/admin/cms/media/svg/([a-f0-9]{32})#', $location, $matches), $location);

        return $matches[1];
    }

    private function languageId(): int
    {
        return (int) LangQuery::create()->findOneByLocale($this->locale())?->getId();
    }

    private function normalize(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * A sentence as the screens say it in the language of the shop.
     *
     * @param array<string, scalar> $parameters
     */
    private function said(string $english, array $parameters = []): string
    {
        return $this->getService(TranslatorInterface::class)->trans($english, $parameters, TheliaCMS::DOMAIN_NAME, $this->locale());
    }

    private function image(string $title): LibraryImage
    {
        $image = (new LibraryImage())->setFileName(bin2hex(random_bytes(6)).'.jpg')->setWidth(800)->setHeight(600)->setDecorative(0);
        $image->setLocale($this->locale())->setTitle($title)->setAlt('Un texte');
        $image->save();

        $this->getService(CmsMediaLibrary::class)->tag($image);

        return $image;
    }

    private function svg(string $name, string $content): UploadedFile
    {
        $path = $this->temporary();
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, 'image/svg+xml', null, true);
    }

    private function png(string $name): UploadedFile
    {
        $path = $this->temporary();
        imagepng(imagecreatetruecolor(3, 2), $path);

        return new UploadedFile($path, $name, 'image/png', null, true);
    }

    private function temporary(): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'cms-svg-');
        $this->files[] = $path;

        return $path;
    }
}
