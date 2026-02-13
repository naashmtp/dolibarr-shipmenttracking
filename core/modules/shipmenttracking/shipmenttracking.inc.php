<?php
/* Copyright (C) 2024 [your name]
 * Module tracking number automation
 */

dol_include_once('/shipmenttracking/core/modules/modShipmentTracking.class.php');

$res = 0;
$modShipmentTracking = new modShipmentTracking($db);

$tmpobject = $modShipmentTracking;
$res = 1;

if (! $res) {
    $langs->load("errors");
    print $langs->trans("Error") . " " . $langs->trans("Error_SHIPMENTTRACKING_NotFound");
}