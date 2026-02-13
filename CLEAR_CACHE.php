<?php
/**
 * Script pour vider tous les caches
 */

if (function_exists('opcache_reset')) {
    opcache_reset();
    echo "✓ OpCache vidé\n";
} else {
    echo "⚠ OpCache non disponible\n";
}

$deleted = 0;
$files = glob('/tmp/shipment_cache/*');
foreach ($files as $file) {
    if (is_file($file)) {
        unlink($file);
        $deleted++;
    }
}
echo "✓ $deleted fichiers de cache supprimés\n";

$gdrive_files = glob('/tmp/gdrive_cache_*.meta');
foreach ($gdrive_files as $file) {
    unlink($file);
}
echo "✓ Cache Google Drive réinitialisé\n";

echo "\n✅ Tous les caches ont été vidés!\n";
echo "Accédez maintenant à votre page de tracking dans Dolibarr.\n";
