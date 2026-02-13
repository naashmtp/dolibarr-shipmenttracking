<?php
/**
 * Core library for shipmenttracking module
 */

/**
 * Prepare admin pages header
 *
 * @return array
 */
function shipmenttrackingAdminPrepareHead()
{
    global $langs, $conf;

    $langs->load("shipmenttracking@shipmenttracking");
    
    $h = 0;
    $head = array();

    $head[$h][0] = dol_buildpath("/shipmenttracking/admin/setup.php", 1);
    $head[$h][1] = $langs->trans("Settings");
    $head[$h][2] = 'settings';
    $h++;

    return $head;
}

/**
 * Vérifie l'accessibilité du fichier Excel
 *
 * @return array Array avec 'success' (bool) et 'message' (string)
 */
function checkExcelFileAccess()
{
    global $conf, $langs;

    $result = array(
        'success' => false,
        'message' => ''
    );

    if (empty($conf->global->SHIPMENTTRACKING_EXCEL_PATH)) {
        $result['message'] = $langs->trans("NoExcelPathDefined");
        return $result;
    }

    if (!file_exists($conf->global->SHIPMENTTRACKING_EXCEL_PATH)) {
        $result['message'] = sprintf($langs->trans("ExcelFileNotFound"), $conf->global->SHIPMENTTRACKING_EXCEL_PATH);
        return $result;
    }

    if (!is_readable($conf->global->SHIPMENTTRACKING_EXCEL_PATH)) {
        $result['message'] = sprintf($langs->trans("ExcelFileNotReadable"), $conf->global->SHIPMENTTRACKING_EXCEL_PATH);
        return $result;
    }

    $result['success'] = true;
    return $result;
}

/**
 * Met à jour la date de dernière importation
 *
 * @param DoliDB $db Database handler
 * @return bool True si succès, False si erreur
 */
function updateLastImportDate($db)
{
    return dolibarr_set_const($db, 'SHIPMENTTRACKING_LAST_IMPORT', dol_now(), 'chaine', 0, '', $db->entity);
}

/**
 * Formate le numéro de suivi pour l'affichage
 *
 * @param string $tracking_number Numéro de suivi
 * @return string Numéro de suivi formaté
 */
function formatTrackingNumber($tracking_number)
{
    return !empty($tracking_number) ? $tracking_number : '<span class="opacitymedium">'.$langs->trans("None").'</span>';
}