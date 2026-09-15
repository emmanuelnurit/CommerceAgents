#!/usr/bin/env bash
#
# MYO-372 — Détection bruyante des commits non poussés (dépôt module
# CommerceAgents), filet complémentaire à auto-push-myorg.sh.
#
# auto-push-myorg.sh tente un push après CHAQUE commit sur myorg, mais peut
# échouer sans que personne ne le remarque (réseau, credentials absents hors
# run agent, non fast-forward — jamais de retry agressif ni de force par
# design, voir ce script). Celui-ci tourne indépendamment du hook (cron,
# même esprit que le filet de bundles MYO-369) et journalise un WARN si un
# commit sur myorg reste non poussé plus de STALE_MINUTES, pour qu'un
# heartbeat futur le détecte par grep (même modèle que MYO-359/360 : la
# fraîcheur se vérifie en comparant les logs, pas en interrogeant un état
# vivant).
#
# Ne pousse JAMAIS : uniquement lecture (git fetch — fonctionne sans
# credentials, ce dépôt GitHub est public en lecture, vérifié via
# `curl -o /dev/null -w '%{http_code}' https://api.github.com/repos/.../CommerceAgents`
# → 200) + log. Aucun credential nécessaire ici, contrairement à
# auto-push-myorg.sh qui pousse et a donc besoin d'un token.
#
# Idempotent et non bloquant : verrou dédié (n'entre jamais en conflit avec
# le verrou de auto-push-myorg.sh, fichiers séparés), sortie silencieuse
# quand tout est à jour.
#
# ── Usage ────────────────────────────────────────────────────────────────
#
#   ./scripts/check-unpushed-myorg.sh [cron|manual]
#
#   Variables (toutes optionnelles) :
#     MODULE_REPO     défaut: dossier contenant ce script
#     REMOTE          défaut: origin
#     BRANCH          défaut: myorg
#     LOG_DIR         défaut: /home/enurit/backups/thelia3-bundles (même
#                     fichier de log qu'auto-push-myorg.sh — un seul endroit
#                     à grep pour tous les garde-fous de ce dépôt)
#     STALE_MINUTES   défaut: 30 (âge du commit le plus ancien non poussé
#                     avant de journaliser un WARN)
#
# ── Où regarder ──────────────────────────────────────────────────────────
#
#   grep WARN-UNPUSHED /home/enurit/backups/thelia3-bundles/auto-push.log
#
set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
MODULE_REPO="${MODULE_REPO:-$(cd "$SCRIPT_DIR/.." && pwd)}"
REMOTE="${REMOTE:-origin}"
BRANCH="${BRANCH:-myorg}"
LOG_DIR="${LOG_DIR:-/home/enurit/backups/thelia3-bundles}"
STALE_MINUTES="${STALE_MINUTES:-30}"
TRIGGER_SOURCE="${1:-manual}"

LOG_FILE="${LOG_DIR}/auto-push.log"
LOCK_FILE="${LOG_DIR}/.check-unpushed.lock"

mkdir -p "$LOG_DIR"
log() { printf '%s\n' "$1" >>"$LOG_FILE"; }
now_iso() { date -u +%Y-%m-%dT%H:%M:%SZ; }

cd "$MODULE_REPO" 2>/dev/null || {
  log "$(now_iso) [${TRIGGER_SOURCE}] SKIP-CHECK (dépôt module introuvable: ${MODULE_REPO})"
  exit 0
}

exec 8>"$LOCK_FILE"
if ! flock -n 8; then
  exit 0
fi

if ! git fetch "$REMOTE" "$BRANCH" >/dev/null 2>&1; then
  log "$(now_iso) [${TRIGGER_SOURCE}] WARN-CHECK (git fetch ${REMOTE} ${BRANCH} a échoué, vérification reportée)"
  exit 0
fi

oldest_unpushed="$(git log --reverse --format=%H "${REMOTE}/${BRANCH}..${BRANCH}" -- 2>/dev/null | head -1)"

if [[ -z "$oldest_unpushed" ]]; then
  exit 0
fi

commit_epoch="$(git log -1 --format=%ct "$oldest_unpushed" 2>/dev/null || echo 0)"
now_epoch="$(date -u +%s)"
age_minutes=$(( (now_epoch - commit_epoch) / 60 ))

if [[ "$age_minutes" -ge "$STALE_MINUTES" ]]; then
  count="$(git log --oneline "${REMOTE}/${BRANCH}..${BRANCH}" -- 2>/dev/null | wc -l | tr -d ' ')"
  log "$(now_iso) [${TRIGGER_SOURCE}] WARN-UNPUSHED ${count} commit(s) non poussé(s) sur ${BRANCH} depuis ${age_minutes}min (plus ancien: ${oldest_unpushed:0:12}) — auto-push-myorg.sh a dû échouer, voir RESULT FAILED ci-dessus"
fi

exit 0
