<?php

declare(strict_types=1);

/*
| Laragon exports the contents of .env into the shell, and PHP hands those
| values to the process in $_SERVER. Dotenv reads $_SERVER before it reads
| anything PHPUnit writes, so the <env> entries in phpunit.xml lose and the
| suite runs against the development MySQL database, the database session
| driver, and a live Reverb.
|
| PHPUnit has already applied those entries to $_ENV by the time this file
| loads, so copying them across makes phpunit.xml the last word on every
| machine.
*/
foreach ($_ENV as $key => $value) {
    $_SERVER[$key] = $value;
}

require __DIR__.'/../vendor/autoload.php';
