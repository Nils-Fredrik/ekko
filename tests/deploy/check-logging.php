<?php

// Fed on stdin to both production images: no test files or credentials enter the image.
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
if (ini_get('zend.exception_ignore_args') !== '1' || ! $app->environment('production')) {
    exit(1);
}

Log::debug('synthetic-debug-marker');
Log::info('logging.probe');
Artisan::command('test:logging', function () {
    throw new QueryException('pgsql', 'select synthetic-query-marker', ['synthetic-binding-marker'], new PDOException('synthetic-password-marker'));
});
$output = new BufferedOutput;
if ($kernel->handle(new ArrayInput(['command' => 'test:logging']), $output) !== 1) {
    exit(2);
}
if ($output->fetch() !== "Command failed. See the structured application log for code locations.\n") {
    exit(3);
}
