<?php
/**
 * Page de statut du cache en temps réel
 */

// Load Dolibarr environment
require_once '../../main.inc.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once './class/realtime_gdrive_handler.class.php';

// Access control
if (!$user->admin) accessforbidden();

$action = GETPOST('action', 'alpha');

// Action pour forcer le rafraîchissement
if ($action == 'refresh') {
    $handler = new RealtimeGoogleDriveHandler($db);
    $fileId = $conf->global->SHIPMENTTRACKING_GDRIVE_FILE_ID;
    $handler->clearCache($fileId);
    setEventMessages("Cache vidé. Le prochain accès téléchargera une nouvelle version.", []);
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

llxHeader('', 'Statut Cache Temps Réel');

print load_fiche_titre('Statut du Cache Google Drive - Temps Réel', '', 'technic');

$fileId = $conf->global->SHIPMENTTRACKING_GDRIVE_FILE_ID;

if (empty($fileId)) {
    print '<div class="error">ID Google Drive non configuré</div>';
    llxFooter();
    exit;
}

$handler = new RealtimeGoogleDriveHandler($db);
$cacheInfo = $handler->getCacheInfo($fileId);

print '<div style="background: white; padding: 30px; border-radius: 10px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); margin: 20px 0;">';

if (!$cacheInfo['exists']) {
    print '<div style="text-align: center; padding: 40px;">';
    print '<h2 style="color: #ff9800;">⚠️ Aucun cache</h2>';
    print '<p style="font-size: 16px; color: #666;">Le fichier sera téléchargé depuis Google Drive au prochain accès.</p>';
    print '</div>';
} else {
    $statusColor = $cacheInfo['is_fresh'] ? '#4CAF50' : '#ff9800';
    $statusIcon = $cacheInfo['is_fresh'] ? '✅' : '⚠️';
    $statusText = $cacheInfo['is_fresh'] ? 'FRAIS' : 'PÉRIMÉ';

    print '<div style="text-align: center; margin-bottom: 30px;">';
    print '<h2 style="color: ' . $statusColor . '; font-size: 32px;">' . $statusIcon . ' Cache ' . $statusText . '</h2>';
    print '</div>';

    print '<table class="noborder centpercent" style="font-size: 16px;">';

    print '<tr class="oddeven">';
    print '<td style="width: 40%; padding: 15px;"><strong>Âge du cache</strong></td>';
    print '<td style="padding: 15px;">';
    print '<span style="font-size: 24px; color: ' . $statusColor . '; font-weight: bold;">' . $cacheInfo['age_formatted'] . '</span>';
    print '</td>';
    print '</tr>';

    print '<tr class="oddeven">';
    print '<td style="padding: 15px;"><strong>Date de mise en cache</strong></td>';
    print '<td style="padding: 15px;">' . $cacheInfo['cache_time'] . '</td>';
    print '</tr>';

    print '<tr class="oddeven">';
    print '<td style="padding: 15px;"><strong>Taille du fichier</strong></td>';
    print '<td style="padding: 15px;">' . $cacheInfo['size_mb'] . ' MB</td>';
    print '</tr>';

    print '<tr class="oddeven">';
    print '<td style="padding: 15px;"><strong>Durée du cache</strong></td>';
    print '<td style="padding: 15px;">30 secondes</td>';
    print '</tr>';

    print '<tr class="oddeven">';
    print '<td style="padding: 15px;"><strong>Prochain rafraîchissement</strong></td>';
    print '<td style="padding: 15px;">';
    if ($cacheInfo['is_fresh']) {
        print 'Dans ' . $cacheInfo['next_refresh'];
    } else {
        print '<span style="color: #ff9800; font-weight: bold;">Au prochain accès</span>';
    }
    print '</td>';
    print '</tr>';

    print '</table>';
}

print '</div>';

// Barre de progression pour l'âge du cache
if ($cacheInfo['exists']) {
    $percentage = min(100, ($cacheInfo['age'] / 30) * 100);
    $barColor = $percentage < 100 ? '#4CAF50' : '#ff9800';

    print '<div style="background: #f5f5f5; padding: 20px; border-radius: 10px; margin: 20px 0;">';
    print '<h3>Fraîcheur du cache</h3>';
    print '<div style="background: #e0e0e0; height: 30px; border-radius: 15px; overflow: hidden; position: relative;">';
    print '<div style="background: ' . $barColor . '; height: 100%; width: ' . $percentage . '%; transition: all 0.3s;"></div>';
    print '<div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); font-weight: bold; color: #333;">';
    print round($percentage) . '%';
    print '</div>';
    print '</div>';
    print '<p style="text-align: center; margin-top: 10px; color: #666;">Le cache expire après 30 secondes</p>';
    print '</div>';
}

// Actions
print '<div style="text-align: center; margin: 30px 0;">';
print '<a href="' . $_SERVER['PHP_SELF'] . '" class="button" style="background: #2196F3; color: white; padding: 12px 24px; margin: 5px; text-decoration: none; border-radius: 5px;">🔄 ACTUALISER L\'ÉTAT</a>';
print '<a href="' . $_SERVER['PHP_SELF'] . '?action=refresh" class="button" style="background: #ff9800; color: white; padding: 12px 24px; margin: 5px; text-decoration: none; border-radius: 5px;">🗑️ VIDER LE CACHE</a>';
print '<a href="' . DOL_URL_ROOT . '/custom/shipmenttracking/tracking.php" class="button" style="background: #8a2be2; color: white; padding: 12px 24px; margin: 5px; text-decoration: none; border-radius: 5px;">📦 ALLER AU TRACKING</a>';
print '</div>';

// Explications
print '<div style="background: #e3f2fd; padding: 20px; border-radius: 10px; margin: 20px 0;">';
print '<h3>💡 Comment ça marche ?</h3>';
print '<ul style="line-height: 2;">';
print '<li><strong>Cache intelligent</strong> : Le fichier est conservé en cache pendant 30 secondes</li>';
print '<li><strong>Actualisation automatique</strong> : Après 30 secondes, le fichier est re-téléchargé depuis Google Drive</li>';
print '<li><strong>Performance optimale</strong> : Évite de télécharger le fichier à chaque requête</li>';
print '<li><strong>Données fraîches</strong> : Maximum 30 secondes de délai par rapport à Google Drive</li>';
print '<li><strong>Fallback intelligent</strong> : Si le téléchargement échoue, utilise le cache même périmé</li>';
print '</ul>';
print '</div>';

// Auto-refresh toutes les 5 secondes
print '<script>setTimeout(function(){ window.location.reload(); }, 5000);</script>';

llxFooter();
?>
