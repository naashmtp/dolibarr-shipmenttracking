<?php
/**
 * Script de diagnostic de performance
 * Mesure EXACTEMENT où sont les lenteurs
 */

$startTotal = microtime(true);
$timings = [];

function logTiming($label, $start) {
    global $timings;
    $duration = microtime(true) - $start;
    $timings[] = [
        'label' => $label,
        'duration' => $duration,
        'formatted' => round($duration, 3) . 's'
    ];
    return microtime(true);
}

echo "<h2>🔍 DIAGNOSTIC DE PERFORMANCE - Module Shipment Tracking</h2>";
echo "<pre>";

// 1. Chargement Dolibarr
$step = microtime(true);
require_once '../../main.inc.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once './class/realtime_gdrive_handler.class.php';
require_once './class/optimized_excel_cache.class.php';
$step = logTiming("1️⃣ Chargement Dolibarr + Classes", $step);

// 2. Récupération du fichier Google Drive
$step = microtime(true);
$fileId = $conf->global->SHIPMENTTRACKING_GDRIVE_FILE_ID;
if (empty($fileId)) {
    die("❌ Pas d'ID Google Drive configuré\n");
}

$realtimeHandler = new RealtimeGoogleDriveHandler($db);
$step = logTiming("2️⃣ Instanciation RealtimeGoogleDriveHandler", $step);

// 3. Téléchargement/Cache du fichier
$step = microtime(true);
$cachedFile = $realtimeHandler->getLatestFile($fileId);
$step = logTiming("3️⃣ getLatestFile() - TÉLÉCHARGEMENT GOOGLE DRIVE", $step);

if ($cachedFile === false) {
    die("❌ Échec récupération fichier Google Drive\n");
}

echo "   ✓ Fichier obtenu: $cachedFile\n";
echo "   ✓ Taille: " . round(filesize($cachedFile)/1024/1024, 2) . " MB\n\n";

// 4. Lecture du cache optimisé
$step = microtime(true);
$optimizedCache = new OptimizedExcelCache($db);
$today = date('Y-m-d');
echo "   Date recherchée: $today\n";
$cachedData = $optimizedCache->getDataForDate($cachedFile, $today);
$step = logTiming("4️⃣ getDataForDate() - PARSING EXCEL", $step);

if ($cachedData && $cachedData['found']) {
    echo "   ✓ Date trouvée avec " . count($cachedData['data']) . " expéditions\n\n";
} else {
    echo "   ⚠️  Date non trouvée\n\n";
}

// 5. Récupération des expéditions Dolibarr (si on avait des données)
$step = microtime(true);
// Simuler la requête SQL
$sql = "SELECT ref FROM llxbm_expedition WHERE fk_statut = 1 LIMIT 100";
$resql = $db->query($sql);
$expeditions = [];
if ($resql) {
    while ($obj = $db->fetch_object($resql)) {
        $expeditions[] = $obj->ref;
    }
}
$step = logTiming("5️⃣ Requête SQL Expéditions Dolibarr", $step);
echo "   ✓ Expéditions trouvées: " . count($expeditions) . "\n\n";

// 6. Temps total
$totalTime = microtime(true) - $startTotal;
$timings[] = [
    'label' => '⏱️  TEMPS TOTAL',
    'duration' => $totalTime,
    'formatted' => round($totalTime, 3) . 's'
];

// Affichage du rapport
echo "═══════════════════════════════════════════\n";
echo "📊 RAPPORT DE PERFORMANCE\n";
echo "═══════════════════════════════════════════\n\n";

foreach ($timings as $timing) {
    $percentage = round(($timing['duration'] / $totalTime) * 100, 1);
    $bar = str_repeat('█', min(50, (int)($percentage / 2)));

    printf("%-45s %8s  %5.1f%%\n", $timing['label'], $timing['formatted'], $percentage);
    echo "  $bar\n\n";
}

echo "═══════════════════════════════════════════\n";
echo "🎯 GOULOT D'ÉTRANGLEMENT IDENTIFIÉ:\n";
echo "═══════════════════════════════════════════\n";

// Trouver la plus longue étape
usort($timings, function($a, $b) {
    return $b['duration'] <=> $a['duration'];
});

$slowest = $timings[0];
if ($slowest['duration'] > 5) {
    echo "⚠️  PROBLÈME MAJEUR: {$slowest['label']}\n";
    echo "   Temps: {$slowest['formatted']}\n\n";

    if (strpos($slowest['label'], 'GOOGLE DRIVE') !== false) {
        echo "💡 SOLUTION:\n";
        echo "   - Le téléchargement Google Drive est trop lent\n";
        echo "   - Vérifier la connexion internet du serveur\n";
        echo "   - Réduire les timeouts dans RealtimeGoogleDriveHandler\n";
        echo "   - Vérifier que le cache de 30 secondes fonctionne\n";
    } elseif (strpos($slowest['label'], 'PARSING EXCEL') !== false) {
        echo "💡 SOLUTION:\n";
        echo "   - Le parsing Excel est trop lent\n";
        echo "   - Vérifier que setLoadSheetsOnly fonctionne\n";
        echo "   - Vérifier que le cache JSON est actif\n";
    }
} else {
    echo "✅ Toutes les étapes sont rapides (<5s)\n";
}

echo "\n</pre>";
