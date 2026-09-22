# auto-number

Generate sequential document numbers (invoice, PO, delivery order, whatever) without the classic race condition.

If you've built this before, it probably looks like this:

```php
$q = "SELECT MAX(RIGHT(no_transaksi,4)) AS kd_max FROM tinvoice
      WHERE MONTH(tanggal)='$bulan' AND YEAR(tanggal)='$tahun'";
```

...read the max, add one in PHP, save it. It works until two requests hit it close enough together that both read the same max before either writes back, and you get two invoices with the same number. This isn't hypothetical, it's a standard concurrency bug and it shows up under real load.

This library replaces that with an atomic counter (MySQL's `LAST_INSERT_ID()` trick under the hood), so two concurrent calls can't ever get the same number.

## Install

```
composer require elrayn/auto-number
```

## Usage

```php
use Elrayn\AutoNumber\AutoNumber;

$number = AutoNumber::make($pdo)
    ->key('invoice')
    ->prefix('INV')
    ->resetMonthly()
    ->digits(4)
    ->next();

// "INV-202609-0001", then "INV-202609-0002", and so on.
// Resets back to 0001 automatically in October.
```

The counters table (`auto_number_counters` by default) is created automatically on first use. Different `key()` values get independent counters, so `invoice` and `po` won't collide.

## Options

| Method | Default | Description |
|---|---|---|
| `key(string)` | `'default'` | Which counter this is. Use a different key per document type. |
| `prefix(string)` | none | Prepended to the number, e.g. `INV`. |
| `digits(int)` | `4` | Zero-padding width. A value past this width isn't truncated (`99999` stays `99999`, not cut to 4 digits). |
| `separator(string)` | `'-'` | Joins prefix, period, and number. |
| `resetDaily()` / `resetMonthly()` / `resetYearly()` | none (never resets) | Starts the counter back at 1 for each new period. |
| `table(string)` | `'auto_number_counters'` | Override if the default name collides with something. |

## How the atomic part works

On MySQL:

```sql
INSERT INTO auto_number_counters (name, value) VALUES (:name, 1)
ON DUPLICATE KEY UPDATE value = LAST_INSERT_ID(value + 1)
```

`LAST_INSERT_ID(expr)` is a MySQL extension that sets the session's last-insert-id to whatever `expr` evaluates to, and the whole statement runs under a row lock. Two concurrent calls can't both read the same value and increment from it, unlike `SELECT MAX()` then a separate `UPDATE`.

SQLite is also supported (via `INSERT ... ON CONFLICT ... RETURNING`), mainly so the test suite doesn't need a real MySQL server.

## License

MIT
