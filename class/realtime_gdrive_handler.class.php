<?php
/**
 * Class RealtimeGoogleDriveHandler
 * Gestion de la synchronisation en temps réel avec Google Drive
 */
class RealtimeGoogleDriveHandler
{
    private $db;
    private $error;

    const CACHE_DURATION = 1800; // Cache de 30 minutes (1800 secondes) - Optimisé pour éviter les retéléchargements fréquents

    /**
     * Constructeur
     */
    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Obtient le fichier le plus récent depuis Google Drive
     * Utilise un cache de 30 secondes pour éviter trop de téléchargements
     *
     * @param string $fileId ID du fichier Google Drive
     * @return string|false Chemin vers le fichier à utiliser
     */
    public function getLatestFile($fileId)
    {
        $cacheFile = '/tmp/gdrive_cache_' . $fileId . '.xlsx';
        $cacheMetaFile = '/tmp/gdrive_cache_' . $fileId . '.meta';

        // Vérifier si le cache existe et est récent (< 30 secondes)
        if (file_exists($cacheFile) && file_exists($cacheMetaFile)) {
            $cacheTime = (int)file_get_contents($cacheMetaFile);
            $age = time() - $cacheTime;

            if ($age < self::CACHE_DURATION) {
                dol_syslog("RealtimeGoogleDriveHandler: Using cache (age: {$age}s)", LOG_DEBUG);
                return $cacheFile;
            }
        }

        // Télécharger la nouvelle version - OPTIMISÉ avec timeout court
        dol_syslog("RealtimeGoogleDriveHandler: Downloading fresh copy from Google Drive", LOG_INFO);

        // UNE SEULE URL simple avec timeout court (10s max)
        $timestamp = time();
        $url = "https://docs.google.com/spreadsheets/d/{$fileId}/export?format=xlsx&v={$timestamp}";

        $context = stream_context_create([
            'http' => [
                'follow_location' => 1,
                'max_redirects' => 3,
                'timeout' => 10, // ⚡ TIMEOUT RÉDUIT à 10 secondes au lieu de 30!
                'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                'header' => "Cache-Control: no-cache\r\n"
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false
            ]
        ]);

        $content = @file_get_contents($url, false, $context);

        if ($content === false || strlen($content) < 100000) {
            $this->error = "Failed to download file from Google Drive (tried multiple URLs)";
            dol_syslog("RealtimeGoogleDriveHandler: All download attempts failed", LOG_ERR);

            // Si échec, utiliser le cache même s'il est vieux
            if (file_exists($cacheFile)) {
                dol_syslog("RealtimeGoogleDriveHandler: Fallback to old cache", LOG_WARNING);
                return $cacheFile;
            }

            return false;
        }

        file_put_contents($cacheFile, $content);
        file_put_contents($cacheMetaFile, time());

        $sizeMB = round(strlen($content) / 1024 / 1024, 2);
        dol_syslog("RealtimeGoogleDriveHandler: Fresh file cached ({$sizeMB} MB)", LOG_INFO);

        return $cacheFile;
    }

    /**
     * Force la suppression du cache pour forcer un nouveau téléchargement
     *
     * @param string $fileId ID du fichier Google Drive
     */
    public function clearCache($fileId)
    {
        $cacheFile = '/tmp/gdrive_cache_' . $fileId . '.xlsx';
        $cacheMetaFile = '/tmp/gdrive_cache_' . $fileId . '.meta';

        if (file_exists($cacheFile)) {
            @unlink($cacheFile);
        }
        if (file_exists($cacheMetaFile)) {
            @unlink($cacheMetaFile);
        }

        dol_syslog("RealtimeGoogleDriveHandler: Cache cleared", LOG_INFO);
    }

    /**
     * Obtient les informations sur le cache actuel
     *
     * @param string $fileId ID du fichier Google Drive
     * @return array Info sur le cache
     */
    public function getCacheInfo($fileId)
    {
        $cacheFile = '/tmp/gdrive_cache_' . $fileId . '.xlsx';
        $cacheMetaFile = '/tmp/gdrive_cache_' . $fileId . '.meta';

        if (!file_exists($cacheFile) || !file_exists($cacheMetaFile)) {
            return [
                'exists' => false,
                'age' => null,
                'size' => null,
                'is_fresh' => false
            ];
        }

        $cacheTime = (int)file_get_contents($cacheMetaFile);
        $age = time() - $cacheTime;
        $size = filesize($cacheFile);
        $sizeMB = round($size / 1024 / 1024, 2);

        return [
            'exists' => true,
            'age' => $age,
            'age_formatted' => $age < 60 ? "{$age}s" : floor($age/60) . "m " . ($age%60) . "s",
            'size' => $size,
            'size_mb' => $sizeMB,
            'is_fresh' => $age < self::CACHE_DURATION,
            'cache_time' => date('Y-m-d H:i:s', $cacheTime),
            'next_refresh' => $age < self::CACHE_DURATION ? (self::CACHE_DURATION - $age) . "s" : "Now"
        ];
    }

    /**
     * Récupère la dernière erreur
     */
    public function getError()
    {
        return $this->error;
    }
}
