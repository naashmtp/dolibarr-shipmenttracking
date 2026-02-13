<?php
if (!defined('INC_FROM_DOLIBARR')) {
    define('INC_FROM_DOLIBARR', true);
}
require_once DOL_DOCUMENT_ROOT . '/core/modules/DolibarrModules.class.php';

class modShipmentTracking extends DolibarrModules
{
    public function __construct($db)
    {
        global $langs, $conf;
        $this->db = $db;
       
        $this->numero = 700001;
        $this->rights_class = 'shipmenttracking';
        $this->family = "interface";
        $this->module_position = 500;
        $this->name = "ShipmentTracking";
        $this->description = "Automatisation de la mise à jour des numéros de suivi";
        $this->version = '2.0';
        $this->const_name = 'MAIN_MODULE_SHIPMENTTRACKING';
        $this->picto = 'shipment';

        $this->config_page_url = array('setup.php@shipmenttracking');

        $this->depends = array('modExpedition');
        $this->requiredby = array();
        $this->conflictwith = array();
        $this->langfiles = array("shipmenttracking@shipmenttracking");

        $this->module_parts = array(
            'triggers' => 1,
            'hooks' => array(
        'sendemailform',  // Pour intercepter avant l'envoi
        'emailtemplates'  // Pour nos substitutions
    )
);

        
        $this->rights = array();
        $r = 0;
        $this->rights[$r][0] = 700011;
        $this->rights[$r][1] = 'Lire les mises à jour de tracking';
        $this->rights[$r][3] = 1;
        $this->rights[$r][4] = 'read';
        $r++;
        $this->rights[$r][0] = 700012;
        $this->rights[$r][1] = 'Mettre à jour les numéros de suivi';
        $this->rights[$r][3] = 0;
        $this->rights[$r][4] = 'write';
        $r++;
    }

    /**
     * Function called when module is enabled.
     * The init function add constants, boxes, permissions and menus
     * (defined in constructor) into Dolibarr database.
     * It also creates data directories
     *
     * @param string $options Options when enabling module ('', 'noboxes')
     * @return int 1 if OK, 0 if KO
     */
    public function init($options = '')
    {
        $sql = array();
        $result = $this->loadTables();
        return $this->_init($sql, $options);
    }

    /**
     * Function called when module is disabled.
     * Remove from database constants, boxes and permissions from Dolibarr database.
     * Data directories are not deleted
     *
     * @param string $options Options when enabling module ('', 'noboxes')
     * @return int 1 if OK, 0 if KO
     */
    public function remove($options = '')
    {
        $sql = array();
        return $this->_remove($sql, $options);
    }

    /**
     * Create tables, keys and data required by module
     * Files llx_table1.sql, llx_table1.key.sql llx_data.sql with create table, create keys
     * and create data commands must be stored in directory /shipmenttracking/sql/
     * This function is called by this->init
     *
     * @return int <=0 if KO, >0 if OK
     */
    protected function loadTables()
    {
        return $this->_load_tables('/shipmenttracking/sql/');
    }
}