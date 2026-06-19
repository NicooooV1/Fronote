#!/usr/bin/env bash
# ============================================================================
# RUNBOOK — Purge du .env de l'historique git + rotation du secret DB
# ============================================================================
# Constat audit (#5) : un .env contenant DB_PASS a été committé dans l'historique
# (présent sur origin/main). Tout clone/fork expose ce mot de passe MySQL.
#
# ⚠️ NE PAS exécuter à l'aveugle : réécrit l'historique git et nécessite un
#    force-push (action IRRÉVERSIBLE côté remote, impacte tous les clones).
#    À lancer manuellement, après avoir prévenu l'équipe et fait une sauvegarde.
# ============================================================================
set -euo pipefail

echo "Ce script est un RUNBOOK. Lisez-le, puis décommentez les étapes voulues."
exit 0

# --- 0. Sauvegarde du dépôt (sécurité) -------------------------------------
# git clone --mirror . ../pronote-backup-$(date +%Y%m%d).git

# --- 1. Purger .env (et le .bak) de TOUT l'historique ----------------------
# Option A — git filter-repo (recommandé) :
#   pip install git-filter-repo
#   git filter-repo --invert-paths --path .env --path .env.stale-copied-from-pronote.bak
#
# Option B — BFG :
#   bfg --delete-files .env
#   git reflog expire --expire=now --all && git gc --prune=now --aggressive

# --- 2. Forcer la mise à jour du remote (IRRÉVERSIBLE) ---------------------
#   git push origin --force --all
#   git push origin --force --tags

# --- 3. ROTATION du secret exposé (OBLIGATOIRE) ----------------------------
# Le mot de passe ayant fuité dans l'historique, le purger ne suffit pas :
#   - changer DB_PASS côté MySQL :
#       ALTER USER 'pronote'@'%' IDENTIFIED BY '<nouveau_mot_de_passe_fort>';
#       FLUSH PRIVILEGES;
#   - reporter le nouveau mot de passe dans .env (DB_PASS=...)
#   - faire de même pour APP_KEY / JWT_SECRET si présents dans l'historique.

# --- 4. Demander à tous les développeurs de re-cloner ----------------------
# Les anciens clones contiennent encore l'historique réécrit ; un simple pull
# ne suffit pas après un force-push.
