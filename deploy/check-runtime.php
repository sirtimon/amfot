<?php
// Infrastructure probe: no config dumps, credentials, job payloads or exception messages.
declare(strict_types=1);

$worker = in_array('--worker', $argv, true);
$errors = [];
$check = static function (bool $ok, string $label) use (&$errors): void {
    echo ($ok ? 'OK ' : 'FAIL ') . $label . PHP_EOL;
    if (!$ok) {
        $errors[] = $label;
    }
};

try {
    chdir('/var/www/html');
    require 'vendor/autoload.php';
    $app = require 'bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $check(config('app.env') === 'production', 'production environment');
    $check(config('app.debug') === false, 'debug disabled in effective config');
    $check(config('app.url') === 'https://lms.idcg.tj', 'canonical HTTPS URL');
    $check(config('session.secure') === true, 'secure session cookie');
    $check(config('queue.default') === 'database', 'database queue');
    $timeout = filter_var(getenv('QUEUE_WORKER_TIMEOUT') ?: '60', FILTER_VALIDATE_INT);
    $retry = (int) config('queue.connections.database.retry_after');
    $check($timeout !== false && $timeout > 0 && $timeout <= 300 && $retry > $timeout,
        'worker timeout in 1..300 and below effective database retry_after');
    $check(extension_loaded('pcntl'), 'pcntl available for worker timeouts');
    foreach (['storage/framework', 'storage/logs', 'bootstrap/cache', 'storage/app/public', 'storage/app/scorm'] as $dir) {
        $check(is_dir($dir) && is_writable($dir), $dir . ' writable by probe user');
    }
    foreach (['storage' => '../storage/app/public', 'scorm' => '../storage/app/scorm'] as $name => $target) {
        $link = 'public/' . $name;
        $check(is_link($link) && readlink($link) === $target && is_dir($link), $link . ' relative symlink');
    }
    Illuminate\Support\Facades\DB::connection()->select('SELECT 1');
    $check(true, 'database connection');
    if (!$worker) {
        foreach (['jobs', 'failed_jobs'] as $table) {
            echo $table . '_count=' . Illuminate\Support\Facades\DB::table($table)->count() . PHP_EOL;
        }
    }
} catch (Throwable $e) {
    // Exceptions can contain SQL bindings and credentials; expose the type only.
    $check(false, 'runtime probe (' . get_class($e) . ')');
}
exit($errors === [] ? 0 : 1);
