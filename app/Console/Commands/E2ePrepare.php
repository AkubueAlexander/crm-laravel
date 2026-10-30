<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use PDO;

/**
 * 15.6: prepares the isolated database the Playwright run talks to.
 * Refuses to run unless APP_ENV=e2e AND the default connection is a Postgres database whose name ends in _e2e,
 * so it can never wipe the dev or test databases.
 */
class E2ePrepare extends Command
{
    protected $signature = 'e2e:prepare';

    protected $description = 'Create the E2E database if missing, migrate:fresh and seed the dev tenant (APP_ENV=e2e only).';

    public function handle(): int
    {
        if (! app()->environment('e2e')) {
            $this->error('e2e:prepare only runs with APP_ENV=e2e (use --env=e2e).');

            return self::FAILURE;
        }

        $connection = (string) config('database.default');
        $cfg = (array) config("database.connections.{$connection}");
        $db = (string) ($cfg['database'] ?? '');

        if ($connection !== 'pgsql' || ! preg_match('/^[A-Za-z0-9_]+_e2e$/', $db)) {
            $this->error("Refusing to run: database \"{$db}\" on \"{$connection}\" is not a pgsql database ending in _e2e.");

            return self::FAILURE;
        }

        $this->ensureDatabase($cfg, $db);
        app('db')->purge($connection);

        $this->call('migrate:fresh', ['--force' => true]);
        $this->call('db:seed', ['--class' => 'Database\\Seeders\\DevTenantSeeder', '--force' => true]);
        $this->call('db:seed', ['--class' => 'Database\\Seeders\\E2eDealSeeder', '--force' => true]);

        $this->info("E2E database {$db} is ready.");

        return self::SUCCESS;
    }

    /** @param  array<string, mixed>  $cfg */
    private function ensureDatabase(array $cfg, string $db): void
    {
        $dsn = sprintf('pgsql:host=%s;port=%s;dbname=postgres', $cfg['host'] ?? '127.0.0.1', $cfg['port'] ?? 5432);
        $pdo = new PDO($dsn, (string) ($cfg['username'] ?? ''), (string) ($cfg['password'] ?? ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        $stmt = $pdo->prepare('SELECT 1 FROM pg_database WHERE datname = ?');
        $stmt->execute([$db]);

        if (! $stmt->fetchColumn()) {
            $pdo->exec('CREATE DATABASE "'.$db.'"');
            $this->info("Created database {$db}.");
        }
    }
}
