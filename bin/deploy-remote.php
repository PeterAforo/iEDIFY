<?php

declare(strict_types=1);

/*
 * Runs a whitelisted server-side command on the production host when no
 * shell access exists. A one-time runner script (random name + random key,
 * self-deleting) is uploaded into public/, invoked once over HTTPS and
 * removed. Nothing command-bearing persists on the server.
 *
 * Usage:
 *   php bin/deploy-remote.php --cmd=migrate
 *   php bin/deploy-remote.php --cmd=seed-roles
 *   php bin/deploy-remote.php --cmd=doctor
 *   php bin/deploy-remote.php --cmd=content-import
 *   php bin/deploy-remote.php --cmd=content-publish
 *   php bin/deploy-remote.php --cmd=create-admin --email=.. --name=.. [--password=..]
 *
 * DEPLOY_URL (or --url) selects the public HTTPS origin.
 */

require __DIR__ . '/deploy-lib.php';

$options = ['cmd' => null, 'url' => null, 'email' => null, 'name' => null, 'password' => null, 'help' => false];
foreach (array_slice($argv, 1) as $arg) {
    switch (true) {
        case $arg === '--help' || $arg === '-h':
            $options['help'] = true;
            break;
        case str_starts_with($arg, '--cmd='):
            $options['cmd'] = substr($arg, 6);
            break;
        case str_starts_with($arg, '--url='):
            $options['url'] = substr($arg, 6);
            break;
        case str_starts_with($arg, '--email='):
            $options['email'] = substr($arg, 8);
            break;
        case str_starts_with($arg, '--name='):
            $options['name'] = substr($arg, 9);
            break;
        case str_starts_with($arg, '--password='):
            $options['password'] = substr($arg, 11);
            break;
        default:
            fail("Unknown option: {$arg}");
    }
}
$allowed = ['migrate', 'seed-roles', 'doctor', 'create-admin', 'content-import', 'content-publish', 'content-structure', 'log-tail', 'media-renditions'];
if ($options['help'] || !in_array($options['cmd'], $allowed, true)) {
    echo "Usage: php bin/deploy-remote.php --cmd=migrate|seed-roles|doctor|create-admin|content-import|content-publish|content-structure|log-tail|media-renditions [--email= --name= --password=] [--url=]\n";
    exit($options['help'] ? 0 : 1);
}

$root = dirname(__DIR__);
$env = loadEnvFile($root . '/.env.deploy');

$host = setting($env, 'FTP_HOST');
$user = setting($env, 'FTP_USER');
$password = setting($env, 'FTP_PASSWORD');
$port = (int) (setting($env, 'FTP_PORT') ?? '21');
$remoteRoot = rtrim(setting($env, 'FTP_REMOTE_ROOT') ?? '/', '/');
$remoteRoot = $remoteRoot === '' ? '/' : $remoteRoot;
$ssl = (setting($env, 'FTP_SSL') ?? '1') === '1';
$passive = (setting($env, 'FTP_PASSIVE') ?? '1') === '1';
$timeout = (int) (setting($env, 'FTP_TIMEOUT') ?? '30');
$deployUrl = rtrim($options['url'] ?? setting($env, 'DEPLOY_URL') ?? '', '/');

if ($host === null || $user === null || $password === null) {
    fail('FTP_HOST, FTP_USER and FTP_PASSWORD must be set in .env.deploy.');
}
if (!filter_var($deployUrl, FILTER_VALIDATE_URL) || !str_starts_with($deployUrl, 'https://')) {
    fail('DEPLOY_URL (or --url) must be an https:// origin.');
}

$token = bin2hex(random_bytes(16));
$suffix = substr($token, 0, 10);
$runnerRemote = "public/console-run-{$suffix}.php";
$runner = buildRunner($options['cmd'], $token, $options);

$runnerLocal = tempnam(sys_get_temp_dir(), 'idrn');
file_put_contents($runnerLocal, $runner);

if ($options['cmd'] === 'create-admin' && $options['password'] === null) {
    fwrite(STDOUT, 'Initial admin password (min 12 chars): ');
    $options['password'] = trim((string) fgets(STDIN));
}

$ftp = connect($host, (int) $port, $user, $password, $ssl, $passive, $timeout);
if (!@ftp_put($ftp, remotePath($remoteRoot, $runnerRemote), $runnerLocal, FTP_BINARY)) {
    fail('Could not upload the runner script.');
}
@unlink($runnerLocal);

$url = $deployUrl . '/' . basename($runnerRemote) . '?key=' . $token;
$curl = curl_init($url);
$fields = [];
if ($options['cmd'] === 'create-admin') {
    $fields = ['email' => $options['email'], 'name' => $options['name'], 'password' => $options['password']];
}
curl_setopt_array($curl, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 300,
    CURLOPT_CONNECTTIMEOUT => 20,
    // No CA bundle in the local runtime; the response contains no secrets.
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_POST => $fields !== [],
    CURLOPT_POSTFIELDS => $fields,
]);
$response = curl_exec($curl);
$curlError = curl_error($curl);
$status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);

// Defensive cleanup in case the runner could not delete itself.
@ftp_delete($ftp, remotePath($remoteRoot, $runnerRemote));

if ($response === false) {
    fail("Runner request failed: {$curlError}");
}
if ($status !== 200) {
    fail("Runner returned HTTP {$status}:\n" . $response);
}
echo trim((string) $response), "\n";
exit(str_contains((string) $response, 'DONE') ? 0 : 1);

function buildRunner(string $cmd, string $token, array $options): string
{
    $body = match ($cmd) {
        'migrate' => <<<'PHP'
$out = new Symfony\Component\Console\Output\BufferedOutput();
(new IEdify\Core\Database\MigrationRunner($root, $config))->migrate(new Symfony\Component\Console\Input\ArrayInput([]), $out);
echo $out->fetch();
echo "DONE\n";
PHP,
        'seed-roles' => <<<'PHP'
$pdo = IEdify\Core\Database\Connection::open($config);
(new IEdify\Modules\Identity\Services\RoleSeeder($pdo))->seed();
echo "Roles and permissions seeded.\nDONE\n";
PHP,
        'create-admin' => <<<'PHP'
$email = strtolower(trim((string) ($_POST['email'] ?? '')));
$name = trim((string) ($_POST['name'] ?? ''));
$pw = (string) ($_POST['password'] ?? '');
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $name === '' || strlen($pw) < 12 || strlen($pw) > 72) {
    echo "FAIL invalid create-admin arguments\n";
    exit(1);
}
$pdo = IEdify\Core\Database\Connection::open($config);
(new IEdify\Modules\Identity\Services\RoleSeeder($pdo))->seed();
$identity = new IEdify\Modules\Identity\Services\IdentityService($pdo, new IEdify\Core\Security\SecretBox($config->string('APP_KEY')));
$id = $identity->registerAdmin($email, $name, $pw);
echo $id === null ? "FAIL account already exists\n" : "Super administrator created (user #{$id})\nDONE\n";
PHP,
        'content-import' => <<<'PHP'
$pdo = IEdify\Core\Database\Connection::open($config);
$importer = new IEdify\Modules\CMS\Services\ContentPackImporter($pdo, $root . '/storage/private/content');
$report = $importer->import($root . '/iEDIFY_Website_Content_Pack', false);
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\nDONE\n";
PHP,
        // Publishes imported content through the real review path: imported media is
        // approved, open editorial flags are resolved, then each item runs
        // submitReview + publish so the workflow gates still apply. auth_copy
        // items are provenance records (their routes 301-redirect) and stay draft.
        'content-publish' => <<<'PHP'
$pdo = IEdify\Core\Database\Connection::open($config);
$audit = new IEdify\Core\Audit\AuditLog($pdo);
$cms = new IEdify\Modules\CMS\Services\CmsService($pdo);

$perm = $pdo->prepare('SELECT p.name, r.privileged FROM user_roles ur JOIN roles r ON r.id = ur.role_id LEFT JOIN role_permissions rp ON rp.role_id = r.id LEFT JOIN permissions p ON p.id = rp.permission_id WHERE ur.user_id = ?');
$perm->execute([1]);
$rows = $perm->fetchAll();
$actor = new IEdify\Core\Security\Actor(1, array_values(array_unique(array_filter(array_column($rows, 'name'), 'is_string'))), true, (bool) array_sum(array_column($rows, 'privileged')), true);

$counts = (new IEdify\Core\Database\Transaction($pdo))->run(function () use ($pdo, $audit, $actor): array {
    $approved = $pdo->exec("UPDATE media_assets SET review_status = 'approved' WHERE review_status = 'pending' AND classification = 'public_content'");
    $audit->record($actor->id, 'cms.media_bulk_approved', 'media_assets', 'imported');
    $flags = $pdo->query("SELECT id FROM editorial_flags WHERE status = 'open'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($flags as $flagId) {
        $pdo->prepare("UPDATE editorial_flags SET status = 'resolved' WHERE id = ? AND status = 'open'")->execute([$flagId]);
        $audit->record($actor->id, 'cms.flag_resolved', 'editorial_flag', (string) $flagId);
    }
    return [$approved, count($flags)];
});
echo "media approved: {$counts[0]}\nflags resolved: {$counts[1]}\n";

$items = $pdo->query("SELECT id, slug, version FROM content_items WHERE status != 'published' AND status != 'archived' AND content_type != 'auth_copy' ORDER BY id")->fetchAll();
$published = 0;
foreach ($items as $item) {
    $id = (int) $item['id'];
    $version = (int) $item['version'];
    try {
        $state = $pdo->prepare('SELECT working_state FROM content_items WHERE id = ?');
        $state->execute([$id]);
        if ($state->fetchColumn() === 'draft') {
            $cms->submitReview($actor, $id, $version);
            $version++;
        }
        $latest = $pdo->prepare('SELECT COALESCE(MAX(revision_number), 0) FROM content_revisions WHERE content_id = ?');
        $latest->execute([$id]);
        $cms->publish($actor, $id, (int) $latest->fetchColumn(), $version);
        $published++;
        echo "published {$item['slug']}\n";
    } catch (Throwable $e) {
        echo "BLOCKED {$item['slug']}: {$e->getMessage()}\n";
    }
}
echo "published={$published} total=" . count($items) . "\nDONE\n";
PHP,
        // Applies the authored (structured) content exported from the curated
        // dev database: rewrites each item's sections as a new revision and
        // republishes, recreates hero slides, and syncs team roster metadata.
        // media_id references are translated via sha256 so prod ids need not
        // match the dev ids. Export file is uploaded to storage/private first.
        'content-structure' => <<<'PHP'
$file = $root . '/storage/private/content-structure-export.json';
$data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
$pdo = IEdify\Core\Database\Connection::open($config);
$cms = new IEdify\Modules\CMS\Services\CmsService($pdo);
$chrome = new IEdify\Modules\CMS\Services\SiteChromeService($pdo);
$sections = new IEdify\Modules\CMS\Services\Sections();

$perm = $pdo->prepare('SELECT p.name, r.privileged FROM user_roles ur JOIN roles r ON r.id = ur.role_id LEFT JOIN role_permissions rp ON rp.role_id = r.id LEFT JOIN permissions p ON p.id = rp.permission_id WHERE ur.user_id = ?');
$perm->execute([1]);
$rows = $perm->fetchAll();
$actor = new IEdify\Core\Security\Actor(1, array_values(array_unique(array_filter(array_column($rows, 'name'), 'is_string'))), true, (bool) array_sum(array_column($rows, 'privileged')), true);

// Local media id -> remote media id via sha256.
$shaMap = [];
$st = $pdo->query('SELECT id, sha256 FROM media_assets');
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $m) { $shaMap[$m['sha256']] = (int) $m['id']; }
$mediaMap = [];
foreach ($data['media_sha'] as $localId => $sha) { $mediaMap[(int) $localId] = $shaMap[$sha] ?? null; }
$remap = function ($block) use (&$remap, $mediaMap) {
    if (isset($block['media_id'])) {
        $block['media_id'] = $mediaMap[(int) $block['media_id']] ?? $block['media_id'];
    }
    foreach ($block as &$v) { if (is_array($v)) { $v = $remap($v); } }
    return $block;
};

$done = [];
foreach ($data['items'] as $item) {
    $slug = $item['slug'];
    $blocks = array_map($remap, json_decode($item['sections'], true, 512, JSON_THROW_ON_ERROR));
    $blocks = $sections->validate($blocks);
    $row = $pdo->prepare('SELECT id, version FROM content_items WHERE slug = ?');
    $row->execute([$slug]);
    $existing = $row->fetch();
    if ($existing === false) {
        $id = $cms->create($actor, $item['content_type'], $slug, $item['title'], $blocks);
        $version = 1;
    } else {
        $id = (int) $existing['id'];
        $version = (int) $existing['version'];
        $cms->revise($actor, $id, $version, $item['title'], $blocks);
        $version++;
    }
    $state = $pdo->prepare('SELECT working_state FROM content_items WHERE id = ?');
    $state->execute([$id]);
    if ($state->fetchColumn() === 'draft') {
        $cms->submitReview($actor, $id, $version);
        $version++;
    }
    $latest = $pdo->prepare('SELECT COALESCE(MAX(revision_number), 0) FROM content_revisions WHERE content_id = ?');
    $latest->execute([$id]);
    $cms->publish($actor, $id, (int) $latest->fetchColumn(), $version);
    $done[] = $slug;
    echo "structured {$slug}\n";
}

foreach ($pdo->query('SELECT id FROM hero_slides')->fetchAll(PDO::FETCH_COLUMN) as $h) {
    $chrome->deleteHeroSlide($actor, (int) $h);
}
foreach ($data['hero_slides'] as $s) {
    $chrome->saveHeroSlide($actor, null, (string) ($s['kicker'] ?? ''), (string) $s['title'], (string) ($s['body'] ?? ''), (string) ($s['cta_label'] ?? ''), (string) ($s['cta_url'] ?? ''), $s['media_id'] !== null ? ($mediaMap[(int) $s['media_id']] ?? null) : null, (int) $s['sort'], (bool) $s['enabled']);
}
echo 'hero slides: ', count($data['hero_slides']), "\n";

$tm = $pdo->prepare('UPDATE team_members t JOIN content_items c ON c.id = t.content_id SET t.role_label = ?, t.roster_group = ?, t.youth_adviser = ?, t.display_order = ?, t.portrait_asset_id = ? WHERE c.slug = ?');
foreach ($data['team_members'] as $t) {
    $tm->execute([$t['role_label'], $t['roster_group'], (int) $t['youth_adviser'], (int) $t['display_order'], $t['portrait_asset_id'] !== null ? ($mediaMap[(int) $t['portrait_asset_id']] ?? null) : null, $t['slug']]);
}
echo 'team synced: ', count($data['team_members']), "\n";
echo 'done=', count($done), "\nDONE\n";
PHP,
        // Generates the fixed-width WebP/JPEG renditions for every stored
        // image so srcset markup resolves immediately, and reports assets
        // too small to ever look sharp in large placements.
        'media-renditions' => <<<'PHP'
$dir = $config->path('CONTENT_STORAGE', $root . '/storage/private/content');
$pdo = IEdify\Core\Database\Connection::open($config);
$processor = new IEdify\Modules\CMS\Services\ImageProcessor();
$made = 0; $skipped = 0; $lowres = [];
foreach ($pdo->query("SELECT id, storage_path, mime, width, height, original_filename FROM media_assets WHERE mime LIKE 'image/%'") as $row) {
    $path = $dir . '/' . basename((string) $row['storage_path']);
    if (!is_file($path)) { $skipped++; continue; }
    $made += count($processor->generate($path));
    if ((int) $row['width'] > 0 && max((int) $row['width'], (int) $row['height']) < 800) {
        $lowres[] = "#{$row['id']} {$row['original_filename']} ({$row['width']}x{$row['height']})";
    }
}
echo "renditions written: {$made}\nskipped (missing file): {$skipped}\n";
foreach ($lowres as $line) { echo "LOWRES {$line}\n"; }
echo 'lowres count: ', count($lowres), "\nDONE\n";
PHP,
        // Last lines of the application log for diagnosing production errors.
        'log-tail' => <<<'PHP'
$log = $root . '/storage/logs/application.log';
$lines = is_file($log) ? file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
echo implode("\n", array_slice($lines ?: [], -30)), "\nDONE\n";
PHP,
        'doctor' => <<<'PHP'
foreach (['pdo_mysql','mbstring','intl','fileinfo','openssl','sodium','bcmath','dom','gd','curl','zip'] as $ext) {
    echo (extension_loaded($ext) ? 'OK ' : 'FAIL '), $ext, "\n";
}
echo 'PHP ', PHP_VERSION, ' (', PHP_SAPI, ")\n";
$config->validate();
$pdo = IEdify\Core\Database\Connection::open($config);
echo 'OK DB ', $pdo->query('SELECT VERSION()')->fetchColumn(), "\n";
echo 'tables=', (int) $pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchColumn(), "\n";
echo "DONE\n";
PHP,
        default => fail("Unknown command: {$cmd}"),
    };

    return <<<PHP
<?php
// Temporary deployment runner ({$cmd}). Self-deletes after one invocation.
if (!hash_equals('{$token}', (string) (\$_GET['key'] ?? ''))) {
    http_response_code(403);
    exit;
}
header('Content-Type: text/plain; charset=utf-8');
set_time_limit(300);
\$root = dirname(__DIR__);
require \$root . '/vendor/autoload.php';
try {
    \$config = IEdify\Core\Config::load(\$root);
{$body}
} catch (Throwable \$e) {
    echo 'ERROR ', \$e::class, ': ', \$e->getMessage(), "\n";
}
@unlink(__FILE__);
PHP;
}
