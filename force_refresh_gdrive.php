<?php
/**
 * Force Refresh Google Drive - Bypass cache agressif
 * Cette page force un rafraîchissement complet du fichier Google Drive
 */

// Load Dolibarr environment
require_once '../../main.inc.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';

// Access control
if (!$user->admin) accessforbidden();

$action = GETPOST('action', 'alpha');

llxHeader('', 'Force Refresh Google Drive');

print load_fiche_titre('🔄 Force Refresh Google Drive');

if ($action == 'force_refresh') {
    print '<div class="info">';
    print '<h3>🚀 Tentative de rafraîchissement forcé...</h3>';

    $fileId = $conf->global->SHIPMENTTRACKING_GDRIVE_FILE_ID;

    if (empty($fileId)) {
        print '<p style="color: red;">❌ ID Google Drive non configuré</p>';
        print '</div>';
        llxFooter();
        exit;
    }

    // Supprimer TOUS les caches locaux
    print '<p>1️⃣ Suppression des caches locaux...</p>';
    $cacheFiles = glob('/tmp/gdrive_*');
    foreach ($cacheFiles as $file) {
        @unlink($file);
    }
    print '<p style="color: green;">✓ Caches locaux supprimés</p>';

    // Forcer le garbage collector
    gc_collect_cycles();

    // Tentative 1: URL avec multiples paramètres anti-cache
    print '<p>2️⃣ Tentative téléchargement avec URL anti-cache...</p>';
    $timestamp = time();
    $random = rand(100000, 999999);

    // On teste seulement 2 URLs pour économiser la mémoire
    $urls = [
        "https://docs.google.com/spreadsheets/d/{$fileId}/export?format=xlsx&v={$timestamp}&r={$random}",
        "https://docs.google.com/spreadsheets/d/{$fileId}/export?format=xlsx&_=" . (time() * 1000)
    ];

    $success = false;
    $bestFile = null;
    $maxRows = 0;

    foreach ($urls as $idx => $url) {
        print "<p>  Essai " . ($idx + 1) . "...</p>";

        $context = stream_context_create([
            'http' => [
                'follow_location' => 1,
                'max_redirects' => 5,
                'timeout' => 45,
                'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0.0.0',
                'header' => "Cache-Control: no-cache, no-store, must-revalidate, max-age=0\r\n" .
                           "Pragma: no-cache\r\n" .
                           "Expires: 0\r\n" .
                           "If-Modified-Since: Thu, 01 Jan 1970 00:00:00 GMT\r\n" .
                           "If-None-Match: *\r\n"
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false
            ]
        ]);

        $content = @file_get_contents($url, false, $context);

        if ($content && strlen($content) > 100000) {
            $tmpFile = '/tmp/test_refresh_' . $idx . '_' . time() . '.xlsx';
            file_put_contents($tmpFile, $content);

            // Compter les lignes avec lecture optimisée
            require_once __DIR__ . '/vendor/autoload.php';
            try {
                $reader = PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($tmpFile);
                $reader->setReadDataOnly(true);
                $reader->setLoadSheetsOnly(['CAHIER EXPEDITIONS']);

                $spreadsheet = $reader->load($tmpFile);
                $worksheet = $spreadsheet->getSheetByName('CAHIER EXPEDITIONS');
                $rows = $worksheet ? $worksheet->getHighestRow() : 0;

                // Libérer la mémoire immédiatement
                $spreadsheet->disconnectWorksheets();
                unset($spreadsheet);

                print "<p style='color: green;'>  ✓ Téléchargé: " . round(strlen($content)/1024/1024, 2) . " MB, $rows lignes</p>";

                if ($rows > $maxRows) {
                    $maxRows = $rows;
                    $bestFile = $tmpFile;
                    $success = true;
                }
            } catch (Exception $e) {
                print "<p style='color: orange;'>  ⚠ Erreur lecture: " . $e->getMessage() . "</p>";
            }

            // Nettoyer la mémoire après chaque essai
            gc_collect_cycles();
        } else {
            print "<p style='color: red;'>  ✗ Échec</p>";
        }

        // Si on a trouvé un bon fichier, on arrête
        if ($success) {
            print "<p style='color: green;'>✓ Fichier valide trouvé, arrêt des essais</p>";
            break;
        }
    }

    if ($success && $bestFile) {
        print "<p style='color: green;'><strong>3️⃣ Meilleur fichier trouvé: $maxRows lignes</strong></p>";

        // Analyser le contenu avec lecture optimisée
        print '<p>4️⃣ Analyse du contenu...</p>';

        require_once __DIR__ . '/vendor/autoload.php';

        $reader = PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($bestFile);
        $reader->setReadDataOnly(true);
        $reader->setLoadSheetsOnly(['CAHIER EXPEDITIONS']);

        $spreadsheet = $reader->load($bestFile);
        $worksheet = $spreadsheet->getSheetByName('CAHIER EXPEDITIONS');
        $highestRow = $worksheet->getHighestRow();

        // Chercher les dernières dates (seulement 20 lignes pour économiser la mémoire)
        print '<p><strong>📅 Dates trouvées dans les 20 dernières lignes:</strong></p>';
        print '<ul>';
        $datesFound = [];
        for ($i = $highestRow; $i >= max(2, $highestRow - 20); $i--) {
            $cellA = trim($worksheet->getCell('A'.$i)->getValue());
            if (preg_match('/(\d{2}\/\d{2}\/\d{4})$/i', $cellA, $matches)) {
                $datesFound[] = $cellA;
                print "<li>Ligne $i: <strong>$cellA</strong></li>";
            }
        }
        print '</ul>';

        // Libérer un peu de mémoire
        gc_collect_cycles();

        // Chercher "mardi 14/10/2025" (recherche à partir de la fin pour plus d'efficacité)
        print '<p>🎯 <strong>Recherche de "mardi 14/10/2025"...</strong></p>';
        $found = false;

        // Recherche dans les 200 dernières lignes seulement pour économiser la mémoire
        $startRow = max(2, $highestRow - 200);
        for ($i = $highestRow; $i >= $startRow; $i--) {
            $cellA = trim($worksheet->getCell('A'.$i)->getValue());
            if (stripos($cellA, 'mardi') !== false && stripos($cellA, '14/10/2025') !== false) {
                print "<p style='color: green; font-size: 16px;'><strong>✅ TROUVÉ à la ligne $i: $cellA</strong></p>";
                $found = true;

                // Afficher les 5 lignes suivantes seulement (pas 10)
                print '<p><strong>Lignes suivantes:</strong></p>';
                print '<ul>';
                for ($j = $i + 1; $j <= min($i + 5, $highestRow); $j++) {
                    $cellI = trim($worksheet->getCell('I'.$j)->getValue());
                    $cellH = trim($worksheet->getCell('H'.$j)->getValue());
                    $cellB = trim($worksheet->getCell('B'.$j)->getValue());
                    if (!empty($cellI)) {
                        print "<li>Ligne $j: <strong>SH $cellI</strong> - Tracking: $cellH - Transporteur: $cellB</li>";
                    }
                }
                print '</ul>';

                // Sauvegarder ce fichier comme cache
                print '<p>5️⃣ Sauvegarde du fichier dans le cache...</p>';
                $cacheFile = '/tmp/gdrive_cache_' . $fileId . '.xlsx';
                $cacheMetaFile = '/tmp/gdrive_cache_' . $fileId . '.meta';
                copy($bestFile, $cacheFile);
                file_put_contents($cacheMetaFile, time());
                print "<p style='color: green;'>✓ Cache mis à jour avec le fichier le plus récent</p>";

                // Libérer la mémoire
                $spreadsheet->disconnectWorksheets();
                unset($spreadsheet);

                break;
            }
        }

        if (!$found) {
            print "<p style='color: red; font-size: 16px;'><strong>❌ 'mardi 14/10/2025' NON TROUVÉ</strong></p>";
            print '<p>⚠️ Le fichier exporté par Google Drive ne contient pas encore cette date.</p>';
            print '<p><strong>Causes possibles:</strong></p>';
            print '<ul>';
            print '<li>Cache CDN de Google (peut prendre 1-5 minutes)</li>';
            print '<li>La modification n\'a pas été sauvegardée dans Google Sheets</li>';
            print '<li>Le fichier partagé est une ancienne version</li>';
            print '<li>Google Sheets utilise plusieurs serveurs avec réplication différée</li>';
            print '</ul>';
            print '<p><strong>Solutions:</strong></p>';
            print '<ul>';
            print '<li>Attendre 2-3 minutes puis réessayer</li>';
            print '<li>Vérifier que le fichier est bien sauvegardé dans Google Sheets</li>';
            print '<li>Essayer de faire "Fichier > Télécharger > Microsoft Excel" manuellement</li>';
            print '</ul>';
        }

        // Nettoyer fichiers temporaires
        foreach (glob('/tmp/test_refresh_*') as $file) {
            @unlink($file);
        }

    } else {
        print '<p style="color: red;"><strong>❌ Échec de tous les téléchargements</strong></p>';
    }

    print '</div>';

    print '<div class="center" style="margin-top: 20px;">';
    print '<a href="' . $_SERVER['PHP_SELF'] . '" class="button">◀ Retour</a> ';
    print '<a href="tracking.php" class="button">📦 Voir le tracking</a>';
    print '</div>';

} else {
    // Formulaire
    print '<div class="info">';
    print '<p>Cette page force un téléchargement frais du fichier Google Drive en utilisant plusieurs techniques de bypass de cache.</p>';
    print '<p><strong>⚠️ Attention:</strong> Cette opération peut prendre 30-60 secondes.</p>';
    print '</div>';

    print '<div class="center" style="margin-top: 30px;">';
    print '<a href="' . $_SERVER['PHP_SELF'] . '?action=force_refresh" class="button button-delete">🔄 FORCER LE RAFRAÎCHISSEMENT</a>';
    print '</div>';

    // Afficher l'état actuel
    $fileId = $conf->global->SHIPMENTTRACKING_GDRIVE_FILE_ID;
    if (!empty($fileId)) {
        print '<h3 style="margin-top: 40px;">📊 État actuel du cache</h3>';

        $cacheFile = '/tmp/gdrive_cache_' . $fileId . '.xlsx';
        $cacheMetaFile = '/tmp/gdrive_cache_' . $fileId . '.meta';

        if (file_exists($cacheFile) && file_exists($cacheMetaFile)) {
            $cacheTime = (int)file_get_contents($cacheMetaFile);
            $age = time() - $cacheTime;
            $size = filesize($cacheFile);

            print '<table class="noborder centpercent">';
            print '<tr class="liste_titre"><td>Propriété</td><td>Valeur</td></tr>';
            print '<tr><td>Dernier téléchargement</td><td>' . date('Y-m-d H:i:s', $cacheTime) . ' (il y a ' . $age . 's)</td></tr>';
            print '<tr><td>Taille</td><td>' . round($size/1024/1024, 2) . ' MB</td></tr>';
            print '<tr><td>Fichier</td><td>' . $cacheFile . '</td></tr>';
            print '</table>';
        } else {
            print '<p style="color: orange;">⚠️ Aucun cache local trouvé</p>';
        }
    }
}

llxFooter();
$db->close();
