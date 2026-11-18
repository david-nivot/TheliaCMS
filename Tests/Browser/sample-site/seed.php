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

/*
 * A sample editorial site for the browser checks of the page list: a cycling
 * magazine about five years old. About 150 pages on four levels (sections,
 * subsections, articles), 6 tags, 20 images of the media library, dates spread
 * from January 2021 to November 2026, drafts, hidden, scheduled pages and pages
 * changed since their publication, part of them translated into English. Written
 * in French, the language of the site; the same on every run (fixed seed).
 *
 * The site around the pages comes with them: the CMS set to a showcase site,
 * the folders and contents of the shop removed (a magazine has only pages), a
 * "news" page template with its fields and its model, the news filed under it,
 * and the main and footer menus filled with sample entries. The entries the two
 * menus held before are replaced.
 *
 * Run from the root of the Thelia install, on its development database:
 *
 *     php local/modules/TheliaCMS/Tests/Browser/sample-site/seed.php [directory]
 *
 * The directory (var/theliacms-sample-site by default) receives manifest.json,
 * the ids of what was made; save.sh and restore.sh keep and put back the site.
 * See "Browser checks" in docs/developing.md.
 */

use App\Kernel;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Thelia\Core\Event\Content\ContentDeleteEvent;
use Thelia\Core\Event\Folder\FolderDeleteEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Model\ContentQuery;
use Thelia\Model\FolderQuery;
use TheliaCMS\Content\Document;
use TheliaCMS\Generation\Brief;
use TheliaCMS\Install\MenuSeeder;
use TheliaCMS\Menu\Admin\MenuItemData;
use TheliaCMS\Menu\MenuTargetType;
use TheliaCMS\Menu\MenuTree;
use TheliaCMS\Model\CmsMenuItemQuery;
use TheliaCMS\Model\CmsMenuQuery;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Page\Admin\PageDraft;
use TheliaCMS\Page\Field\FieldDefinitions;
use TheliaCMS\Page\PageTagColor;
use TheliaCMS\Settings\CmsSettings;
use TheliaCMS\Settings\SiteMode;

if (!is_file('vendor/autoload.php') || !is_file('.env')) {
    fwrite(\STDERR, "Run it from the root of the Thelia install.\n");
    exit(1);
}

require 'vendor/autoload.php';
(new Dotenv())->bootEnv('.env');

$directory = rtrim($argv[1] ?? 'var/theliacms-sample-site', '/');
$manifestPath = $directory.'/manifest.json';

if (is_file($manifestPath)) {
    fwrite(\STDERR, $manifestPath." exists: the site is already in the database. Put it back with restore.sh rather than run this again.\n");
    exit(1);
}

@mkdir($directory, 0o775, true);

$kernel = new Kernel('dev', false);
$kernel->boot();
$container = $kernel->getContainer();

// The writers are private services: each is read, by reflection, from a public
// controller that holds it.
$held = static function (string $public, string $property) use ($container): object {
    $holder = $container->get($public);

    return (new ReflectionProperty($holder, $property))->getValue($holder);
};

$pages = $held(TheliaCMS\Page\Admin\CmsPageAdminController::class, 'writer');
$media = $held(TheliaCMS\Media\Admin\CmsMediaAdminController::class, 'writer');
$tags = $held(TheliaCMS\Page\Admin\CmsTagAdminController::class, 'writer');
$types = $held(TheliaCMS\Settings\Admin\PageTypeAdminController::class, 'writer');
$typeList = $held(TheliaCMS\Settings\Admin\PageTypeAdminController::class, 'pageTypes');
$menus = $held(TheliaCMS\Menu\Admin\CmsMenuAdminController::class, 'writer');

// Rendering reads the request (address of the shop, language): one made up, as on the command line.
$request = Request::create('https://thelia-cms-ai.ddev.site/');
$request->setSession(new Session(new MockArraySessionStorage()));
$container->get('request_stack')->push($request);

// Seeded: the same site on every run.
$random = new Random\Randomizer(new Random\Engine\Mt19937(2021));
$connection = Propel\Runtime\Propel::getConnection('TheliaMain');
$manifest = ['pages' => [], 'tags' => [], 'images' => [], 'image_files' => [], 'models' => [], 'menu_items' => []];

// --- The site ----------------------------------------------------------------

// A magazine sells nothing: the CMS is set as on the settings screen.
TheliaCMS\TheliaCMS::setConfigValue(CmsSettings::SITE_MODE, SiteMode::Showcase->value);
$manifest['site_mode'] = SiteMode::Showcase->value;

// Only pages: the folders and contents of the shop go, through the events of
// the core (their images, documents and addresses with them). The contents
// first, then the folders from the leaves up.
$dispatcher = $container->get('event_dispatcher');
$manifest['removed'] = ['contents' => 0, 'folders' => 0];

foreach (ContentQuery::create()->find() as $content) {
    $dispatcher->dispatch(new ContentDeleteEvent((int) $content->getId()), TheliaEvents::CONTENT_DELETE);
    ++$manifest['removed']['contents'];
}

$leaf = $connection->prepare('SELECT f.id FROM folder f WHERE NOT EXISTS (SELECT 1 FROM folder c WHERE c.parent = f.id) LIMIT 1');

// No row reads as null through the statement of Propel, not false.
while ($leaf->execute() && 0 < $folderId = (int) $leaf->fetchColumn()) {
    $dispatcher->dispatch(new FolderDeleteEvent((int) $folderId), TheliaEvents::FOLDER_DELETE);
    ++$manifest['removed']['folders'];

    if (null !== FolderQuery::create()->findPk((int) $folderId)) {
        throw new RuntimeException('The core kept the folder #'.$folderId);
    }
}

// A template for the news: what the generator is told, two fields, a model.
if (!$typeList->exists('news')) {
    $types->add('news');
}

$newsBrief = new Brief(
    'Une actualité du magazine : un événement, une annonce de la rédaction ou une brève. Ce qui se passe, où, quand, et ce que le lecteur peut en faire.',
    'informatif, enthousiaste sans excès',
    'cyclistes de tous niveaux, lecteurs du magazine',
);
$newsFields = FieldDefinitions::fromArray([
    ['code' => 'event_date', 'kind' => 'date', 'labels' => ['fr_FR' => 'Date de l’événement', 'en_US' => 'Date of the event']],
    ['code' => 'place', 'kind' => 'text', 'translated' => true, 'labels' => ['fr_FR' => 'Lieu', 'en_US' => 'Place']],
]);
$newsInstructions = "- Un chapeau de deux phrases au plus, puis le corps.\n- Dire la date et le lieu dans le chapeau quand la page en a.\n- Finir par ce que le lecteur peut faire : s’inscrire, venir, lire la suite.\n- Pas de FAQ.";
$types->describe('news', 'fr_FR', 'Actualité', 'Événement, annonce ou brève du magazine.', $newsBrief, $newsFields, null, $newsInstructions);
$types->describe('news', 'en_US', 'News', 'An event, an announcement or a short news item of the magazine.', $newsBrief, null, null, $newsInstructions);

if (null === $typeList->modelOf('news')) {
    $model = $pages->createModel('news', 'fr_FR', 'Actualité (modèle)');
    $pages->saveContent($model, 'fr_FR', Document::richText(
        '<h2>Le titre de l’actualité</h2>'
        .'<p><strong>Le chapeau : ce qui se passe, où et quand, en deux phrases.</strong></p>'
        .'<p>Le corps de l’article : le contexte, les détails pratiques, une citation si la rédaction en a une.</p>'
        .'<h2>En pratique</h2>'
        .'<p>Comment participer, s’inscrire ou en savoir plus.</p>'
    ));
    $manifest['models'][] = (int) $model->getId();
}

// --- Tags -------------------------------------------------------------------

$tagIds = [];

foreach ([
    'Test matériel' => '#b45309',
    'Conseil' => '#15803d',
    'Portrait' => '#7c3aed',
    'Événement' => '#be123c',
    'Itinéraire' => '#0369a1',
    'Dossier' => null,
] as $name => $color) {
    $tag = $tags->add($name, null === $color ? null : PageTagColor::of($color));
    $tagIds[$name] = (int) $tag->getId();
    $manifest['tags'][] = (int) $tag->getId();
}

// --- Images -----------------------------------------------------------------

$subjects = [
    ['Vélo de route posé contre un muret en pierre', 'route'],
    ['VTT dans une descente de sentier forestier', 'vtt'],
    ['Vélo électrique de ville devant une boulangerie', 'electrique'],
    ['Casque et gants posés sur une selle', 'casque'],
    ['Chaîne de vélo en gros plan, fraîchement graissée', 'chaine'],
    ['Cycliste réglant la hauteur de sa selle', 'selle'],
    ['Peloton dans un col de montagne', 'col'],
    ['Piste cyclable le long d’un canal', 'canal'],
    ['Sacoches de voyage chargées sur un porte-bagages', 'sacoches'],
    ['Atelier de réparation avec outils au mur', 'atelier'],
    ['Mécanicienne changeant une chambre à air', 'mecanicienne'],
    ['Enfants apprenant à rouler dans un parc', 'enfants'],
    ['Départ d’une course de quartier', 'course'],
    ['Vélo cargo transportant deux enfants', 'cargo'],
    ['Route côtière au coucher du soleil', 'littoral'],
    ['Cycliste sous la pluie en ville', 'pluie'],
    ['Pompe et kit de réparation sur un banc', 'kit'],
    ['Gravel sur un chemin de terre blanche', 'gravel'],
    ['Éclairage avant allumé à la tombée de la nuit', 'eclairage'],
    ['Parking à vélos devant une gare', 'gare'],
];
$palette = [[180, 83, 9], [21, 128, 61], [124, 58, 237], [3, 105, 161], [190, 18, 60], [101, 163, 13], [202, 138, 4], [14, 116, 144]];
$images = [];
$tmp = sys_get_temp_dir().'/theliacms-sample-site-'.getmypid();
@mkdir($tmp);

foreach ($subjects as $index => [$alt, $slug]) {
    [$width, $height] = 0 === $index % 4 ? [1200, 1600] : [1920, 1280];
    $canvas = imagecreatetruecolor($width, $height);
    [$r, $g, $b] = $palette[$index % count($palette)];

    for ($y = 0; $y < $height; ++$y) {
        $shade = $y / $height;
        imageline($canvas, 0, $y, $width, $y, imagecolorallocate($canvas, (int) ($r * (1 - $shade * .6)), (int) ($g * (1 - $shade * .6)), (int) ($b * (1 - $shade * .6))));
    }

    $white = imagecolorallocate($canvas, 255, 255, 255);
    imagefilledellipse($canvas, (int) ($width * .7), (int) ($height * .35), (int) ($width * .35), (int) ($width * .35), imagecolorallocatealpha($canvas, 255, 255, 255, 90));
    imagestring($canvas, 5, 40, $height - 60, mb_convert_encoding($alt, 'ISO-8859-1', 'UTF-8'), $white);

    $path = $tmp.'/'.$slug.'.jpg';
    imagejpeg($canvas, $path, 82);
    imagedestroy($canvas);

    $upload = $media->add([new UploadedFile($path, $slug.'.jpg', 'image/jpeg', null, true)], 'fr_FR');

    if ([] === $upload->stored) {
        throw new RuntimeException('The library refused the image '.$slug);
    }

    $image = $upload->stored[0];

    // Most described in French, a few in English too, two decorative, three forgotten.
    if (in_array($index, [5, 12], true)) {
        $media->describe($image, 'fr_FR', null, true);
    } elseif (!in_array($index, [3, 9, 17], true)) {
        $media->describe($image, 'fr_FR', $alt, false);

        if (0 === $index % 3) {
            $media->describe($image, 'en_US', 'Photo: '.$slug, false);
        }
    }

    $images[] = (int) $image->getId();
    $manifest['images'][] = (int) $image->getId();
    $manifest['image_files'][] = (string) $image->getFileName();
}

// --- Pages ------------------------------------------------------------------

$start = new DateTimeImmutable('2021-01-11 09:00:00');
$end = new DateTimeImmutable('2026-11-05 18:00:00');

/** A date between two others, at a working hour. */
$between = static function (DateTimeImmutable $from, DateTimeImmutable $to) use ($random): DateTimeImmutable {
    $date = (new DateTimeImmutable())->setTimestamp($random->getInt($from->getTimestamp(), max($from->getTimestamp(), $to->getTimestamp())));

    return $date->setTime($random->getInt(8, 18), $random->getInt(0, 59), $random->getInt(0, 59));
};

$paragraphs = [
    'Nous avons roulé plusieurs centaines de kilomètres avant d’écrire ces lignes, sur route comme sur chemin.',
    'Le secret tient en peu de choses : un vélo bien réglé, des pneus au bon gonflage et l’envie de sortir.',
    'Nos lecteurs nous ont écrit nombreux après la première version de cet article ; nous l’avons complété de leurs retours.',
    'Comptez une petite heure du début à la fin, et gardez un œil sur la météo avant de partir.',
    'Chaque pratique a ses habitudes : celle-ci est la nôtre, et nous ne prétendons pas qu’elle soit la seule.',
    'Les variantes sont nombreuses ; nous en proposons trois en fin d’article, de la plus simple à la plus sportive.',
];

/**
 * Creates one page and returns it.
 *
 * @param array{parent?: CmsPage|null, tag?: string|null, image?: bool, created?: DateTimeImmutable, english?: bool, status?: string, intro?: string, position?: int, type?: string, fields?: array<string, string>} $options
 */
$page = static function (string $title, array $options = []) use ($pages, $images, $tagIds, $paragraphs, $between, $end, $connection, $random, &$manifest): CmsPage {
    $created = $options['created'] ?? new DateTimeImmutable('2021-01-11 09:00:00');
    $status = $options['status'] ?? 'online';
    $imageId = ($options['image'] ?? false) ? $images[$random->getInt(0, count($images) - 1)] : null;

    $row = (new CmsPage())
        ->setParent(null === ($options['parent'] ?? null) ? 0 : (int) $options['parent']->getId())
        ->setPosition(0)
        ->setVisible('hidden' === $status ? 0 : 1)
        ->setPageType($options['type'] ?? 'default')
        ->setTagId(null === ($options['tag'] ?? null) ? null : $tagIds[$options['tag']])
        ->setImageId($imageId);

    $pages->saveDraft($row, 'fr_FR', new PageDraft(title: $title, fields: $options['fields'] ?? null));

    $body = '<h2>'.htmlspecialchars($title).'</h2><p>'.htmlspecialchars($options['intro'] ?? $paragraphs[$random->getInt(0, count($paragraphs) - 1)]).'</p>';

    if (null !== $imageId && 0 === $random->getInt(0, 1)) {
        // As a component of a theme prints it: no text, the library gives it.
        $body .= sprintf('<img src="/image-library/%d/full/max/0/default.jpg" alt="">', $imageId);
    }

    foreach ($random->pickArrayKeys($paragraphs, 2) as $paragraph) {
        $body .= '<p>'.htmlspecialchars($paragraphs[$paragraph]).'</p>';
    }

    $pages->saveContent($row, 'fr_FR', Document::richText($body));

    $published = $created->modify('+'.$random->getInt(0, 3).' days');
    $modified = $published;

    if ('draft' !== $status) {
        $pages->publish($row, 'fr_FR');

        if ('modified' === $status) {
            // Published, then worked on again: the list says "changed since".
            $pages->saveContent($row, 'fr_FR', Document::richText($body.'<p>Mise à jour : nous avons ajouté une variante proposée par une lectrice.</p>'));
            $modified = $between($published->modify('+1 week'), $end);
        }
    }

    if ($options['english'] ?? false) {
        $pages->saveDraft($row, 'en_US', new PageDraft(title: 'EN · '.$title));
        $pages->saveContent($row, 'en_US', Document::richText('<h2>'.htmlspecialchars('EN · '.$title).'</h2><p>English version of the article, written later.</p>'));

        if ('draft' !== $status) {
            $pages->publish($row, 'en_US');
        }
    }

    if ('scheduled' === $status) {
        $row->setPublishAt(new DateTime('2026-12-01 08:00:00'));
    }

    // Before the dates: a save stamps the row with today.
    $row->setPosition($options['position'] ?? 1)->save();

    // The dates of a site that has lived: the pipeline stamps "now", put back
    // when the page was really written.
    $id = (int) $row->getId();
    $connection->prepare('UPDATE cms_page SET created_at = :created, updated_at = :modified WHERE id = :id')
        ->execute(['created' => $created->format('Y-m-d H:i:s'), 'modified' => $modified->format('Y-m-d H:i:s'), 'id' => $id]);
    $connection->prepare('UPDATE cms_page_content SET created_at = :created, updated_at = :modified, published_at = IF(published_at IS NULL, NULL, :published) WHERE page_id = :id')
        ->execute(['created' => $created->format('Y-m-d H:i:s'), 'modified' => $modified->format('Y-m-d H:i:s'), 'published' => $published->format('Y-m-d H:i:s'), 'id' => $id]);

    $manifest['pages'][] = $id;

    return $row;
};

/** A status drawn the way an editorial site has them: mostly online. */
$someStatus = static function () use ($random): string {
    $draw = $random->getInt(1, 100);

    return match (true) {
        $draw <= 6 => 'draft',
        $draw <= 10 => 'hidden',
        $draw <= 18 => 'modified',
        default => 'online',
    };
};

/**
 * @param array{type?: string, fields?: callable(string, DateTimeImmutable): array<string, string>} $extra
 */
$articles = static function (CmsPage $parent, array $titles, string $tag, DateTimeImmutable $from, DateTimeImmutable $to, array $extra = []) use ($page, $between, $someStatus, $random): void {
    $dated = [];

    foreach ($titles as $title) {
        $dated[] = [$title, $between($from, $to)];
    }

    usort($dated, static fn (array $one, array $other): int => $one[1] <=> $other[1]);

    foreach ($dated as $rank => [$title, $created]) {
        // The draws in the same order as before: the type and the fields draw nothing.
        $options = ['parent' => $parent, 'tag' => 0 === $random->getInt(0, 5) ? 'Dossier' : $tag, 'image' => 0 !== $random->getInt(0, 4), 'created' => $created, 'english' => 0 === $random->getInt(0, 3), 'status' => $someStatus(), 'position' => $rank + 1];

        if (isset($extra['type'])) {
            $options['type'] = $extra['type'];
        }

        if (isset($extra['fields'])) {
            $options['fields'] = $extra['fields']($title, $created);
        }

        $page($title, $options);
    }
};

// The fields of a news page, read from its title and date rather than drawn.
$places = ['Nantes', 'Lyon', 'Strasbourg', 'Annecy', 'Bordeaux', 'Lille', 'Grenoble', 'Rennes'];
$asNews = ['type' => 'news', 'fields' => static fn (string $title, DateTimeImmutable $created): array => [
    'event_date' => $created->modify('+3 weeks')->format('Y-m-d'),
    'place' => $places[crc32($title) % count($places)],
]];
$asShortNews = ['type' => 'news'];

$rubric = static fn (string $title, ?CmsPage $parent, int $rank, DateTimeImmutable $created): CmsPage => $page($title, ['parent' => $parent, 'created' => $created, 'english' => true, 'intro' => 'Tous nos articles de la rubrique « '.$title.' », du plus ancien au plus récent.', 'position' => $rank]);

$t2021 = new DateTimeImmutable('2021-01-11 09:00:00');

$magazine = $rubric('Magazine', null, 10, $t2021);
$gear = $rubric('Matériel', $magazine, 1, $t2021);
$advice = $rubric('Conseils', $magazine, 2, $t2021->modify('+2 days'));
$portraits = $rubric('Portraits', $magazine, 3, new DateTimeImmutable('2022-03-14 10:00:00'));

$articles($rubric('Vélos de route', $gear, 1, $t2021), ['Essai : un vélo de route en acier pour les longues distances', 'Carbone ou aluminium : que choisir ?', 'Freins à disque sur route, le bilan', 'Les pneus de 28 mm sont-ils devenus la norme ?', 'Essai d’un vélo d’endurance à moins de 1 500 €', 'Groupes mécaniques ou électriques', 'Roues hautes : pour qui ?', 'Notre sélection de vélos de route pour débuter'], 'Test matériel', $t2021, $end);
$articles($rubric('VTT et gravel', $gear, 2, $t2021), ['Essai : un gravel polyvalent pour l’aventure', 'Semi-rigide ou tout-suspendu ?', 'Les pneus tubeless expliqués', 'Notre test de tiges de selle télescopiques', 'Le VTT de descente à la portée de tous ?', 'Choisir ses pédales automatiques', 'Gravel : les sacoches de cadre comparées', 'Essai d’un VTT pour enfant'], 'Test matériel', $t2021, $end);
$articles($rubric('Vélos électriques', $gear, 3, new DateTimeImmutable('2021-06-07 09:00:00')), ['Moteur pédalier ou moteur dans la roue', 'Essai d’un vélo cargo électrique', 'Autonomie : ce que disent vraiment les chiffres', 'Entretenir sa batterie en hiver', 'Le vélo pliant électrique au quotidien', 'Speed bike : ce que dit la loi', 'Essai : un VTT électrique léger', 'Aides à l’achat : le point'], 'Test matériel', new DateTimeImmutable('2021-06-07'), $end);
$articles($rubric('Équipement', $gear, 4, $t2021), ['Bien choisir son casque', 'Les vestes imperméables testées sous la pluie', 'Éclairages : notre comparatif', 'Antivols : lesquels résistent vraiment ?', 'Cuissards : le guide des tailles', 'Compteurs GPS comparés', 'Porte-bébé ou remorque ?', 'Gants d’hiver : notre test'], 'Test matériel', $t2021, $end);

$articles($rubric('Entretien', $advice, 1, $t2021), ['Réparer une crevaison en dix minutes', 'Nettoyer et graisser sa chaîne', 'Régler son dérailleur arrière', 'Changer ses plaquettes de frein', 'Vérifier l’usure de sa transmission', 'Purger des freins hydrauliques', 'Préparer son vélo pour l’hiver', 'La trousse à outils minimale'], 'Conseil', $t2021, $end);
$articles($rubric('Entraînement', $advice, 2, new DateTimeImmutable('2021-09-20 09:00:00')), ['Préparer sa première cyclosportive', 'Rouler en côte sans s’épuiser', 'Le fractionné expliqué simplement', 'Bien s’alimenter pendant une sortie longue', 'Récupérer après l’effort', 'S’entraîner l’hiver sur home trainer'], 'Conseil', new DateTimeImmutable('2021-09-20'), $end);
$articles($rubric('Sécurité', $advice, 3, new DateTimeImmutable('2022-04-04 09:00:00')), ['Rouler en ville : les bons réflexes', 'Être vu la nuit', 'Le sas vélo aux feux', 'Rouler en groupe sans danger', 'Apprendre le vélo à un enfant'], 'Conseil', new DateTimeImmutable('2022-04-04'), $end);
$articles($portraits, ['Rencontre avec une mécanicienne d’atelier associatif', 'Le cadreur qui soude encore à la main', 'Une ultra-cycliste entre deux traversées', 'Trois générations de marchands de cycles', 'La coursière à vélo qui forme les nouveaux', 'Le randonneur qui a fait le tour de France des cols'], 'Portrait', new DateTimeImmutable('2022-03-14'), $end);

$news = $rubric('Actualités', null, 11, $t2021);
$events = [
    2021 => ['Lancement du magazine', 'Notre premier atelier réparation', 'Bilan de la saison des courses'],
    2022 => ['Le magazine fête ses un an', 'Fête du vélo au printemps', 'Nouvelle rubrique Portraits', 'Nouvelles pistes cyclables en ville'],
    2023 => ['Nos essais en vidéo', 'Rencontre lecteurs à Nantes', 'Le grand rassemblement gravel', 'Partenariat avec un atelier solidaire'],
    2024 => ['Cinq mille abonnés à la lettre', 'Le salon du cycle vu par la rédaction', 'Sortie lecteurs dans les Vosges', 'Nouvelle formule de la lettre'],
    2025 => ['Les gagnants du concours photo 2025', 'Un guide des itinéraires en préparation', 'Visite d’une usine de cadres', 'Rencontre avec nos lecteurs belges'],
    2026 => ['Le guide des itinéraires est sorti', 'Cinq ans du magazine', 'Programme des sorties d’automne'],
];

foreach ($events as $year => $titles) {
    $yearPage = $rubric((string) $year, $news, 2027 - $year, new DateTimeImmutable($year.'-01-05 09:00:00'));
    $articles($yearPage, $titles, 'Événement', new DateTimeImmutable($year.'-01-06'), min($end, new DateTimeImmutable($year.'-12-20')), $asNews);
}

// A section larger than a branch of the tree shows: the tree leads to the list of all of them.
$shortNews = $rubric('Brèves', $news, 7, $t2021);
$articles($shortNews, [
    'Un nouveau tronçon de voie verte ouvre en Bretagne', 'La prime au vélo cargo reconduite', 'Le salon du vélo ancien revient en mars',
    'Une piste cyclable suspendue à l’étude', 'Le prix des pneus en hausse cette année', 'Ouverture d’un atelier solidaire en centre-ville',
    'Le tour cyclotouriste des lacs complet en deux jours', 'Un record de passages au compteur du pont', 'La fête du vélo change de date',
    'Les vélos en libre-service passent à l’électrique', 'Un parking vélo sécurisé à la gare', 'Le marquage obligatoire des vélos d’occasion',
    'Une course de nuit à travers la ville', 'Le gravel entre au programme des fédérations', 'La rentrée à vélo pour les collégiens',
    'Des stations de gonflage en libre accès', 'Un col fermé aux voitures le dimanche', 'Les ventes de vélos électriques ralentissent',
    'Une application pour signaler les nids-de-poule', 'Le casque recommandé pour les moins de douze ans', 'Une boucle de 300 km balisée dans le Jura',
    'Le vélo-école ouvre ses inscriptions', 'Le prix des antivols connectés baisse', 'Les trains acceptent plus de vélos cet été',
    'Un nouveau record de l’heure amateur', 'La bourse aux vélos de printemps', 'Les rues scolaires se multiplient',
    'Le réseau express vélo s’étend', 'Une exposition sur l’histoire du cycle', 'Le baromètre des villes cyclables publié',
], 'Événement', $t2021, $end, $asShortNews);

$guides = $rubric('Guides', null, 12, new DateTimeImmutable('2022-01-10 09:00:00'));
$starting = $rubric('Bien débuter', $guides, 1, new DateTimeImmutable('2022-01-10 09:00:00'));
$articles($starting, ['Choisir la taille de son cadre', 'Les réglages de base avant de partir', 'Le vocabulaire du cycliste', 'Le vélotaf, mode d’emploi'], 'Dossier', new DateTimeImmutable('2022-01-10'), $end);
$routes = $rubric('Itinéraires', $guides, 2, new DateTimeImmutable('2022-01-10 09:00:00'));

foreach (['Montagne' => ['Trois cols des Alpes pour débuter', 'Les Vosges en deux jours'], 'Littoral' => ['La Vélodyssée en famille', 'Le tour d’une île à vélo'], 'Vélotourisme' => ['Partir une semaine en sacoches'], 'Ville' => ['Traverser Paris par les berges', 'Lyon à vélo en un week-end']] as $rank => $titles) {
    $articles($rubric($rank, $routes, array_search($rank, ['Montagne', 'Littoral', 'Vélotourisme', 'Ville'], true) + 1, new DateTimeImmutable('2022-01-10 09:00:00')), $titles, 'Itinéraire', new DateTimeImmutable('2022-01-10'), $end);
}

$team = $page('La rédaction', ['created' => $t2021, 'english' => true, 'position' => 13]);
$contact = $page('Nous écrire', ['created' => $t2021, 'english' => true, 'position' => 14]);
$page('Archives de l’ancien blog', ['created' => $t2021, 'status' => 'hidden', 'position' => 15]);
$page('Le guide des itinéraires : précommande', ['created' => new DateTimeImmutable('2026-10-28 10:00:00'), 'status' => 'scheduled', 'tag' => 'Événement', 'image' => true, 'position' => 16]);

// --- Menus ------------------------------------------------------------------

// The two menus of the module, filled: as soon as one holds an entry, it takes
// the header (main) or footer (footer) links of the theme over. An entry to a
// page has no label of its own: the title of the page is shown, in each language.
// Any other entry is labelled in French and English: an entry left without a
// label in the language read is left out. Flexy prints the first level of the
// footer menu only, so that one stays flat.
(new MenuSeeder())->seed();

/** Empties a shared menu, then files its entries: [type, target, labels by locale, url, children]. */
$fill = static function (string $code, array $entries) use ($menus, &$manifest): void {
    $menu = CmsMenuQuery::findShared($code) ?? throw new RuntimeException('No shared menu "'.$code.'".');

    foreach (CmsMenuItemQuery::create()->filterByMenuId($menu->getId())->filterByParent(MenuTree::ROOT)->find() as $item) {
        $menus->deleteItem($menu, $item);
    }

    $file = static function (array $entries, int $parent) use (&$file, $menus, $menu, &$manifest): void {
        foreach ($entries as [$type, $target, $labels, $url, $children]) {
            $item = null;

            foreach ($labels ?? ['fr_FR' => null] as $locale => $label) {
                $item = $menus->saveItem($menu, $item, $locale, new MenuItemData(
                    type: $type,
                    targetId: null === $target ? null : (int) $target->getId(),
                    url: $url,
                    label: $label,
                    parent: $parent,
                    openNewTab: MenuTargetType::Url === $type,
                ));
            }

            $manifest['menu_items'][] = (int) $item->getId();
            $file($children, (int) $item->getId());
        }
    };

    $file($entries, MenuTree::ROOT);
};

$toPage = static fn (CmsPage $target, array $children = []): array => [MenuTargetType::Page, $target, null, null, $children];

// The legal pages the module seeded on its activation, when the host still has them.
$legal = static function (string $title) use ($connection): ?CmsPage {
    $statement = $connection->prepare('SELECT p.id FROM cms_page p JOIN cms_page_i18n i ON i.id = p.id AND i.locale = \'fr_FR\' WHERE p.parent = 0 AND p.model = 0 AND p.deleted_at IS NULL AND i.title = :title ORDER BY p.id LIMIT 1');
    $statement->execute(['title' => $title]);
    $id = (int) $statement->fetchColumn();

    return 0 === $id ? null : TheliaCMS\Model\CmsPageQuery::create()->findPk($id);
};
$legalPages = array_values(array_filter(array_map($legal, ['Mentions légales', 'Politique de confidentialité', 'Cookies', 'Déclaration d’accessibilité'])));

// Seeded as drafts whose sample text the module refuses to publish: an entry to
// them would wait in the menu, "to fix". A text of the magazine replaces it.
foreach ($legalPages as $legalPage) {
    foreach (['fr_FR' => 'Texte de démonstration du magazine, écrit pour le jeu de test : il ne vaut pas mention légale.', 'en_US' => 'Demonstration text of the magazine, written for the sample site: it is not a legal notice.'] as $locale => $text) {
        $pages->saveContent($legalPage, $locale, Document::richText('<p>'.htmlspecialchars($text).'</p>'));
        $pages->publish($legalPage, $locale);
    }
}

$legalEntries = array_map($toPage, $legalPages);

$fill('main', [
    $toPage($magazine, [$toPage($gear), $toPage($advice), $toPage($portraits)]),
    $toPage($news, [$toPage($shortNews)]),
    $toPage($guides, [$toPage($starting), $toPage($routes)]),
    $toPage($team),
]);
$fill('footer', [
    $toPage($team),
    $toPage($contact),
    [MenuTargetType::Url, null, ['fr_FR' => 'La lettre d’information', 'en_US' => 'Newsletter'], 'https://example.com/lettre', []],
    ...$legalEntries,
]);

file_put_contents($manifestPath, json_encode($manifest, \JSON_PRETTY_PRINT));
printf("%d pages, %d tags, %d images, a news template, %d menu entries; %d contents and %d folders of the shop removed; showcase site. Ids in %s\n", count($manifest['pages']), count($manifest['tags']), count($manifest['images']), count($manifest['menu_items']), $manifest['removed']['contents'], $manifest['removed']['folders'], $manifestPath);
