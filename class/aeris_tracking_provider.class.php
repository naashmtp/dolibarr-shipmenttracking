<?php
/**
 * Class AerisTrackingProvider
 * Récupère les numéros de suivi depuis l'API Aeris (cahier d'expédition)
 */
class AerisTrackingProvider
{
    private $db;
    private $apiUrl;

    public function __construct($db)
    {
        global $conf;
        $this->db = $db;
        $this->apiUrl = !empty($conf->global->SHIPMENTTRACKING_AERIS_API_URL)
            ? $conf->global->SHIPMENTTRACKING_AERIS_API_URL
            : 'http://localhost:3003/api/cahier-expedition';
    }

    /**
     * Récupère tous les trackings pour une date donnée
     *
     * @param string $date Format YYYY-MM-DD
     * @return array|false Tableau indexé par numéro SH : ['14891' => ['tracking' => 'XN...', 'carrier' => 'CHRONO 18']]
     */
    public function getTrackingByDate($date)
    {
        $data = $this->callApi(array('date' => $date));
        if ($data === false) {
            return false;
        }

        $trackingMap = array();
        foreach ($data as $record) {
            $sh = isset($record['sh']) ? trim($record['sh']) : '';
            $tracking = isset($record['numero_suivi']) ? trim($record['numero_suivi']) : '';
            $carrier = isset($record['transporteur']) ? trim($record['transporteur']) : '';

            if (empty($sh) || empty($tracking)) {
                continue;
            }

            // Gérer les SH multiples (format: "14864 + 14880")
            if (strpos($sh, '+') !== false) {
                $shNumbers = array_map('trim', explode('+', $sh));
            } else {
                $shNumbers = array($sh);
            }

            foreach ($shNumbers as $shNum) {
                $clean = preg_replace('/[^0-9]/', '', $shNum);
                if (empty($clean)) {
                    continue;
                }

                if (isset($trackingMap[$clean])) {
                    // SH apparaît dans plusieurs lignes → concaténer les trackings
                    if (!is_array($trackingMap[$clean]['tracking'])) {
                        $trackingMap[$clean]['tracking'] = array($trackingMap[$clean]['tracking']);
                    }
                    $trackingMap[$clean]['tracking'][] = $tracking;
                } else {
                    $trackingMap[$clean] = array(
                        'tracking' => $tracking,
                        'carrier' => $carrier
                    );
                }
            }
        }

        return $trackingMap;
    }

    /**
     * Récupère le numéro de suivi pour un numéro SH donné
     *
     * @param string $shNumber Numéro SH (ex: "14891")
     * @return string|false Numéro de suivi ou false
     */
    public function getTrackingBySH($shNumber)
    {
        $data = $this->callApi(array('sh' => $shNumber));
        if ($data === false || empty($data)) {
            return false;
        }

        // Prendre le premier résultat avec un numéro de suivi
        foreach ($data as $record) {
            $tracking = isset($record['numero_suivi']) ? trim($record['numero_suivi']) : '';
            if (!empty($tracking)) {
                // Vérifier que le SH correspond (peut être dans un multi-SH)
                $sh = isset($record['sh']) ? trim($record['sh']) : '';
                if (strpos($sh, '+') !== false) {
                    $shNumbers = array_map('trim', explode('+', $sh));
                    foreach ($shNumbers as $s) {
                        if (preg_replace('/[^0-9]/', '', $s) === strval($shNumber)) {
                            return $tracking;
                        }
                    }
                } else {
                    return $tracking;
                }
            }
        }

        return false;
    }

    /**
     * Vérifie la connectivité avec l'API Aeris
     *
     * @return bool
     */
    public function checkConnection()
    {
        $data = $this->callApi(array('date' => date('Y-m-d')));
        return $data !== false;
    }

    /**
     * Appel HTTP vers l'API Aeris
     *
     * @param array $params Paramètres de requête
     * @return array|false Données JSON décodées ou false
     */
    private function callApi($params)
    {
        $url = $this->apiUrl . '?' . http_build_query($params);

        $context = stream_context_create(array(
            'http' => array(
                'method' => 'GET',
                'timeout' => 5,
                'header' => 'Accept: application/json'
            )
        ));

        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            dol_syslog('AerisTrackingProvider: Erreur appel API ' . $url, LOG_ERR);
            return false;
        }

        $data = json_decode($response, true);
        if ($data === null) {
            dol_syslog('AerisTrackingProvider: Erreur décodage JSON depuis ' . $url, LOG_ERR);
            return false;
        }

        return $data;
    }
}
