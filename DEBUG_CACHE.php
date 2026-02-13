<?php
/**
 * Script de debug pour voir ce que le cache retourne vraiment
 */

header('Content-Type: text/plain; charset=utf-8');

echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║              DEBUG DU CACHE VIA WEB                         ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n\n";

echo "Date/Heure: " . date('Y-m-d H:i:s') . "\n\n";

// Charger Dolibarr
require_once '../../main.inc.php';
require_once './class/realtime_gdrive_handler.class.php';
require_once './class/optimized_excel_cache.class.php';

// Vider opcache si possible
if (function_exists('opcache_reset')) {
    opcache_reset();
    echo "✓ OpCache vidé\n";
}

// Informations sur le fichier de classe
$classFile = __DIR__ . '/class/optimized_excel_cache.class.php';
echo "Fichier de classe: $classFile\n";
echo "Modifié: " . date('Y-m-d H:i:s', filemtime($classFile)) . "\n";
echo "Taille: " . filesize($classFile) . " octets\n\n";

// Vérifier que la correction est présente
$content = file_get_contents($classFile);
if (strpos($content, 'FIX: Les données dans le buffer') !== false) {
    echo "✓ La correction est présente dans le fichier\n\n";
} else {
    echo "❌ La correction n'est PAS présente dans le fichier!\n\n";
}

// Obtenir le fichier Excel
$fileId = $conf->global->SHIPMENTTRACKING_GDRIVE_FILE_ID ?? '1ijuQGdypjWz36_e5xOGNVgxfoTV06-MindoJWfvK0ik';
$realtimeHandler = new RealtimeGoogleDriveHandler($db);
$excelPath = $realtimeHandler->getLatestFile($fileId);

echo "Fichier Excel: $excelPath\n";
echo "Taille: " . round(filesize($excelPath) / 1024 / 1024, 2) . " MB\n";
echo "Modifié: " . date('Y-m-d H:i:s', filemtime($excelPath)) . "\n\n";

// Forcer la régénération du cache
echo "=== Test pour le 17/10/2025 ===\n\n";

// Supprimer le cache existant pour cette date
$cacheKey = md5($excelPath . '2025-10-17');
$cacheFile = '/tmp/shipment_cache/date_' . $cacheKey . '.json';
$cacheMetaFile = '/tmp/shipment_cache/date_' . $cacheKey . '.meta';

if (file_exists($cacheFile)) {
    unlink($cacheFile);
    echo "✓ Ancien cache supprimé\n";
}
if (file_exists($cacheMetaFile)) {
    unlink($cacheMetaFile);
}

// Régénérer le cache
$optimizedCache = new OptimizedExcelCache($db);
$cachedData = $optimizedCache->getDataForDate($excelPath, '2025-10-17');

if ($cachedData === false) {
    echo "❌ Erreur lors de la génération du cache\n";
    exit(1);
}

if (!$cachedData['found']) {
    echo "❌ Date 17/10/2025 non trouvée\n";
    exit(1);
}

echo "✓ Cache généré\n";
echo "✓ Date: " . $cachedData['date'] . "\n";
echo "✓ Nombre d'expéditions: " . count($cachedData['data']) . "\n\n";

echo "CONTENU:\n";
echo str_repeat("-", 80) . "\n";

foreach ($cachedData['data'] as $row) {
    echo sprintf("%-15s | %-30s | %-20s\n",
        $row['shipment'],
        $row['tracking'],
        $row['carrier']
    );
}

echo str_repeat("-", 80) . "\n\n";

// Vérification
$expectedExps = ['13691', '13692', '13693', '13695', '13696', '13697'];
$foundExps = [];

foreach ($cachedData['data'] as $row) {
    if (preg_match_all('/\d{5}/', $row['shipment'], $matches)) {
        foreach ($matches[0] as $match) {
            $foundExps[] = $match;
        }
    }
}

$foundExps = array_unique($foundExps);
sort($foundExps);
sort($expectedExps);

echo "RÉSULTAT:\n";
echo "Attendu: " . implode(', ', $expectedExps) . "\n";
echo "Trouvé:  " . implode(', ', $foundExps) . "\n\n";

if ($foundExps == $expectedExps) {
    echo "✅ SUCCESS! Le cache contient les BONNES expéditions!\n";
    echo "\nMaintenez, rechargez la page tracking.php\n";
} else {
    echo "❌ ERREUR! Le cache contient toujours les mauvaises expéditions!\n";
}
