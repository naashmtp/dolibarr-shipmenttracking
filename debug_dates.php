<?php
/**
 * Script de débogage pour analyser la lecture des dates dans l'Excel
 */

require_once '../../main.inc.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once __DIR__ . '/vendor/autoload.php';

if (!$user->admin) accessforbidden();

llxHeader('', 'Debug Dates Excel');

print load_fiche_titre('Débogage - Analyse des dates Excel', '', 'shipment');

try {
    $excelPath = $conf->global->SHIPMENTTRACKING_EXCEL_PATH;

    if (empty($excelPath) || !file_exists($excelPath)) {
        print '<div class="error">Fichier Excel non trouvé</div>';
        llxFooter();
        exit;
    }

    print '<div style="background: #f5f5f5; padding: 20px; border-radius: 5px;">';
    print '<h3>Fichier: ' . $excelPath . '</h3>';
    print '<pre style="background: white; padding: 15px; border-radius: 5px; overflow-x: auto; font-family: monospace; font-size: 12px;">';

    $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($excelPath);
    $worksheet = $spreadsheet->getSheetByName('CAHIER EXPEDITIONS');

    $currentDate = null;
    $lineCount = 0;
    $dateCount = 0;

    print str_repeat('=', 120) . "\n";
    print "Analyse ligne par ligne du fichier Excel\n";
    print str_repeat('=', 120) . "\n\n";

    foreach ($worksheet->getRowIterator(2) as $row) {
        $rowIndex = $row->getRowIndex();
        $cellA = trim($worksheet->getCell('A'.$rowIndex)->getValue());
        $cellB = trim($worksheet->getCell('B'.$rowIndex)->getValue());
        $cellH = trim($worksheet->getCell('H'.$rowIndex)->getValue());
        $cellI = trim($worksheet->getCell('I'.$rowIndex)->getValue());

        // Vérifier si c'est une ligne de date
        if (preg_match('/(\d{2}\/\d{2}\/\d{4})$/', $cellA, $matches)) {
            $dateStr = $matches[0];
            $date = DateTime::createFromFormat('d/m/Y', $dateStr);
            $currentDate = $date ? $date->format('Y-m-d') : null;
            $dateCount++;

            print "\n";
            print str_repeat('-', 120) . "\n";
            print ">>> NOUVELLE SECTION DE DATE <<<\n";
            print "Ligne $rowIndex: DATE TROUVÉE = $dateStr => " . ($currentDate ?? 'invalide') . "\n";
            print "Cellule A complète: \"$cellA\"\n";
            print str_repeat('-', 120) . "\n\n";
            continue;
        }

        // Si on a une date courante et des données
        if ($currentDate && !empty($cellI)) {
            $lineCount++;
            print sprintf(
                "Ligne %4d | DATE: %s | SH: %-8s | Tracking: %-15s | Client: %-30s\n",
                $rowIndex,
                $currentDate,
                $cellI,
                substr($cellH, 0, 15),
                substr($cellB, 0, 30)
            );

            // Arrêter après 100 lignes pour ne pas surcharger
            if ($lineCount > 100) {
                print "\n... (arrêt après 100 lignes de données) ...\n";
                break;
            }
        }
    }

    print "\n";
    print str_repeat('=', 120) . "\n";
    print "RÉSUMÉ:\n";
    print "  - Sections de dates trouvées: $dateCount\n";
    print "  - Lignes de données analysées: $lineCount\n";
    print str_repeat('=', 120) . "\n";

    print '</pre>';
    print '</div>';

    print '<div style="margin-top: 20px; background: #e3f2fd; padding: 20px; border-radius: 5px;">';
    print '<h3>Test pour une date spécifique</h3>';
    print '<form method="GET" action="'.$_SERVER["PHP_SELF"].'">';
    print 'Date à tester: <input type="date" name="test_date" value="'.GETPOST('test_date', 'alpha').'">';
    print ' <input type="submit" class="button" value="Analyser">';
    print '</form>';

    $testDate = GETPOST('test_date', 'alpha');
    if (!empty($testDate)) {
        print '<pre style="background: white; padding: 15px; border-radius: 5px; margin-top: 15px; font-family: monospace; font-size: 12px;">';
        print "Expéditions trouvées pour le $testDate:\n";
        print str_repeat('-', 80) . "\n";

        $spreadsheet2 = \PhpOffice\PhpSpreadsheet\IOFactory::load($excelPath);
        $worksheet2 = $spreadsheet2->getSheetByName('CAHIER EXPEDITIONS');
        $currentSection = false;
        $found = 0;

        foreach ($worksheet2->getRowIterator(2) as $row) {
            $rowIndex = $row->getRowIndex();
            $cellA = trim($worksheet2->getCell('A'.$rowIndex)->getValue());

            if (preg_match('/(\d{2}\/\d{2}\/\d{4})$/', $cellA, $matches)) {
                $date = DateTime::createFromFormat('d/m/Y', $matches[0]);
                $currentSection = $date && $date->format('Y-m-d') === $testDate;
                continue;
            }

            if ($currentSection) {
                $cellI = trim($worksheet2->getCell('I'.$rowIndex)->getValue());
                $cellH = trim($worksheet2->getCell('H'.$rowIndex)->getValue());
                $cellB = trim($worksheet2->getCell('B'.$rowIndex)->getValue());

                if (!empty($cellI) && !empty($cellH)) {
                    $found++;
                    print sprintf("SH %-10s | Tracking: %-20s | %s\n", $cellI, $cellH, substr($cellB, 0, 40));
                }
            }
        }

        if ($found == 0) {
            print "Aucune expédition trouvée pour cette date\n";
        } else {
            print "\nTotal: $found expédition(s) trouvée(s)\n";
        }
        print '</pre>';
    }
    print '</div>';

} catch (Exception $e) {
    print '<div class="error">Erreur: ' . $e->getMessage() . '</div>';
}

llxFooter();
?>
