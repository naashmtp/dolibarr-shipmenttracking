#!/usr/bin/env php
<?php
/**
 * Script simple de synchronisation Google Drive
 * Peut être exécuté en CLI ou via cron
 */

// Définir le chemin si nécessaire
if (!defined('DOL_DOCUMENT_ROOT')) {
    $path = __DIR__ . '/../../..';
    define('DOL_DOCUMENT_ROOT', realpath($path));
}

$fileId = '1ijuQGdypjWz36_e5xOGNVgxfoTV06-MindoJWfvK0ik';
$destination = '/var/www/html/dolibarr/documents/shipmenttracking/SUIVI_GENERAL.xlsx';

echo "[" . date('Y-m-d H:i:s') . "] Début de la synchronisation\n";

$dir = dirname($destination);
if (!is_dir($dir)) {
    mkdir($dir, 0755, true);
    echo "[" . date('Y-m-d H:i:s') . "] Dossier créé: $dir\n";
}

$url = "https://docs.google.com/spreadsheets/d/$fileId/export?format=xlsx";

echo "[" . date('Y-m-d H:i:s') . "] Téléchargement depuis: $url\n";

// Télécharger avec file_get_contents (plus simple que cURL)
$context = stream_context_create([
    'http' => [
        'method' => 'GET',
        'follow_location' => 1,
        'max_redirects' => 5,
        'timeout' => 60,
        'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
    ],
    'ssl' => [
        'verify_peer' => false,
        'verify_peer_name' => false
    ]
]);

$content = @file_get_contents($url, false, $context);

if ($content === false) {
    echo "[" . date('Y-m-d H:i:s') . "] ERREUR: Impossible de télécharger le fichier\n";
    exit(1);
}

$size = strlen($content);
$sizeMB = round($size / 1024 / 1024, 2);

if ($size < 100000) {
    echo "[" . date('Y-m-d H:i:s') . "] ERREUR: Fichier trop petit ($sizeMB MB)\n";
    exit(1);
}

if (substr($content, 0, 2) !== 'PK') {
    echo "[" . date('Y-m-d H:i:s') . "] ERREUR: Pas un fichier Excel valide\n";
    exit(1);
}

if (file_put_contents($destination, $content) === false) {
    echo "[" . date('Y-m-d H:i:s') . "] ERREUR: Impossible d'écrire le fichier\n";
    exit(1);
}

if (file_exists($destination)) {
    chmod($destination, 0644);
    echo "[" . date('Y-m-d H:i:s') . "] ✓ Synchronisation réussie\n";
    echo "[" . date('Y-m-d H:i:s') . "] ✓ Taille: $sizeMB MB\n";
    echo "[" . date('Y-m-d H:i:s') . "] ✓ Fichier: $destination\n";
    exit(0);
} else {
    echo "[" . date('Y-m-d H:i:s') . "] ERREUR: Le fichier n'existe pas après l'écriture\n";
    exit(1);
}
