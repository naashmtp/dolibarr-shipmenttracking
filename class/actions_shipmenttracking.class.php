<?php
require_once DOL_DOCUMENT_ROOT . '/custom/shipmenttracking/class/aeris_tracking_provider.class.php';

class ActionsShipmentTracking
{
    public $db;
    public $error = '';
    public $errors = array();

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function formObjectOptions($parameters, &$object, &$action, $hookmanager)
    {
        global $conf, $user, $langs;

        $error = 0;
        $context = explode(':', $parameters['context']);

        if (in_array('shipmentcard', $context)) {
            if ($action == 'presend') {
                try {
                    $refNumber = substr($object->ref, 7);

                    if (empty($refNumber)) {
                        throw new Exception($langs->trans("NoShipmentNumber"));
                    }

                    $trackingNumber = $this->getTrackingNumberFromAeris($refNumber);

                    if (!$trackingNumber) {
                        throw new Exception($langs->trans("NoTrackingFound") . " " . $refNumber);
                    }

                    $conf->global->SHIPMENT_TRACKING_NUMBER = $trackingNumber;

                } catch (Exception $e) {
                    setEventMessages($e->getMessage(), null, 'errors');
                    return -1;
                }
            }
        }
        return 0;
    }

    public function emailBuilderOptions($parameters, &$object, &$action, $hookmanager)
    {
        global $conf;

        if (!empty($conf->global->SHIPMENT_TRACKING_NUMBER)) {
            $parameters['content'] = str_replace('__TRACKING_NUMBER__', $conf->global->SHIPMENT_TRACKING_NUMBER, $parameters['content']);
            unset($conf->global->SHIPMENT_TRACKING_NUMBER);
        }

        return 0;
    }

    private function getTrackingNumberFromAeris($searchNumber)
    {
        $provider = new AerisTrackingProvider($this->db);
        $tracking = $provider->getTrackingBySH($searchNumber);

        if ($tracking === false) {
            return false;
        }

        return $tracking;
    }
}
