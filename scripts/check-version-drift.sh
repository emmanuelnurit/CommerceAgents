#!/usr/bin/env bash
#
# MYO-452/MYO-453 — Détection de l'écart de version (dépôt module
# CommerceAgents), filet complémentaire à check-unpushed-myorg.sh.
#
# 3e récidive du même écart (MYO-408/409 pour 0.3.8→0.4.0, MYO-425 pour
# 0.4.0→0.4.1, MYO-452/453 pour 0.4.1→0.4.2) : des commits s'accumulent sur
# `myorg` sans que `Config/module.xml` ni un tag `vX.Y.Z` ne suivent, jusqu'à
# ce qu'un tri manuel s'en aperçoive plusieurs semaines après coup. Un
# correctif ponctuel sans mécanisme garantit une 4e fois — ce script ferme
# l'écart par de la détection continue, sur le même modèle que
# check-unpushed-myorg.sh (MYO-372) : cron */15, LOG_DIR partagé, idempotent,
# silencieux quand tout est propre.
#
# Contrairement à check-unpushed-myorg.sh, aucun accès réseau n'est
# nécessaire ici : le tag le plus récent, les commits au-dessus et
# Config/module.xml sont tous des états locaux du dépôt. Un tag posé mais pas
# encore poussé reste couvert séparément par check-unpushed-myorg.sh
# (WARN-UNPUSHED-TAGS) — ne pas dupliquer cette vérification ici.
#
# Détecte et alerte (log WARN) UNIQUEMENT : ne pose jamais de tag, ne modifie
# jamais Config/module.xml. Le versionnement reste une décision humaine/agent,
# même contrat que check-unpushed-myorg.sh.
#
# ── Usage ────────────────────────────────────────────────────────────────
#
#   ./scripts/check-version-drift.sh [cron|manual]
#
#   Variables (toutes optionnelles) :
#     MODULE_REPO     défaut: dossier contenant ce script
#     LOG_DIR         défaut: /home/enurit/backups/thelia3-bundles (même
#                     fichier de log que check-unpushed-myorg.sh/
#                     auto-push-myorg.sh — un seul endroit à grep pour tous
#                     les garde-fous de ce dépôt)
#
# ── Où regarder ──────────────────────────────────────────────────────────
#
#   grep WARN-VERSION /home/enurit/backups/thelia3-bundles/auto-push.log
#
set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
MODULE_REPO="${MODULE_REPO:-$(cd "$SCRIPT_DIR/.." && pwd)}"
LOG_DIR="${LOG_DIR:-/home/enurit/backups/thelia3-bundles}"
TRIGGER_SOURCE="${1:-manual}"

LOG_FILE="${LOG_DIR}/auto-push.log"
LOCK_FILE="${LOG_DIR}/.check-version-drift.lock"

mkdir -p "$LOG_DIR"
log() { printf '%s\n' "$1" >>"$LOG_FILE"; }
now_iso() { date -u +%Y-%m-%dT%H:%M:%SZ; }

cd "$MODULE_REPO" 2>/dev/null || {
  log "$(now_iso) [${TRIGGER_SOURCE}] SKIP-VERSION-CHECK (dépôt module introuvable: ${MODULE_REPO})"
  exit 0
}

exec 9>"$LOCK_FILE"
if ! flock -n 9; then
  exit 0
fi

latest_tag="$(git tag -l 'v*' --sort=-v:refname 2>/dev/null | head -1)"
if [[ -z "$latest_tag" ]]; then
  log "$(now_iso) [${TRIGGER_SOURCE}] SKIP-VERSION-CHECK (aucun tag vX.Y.Z trouvé localement)"
  exit 0
fi

commits_ahead="$(git log --oneline "${latest_tag}..HEAD" -- 2>/dev/null | wc -l | tr -d ' ')"

if [[ "$commits_ahead" -gt 0 ]]; then
  head_sha="$(git rev-parse --short HEAD 2>/dev/null || echo unknown)"
  log "$(now_iso) [${TRIGGER_SOURCE}] WARN-VERSION-COMMITS-AHEAD ${commits_ahead} commit(s) au-dessus de ${latest_tag} (HEAD: ${head_sha}) — vérifier si une release est due (Config/module.xml + CHANGELOG.md + nouveau tag), voir README/CHANGELOG pour la procédure"

  module_xml="${MODULE_REPO}/Config/module.xml"
  module_version="$(sed -n 's:.*<version>\(.*\)</version>.*:\1:p' "$module_xml" 2>/dev/null | head -1)"
  tag_version="${latest_tag#v}"

  if [[ -z "$module_version" ]]; then
    log "$(now_iso) [${TRIGGER_SOURCE}] WARN-CHECK (impossible de lire <version> dans ${module_xml})"
  elif [[ "$module_version" == "$tag_version" ]]; then
    log "$(now_iso) [${TRIGGER_SOURCE}] WARN-VERSION-MODULE-XML-STALE Config/module.xml déclare toujours ${module_version} (= ${latest_tag}) alors que ${commits_ahead} commit(s) sont livrés au-dessus — récidive du schéma MYO-408/425/452 si non traité"
  fi
fi

exit 0
