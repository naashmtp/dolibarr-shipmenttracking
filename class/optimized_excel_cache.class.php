<?php
/**
 * Class OptimizedExcelCache
 * Cache intelligent et optimisé pour le fichier Excel Google Drive
 */
class OptimizedExcelCache
{
    private $db;
    private $cacheDir = '/tmp/shipment_cache';
    const CACHE_DURATION = 1800; // 30 minutes - Synchronisé avec RealtimeGoogleDriveHandler

    public function __construct($db)
    {
        $this->db = $db;

        // Créer le dossier de cache s'il n'existe pas
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0755, true);
        }
    }

    /**
     * Récupère les données pour une date spécifique
     * STRATÉGIE INTELLIGENTE: Au premier chargement, met en cache les 30 dernières dates
     * pour que toutes les dates soient instantanées ensuite!
     */
    public function getDataForDate($excelFile, $date)
    {
        // Vérifier le cache JSON pour cette date
        $cacheKey = md5($excelFile . $date);
        $cacheFile = $this->cacheDir . '/date_' . $cacheKey . '.json';
        $cacheMetaFile = $this->cacheDir . '/date_' . $cacheKey . '.meta';

        // Si le cache existe et est récent
        if (file_exists($cacheFile) && file_exists($cacheMetaFile)) {
            $cacheTime = (int)file_get_contents($cacheMetaFile);
            $age = time() - $cacheTime;

            if ($age < self::CACHE_DURATION) {
                if (function_exists('dol_syslog')) {
                    dol_syslog("OptimizedExcelCache: Using JSON cache for date $date (age: {$age}s)", LOG_DEBUG);
                }
                $data = json_decode(file_get_contents($cacheFile), true);
                return $data;
            }
        }

        // Sinon, extraire TOUTES les dates récentes en un seul passage
        if (function_exists('dol_syslog')) {
            dol_syslog("OptimizedExcelCache: Parsing Excel and caching last 30 dates", LOG_INFO);
        }

        $allDates = $this->extractAllRecentDates($excelFile, 30);

        // Sauvegarder toutes les dates dans le cache
        $now = time();
        foreach ($allDates as $dateStr => $dateData) {
            $dateCacheKey = md5($excelFile . $dateStr);
            $dateCacheFile = $this->cacheDir . '/date_' . $dateCacheKey . '.json';
            $dateCacheMetaFile = $this->cacheDir . '/date_' . $dateCacheKey . '.meta';

            file_put_contents($dateCacheFile, json_encode($dateData));
            file_put_contents($dateCacheMetaFile, $now);
        }

        if (function_exists('dol_syslog')) {
            dol_syslog("OptimizedExcelCache: Cached " . count($allDates) . " dates", LOG_INFO);
        }

        // Retourner la date demandée
        return isset($allDates[$date]) ? $allDates[$date] : ['found' => false];
    }

    /**
     * Extrait les données d'une date spécifique depuis le fichier Excel
     * Utilise les options d'optimisation de PhpSpreadsheet
     */
    private function extractDateFromExcel($excelFile, $date_filter)
    {
        try {
            require_once __DIR__ . '/../vendor/autoload.php';

            // Options d'optimisation MAXIMALE pour lecture rapide
            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($excelFile);
            $reader->setReadDataOnly(true); // Ignorer les styles, formules, etc.
            $reader->setLoadSheetsOnly(['CAHIER EXPEDITIONS']); // Charger UNIQUEMENT cet onglet (96% plus rapide!)

            $spreadsheet = $reader->load($excelFile);
            $worksheet = $spreadsheet->getSheetByName('CAHIER EXPEDITIONS');

            if (!$worksheet) {
                return false;
            }

            $highestRow = $worksheet->getHighestRow();
            $dataBuffer = [];
            $dateCount = 0;
            $targetDateFound = false;

            // Parcourir de la fin vers le début
            for ($rowIndex = $highestRow; $rowIndex >= 2; $rowIndex--) {
                $cell = $worksheet->getCell('A'.$rowIndex);
                $cellValue = $cell->getValue();
                $cellA = trim($cellValue);

                // Convertir les dates Excel numériques en format lisible
                if (is_numeric($cellValue) && $cellValue > 40000) {
                    try {
                        $excelDate = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($cellValue);
                        $cellA = $excelDate->format('d/m/Y');
                    } catch (Exception $e) {
                        // Pas une date Excel valide
                    }
                }

                // Vérifier si c'est une ligne de date (avec ou sans jour de la semaine)
                // Accepte: "14/10/2025" ou "mardi 14/10/2025" ou "Mardi 14/10/2025"
                if (preg_match('/(\d{2}\/\d{2}\/\d{4})$/i', $cellA, $matches)) {
                    $dateStr = $matches[0];
                    $date = DateTime::createFromFormat('d/m/Y', $dateStr);
                    $dateFormatted = $date ? $date->format('Y-m-d') : null;

                    // FIX: Si c'est la date recherchée et qu'on a collecté des données
                    if ($dateFormatted === $date_filter && count($dataBuffer) > 0) {
                        // Libérer la mémoire
                        $spreadsheet->disconnectWorksheets();
                        unset($spreadsheet);

                        return [
                            'found' => true,
                            'date' => $dateStr,
                            'data' => array_reverse($dataBuffer) // Remettre dans l'ordre chronologique
                        ];
                    }

                    // Reset buffer pour la prochaine section
                    $dataBuffer = [];

                    // Limiter le parcours aux 30 dernières dates pour optimiser
                    if (++$dateCount >= 30) {
                        break;
                    }
                } else {
                    // Collecter les données
                    $cellI = trim($worksheet->getCell('I'.$rowIndex)->getValue());
                    $cellH = trim($worksheet->getCell('H'.$rowIndex)->getValue());
                    $cellB = trim($worksheet->getCell('B'.$rowIndex)->getValue());

                    if (!empty($cellI) || !empty($cellH)) {
                        $dataBuffer[] = [
                            'shipment' => $cellI,
                            'tracking' => $cellH,
                            'carrier' => $cellB
                        ];
                    }
                }
            }

            // Libérer la mémoire
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);

            return ['found' => false];

        } catch (Exception $e) {
            if (function_exists('dol_syslog')) {
                dol_syslog("OptimizedExcelCache: Error parsing Excel - " . $e->getMessage(), LOG_ERR);
            }
            return false;
        }
    }

    /**
     * Extrait TOUTES les dates récentes en un seul passage
     * MÉTHODE INTELLIGENTE: Un seul chargement, 30 dates en cache!
     */
    private function extractAllRecentDates($excelFile, $maxDates = 30)
    {
        try {
            require_once __DIR__ . '/../vendor/autoload.php';

            // Options d'optimisation MAXIMALE
            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($excelFile);
            $reader->setReadDataOnly(true);
            $reader->setLoadSheetsOnly(['CAHIER EXPEDITIONS']);

            $spreadsheet = $reader->load($excelFile);
            $worksheet = $spreadsheet->getSheetByName('CAHIER EXPEDITIONS');

            if (!$worksheet) {
                return [];
            }

            $highestRow = $worksheet->getHighestRow();
            $allDates = [];
            $dataBuffer = [];

            // Parcourir de la fin vers le début (dates récentes en premier)
            for ($rowIndex = $highestRow; $rowIndex >= 2; $rowIndex--) {
                $cell = $worksheet->getCell('A'.$rowIndex);
                $cellValue = $cell->getValue();
                $cellA = trim($cellValue);

                // Convertir les dates Excel numériques en format lisible
                if (is_numeric($cellValue) && $cellValue > 40000) {
                    try {
                        $excelDate = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($cellValue);
                        $cellA = $excelDate->format('d/m/Y');
                    } catch (Exception $e) {
                        // Pas une date Excel valide
                    }
                }

                // Vérifier si c'est une ligne de date (avec ou sans jour de la semaine)
                // Accepte: "14/10/2025" ou "mardi 14/10/2025" ou "Mardi 14/10/2025"
                if (preg_match('/(\d{2}\/\d{2}\/\d{4})$/i', $cellA, $matches)) {
                    $dateStr = $matches[0];
                    $date = DateTime::createFromFormat('d/m/Y', $dateStr);
                    $dateFormatted = $date ? $date->format('Y-m-d') : null;

                    if ($dateFormatted) {
                        // FIX: Les données dans le buffer appartiennent à CETTE date (celle qu'on vient de trouver)
                        // car on lit de bas en haut: on collecte d'abord les données, puis on trouve leur date
                        if (count($dataBuffer) > 0) {
                            $allDates[$dateFormatted] = [
                                'found' => true,
                                'date' => $dateStr,
                                'data' => array_reverse($dataBuffer)
                            ];

                            // Limiter aux N dernières dates
                            if (count($allDates) >= $maxDates) {
                                break;
                            }
                        }

                        // Reset du buffer pour la prochaine section
                        $dataBuffer = [];
                    }
                } else {
                    // Collecter les données pour la date en cours
                    $cellI = trim($worksheet->getCell('I'.$rowIndex)->getValue());
                    $cellH = trim($worksheet->getCell('H'.$rowIndex)->getValue());
                    $cellB = trim($worksheet->getCell('B'.$rowIndex)->getValue());

                    if (!empty($cellI) || !empty($cellH)) {
                        $dataBuffer[] = [
                            'shipment' => $cellI,
                            'tracking' => $cellH,
                            'carrier' => $cellB
                        ];
                    }
                }
            }

            // Libérer la mémoire
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);

            return $allDates;

        } catch (Exception $e) {
            if (function_exists('dol_syslog')) {
                dol_syslog("OptimizedExcelCache: Error extracting all dates - " . $e->getMessage(), LOG_ERR);
            }
            return [];
        }
    }

    /**
     * Vide tout le cache
     */
    public function clearAll()
    {
        $files = glob($this->cacheDir . '/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        if (function_exists('dol_syslog')) {
            dol_syslog("OptimizedExcelCache: All cache cleared", LOG_INFO);
        }
    }

    /**
     * Vide le cache pour une date spécifique
     */
    public function clearDate($excelFile, $date)
    {
        $cacheKey = md5($excelFile . $date);
        $cacheFile = $this->cacheDir . '/date_' . $cacheKey . '.json';
        $cacheMetaFile = $this->cacheDir . '/date_' . $cacheKey . '.meta';

        if (file_exists($cacheFile)) @unlink($cacheFile);
        if (file_exists($cacheMetaFile)) @unlink($cacheMetaFile);

        if (function_exists('dol_syslog')) {
            dol_syslog("OptimizedExcelCache: Cache cleared for date $date", LOG_DEBUG);
        }
    }

    /**
     * Obtient les stats du cache
     */
    public function getStats()
    {
        $files = glob($this->cacheDir . '/*.json');
        $totalSize = 0;
        $count = 0;

        foreach ($files as $file) {
            if (is_file($file)) {
                $totalSize += filesize($file);
                $count++;
            }
        }

        return [
            'cached_dates' => $count,
            'total_size_mb' => round($totalSize / 1024 / 1024, 2),
            'cache_dir' => $this->cacheDir
        ];
    }
}
