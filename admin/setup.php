<?php
require_once '../../../main.inc.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once '../lib/shipmenttracking.lib.php';

if (!$user->admin) accessforbidden();

$action = GETPOST('action', 'alpha');
$error = 0;

$langs->loadLangs(array("admin", "shipmenttracking@shipmenttracking"));

/*
* Actions
*/
if ($action == 'update') {
    $aeris_api_url = GETPOST('SHIPMENTTRACKING_AERIS_API_URL', 'alpha');
    $retroactive_days = GETPOST('SHIPMENTTRACKING_RETROACTIVE_DAYS', 'int');
    $test_email = GETPOST('SHIPMENTTRACKING_TEST_EMAIL', 'alpha');

    $error = 0;

    if ($retroactive_days < 1) {
        setEventMessages($langs->trans("RetroactiveDaysError"), null, 'errors');
        $error++;
    }
    if (!empty($test_email) && !filter_var($test_email, FILTER_VALIDATE_EMAIL)) {
        setEventMessages("L'adresse email de test n'est pas valide", null, 'errors');
        $error++;
    }

    if (!$error) {
        dolibarr_set_const($db, 'SHIPMENTTRACKING_AERIS_API_URL', $aeris_api_url, 'chaine', 0, '', $conf->entity);
        dolibarr_set_const($db, 'SHIPMENTTRACKING_RETROACTIVE_DAYS', $retroactive_days, 'chaine', 0, '', $conf->entity);
        if (!empty($test_email)) {
            dolibarr_set_const($db, 'SHIPMENTTRACKING_TEST_EMAIL', $test_email, 'chaine', 0, '', $conf->entity);
        }
        setEventMessages($langs->trans("SetupSaved"), null, 'mesgs');
    }
}

if ($action == 'set_SHIPMENTTRACKING_TEST_MODE') {
    $test_mode = GETPOST('value', 'int');
    dolibarr_set_const($db, 'SHIPMENTTRACKING_TEST_MODE', $test_mode, 'chaine', 0, '', $conf->entity);
}

/*
* View
*/
llxHeader('', $langs->trans("ShipmentTrackingSetup"));

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans("BackToModuleList").'</a>';
print load_fiche_titre($langs->trans("ShipmentTrackingSetup"), $linkback, 'title_setup');

print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="update">';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td>'.$langs->trans("Parameter").'</td>';
print '<td>'.$langs->trans("Value").'</td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td class="fieldrequired">URL API Aeris (cahier d\'expédition)</td>';
print '<td>';
print '<input type="text" class="flat minwidth400" name="SHIPMENTTRACKING_AERIS_API_URL" value="'.dol_escape_htmltag(
    !empty($conf->global->SHIPMENTTRACKING_AERIS_API_URL) ? $conf->global->SHIPMENTTRACKING_AERIS_API_URL : 'http://localhost:3003/api/cahier-expedition'
).'">';
print '<br><small>URL de l\'API Aeris pour récupérer les numéros de suivi</small>';
print '</td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>'.$langs->trans("Mode Test").'</td>';
print '<td>';
print ajax_constantonoff('SHIPMENTTRACKING_TEST_MODE');
print '</td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>Email de test</td>';
print '<td>';
print '<input type="email" class="flat minwidth300" name="SHIPMENTTRACKING_TEST_EMAIL" value="'.dol_escape_htmltag($conf->global->SHIPMENTTRACKING_TEST_EMAIL).'">';
print '<br><small>Email utilisé pour les tests d\'envoi</small>';
print '</td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td class="fieldrequired">'.$langs->trans("RetroactiveDays").'</td>';
print '<td>';
print '<input type="number" min="1" class="flat width75" name="SHIPMENTTRACKING_RETROACTIVE_DAYS" value="'.
    (empty($conf->global->SHIPMENTTRACKING_RETROACTIVE_DAYS) ? '30' : dol_escape_htmltag($conf->global->SHIPMENTTRACKING_RETROACTIVE_DAYS)).'">';
print '&nbsp;'.$langs->trans("days");
print '<br><small>'.$langs->trans("RetroactiveDaysHelp").'</small>';
print '</td>';
print '</tr>';

print '</table>';

print '<div class="center">';
print '<input type="submit" class="button" name="save" value="'.$langs->trans("Save").'">';
print '</div>';
print '</form>';

llxFooter();
$db->close();
