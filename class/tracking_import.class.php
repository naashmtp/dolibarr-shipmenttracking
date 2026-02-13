<?php
require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
require_once DOL_DOCUMENT_ROOT.'/expedition/class/expedition.class.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

class TrackingImport
{
   private $db;
   private $error;
   private $errors = array();
   
   /**
    * Constructeur
    *
    * @param DoliDB $db Database handler
    */
   public function __construct($db)
   {
       $this->db = $db;
   }

   /**
    * Génère le préfixe SH pour le mois en cours
    * 
    * @return string Format: 'SHAAMM'
    */
    public function getCurrentSHPrefix()
    {
        return 'SH' . date('ym');
    }

   /**
    * Récupère les expéditions sans numéro de suivi sur la période configurée
    *
    * @return array|false Tableau des expéditions ou false si erreur
    */
   private function getEligibleShipments()
   {
       global $conf;

       $retroactiveDays = !empty($conf->global->SHIPMENTTRACKING_RETROACTIVE_DAYS) ? $conf->global->SHIPMENTTRACKING_RETROACTIVE_DAYS : 30;
       $currentPrefix = $this->getCurrentSHPrefix();
       
       $sql = "SELECT rowid, ref";
       $sql.= " FROM ".MAIN_DB_PREFIX."expedition";
       $sql.= " WHERE entity = ".$conf->entity;
       $sql.= " AND (tracking_number IS NULL OR tracking_number = '')";
       $sql.= " AND tms > DATE_SUB(NOW(), INTERVAL ".$retroactiveDays." DAY)";
       $sql.= " AND ref LIKE '".$currentPrefix."-%'";

       dol_syslog("TrackingImport::getEligibleShipments Searching with prefix: ".$currentPrefix, LOG_DEBUG);
       dol_syslog("TrackingImport::getEligibleShipments sql=".$sql, LOG_DEBUG);
       
       $result = $this->db->query($sql);
       if (!$result) {
           $this->error = "Error fetching shipments: " . $this->db->lasterror();
           return false;
       }

       $shipments = array();
       while ($obj = $this->db->fetch_object($result)) {
           $shipments[$obj->rowid] = $obj->ref;
           dol_syslog("TrackingImport::getEligibleShipments Found eligible shipment: ".$obj->ref, LOG_DEBUG);
       }

       return $shipments;
   }

   /**
    * Lire le fichier Excel depuis le NAS et mettre à jour les numéros de suivi
    *
    * @return int <0 si erreur, >0 si OK
    */
    public function importFromExcel()
    {
        global $conf, $langs;
    
        try {
            dol_syslog("=== Starting TrackingImport::importFromExcel ===", LOG_DEBUG);
            
            $filePath = $conf->global->SHIPMENTTRACKING_EXCEL_PATH;
            dol_syslog("Excel file path: " . $filePath, LOG_DEBUG);
            
            if (!file_exists($filePath)) {
                dol_syslog("Excel file NOT FOUND!", LOG_ERR);
                throw new Exception("File not found: " . $filePath);
            }
            dol_syslog("Excel file exists: Yes", LOG_DEBUG);
    
            $eligibleShipments = $this->getEligibleShipments();
            if ($eligibleShipments === false) {
                dol_syslog("Failed to get eligible shipments", LOG_ERR);
                throw new Exception("Failed to get eligible shipments: " . $this->error);
            }
    
            dol_syslog("Found " . count($eligibleShipments) . " eligible shipments", LOG_DEBUG);
    
            if (empty($eligibleShipments)) {
                dol_syslog("No eligible shipments found", LOG_INFO);
                return 0;
            }
    
            $spreadsheet = IOFactory::load($filePath);
            // Sélectionner l'onglet "CAHIER EXPEDITIONS"
            $worksheet = $spreadsheet->getSheetByName('CAHIER EXPEDITIONS');
            if (!$worksheet) {
                dol_syslog("Sheet 'CAHIER EXPEDITIONS' not found!", LOG_ERR);
                throw new Exception("Sheet 'CAHIER EXPEDITIONS' not found in Excel file");
            }
            dol_syslog("Selected sheet: CAHIER EXPEDITIONS", LOG_DEBUG);
            $success = 0;
            $errors = 0;

            $trackingMap = $this->buildTrackingMap($worksheet);
            dol_syslog("Tracking map built. Found " . count($trackingMap) . " entries", LOG_DEBUG);

            foreach ($eligibleShipments as $shipmentId => $shipmentRef) {
                $shNumber = substr($shipmentRef, strrpos($shipmentRef, '-') + 1);
                dol_syslog("Processing shipment: $shipmentRef (extracted number: $shNumber)", LOG_DEBUG);
                
                if (isset($trackingMap[$shNumber])) {
                    dol_syslog("Found tracking number for $shNumber: " . $trackingMap[$shNumber], LOG_DEBUG);
                    $result = $this->updateTrackingNumber($shNumber, $trackingMap[$shNumber]);
                    if ($result > 0) {
                        dol_syslog("Successfully updated tracking for $shipmentRef", LOG_DEBUG);
                        $success++;
                    } else {
                        dol_syslog("Failed to update tracking for $shipmentRef", LOG_WARNING);
                        $errors++;
                    }
                } else {
                    dol_syslog("No tracking number found in Excel for $shNumber", LOG_WARNING);
                }
            }
    
            dol_syslog("Import completed. Success: $success, Errors: $errors", LOG_INFO);
            return $success;
    
        } catch (Exception $e) {
            $this->error = $e->getMessage();
            dol_syslog("TrackingImport::importFromExcel error: " . $this->error, LOG_ERR);
            return -1;
        }
    }

   /**
    * Construit un tableau associatif des numéros SH et leurs trackings
    *
    * @param Worksheet $worksheet La feuille Excel
    * @return array Tableau [numSH => numTracking]
    */
    private function buildTrackingMap($worksheet)
{
    $trackingMap = [];

    dol_syslog("=== Starting buildTrackingMap ===", LOG_DEBUG);
    dol_syslog("Worksheet highest row: " . $worksheet->getHighestRow(), LOG_DEBUG);
    dol_syslog("Worksheet highest column: " . $worksheet->getHighestColumn(), LOG_DEBUG);

    foreach ($worksheet->getRowIterator(2) as $row) {
        $rowIndex = $row->getRowIndex();
        
        try {
            $cellI = $worksheet->getCell('I'.$rowIndex);
            $cellH = $worksheet->getCell('H'.$rowIndex);
            
            $shNumber = trim($cellI->getValue());
            $trackingNumber = trim($cellH->getValue());

            dol_syslog("Row $rowIndex - Raw values:", LOG_DEBUG);
            dol_syslog("  - Column I (SH): '$shNumber'", LOG_DEBUG);
            dol_syslog("  - Column H (Tracking): '$trackingNumber'", LOG_DEBUG);

            if (empty($shNumber) || empty($trackingNumber)) {
                dol_syslog("Row $rowIndex skipped - Empty values detected", LOG_DEBUG);
                continue;
            }

            // Gestion des SH multiples (séparés par +)
            if (strpos($shNumber, '+') !== false) {
                dol_syslog("Row $rowIndex - Multiple SH numbers found: $shNumber", LOG_DEBUG);
                $shNumbers = array_map('trim', explode('+', $shNumber));
                foreach ($shNumbers as $sh) {
                    if (!empty($sh)) {
                        $trackingMap[$sh] = $trackingNumber;
                        dol_syslog("Added mapping: SH='$sh' -> Tracking='$trackingNumber'", LOG_DEBUG);
                    }
                }
            } else {
                $trackingMap[$shNumber] = $trackingNumber;
                dol_syslog("Added single mapping: SH='$shNumber' -> Tracking='$trackingNumber'", LOG_DEBUG);
            }

        } catch (Exception $e) {
            dol_syslog("Error processing row $rowIndex: " . $e->getMessage(), LOG_ERR);
        }
    }

    dol_syslog("=== Finished buildTrackingMap ===", LOG_DEBUG);
    dol_syslog("Total mappings found: " . count($trackingMap), LOG_DEBUG);

    return $trackingMap;
}

   /**
    * Mettre à jour le numéro de suivi d'une expédition
    *
    * @param string $shNumber Numéro SH (sans le préfixe)
    * @param string $trackingNumber Numéro de suivi
    * @return int <0 si erreur, >0 si OK
    */
   public function updateTrackingNumber($shNumber, $trackingNumber)
   {
       $expedition = new Expedition($this->db);
       
       $ref = $this->getCurrentSHPrefix() . '-' . $shNumber;
       dol_syslog("TrackingImport::updateTrackingNumber Updating ref: $ref with tracking: $trackingNumber", LOG_DEBUG);

       $result = $expedition->fetch(0, $ref);
       if ($result <= 0) {
           $this->errors[] = 'Expedition not found: ' . $ref;
           dol_syslog("TrackingImport::updateTrackingNumber Expedition not found: $ref", LOG_WARNING);
           return -1;
       }

       if ($expedition->tracking_number === $trackingNumber) {
           dol_syslog("TrackingImport::updateTrackingNumber Skipping update, tracking number unchanged for: $ref", LOG_DEBUG);
           return 0;
       }

       $expedition->tracking_number = $trackingNumber;
       $result = $expedition->update($expedition->id);
       
       if ($result <= 0) {
           $this->errors[] = 'Update failed for: ' . $ref;
           dol_syslog("TrackingImport::updateTrackingNumber Update failed for: $ref", LOG_ERR);
           return -2;
       }

       $this->logUpdate($expedition->id, $trackingNumber);
       return 1;
   }

   /**
    * Enregistrer la mise à jour dans les logs
    *
    * @param int $expeditionId ID de l'expédition
    * @param string $trackingNumber Numéro de suivi
    */
    private function logUpdate($expeditionId, $trackingNumber)
    {
        global $user, $conf;
        
        $sql = "INSERT INTO ".MAIN_DB_PREFIX."events (type, entity, dateevent, fk_object, elementtype, label, description)";
        $sql.= " VALUES ('TRACKING_UPDATE', ".$conf->entity.", '".$this->db->idate(dol_now())."',";
        $sql.= " ".$expeditionId.", 'shipping',";
        $sql.= " 'Tracking number updated',";
        $sql.= " '".$this->db->escape("New tracking number: ".$trackingNumber)."')";
        
        $this->db->query($sql);
    
        dol_syslog("TrackingImport::logUpdate Tracking number updated for expedition $expeditionId: $trackingNumber", LOG_INFO);
    }

   /**
    * Récupère la dernière erreur
    *
    * @return string Message d'erreur
    */
   public function getError()
   {
       return $this->error;
   }

   /**
    * Récupère toutes les erreurs
    *
    * @return array Liste des erreurs
    */
   public function getErrors()
   {
       return $this->errors;
   }
}