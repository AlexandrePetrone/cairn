<?php

declare(strict_types=1);

use App\Kernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

(new Dotenv())->bootEnv(dirname(__DIR__).'/.env');

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}

// No migrations before the first release: the test schema is derived from
// the entities once per run. DAMA then wraps every test in a transaction.
if ('test' === ($_SERVER['APP_ENV'] ?? null)) {
    $kernel = new Kernel('test', (bool) $_SERVER['APP_DEBUG']);
    $kernel->boot();

    $console = new Application($kernel);
    $console->setAutoExit(false);
    $console->run(new ArrayInput(['command' => 'doctrine:database:create', '--if-not-exists' => true]), new NullOutput());
    $console->run(new ArrayInput(['command' => 'doctrine:schema:update', '--force' => true, '--complete' => true]), new NullOutput());

    $kernel->shutdown();
}
