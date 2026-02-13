#!/bin/bash
################################################################################
# Script de synchronisation du fichier Excel depuis Google Drive
# Usage: ./sync_gdrive_simple.sh
################################################################################

# Configuration
GDRIVE_FILE_ID="1ijuQGdypjWz36_e5xOGNVgxfoTV06-MindoJWfvK0ik"
DESTINATION="/var/www/html/dolibarr/documents/shipmenttracking/SUIVI_GENERAL.xlsx"
TEMP_FILE="/tmp/gdrive_download_$$.xlsx"
LOG_FILE="/var/log/dolibarr/gdrive_sync.log"

# Créer le dossier de destination si nécessaire
mkdir -p "$(dirname "$DESTINATION")"

# Fonction de log
log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $1" | tee -a "$LOG_FILE"
}

log "=== Début de la synchronisation Google Drive ==="

# Télécharger le fichier
log "Téléchargement depuis Google Drive (ID: $GDRIVE_FILE_ID)..."

curl -L -s -S \
    -o "$TEMP_FILE" \
    "https://docs.google.com/spreadsheets/d/$GDRIVE_FILE_ID/export?format=xlsx" \
    2>&1 | tee -a "$LOG_FILE"

# Vérifier que le téléchargement a réussi
if [ ! -f "$TEMP_FILE" ]; then
    log "ERREUR: Le fichier temporaire n'a pas été créé"
    exit 1
fi

# Vérifier la taille du fichier (doit être > 100KB)
FILE_SIZE=$(stat -c%s "$TEMP_FILE" 2>/dev/null || stat -f%z "$TEMP_FILE" 2>/dev/null)
if [ "$FILE_SIZE" -lt 100000 ]; then
    log "ERREUR: Fichier trop petit ($FILE_SIZE octets) - probablement une erreur"
    rm -f "$TEMP_FILE"
    exit 1
fi

# Vérifier que c'est bien un fichier Excel (commence par PK)
if ! head -c 2 "$TEMP_FILE" | grep -q "PK"; then
    log "ERREUR: Le fichier téléchargé n'est pas un fichier Excel valide"
    rm -f "$TEMP_FILE"
    exit 1
fi

# Déplacer le fichier vers la destination
mv "$TEMP_FILE" "$DESTINATION"

# Définir les permissions
chown www-data:www-data "$DESTINATION" 2>/dev/null || true
chmod 644 "$DESTINATION"

FILE_SIZE_MB=$(echo "scale=2; $FILE_SIZE / 1024 / 1024" | bc)
log "✓ Synchronisation réussie - Taille: ${FILE_SIZE_MB} MB"
log "✓ Fichier disponible: $DESTINATION"
log "=== Fin de la synchronisation ==="

exit 0
