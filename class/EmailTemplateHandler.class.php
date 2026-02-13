<?php
require_once DOL_DOCUMENT_ROOT.'/core/class/CMailFile.class.php';

class EmailTemplateHandler 
{
    private $db;
    private $test_email;
    private $tracking_urls = [
        'DHL' => 'https://www.dhl.com/fr-fr/home/tracking/tracking-express.html?submit=1&tracking-id=',
        'CHRONOPOST' => 'https://www.chronopost.fr/tracking-no-cms/suivi-page?listeNumerosLT=',
        'UPS' => 'https://www.ups.com/track?loc=fr_FR&tracknum='
    ];

    public function __construct($db) 
    {
        $this->db = $db;
        global $conf;
        $this->test_email = !empty($conf->global->SHIPMENTTRACKING_TEST_EMAIL) ? 
            $conf->global->SHIPMENTTRACKING_TEST_EMAIL : 'votre.email@test.com';
    }

    /**
     * Prépare les données pour l'email en regroupant les SH et trackings
     */
    private function prepareEmailData($shipmentId, $tracking, $carrier) 
    {
        global $conf;
        
        $sql = "SELECT e.ref as shipment_ref, e.tracking_number, 
                       s.ref as order_ref, so.email, so.nom as client_name
                FROM ".MAIN_DB_PREFIX."expedition as e
                LEFT JOIN ".MAIN_DB_PREFIX."element_element as el ON e.rowid = el.fk_target
                LEFT JOIN ".MAIN_DB_PREFIX."commande as s ON el.fk_source = s.rowid
                LEFT JOIN ".MAIN_DB_PREFIX."societe as so ON s.fk_soc = so.rowid
                WHERE e.rowid = " . intval($shipmentId);

        $result = $this->db->query($sql);
        if ($obj = $this->db->fetch_object($result)) {
            return [
                'ref' => $obj->shipment_ref,
                'order_ref' => $obj->order_ref,
                'tracking' => $tracking,
                'carrier' => $carrier,
                'client_name' => $obj->client_name,
                'email' => $obj->email
            ];
        }
        
        return false;
    }

    /**
     * Génère le bouton de suivi
     */
    private function generateTrackingButton($tracking, $carrier) 
    {
        $url = $this->tracking_urls[$carrier] . urlencode($tracking);
        return '<a href="'.$url.'" target="_blank" style="display: inline-block; background-color: #8a2be2; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; margin: 10px 0;">Suivre votre colis</a>';
    }

    /**
     * Envoie l'email avec le bon format
     */
    public function sendTrackingEmail($shipmentId, $tracking, $carrier, $shipping_date = null)
{
   global $conf, $user;

   if (empty($shipping_date)) {
       $shipping_date = date('Y-m-d');
   }

   if (empty($shipmentId) || empty($tracking) || empty($carrier)) {
       return false;
   }
   
   $data = $this->prepareEmailData($shipmentId, $tracking, $carrier);
   if (!$data) {
       return false;
   }

   if (!empty($conf->global->SHIPMENTTRACKING_TEST_MODE)) {
       $subject = '[TEST] Votre numéro de suivi - ' . $data['ref'];
       $message = '<div style="background-color: #ffeb3b; padding: 10px; margin-bottom: 10px;">';
       $message .= '<strong>MODE TEST</strong><br>';
       $message .= 'Email original destiné à : ' . $data['email'];
       $message .= '</div>';
       $to = $conf->global->SHIPMENTTRACKING_TEST_EMAIL;
   } else {
       $subject = 'Votre numéro de suivi - ' . $data['ref'];
       $message = '';
       $to = $data['email'];
   }

   $message .= '<div style="font-family: Arial, sans-serif; line-height: 1.6;">';
   $message .= '<p>Bonjour,</p>';
   $message .= '<p>Voici votre numéro de suivi pour votre expédition ' . $data['ref'] . '</p>';
   $message .= '<p style="margin: 20px 0;">Numéro de suivi : <strong>' . $data['tracking'] . '</strong></p>';
   $message .= $this->generateTrackingButton($data['tracking'], $data['carrier']);
   $message .= '<p>Bien cordialement,<br>' . $conf->global->MAIN_INFO_SOCIETE_NOM . '</p>';
   $message .= '</div>';

   $filename = $data['ref'] . '.pdf';
   $filedir = DOL_DATA_ROOT . '/expedition/sending/' . $data['ref'];
   $filepath = $filedir . '/' . $filename;
   $arrayfiles = [];
   $arraymime = [];
   $arraynames = [];

   if (file_exists($filepath)) {
       $arrayfiles[] = $filepath;
       $arraymime[] = 'application/pdf';
       $arraynames[] = $filename;
   }

   try {
       $cmail = new CMailFile(
           $subject,
           $to,
           $conf->global->MAIN_MAIL_EMAIL_FROM,
           $message,
           $arrayfiles,
           $arraymime,
           $arraynames,
           '',
           '',
           0,
           1
       );

       if ($cmail->sendfile()) {
           $sql = "INSERT INTO " . MAIN_DB_PREFIX . "shipmenttracking_emails (
                       fk_shipment,
                       tracking_number,
                       email_to,
                       date_envoi,
                       shipping_date,
                       fk_user_author,
                       entity,
                       status
                   ) VALUES (
                       " . intval($shipmentId) . ",
                       '" . $this->db->escape($tracking) . "',
                       '" . $this->db->escape($to) . "',
                       NOW(),
                       '" . $this->db->escape($shipping_date) . "',
                       " . ($user->id > 0 ? $user->id : 'NULL') . ",
                       " . $conf->entity . ",
                       1
                   )";
           $this->db->query($sql);

           $sql = "UPDATE " . MAIN_DB_PREFIX . "expedition ";
           $sql.= "SET date_tracking_sent = '" . $this->db->idate(dol_now()) . "' ";
           if (!empty($conf->global->SHIPMENTTRACKING_TEST_MODE)) {
               $sql.= ", tracking_test_sent = 1 ";
           }
           $sql.= "WHERE rowid = " . intval($shipmentId);
           $this->db->query($sql);

           return true;
       }
   } catch (Exception $e) {
       return false;
   }

   return false;
}

    /**
     * Marque une expédition comme "email envoyé" sans réellement envoyer d'email
     * Utilisé pour les expéditions groupées qui partagent le même numéro de suivi
     *
     * @param int $shipmentId ID de l'expédition
     * @param string $tracking Numéro de suivi
     * @param string $shipping_date Date d'expédition (format YYYY-MM-DD)
     * @return bool
     */
    public function markAsSent($shipmentId, $tracking, $shipping_date = null)
    {
        global $conf, $user;

        if (empty($shipping_date)) {
            $shipping_date = date('Y-m-d');
        }

        $data = $this->prepareEmailData($shipmentId, $tracking, 'CHRONOPOST');
        $email = $data ? $data['email'] : '';

        $sql = "INSERT INTO " . MAIN_DB_PREFIX . "shipmenttracking_emails (
                    fk_shipment,
                    tracking_number,
                    email_to,
                    date_envoi,
                    shipping_date,
                    fk_user_author,
                    entity,
                    status
                ) VALUES (
                    " . intval($shipmentId) . ",
                    '" . $this->db->escape($tracking) . "',
                    '" . $this->db->escape($email) . "',
                    NOW(),
                    '" . $this->db->escape($shipping_date) . "',
                    " . ($user->id > 0 ? $user->id : 'NULL') . ",
                    " . $conf->entity . ",
                    1
                )";

        if (!$this->db->query($sql)) {
            return false;
        }

        $sql = "UPDATE " . MAIN_DB_PREFIX . "expedition ";
        $sql.= "SET date_tracking_sent = '" . $this->db->idate(dol_now()) . "' ";
        if (!empty($conf->global->SHIPMENTTRACKING_TEST_MODE)) {
            $sql.= ", tracking_test_sent = 1 ";
        }
        $sql.= "WHERE rowid = " . intval($shipmentId);
        $this->db->query($sql);

        return true;
    }
}