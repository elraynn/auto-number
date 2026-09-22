<?php

namespace Elrayn\AutoNumber;

use DateTimeImmutable;
use PDO;

class AutoNumber
{
    protected static array $ensuredTables = [];

    protected PDO $pdo;
    protected string $key = 'default';
    protected ?string $prefix = null;
    protected string $separator = '-';
    protected int $digits = 4;
    protected ?string $resetPeriod = null;
    protected string $table = 'auto_number_counters';
    protected ?DateTimeImmutable $now = null;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public static function make(PDO $pdo): self
    {
        return new self($pdo);
    }

    public function key(string $key): self
    {
        $this->key = $key;
        return $this;
    }

    public function prefix(string $prefix): self
    {
        $this->prefix = $prefix;
        return $this;
    }

    public function separator(string $separator): self
    {
        $this->separator = $separator;
        return $this;
    }

    public function digits(int $digits): self
    {
        $this->digits = $digits;
        return $this;
    }

    public function resetDaily(): self
    {
        $this->resetPeriod = 'daily';
        return $this;
    }

    public function resetMonthly(): self
    {
        $this->resetPeriod = 'monthly';
        return $this;
    }

    public function resetYearly(): self
    {
        $this->resetPeriod = 'yearly';
        return $this;
    }

    public function table(string $table): self
    {
        $this->table = $table;
        return $this;
    }

    // Mainly for tests: pin "now" instead of using the real current time,
    // so reset-period behavior can be verified deterministically.
    public function at(DateTimeImmutable $now): self
    {
        $this->now = $now;
        return $this;
    }

    public function next(): string
    {
        $this->ensureTable();

        $period = NumberFormatter::periodSuffix($this->resetPeriod, $this->now);
        $counterName = $period ? "{$this->key}:{$period}" : $this->key;
        $value = $this->increment($counterName);

        return NumberFormatter::format($this->prefix, $period, $value, $this->digits, $this->separator);
    }

    // Atomic under concurrent calls. The pattern this replaces
    // (SELECT MAX(...) then +1 in PHP) races when two requests read
    // the same max before either writes back.
    protected function increment(string $counterName): int
    {
        if ($this->driver() === 'sqlite') {
            // RETURNING needs SQLite 3.35+, which isn't safe to assume.
            // Upsert then read back, wrapped in a transaction so SQLite's
            // write lock covers both statements.
            $this->pdo->beginTransaction();

            try {
                $this->pdo->prepare(
                    "INSERT INTO {$this->table} (name, value) VALUES (:name, 1)
                     ON CONFLICT(name) DO UPDATE SET value = value + 1"
                )->execute(['name' => $counterName]);

                $stmt = $this->pdo->prepare("SELECT value FROM {$this->table} WHERE name = :name");
                $stmt->execute(['name' => $counterName]);
                $value = (int) $stmt->fetchColumn();

                $this->pdo->commit();
            } catch (\Throwable $e) {
                $this->pdo->rollBack();
                throw $e;
            }

            return $value;
        }

        $stmt = $this->pdo->prepare(
            "INSERT INTO {$this->table} (name, value) VALUES (:name, 1)
             ON DUPLICATE KEY UPDATE value = LAST_INSERT_ID(value + 1)"
        );
        $stmt->execute(['name' => $counterName]);

        return (int) $this->pdo->lastInsertId();
    }

    protected function ensureTable(): void
    {
        // Keyed by connection + table, not just table name - otherwise a
        // second PDO connection (a different test, a read replica, a
        // reconnect) wrongly trusts a table that only exists on the first
        // connection's database.
        $cacheKey = spl_object_id($this->pdo) . ':' . $this->table;

        if (isset(self::$ensuredTables[$cacheKey])) {
            return;
        }

        $engine = $this->driver() === 'mysql' ? ' ENGINE=InnoDB' : '';

        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS {$this->table} (
                name VARCHAR(191) NOT NULL PRIMARY KEY,
                value INTEGER NOT NULL DEFAULT 0
            )" . $engine
        );

        self::$ensuredTables[$cacheKey] = true;
    }

    protected function driver(): string
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }
}
