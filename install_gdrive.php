<?php
/**
 * Installation automatique de Google Drive Sync
 * Cette page configure tout automatiquement avec un seul clic
 */

require_once '../../main.inc.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';

if (!$user->admin) accessforbidden();

llxHeader('', 'Installation Google Drive');

$action = GETPOST('action', 'alpha');

if ($action == 'install') {
    print '<div style="background: #f5f5f5; padding: 20px; margin: 20px; border-radius: 5px;">';
    print '<h2>🚀 Installation en cours...</h2>';
    print '<pre style="background: white; padding: 15px; border-radius: 5px; font-family: monospace;">';

    $errors = 0;
    $fileId = '1ijuQGdypjWz36_e5xOGNVgxfoTV06-MindoJWfvK0ik';
    $destPath = DOL_DATA_ROOT . '/shipmenttracking/SUIVI_GENERAL.xlsx';

    // Étape 1: Créer le dossier
    print "Étape 1: Création du dossier\n";
    print str_repeat('-', 80) . "\n";
    $destDir = dirname($destPath);
    if (!is_dir($destDir)) {
        $result = dol_mkdir($destDir);
        if ($result >= 0) {
            print "✓ Dossier créé: $destDir\n\n";
        } else {
            print "✗ Erreur lors de la création du dossier\n\n";
            $errors++;
        }
    } else {
        print "✓ Dossier existe déjà: $destDir\n\n";
    }

    // Étape 2: Télécharger le fichier
    if ($errors == 0) {
        print "Étape 2: Téléchargement depuis Google Drive\n";
        print str_repeat('-', 80) . "\n";
        $url = "https://docs.google.com/spreadsheets/d/$fileId/export?format=xlsx";
        print "URL: $url\n";

        $context = stream_context_create([
            'http' => [
                'follow_location' => 1,
                'max_redirects' => 5,
                'timeout' => 60,
                'user_agent' => 'Mozilla/5.0'
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false
            ]
        ]);

        $content = @file_get_contents($url, false, $context);

        if ($content === false) {
            print "✗ Erreur lors du téléchargement\n\n";
            $errors++;
        } else {
            $size = strlen($content);
            $sizeMB = round($size / 1024 / 1024, 2);
            print "✓ Téléchargement réussi: $sizeMB MB\n\n";

            // Étape 3: Vérifier le fichier
            print "Étape 3: Vérification du fichier\n";
            print str_repeat('-', 80) . "\n";

            if ($size < 100000) {
                print "✗ Fichier trop petit\n\n";
                $errors++;
            } elseif (substr($content, 0, 2) !== 'PK') {
                print "✗ Pas un fichier Excel valide\n\n";
                $errors++;
            } else {
                print "✓ Fichier Excel valide\n\n";

                // Étape 4: Sauvegarder le fichier
                print "Étape 4: Sauvegarde du fichier\n";
                print str_repeat('-', 80) . "\n";

                if (file_put_contents($destPath, $content) !== false) {
                    @chmod($destPath, 0644);
                    print "✓ Fichier sauvegardé: $destPath\n";
                    print "✓ Taille finale: $sizeMB MB\n\n";

                    // Étape 5: Configuration base de données
                    print "Étape 5: Configuration de la base de données\n";
                    print str_repeat('-', 80) . "\n";

                    dolibarr_set_const($db, 'SHIPMENTTRACKING_EXCEL_PATH', $destPath, 'chaine', 0, '', $conf->entity);
                    dolibarr_set_const($db, 'SHIPMENTTRACKING_GDRIVE_FILE_ID', $fileId, 'chaine', 0, '', $conf->entity);

                    print "✓ Chemin configuré: $destPath\n";
                    print "✓ ID Google Drive configuré: $fileId\n\n";

                } else {
                    print "✗ Erreur lors de la sauvegarde\n\n";
                    $errors++;
                }
            }
        }
    }

    print "\n";
    print str_repeat('=', 80) . "\n";
    if ($errors == 0) {
        print "🎉 INSTALLATION TERMINÉE AVEC SUCCÈS !\n";
        print str_repeat('=', 80) . "\n\n";
        print "Le module ShipmentTracking est maintenant configuré pour Google Drive.\n\n";
        print "Testez maintenant:\n";
        print "👉 " . DOL_URL_ROOT . "/custom/shipmenttracking/tracking.php\n";
    } else {
        print "❌ INSTALLATION ÉCHOUÉE ($errors erreurs)\n";
        print str_repeat('=', 80) . "\n";
        print "Veuillez corriger les erreurs ci-dessus.\n";
    }

    print '</pre>';
    print '</div>';

    if ($errors == 0) {
        print '<div style="text-align: center; margin: 20px;">';
        print '<a href="' . DOL_URL_ROOT . '/custom/shipmenttracking/tracking.php" class="button" style="background: #8a2be2; color: white; padding: 15px 30px; font-size: 18px; text-decoration: none; border-radius: 5px; display: inline-block;">✨ TESTER LE MODULE MAINTENANT ✨</a>';
        print '</div>';
    }
} else {
    print '<div style="max-width: 800px; margin: 50px auto; text-align: center;">';
    print '<h1 style="color: #8a2be2;">🚀 Installation Google Drive</h1>';
    print '<p style="font-size: 18px; margin: 30px 0;">Cette installation va configurer le module ShipmentTracking pour utiliser Google Drive au lieu du NAS.</p>';

    print '<div style="background: #e3f2fd; padding: 20px; border-radius: 10px; margin: 30px 0; text-align: left;">';
    print '<h3>📋 Ce qui va être fait :</h3>';
    print '<ul style="font-size: 16px; line-height: 2;">';
    print '<li>✅ Création du dossier de destination</li>';
    print '<li>✅ Téléchargement du fichier Excel depuis Google Drive</li>';
    print '<li>✅ Vérification de l\'intégrité du fichier</li>';
    print '<li>✅ Configuration automatique dans Dolibarr</li>';
    print '<li>✅ Le module fonctionnera exactement comme avant !</li>';
    print '</ul>';
    print '</div>';

    print '<div style="margin: 40px 0;">';
    print '<a href="' . $_SERVER["PHP_SELF"] . '?action=install" class="button" style="background: #8a2be2; color: white; padding: 20px 40px; font-size: 20px; text-decoration: none; border-radius: 10px; display: inline-block; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">';
    print '⚡ INSTALLER MAINTENANT ⚡';
    print '</a>';
    print '</div>';

    print '<p style="color: #666; font-size: 14px; margin-top: 40px;">L\'installation prend environ 5 secondes</p>';
    print '</div>';
}

llxFooter();
?>
