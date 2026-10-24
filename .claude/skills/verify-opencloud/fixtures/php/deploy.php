<?php

// Opencloud runs this as a deploy command before the new version gets traffic: php /var/www/deploy.php <cell>
require __DIR__.'/lib.php';

$cell = $argv[1] ?? 'unknown';
echo "deploy-marker-{$cell}\n";

$pdo = verify_pdo();

if ($pdo !== null) {
    verify_tables($pdo);
    $pdo->prepare('INSERT INTO verify_deploys (cell, created_at) VALUES (?, ?)')->execute([$cell, date(DATE_ATOM)]);
    echo "The deploy command wrote one row to verify_deploys.\n";
}
