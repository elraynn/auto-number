<?php

// Proves the race condition in SELECT MAX()+1, and shows auto-number doesn't have it.
// Spawns real OS processes (not threads) hitting the same SQLite file, so the
// database actually sees concurrent connections, same as it would in production.
//
// Run: php examples/concurrency-proof.php

require __DIR__ . '/../vendor/autoload.php';

use Elrayn\AutoNumber\AutoNumber;

const WORKERS = 10;
const PER_WORKER = 5;
const DB_FILE = __DIR__ . '/concurrency-proof.sqlite';

if (($argv[1] ?? null) === '--worker') {
    worker($argv[2]);
    exit;
}

function worker(string $strategy): void
{
    $pdo = new PDO('sqlite:' . DB_FILE);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA busy_timeout = 5000');

    for ($i = 0; $i < PER_WORKER; $i++) {
        $number = $strategy === 'old'
            ? oldSelectMaxPlusOne($pdo)
            : AutoNumber::make($pdo)->key('demo')->digits(4)->next();

        fwrite(STDOUT, $number . "\n");
    }
}

function oldSelectMaxPlusOne(PDO $pdo): string
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS old_style (id INTEGER PRIMARY KEY AUTOINCREMENT, number TEXT)');

    $max = (int) $pdo->query('SELECT MAX(CAST(number AS INTEGER)) FROM old_style')->fetchColumn();

    // Real code doesn't INSERT the instant it reads the max - it builds the
    // rest of the record, runs validation, etc first. That gap is what turns
    // a narrow race into a near-guaranteed one under real traffic.
    usleep(20000);

    $next = $max + 1;
    $pdo->prepare('INSERT INTO old_style (number) VALUES (:n)')->execute(['n' => (string) $next]);

    return (string) $next;
}

function run(string $strategy): array
{
    @unlink(DB_FILE);

    $processes = [];
    $pipes = [];

    for ($w = 0; $w < WORKERS; $w++) {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        // Forward the extension flag: on a lot of default PHP installs
        // (including whatever ran this script if you're reading this after
        // hitting "could not find driver"), pdo_sqlite ships but isn't
        // enabled in php.ini. Workers are separate processes, so they don't
        // inherit whatever -d flags the parent was started with.
        $command = [PHP_BINARY, '-d', 'extension=pdo_sqlite', __FILE__, '--worker', $strategy];
        $proc = proc_open($command, $descriptors, $pipe);
        $processes[] = $proc;
        $pipes[] = $pipe;
    }

    $numbers = [];
    foreach ($pipes as $i => $pipe) {
        $output = trim(stream_get_contents($pipe[1]));
        $errors = trim(stream_get_contents($pipe[2]));
        fclose($pipe[1]);
        fclose($pipe[2]);
        $exitCode = proc_close($processes[$i]);

        if ($exitCode !== 0) {
            fwrite(STDERR, "Worker {$i} failed (exit {$exitCode}):\n{$errors}\n{$output}\n");
            exit(1);
        }

        $numbers = array_merge($numbers, array_filter(explode("\n", $output)));
    }

    @unlink(DB_FILE);

    return $numbers;
}

echo "Running " . WORKERS . " processes x " . PER_WORKER . " increments each.\n\n";

echo "Old style (SELECT MAX()+1):\n";
$old = run('old');
$oldDupes = count($old) - count(array_unique($old));
echo "  " . count($old) . " numbers generated, " . count(array_unique($old)) . " unique, {$oldDupes} duplicate(s)\n\n";

echo "auto-number:\n";
$new = run('new');
$newDupes = count($new) - count(array_unique($new));
echo "  " . count($new) . " numbers generated, " . count(array_unique($new)) . " unique, {$newDupes} duplicate(s)\n\n";

echo $oldDupes > 0
    ? "Old approach produced duplicate numbers, as expected under concurrent load.\n"
    : "Old approach got lucky this run - try again, or raise WORKERS.\n";

echo $newDupes === 0
    ? "auto-number produced zero duplicates.\n"
    : "Unexpected: auto-number produced duplicates - that would be a real bug, please report it.\n";
