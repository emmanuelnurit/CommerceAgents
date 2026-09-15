#!/usr/bin/env bash
#
# MYO-372 — Installe le garde-fou auto-push (dépôt module CommerceAgents).
#
# Ce script est la partie versionnée du mécanisme, sur le même modèle que
# thelia3/scripts/install-backup-triggers.sh (MYO-369) : le dépôt site n'est
# pas touché par ce ticket (hors périmètre, tranché sur MYO-366), donc ce
# garde-fou a son propre installateur, entièrement dans le dépôt module.
#
# Deux volets :
#   1. Push après chaque commit sur myorg — déjà câblé, rien à installer ici :
#      scripts/git-hooks/post-commit est le même fichier versionné que
#      MYO-369 (core.hooksPath déjà pointé dessus par
#      thelia3/scripts/install-backup-triggers.sh), étendu pour appeler en
#      plus scripts/auto-push-myorg.sh en tâche de fond.
#   2. Détection bruyante des commits non poussés (filet indépendant du
#      hook, cf. check-unpushed-myorg.sh) — installée ici via crontab.
#
# Idempotent : ré-exécutable sans effet de bord — ne duplique pas la ligne
# crontab (remplacée par une ligne fraîche à chaque run).
#
# ── Usage ────────────────────────────────────────────────────────────────
#
#   ./scripts/install-push-guard.sh
#
#   Variables (toutes optionnelles) :
#     MODULE_REPO         défaut: dossier contenant ce script
#     CRON_INTERVAL_MIN   défaut: 15 (minutes entre deux vérifications cron)
#     STALE_MINUTES       défaut: 30 (transmis à check-unpushed-myorg.sh)
#     LOG_DIR             défaut: /home/enurit/backups/thelia3-bundles
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
MODULE_REPO="${MODULE_REPO:-$(cd "$SCRIPT_DIR/.." && pwd)}"
CRON_INTERVAL_MIN="${CRON_INTERVAL_MIN:-15}"
STALE_MINUTES="${STALE_MINUTES:-30}"
LOG_DIR="${LOG_DIR:-/home/enurit/backups/thelia3-bundles}"
CHECK_SCRIPT="${MODULE_REPO}/scripts/check-unpushed-myorg.sh"
PUSH_SCRIPT="${MODULE_REPO}/scripts/auto-push-myorg.sh"

echo "── Scripts exécutables ─────────────────────────────────────────"
chmod +x "$CHECK_SCRIPT" "$PUSH_SCRIPT" "${MODULE_REPO}/scripts/git-hooks/post-commit"
echo "✓ ${PUSH_SCRIPT}"
echo "✓ ${CHECK_SCRIPT}"

echo
echo "── Hook post-commit (core.hooksPath) ───────────────────────────"
hooks_dir="${MODULE_REPO}/scripts/git-hooks"
git -C "$MODULE_REPO" config core.hooksPath "$hooks_dir"
echo "✓ ${MODULE_REPO} : core.hooksPath = ${hooks_dir} (push + bundles, même hook)"

echo
echo "── Cron utilisateur (détection bruyante) ───────────────────────"
mkdir -p "$LOG_DIR"

CRON_MARKER="# MYO-372 check-unpushed-myorg filet cron — géré par CommerceAgents/scripts/install-push-guard.sh, ne pas éditer à la main"
CRON_LINE="*/${CRON_INTERVAL_MIN} * * * * STALE_MINUTES=${STALE_MINUTES} LOG_DIR=${LOG_DIR} ${CHECK_SCRIPT} cron >>${LOG_DIR}/auto-push.log 2>&1"

existing_cron="$(crontab -l 2>/dev/null || true)"
filtered_cron="$(printf '%s\n' "$existing_cron" | grep -vF "$CHECK_SCRIPT" | grep -vF "$CRON_MARKER" || true)"
new_cron="$(printf '%s\n%s\n%s\n' "$filtered_cron" "$CRON_MARKER" "$CRON_LINE" | sed '/^[[:space:]]*$/d')"
printf '%s\n' "$new_cron" | crontab -

echo "✓ crontab installé/actualisé pour $(whoami) : toutes les ${CRON_INTERVAL_MIN} min"
crontab -l | grep -F "$CHECK_SCRIPT" | sed 's/^/  /'

echo
echo "✓ MYO-372 : auto-push (hook) + détection non-poussé (cron) installés."
echo "  Logs : ${LOG_DIR}/auto-push.log"
