<?php

declare(strict_types=1);

namespace qa;

use Castor\Attribute\AsOption;
use Castor\Attribute\AsRawTokens;
use Castor\Attribute\AsTask;
use Symfony\Component\Console\Input\InputOption;

use function Castor\capture;
use function Castor\context;
use function Castor\fs;
use function Castor\http_download;
use function Castor\http_request;
use function Castor\io;
use function Castor\run;

/** Pinned release of symfony-lsp (https://github.com/symfony/language-tools/releases); .github/workflows/ci.yaml pins the same one. */
const SYMFONY_LSP_VERSION = '0.24.1';

#[AsTask(description: 'Runs the PHPUnit suite, e.g. "castor test --filter=IssueListTest"', aliases: ['test'])]
function test(#[AsRawTokens] array $args): int
{
    return in_container(['php', 'bin/phpunit', ...array_values($args)], allowFailure: true);
}

#[AsTask(description: 'Lints the container, the Twig templates and the YAML files', aliases: ['lint'])]
function lint(): int
{
    return console(['lint:container'], allowFailure: true)
        ?: console(['lint:twig', 'templates/', 'storia/'], allowFailure: true)
        ?: console(['lint:yaml', 'config/', 'translations/', 'storia/'], allowFailure: true);
}

#[AsTask(description: 'Fixes the coding standard with php-cs-fixer; --dry-run only reports the differences', aliases: ['cs'])]
function cs(
    #[AsOption(description: 'Report the files that would change without touching them', mode: InputOption::VALUE_NONE)]
    bool $dryRun,
): int {
    return in_container(['vendor/bin/php-cs-fixer', 'fix', ...($dryRun ? ['--dry-run', '--diff'] : [])], allowFailure: true);
}

#[AsTask(description: 'Runs the PHPStan analysis', aliases: ['phpstan'])]
function phpstan(#[AsRawTokens] array $args): int
{
    // phpstan-symfony reads the compiled dev container.
    console(['cache:warmup', '--env=dev', '--quiet']);

    return in_container(['vendor/bin/phpstan', 'analyse', '--memory-limit=-1', ...array_values($args)], allowFailure: true);
}

#[AsTask(description: 'Compares the components with their Storia snapshots; --update rewrites them (review the diff first)')]
function storia(
    #[AsOption(description: 'Rewrite the snapshots', mode: InputOption::VALUE_NONE)]
    bool $update,
): int {
    return console(['storia:test', ...($update ? ['--update'] : [])], allowFailure: true);
}

#[AsTask(description: 'Runs the Symfony Language Tools checker (routes, services, templates, translations...) against the cli container', aliases: ['lsp'])]
function lsp(#[AsRawTokens] array $args): int
{
    // The checker boots the application through "docker compose exec cli" (.symfony-lsp.json), so the stack must be up.
    $process = run([symfony_lsp_binary(), 'check', ...array_values($args)], context: context()->withAllowFailure());

    return $process->getExitCode() ?? 0;
}

#[AsTask(description: 'Runs every check the CI runs: coding standard, PHPStan, linters, symfony-lsp and the test suite', aliases: ['qa'])]
function all(): int
{
    $checks = [
        'php-cs-fixer' => static fn (): int => cs(dryRun: true),
        'phpstan' => static fn (): int => phpstan([]),
        'lint' => static fn (): int => lint(),
        'symfony-lsp' => static fn (): int => lsp([]),
        'phpunit' => static fn (): int => test([]),
    ];
    $failures = [];

    foreach ($checks as $name => $check) {
        io()->section($name);
        if (0 !== $check()) {
            $failures[] = $name;
        }
    }

    if ([] !== $failures) {
        io()->error('Failed: '.implode(', ', $failures));

        return 1;
    }

    io()->success('Everything passed.');

    return 0;
}

/** Uses symfony-lsp from the PATH when installed, otherwise downloads the pinned release into var/bin/. */
function symfony_lsp_binary(): string
{
    $onPath = trim(capture(['sh', '-c', 'command -v symfony-lsp || true']));
    if ('' !== $onPath) {
        return $onPath;
    }

    $binary = __DIR__.'/../var/bin/symfony-lsp-'.SYMFONY_LSP_VERSION;
    if (is_executable($binary)) {
        return $binary;
    }

    $platform = match ([\PHP_OS_FAMILY, php_uname('m')]) {
        ['Darwin', 'arm64'] => 'macos-arm64',
        ['Linux', 'x86_64'] => 'linux-x64',
        ['Linux', 'aarch64'], ['Linux', 'arm64'] => 'linux-arm64',
        default => throw new \RuntimeException(\sprintf('No symfony-lsp build for %s/%s: install it yourself and put it on the PATH.', \PHP_OS_FAMILY, php_uname('m'))),
    };
    $name = \sprintf('symfony-lsp-v%s-%s', SYMFONY_LSP_VERSION, $platform);
    $base = \sprintf('https://github.com/symfony/language-tools/releases/download/v%s/', SYMFONY_LSP_VERSION);
    $tmp = sys_get_temp_dir().'/'.$name;

    io()->note(\sprintf('Downloading symfony-lsp %s (%s)', SYMFONY_LSP_VERSION, $platform));
    fs()->mkdir([\dirname($binary), $tmp]);
    http_download($base.$name.'.tar.gz', $tmp.'.tar.gz');

    $sums = http_request('GET', $base.'SHA256SUMS')->getContent();
    if (!preg_match('/^([0-9a-f]{64})\s+'.preg_quote($name.'.tar.gz', '/').'$/m', $sums, $m) || hash_file('sha256', $tmp.'.tar.gz') !== $m[1]) {
        fs()->remove([$tmp, $tmp.'.tar.gz']);
        throw new \RuntimeException('The symfony-lsp archive does not match SHA256SUMS.');
    }

    run(['tar', '-xzf', $tmp.'.tar.gz', '-C', $tmp, '--strip-components=1']);
    fs()->copy($tmp.'/symfony-lsp', $binary, true);
    fs()->chmod($binary, 0o755);
    fs()->remove([$tmp, $tmp.'.tar.gz']);
    if ('Darwin' === \PHP_OS_FAMILY) {
        run(['xattr', '-d', 'com.apple.quarantine', $binary], context: context()->withAllowFailure()->withQuiet());
    }

    return $binary;
}
