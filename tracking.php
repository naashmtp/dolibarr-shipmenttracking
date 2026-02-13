<?php
require_once '../../main.inc.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once './class/EmailTemplateHandler.class.php';
require_once './class/aeris_tracking_provider.class.php';
if (isset($_GET['test_mode'])) {
    dolibarr_set_const($db, 'SHIPMENTTRACKING_TEST_MODE', $_GET['test_mode'] ? 1 : 0, 'chaine', 0, '', $conf->entity);
}

if (isset($_GET['test_email']) && !empty($_GET['test_email'])) {
    dolibarr_set_const($db, 'SHIPMENTTRACKING_TEST_EMAIL', $_GET['test_email'], 'chaine', 0, '', $conf->entity);
}

llxHeader('', 'ShipmentTracking');

class TrackingMailTester
{
    private $db;
    private $emailHandler;

    public function __construct($db)
    {
        $this->db = $db;
        $this->emailHandler = new EmailTemplateHandler($db);
    }

    public function analyze($date_filter = '')
    {
        global $conf;

        if (empty($date_filter)) {
            $date_filter = date('Y-m-d');
        }

        if (!$this->checkAerisConnection()) {
            return false;
        }

        $trackingData = $this->getTrackingFromAeris($date_filter);
        if ($trackingData === false) {
            return false;
        }

        $shipments = $this->getEligibleShipments(array_keys($trackingData), $date_filter);

        $this->displayFilters($date_filter);

        $this->displayAnalysis($shipments, $trackingData, $date_filter);
    }

    private function displayFilters($current_date)
{
    global $conf;

    print '<div class="fichecenter" style="margin-bottom: 20px;">';
    print '<form method="GET" action="'.$_SERVER["PHP_SELF"].'">';
    print '<div class="div-table-responsive-no-min">';
    print '<table class="noborder centpercent">';

    print '<tr class="liste_titre">';
    print '<td style="background: #8a2be2; color: white;">Sélectionnez une date</td>';
    print '</tr>';
    print '<tr class="oddeven" style="text-align: center;">';
    print '<td style="padding: 15px;">';
    print '<input type="date" name="date_filter" value="'.$current_date.'" style="padding: 8px; margin-right: 10px;">';
    print '<input type="submit" class="button" value="FILTRE" style="background-color: #8a2be2; border: none; padding: 8px 20px; color: white; cursor: pointer;">';
    print '</td>';
    print '</tr>';

    print '<tr class="liste_titre">';
    print '<td style="background: #ffc107; color: black;">Mode Test</td>';
    print '</tr>';
    print '<tr class="oddeven" style="text-align: center;">';
    print '<td style="padding: 15px;">';

    $testMode = !empty($conf->global->SHIPMENTTRACKING_TEST_MODE);
    $testEmail = !empty($conf->global->SHIPMENTTRACKING_TEST_EMAIL) ? $conf->global->SHIPMENTTRACKING_TEST_EMAIL : '';

    print '<div style="margin-bottom: 10px;">';
    print '<label class="switch" style="margin-right: 10px;">';
    print '<input type="checkbox" name="test_mode" value="1" '.($testMode ? 'checked' : '').'
           onchange="this.form.submit();" style="margin-right: 5px;">';
    print 'Activer le mode test';
    print '</label>';
    print '</div>';

    if ($testMode) {
        print '<div style="margin-top: 10px;">';
        print '<input type="email" name="test_email" value="'.$testEmail.'"
               placeholder="Email de test" style="padding: 8px; margin-right: 10px; width: 250px;">';
        print '<input type="submit" class="button" value="SAUVEGARDER"
               style="background-color: #ffc107; border: none; padding: 8px 20px; color: black; cursor: pointer;">';
        print '</div>';
    }

    print '</td>';
    print '</tr>';

    print '</table>';
    print '</div>';
    print '</form>';
    print '</div>';
}

    private function getCurrentSHPrefix($date)
    {
        $dateObj = DateTime::createFromFormat('Y-m-d', $date);
        return 'SH' . $dateObj->format('ym');
    }

    /**
     * Détecte le transporteur à partir du nom Aeris
     */
    private function detectCarrier($carrierName)
    {
        $carrier = strtoupper(trim($carrierName));

        if (preg_match('/^CHRONO/i', $carrier)) {
            return 'CHRONOPOST';
        } elseif (preg_match('/^UPS/i', $carrier)) {
            return 'UPS';
        } elseif (preg_match('/^DHL/i', $carrier)) {
            return 'DHL';
        }

        // Par défaut : DHL (si rien ne correspond)
        return 'DHL';
    }

    private function checkAerisConnection()
    {
        $provider = new AerisTrackingProvider($this->db);
        if (!$provider->checkConnection()) {
            print '<div class="error">Erreur: Impossible de se connecter à l\'API Aeris</div>';
            print '<p>Vérifiez que le service Aeris est démarré et accessible.</p>';
            return false;
        }
        return true;
    }

    private function getTrackingFromAeris($date_filter)
    {
        try {
            $provider = new AerisTrackingProvider($this->db);
            $trackingMap = $provider->getTrackingByDate($date_filter);

            if ($trackingMap === false) {
                throw new Exception("Erreur lors de la récupération des données depuis Aeris");
            }

            // Normaliser les noms de transporteurs
            foreach ($trackingMap as $shNum => &$info) {
                $info['carrier'] = $this->detectCarrier($info['carrier']);
            }
            unset($info);

            return $trackingMap;

        } catch (Exception $e) {
            print '<div class="error">Erreur Aeris : ' . $e->getMessage() . '</div>';
            return false;
        }
    }

    private function getEligibleShipments($expNumbers, $date)
    {
        global $conf;

        if (empty($expNumbers)) {
            return [];
        }

        $shPrefix = $this->getCurrentSHPrefix($date);
        $expList = implode("','", array_map([$this->db, 'escape'], $expNumbers));

        $sql = "SELECT DISTINCT e.rowid, e.ref, e.tracking_number, s.fk_soc, s.ref as order_ref, so.email, so.nom";
        $sql.= " FROM llxbm_expedition as e";
        $sql.= " LEFT JOIN llxbm_element_element as el ON e.rowid = el.fk_target";
        $sql.= " LEFT JOIN llxbm_commande as s ON el.fk_source = s.rowid";
        $sql.= " LEFT JOIN llxbm_societe as so ON s.fk_soc = so.rowid";
        $sql.= " WHERE e.ref LIKE '" . $shPrefix . "-%'";
        $sql.= " AND SUBSTRING_INDEX(e.ref, '-', -1) IN ('".$expList."')";
        $sql.= " AND e.entity = ".$conf->entity;
        // Exclure les clients qui ont FAIRE parmi leurs commerciaux
        $sql.= " AND NOT EXISTS (
            SELECT 1 FROM llxbm_societe_commerciaux sc2
            JOIN llxbm_user u2 ON sc2.fk_user = u2.rowid
            WHERE sc2.fk_soc = so.rowid AND u2.lastname = 'FAIRE'
        )";
        $sql.= " ORDER BY e.ref DESC";

        $result = $this->db->query($sql);
        $shipments = array();

        if ($result) {
            while ($obj = $this->db->fetch_object($result)) {
                $shipments[$obj->ref] = $obj;
            }
        }

        return $shipments;
    }

    private function getShipmentNumber($shipmentRef)
    {
        if (strpos($shipmentRef, '-') !== false) {
            $parts = explode('-', $shipmentRef);
            return end($parts);
        }
        return preg_replace('/[^0-9]/', '', $shipmentRef);
    }

    private function displayAnalysis($shipments, $trackingData, $date_filter)
{
    global $conf;

    // Récupération des emails déjà envoyés
    $sentEmails = array();
    $sql = "SELECT fk_shipment, tracking_number, date_envoi
            FROM " . MAIN_DB_PREFIX . "shipmenttracking_emails
            WHERE shipping_date = '" . $this->db->escape($date_filter) . "'
            AND entity = " . $conf->entity;
    $result = $this->db->query($sql);
    while ($obj = $this->db->fetch_object($result)) {
        $sentEmails[$obj->fk_shipment] = array(
            'tracking' => $obj->tracking_number,
            'date_envoi' => $obj->date_envoi
        );
    }

    if (empty($shipments)) {
        print '<div class="info" style="text-align: center; padding: 20px; background: #f8f9fa; border-radius: 4px; margin: 20px 0;">';
        print 'Aucune expédition trouvée pour cette date';
        print '</div>';
        return;
    }

    // GROUPER les expéditions par numéro de tracking (pour les multi-SH d'Aeris)
    $groupedByTracking = array();
    foreach ($shipments as $shipment) {
        $shNumber = $this->getShipmentNumber($shipment->ref);
        $trackingInfo = isset($trackingData[$shNumber]) ? $trackingData[$shNumber] : null;
        $trackingValue = '';
        if ($trackingInfo) {
            $trackingValue = is_array($trackingInfo['tracking']) ?
                implode(', ', $trackingInfo['tracking']) :
                $trackingInfo['tracking'];
        }

        // Clé de groupement : tracking + email (même tracking mais clients différents = emails séparés)
        $groupKey = $trackingValue . '|' . $shipment->email;

        if (!isset($groupedByTracking[$groupKey])) {
            $groupedByTracking[$groupKey] = array(
                'shipments' => array(),
                'tracking' => $trackingValue,
                'carrier' => $trackingInfo ? $trackingInfo['carrier'] : 'CHRONOPOST',
                'email' => $shipment->email,
                'nom' => $shipment->nom,
                'allSent' => true,
                'sentDate' => null
            );
        }
        $groupedByTracking[$groupKey]['shipments'][] = $shipment;

        if (!isset($sentEmails[$shipment->rowid])) {
            $groupedByTracking[$groupKey]['allSent'] = false;
        } else {
            $groupedByTracking[$groupKey]['sentDate'] = $sentEmails[$shipment->rowid]['date_envoi'];
        }
    }

    print '<form method="POST" action="' . $_SERVER["PHP_SELF"] . '">';
    print '<input type="hidden" name="token" value="' . newToken() . '">';
    print '<input type="hidden" name="date_filter" value="' . GETPOST('date_filter', 'alpha') . '">';

    print '<div style="margin-bottom: 20px; display: flex; gap: 20px; justify-content: center;">';
    $totalGroups = count($groupedByTracking);
    $readyToSend = 0;
    $missingEmail = 0;
    $missingTracking = 0;
    $alreadySent = 0;

    foreach ($groupedByTracking as $group) {
        if ($group['allSent']) {
            $alreadySent++;
        } elseif (empty($group['email'])) {
            $missingEmail++;
        } elseif (empty($group['tracking'])) {
            $missingTracking++;
        } else {
            $readyToSend++;
        }
    }

    $statBoxes = [
        ['Total envois', $totalGroups, '#6c757d'],
        ['Prêts à envoyer', $readyToSend, '#28a745'],
        ['Déjà envoyés', $alreadySent, '#4CAF50'],
        ['Sans email', $missingEmail, '#ffc107'],
        ['Sans tracking', $missingTracking, '#dc3545']
    ];

    foreach ($statBoxes as $box) {
        print '<div style="background: white; padding: 15px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); text-align: center; min-width: 150px;">';
        print '<div style="color: '.$box[2].'; font-size: 24px; font-weight: bold;">'.$box[1].'</div>';
        print '<div style="color: #666;">'.$box[0].'</div>';
        print '</div>';
    }
    print '</div>';

    print '<div class="div-table-responsive-no-min" style="margin-top: 20px;">';
    print '<table class="noborder centpercent">';
    print '<tr class="liste_titre" style="background: #8a2be2; color: white;">';
    print '<td>Expédition(s)</td>';
    print '<td>Client</td>';
    print '<td>Transporteur</td>';
    print '<td>N° Tracking</td>';
    print '<td>Email</td>';
    print '<td style="width: 120px;">Statut</td>';
    print '<td style="width: 100px;">Actions</td>';
    print '</tr>';

    foreach ($groupedByTracking as $groupKey => $group) {
        $shipmentRefs = array_map(function($s) { return $s->ref; }, $group['shipments']);
        $shipmentIds = array_map(function($s) { return $s->rowid; }, $group['shipments']);
        $primaryShipment = $group['shipments'][0];
        $isGrouped = count($group['shipments']) > 1;

        print '<tr class="oddeven" style="height: 50px;' . ($isGrouped ? ' background: #f0f8ff;' : '') . '">';

        print '<td>';
        if ($isGrouped) {
            print '<span style="color: #8a2be2; font-weight: bold;" title="Expéditions groupées">';
            print implode(' + ', $shipmentRefs);
            print '</span>';
        } else {
            print $shipmentRefs[0];
        }
        print '</td>';

        print '<td>' . $group['nom'] . '</td>';

        print '<td>';
        foreach ($shipmentIds as $sid) {
            print '<input type="hidden" name="carrier[' . $sid . ']" value="' . $group['carrier'] . '">';
        }
        print $group['carrier'];
        print '</td>';

        print '<td>';
        foreach ($shipmentIds as $sid) {
            print '<input type="hidden" name="tracking[' . $sid . ']" value="' . $group['tracking'] . '">';
        }
        print '<input type="text" class="flat" style="width: 95%; padding: 5px;" name="group_tracking[' . $groupKey . ']" value="' . $group['tracking'] . '"' . ($group['allSent'] ? ' readonly' : '') . '>';
        print '</td>';

        print '<td>';
        foreach ($shipmentIds as $sid) {
            print '<input type="hidden" name="email[' . $sid . ']" value="' . $group['email'] . '">';
        }
        print '<input type="text" class="flat" style="width: 95%; padding: 5px;" name="group_email[' . $groupKey . ']" value="' . $group['email'] . '"' . ($group['allSent'] ? ' readonly' : '') . '>';
        print '</td>';

        print '<td style="text-align: center;">';
        if ($group['allSent']) {
            $dateEnvoi = new DateTime($group['sentDate']);
            print '<span style="background: #4CAF50; color: white; padding: 6px 12px; border-radius: 4px; font-size: 12px; display: inline-block; min-width: 120px;">';
            print '✓ Envoyé le ' . $dateEnvoi->format('d/m H:i');
            print '</span>';
        } elseif (empty($group['email'])) {
            print '<span style="background: #ffc107; color: #000; padding: 6px 12px; border-radius: 4px; font-size: 12px; display: inline-block; min-width: 120px;">Pas d\'email</span>';
        } elseif (empty($group['tracking'])) {
            print '<span style="background: #ffc107; color: #000; padding: 6px 12px; border-radius: 4px; font-size: 12px; display: inline-block; min-width: 120px;">Pas de tracking</span>';
        } else {
            $label = $isGrouped ? 'Prêt (groupé)' : 'Prêt';
            print '<span style="background: #28a745; color: white; padding: 6px 12px; border-radius: 4px; font-size: 12px; display: inline-block; min-width: 120px;">' . $label . '</span>';
        }
        print '</td>';

        print '<td style="text-align: center;">';
        if (!$group['allSent'] && !empty($group['email']) && !empty($group['tracking'])) {
            // Envoyer les IDs de tous les shipments du groupe
            $idsParam = implode(',', $shipmentIds);
            print '<input type="hidden" name="group_ids[' . $primaryShipment->rowid . ']" value="' . $idsParam . '">';
            print '<button type="submit" name="sendsingle[' . $primaryShipment->rowid . ']" class="button" style="background: #8a2be2; border: none; color: white; padding: 6px 12px; cursor: pointer; border-radius: 4px;">ENVOYER</button>';
        }
        print '</td>';
        print '</tr>';
    }

    print '</table>';
    print '</div>';

    print '<div class="center" style="margin-top: 20px; padding: 20px;">';
    if ($readyToSend > 0) {
        print '<button type="submit" name="sendall" value="1" class="button" style="background: #8a2be2; border: none; color: white; padding: 12px 24px; font-size: 16px; cursor: pointer; border-radius: 4px;">ENVOYER TOUS LES EMAILS</button>';
    }
    print '</div>';

    print '</form>';
}
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $emailHandler = new EmailTemplateHandler($db);

    if (isset($_POST['sendsingle'])) {
        $shipmentId = array_key_first($_POST['sendsingle']);
        $tracking = GETPOST('tracking')[$shipmentId];
        $carrier = GETPOST('carrier')[$shipmentId];
        $email = GETPOST('email')[$shipmentId];
        $date_filter_post = GETPOST('date_filter', 'alpha') ?: date('Y-m-d');

        // Récupérer les IDs du groupe si c'est un envoi groupé
        $groupIds = isset($_POST['group_ids'][$shipmentId]) ? explode(',', $_POST['group_ids'][$shipmentId]) : array($shipmentId);

        if (empty($tracking) || empty($email)) {
            setEventMessages('Email et numéro de tracking requis', [], 'errors');
        } else {
            // Envoyer UN seul email pour le groupe
            if ($emailHandler->sendTrackingEmail($shipmentId, $tracking, $carrier, $date_filter_post)) {
                // Marquer TOUS les shipments du groupe comme envoyés
                foreach ($groupIds as $gid) {
                    if ($gid != $shipmentId) {
                        $emailHandler->markAsSent($gid, $tracking, $date_filter_post);
                    }
                }
                $nbShipments = count($groupIds);
                $msg = $nbShipments > 1
                    ? 'Email envoyé avec succès à ' . $email . ' (pour ' . $nbShipments . ' expéditions groupées)'
                    : 'Email envoyé avec succès à ' . $email;
                setEventMessages($msg, []);
            } else {
                setEventMessages('Erreur lors de l\'envoi de l\'email', [], 'errors');
            }
        }
    }
    elseif (isset($_POST['sendall'])) {
        // Envoi en masse - grouper par tracking+email pour éviter les doublons
        $trackings = GETPOST('tracking');
        $carriers = GETPOST('carrier');
        $emails = GETPOST('email');
        $groupIds = isset($_POST['group_ids']) ? $_POST['group_ids'] : array();
        $date_filter_post = GETPOST('date_filter', 'alpha') ?: date('Y-m-d');
        $success = 0;
        $errors = 0;
        $processedTrackings = array(); // Pour éviter d'envoyer 2x le même email

        $sentEmails = array();
        $sql = "SELECT fk_shipment FROM ".MAIN_DB_PREFIX."shipmenttracking_emails
                WHERE shipping_date = '".$db->escape($date_filter_post)."'
                AND entity = ".$conf->entity;
        $result = $db->query($sql);
        while ($obj = $db->fetch_object($result)) {
            $sentEmails[$obj->fk_shipment] = true;
        }

        foreach ($trackings as $shipmentId => $tracking) {
            if (isset($sentEmails[$shipmentId])) {
                continue;
            }

            // Clé unique pour éviter doublons (même tracking + même email = 1 seul envoi)
            $uniqueKey = $tracking . '|' . $emails[$shipmentId];
            if (isset($processedTrackings[$uniqueKey])) {
                $emailHandler->markAsSent($shipmentId, $tracking, $date_filter_post);
                continue;
            }

            if (!empty($tracking) && !empty($emails[$shipmentId])) {
                if ($emailHandler->sendTrackingEmail($shipmentId, $tracking, $carriers[$shipmentId], $date_filter_post)) {
                    $success++;
                    $processedTrackings[$uniqueKey] = true;

                    if (isset($groupIds[$shipmentId])) {
                        $gids = explode(',', $groupIds[$shipmentId]);
                        foreach ($gids as $gid) {
                            if ($gid != $shipmentId) {
                                $emailHandler->markAsSent($gid, $tracking, $date_filter_post);
                            }
                        }
                    }
                } else {
                    $errors++;
                }
            }
        }

        if ($success > 0) {
            setEventMessages($success.' email(s) envoyé(s) avec succès', []);
        }
        if ($errors > 0) {
            setEventMessages($errors.' erreur(s) lors de l\'envoi', [], 'errors');
        }
    }
}

print load_fiche_titre('ShipmentTracking', '', 'shipment');

$tester = new TrackingMailTester($db);
$date_filter = GETPOST('date_filter', 'alpha') ?: date('Y-m-d');
$tester->analyze($date_filter);

llxFooter();
?>
