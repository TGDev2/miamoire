<?php

declare(strict_types=1);

/*
 * « L'autre téléphone ».
 *
 * Ce script est lancé comme un PROCESSUS SÉPARÉ par le test de concurrence. C'est
 * indispensable : PHP est mono-thread, et un verrou qu'on prend soi-même ne bloque
 * jamais. Sans second processus au sens du système, il n'y a pas de concurrence à
 * observer — seulement deux appels qui s'exécutent l'un après l'autre.
 *
 * Il verrouille un lot, le tient assez longtemps pour que la confirmation arrive
 * dessus, puis réduit la quantité et valide. La confirmation doit alors découvrir
 * la NOUVELLE quantité, pas celle qu'elle avait lue avant d'attendre.
 *
 * Usage : php hold_and_consume.php <dsn> <user> <password> <itemId> <newQuantity> <holdMs>
 */

[, $dsn, $user, $password, $itemId, $newQuantity, $holdMs] = $argv;

$pdo = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$pdo->beginTransaction();

$pdo->prepare('SELECT id FROM stock_item WHERE id = ? FOR UPDATE')->execute([$itemId]);

// Le temps que la confirmation atteigne ce lot et se heurte au verrou.
usleep((int) $holdMs * 1000);

$pdo->prepare('UPDATE stock_item SET remaining_quantity = ? WHERE id = ?')
    ->execute([$newQuantity, $itemId]);

$pdo->commit();

echo "committed\n";
