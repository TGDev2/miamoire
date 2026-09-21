<?php

declare(strict_types=1);

/*
 * « L'autre téléphone » qui range un lot ailleurs.
 *
 * Même rôle que hold_and_consume.php, dans un PROCESSUS SÉPARÉ pour la même
 * raison — PHP est mono-thread, un verrou qu'on prend soi-même ne bloque jamais.
 * Il verrouille un lot, attend, change son emplacement, puis valide. La remise à
 * zéro d'un emplacement doit alors relire le lot à son NOUVEL emplacement, pas à
 * celui qu'elle avait vu avant d'attendre.
 *
 * Usage : php hold_and_move.php <dsn> <user> <password> <itemId> <newLocation> <holdMs>
 */

[, $dsn, $user, $password, $itemId, $newLocation, $holdMs] = $argv;

$pdo = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$pdo->beginTransaction();

$pdo->prepare('SELECT id FROM stock_item WHERE id = ? FOR UPDATE')->execute([$itemId]);

usleep((int) $holdMs * 1000);

$pdo->prepare('UPDATE stock_item SET storage_location = ? WHERE id = ?')
    ->execute([$newLocation, $itemId]);

$pdo->commit();

echo "committed\n";
