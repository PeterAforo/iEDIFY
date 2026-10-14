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
$allowed = ['migrate', 'seed-roles', 'doctor', 'create-admin', 'content-import'];
if ($options['help'] || !in_array($options['cmd'], $allowed, true)) {
    echo "Usage: php bin/deploy-remote.php --cmd=migrate|seed-roles|doctor|create-admin|content-import [--email= --name= --password=] [--url=]\n";
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
