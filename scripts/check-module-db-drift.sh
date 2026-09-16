#!/usr/bin/env bash
#
# MYO-463 — Détection de l'écart entre Config/module.xml (version déclarée
# par le CODE du module CommerceAgents) et `module.version` réellement
# stocké en base (version de l'INSTANCE installée) — le 2e maillon de la
# chaîne de version, complémentaire à check-version-drift.sh (MYO-452/453/
# 455) qui ne surveille que tag git <-> Config/module.xml et n'a aucune idée
# de ce qui est réellement chargé dans une base donnée.
#
# Incident réel qui motive ce script (constat MYO-459 -> MYO-461) : le BO a
# affiché `0.3.8` pendant que Config/module.xml était à `0.4.2` depuis 3
# releases (0.4.0/0.4.1/0.4.2) — `module:refresh` n'avait jamais été rejoué
# après les mises à jour de code. check-version-drift.sh n'aurait rien vu :
# il ne lit jamais la base, uniquement l'état git local.
#
# ── Décision d'architecture (tranchée par le CTO, cf. MYO-463) ────────────
#
# Script SÉPARÉ, ne modifie PAS check-version-drift.sh : lire module.version
# nécessite un accès DB/DDEV vivant (conteneur démarré), une classe de panne
# totalement différente de check-version-drift.sh (100% git local, tourne
# même DDEV arrêté). Mélanger les deux aurait fait dépendre la détection
# *repo* (fiable, sans dépendance) d'un service qui n'a rien à voir avec
# elle, et aurait complexifié une dédup déjà délicate (état par HEAD sha).
#
# ── AC2 — le cas cron nu (vérifié réellement, pas juste en manuel) ────────
#
# `ddev` est résolu par CHEMIN ABSOLU (cf. DDEV_CANDIDATES ci-dessous),
# jamais par `command -v` seul : le PATH injecté par cron n'a pas
# `~/.local/bin` (cf. mémoire cron-path-ddev-introuvable.md, MYO-396/442).
#
# Vérifié en conditions RÉELLES (pas seulement `env -i` simulé) :
#   - `env -i HOME="$HOME" PATH=/usr/bin:/bin LOGNAME=... SHELL=/bin/sh
#     PWD="$(pwd)" /home/enurit/.local/bin/ddev mysql -N -e "SELECT ..."`
#     depuis le dépôt module ET depuis un cwd hors de l'arbre du projet DDEV
#     (ddev résout le projet par recherche ascendante de .ddev/config.yaml
#     à partir du cwd, comme git — d'où le `cd "$MODULE_REPO"` ci-dessous,
#     qui garantit un cwd dans l'arbre du projet).
#   - une entrée crontab RÉELLE (pas seulement simulée), programmée pour la
#     minute suivante, a exécuté ce script tel quel et produit une ligne de
#     log identique à l'exécution manuelle — preuve collée dans le
#     commentaire de clôture MYO-463.
#
# Conclusion : `ddev` lancé depuis cron nu, sur un projet DDEV déjà démarré,
# fonctionne sans dépendance à une session interactive — AUCUNE variable de
# session (DOCKER_HOST, agent SSH, etc.) n'est nécessaire ici, contrairement
# à des mécanismes comme le push SSH (MYO-443). Ce script est donc installé
# en cron au même rythme que check-version-drift.sh (voir
# install-push-guard.sh). Si DDEV est arrêté/absent au moment du passage,
# c'est traité comme un SKIP silencieux (dédupliqué, voir plus bas) — jamais
# une alerte : ce script ne sait détecter qu'une dérive de version, pas
# décider si DDEV *devrait* tourner.
#
# ── AC3 — silencieux au repos ──────────────────────────────────────────
#
# - Aucune ligne de log quand Config/module.xml et module.version (base)
#   concordent.
# - Dédup 24h (même fenêtre que check-version-drift.sh) pour le WARN de
#   dérive ET pour les conditions de SKIP (DDEV introuvable/arrêté, ligne
#   absente en base) — pour ne jamais reproduire l'incident MYO-447 (192
#   lignes/jour dans un log partagé) si DDEV reste arrêté un moment.
# - Marqueur d'alerte JSON séparé (.check-module-db-drift.alert), jamais le
#   même fichier que check-version-drift.sh : les deux diagnostics
#   (retard *code* vs retard *instance*) doivent pouvoir être notifiés
#   indépendamment par notify-version-drift.sh.
#
# Ne fait QUE détecter et journaliser : aucune écriture DB, jamais de
# `module:refresh` automatique. L'arbitrage reste humain/agent (CTO), même
# contrat que check-version-drift.sh.
#
# ── Usage ────────────────────────────────────────────────────────────────
#
#   ./scripts/check-module-db-drift.sh [cron|manual]
#
#   Variables (toutes optionnelles) :
#     MODULE_REPO   défaut: dossier contenant ce script
#     LOG_DIR       défaut: /home/enurit/backups/thelia3-bundles (même
#                   fichier de log que les autres filets de ce dépôt)
#     DDEV_BIN      défaut: auto-résolu par chemin absolu (voir
#                   DDEV_CANDIDATES) ; permet de forcer un binaire précis
#                   pour les tests.
#     MODULE_CODE   défaut: CommerceAgents
#
# ── Où regarder ──────────────────────────────────────────────────────────
#
#   grep WARN-MODULE-DB-VERSION /home/enurit/backups/thelia3-bundles/auto-push.log
#
set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
MODULE_REPO="${MODULE_REPO:-$(cd "$SCRIPT_DIR/.." && pwd)}"
LOG_DIR="${LOG_DIR:-/home/enurit/backups/thelia3-bundles}"
MODULE_CODE="${MODULE_CODE:-CommerceAgents}"
TRIGGER_SOURCE="${1:-manual}"

LOG_FILE="${LOG_DIR}/auto-push.log"
LOCK_FILE="${LOG_DIR}/.check-module-db-drift.lock"
STATE_FILE="${LOG_DIR}/.check-module-db-drift.state"
ALERT_FILE="${LOG_DIR}/.check-module-db-drift.alert"

# Dédup 24h, aussi bien pour le WARN de dérive que pour les SKIP (AC3).
DEDUP_WINDOW_SEC=86400
DDEV_TIMEOUT_SEC=30

mkdir -p "$LOG_DIR"
log() { printf '%s\n' "$1" >>"$LOG_FILE"; }
now_iso() { date -u +%Y-%m-%dT%H:%M:%SZ; }

cd "$MODULE_REPO" 2>/dev/null || {
  log "$(now_iso) [${TRIGGER_SOURCE}] SKIP-DB-CHECK (dépôt module introuvable: ${MODULE_REPO})"
  exit 0
}

exec 9>"$LOCK_FILE"
if ! flock -n 9; then
  exit 0
fi

now_epoch="$(date -u +%s)"

# État persisté entre passages (clé=valeur, valeurs sous notre contrôle donc
# `source` est sûr ici).
last_warned_state=""
last_warned_at=0
last_skip_reason=""
last_skip_at=0
if [[ -f "$STATE_FILE" ]]; then
  # shellcheck disable=SC1090
  source "$STATE_FILE"
fi

save_state() {
  {
    printf 'last_warned_state=%s\n' "$last_warned_state"
    printf 'last_warned_at=%s\n' "$last_warned_at"
    printf 'last_skip_reason=%s\n' "$last_skip_reason"
    printf 'last_skip_at=%s\n' "$last_skip_at"
  } >"$STATE_FILE"
}

# SKIP dédupliqué 24h par raison — un run cron */15 sur un DDEV arrêté ne
# doit jamais reproduire les 192 lignes/jour de MYO-447.
#
# `code` est un token stable (pas de texte dynamique) : c'est la SEULE chose
# persistée dans STATE_FILE, qui est ensuite relu via `source`. `detail` (le
# message humain, potentiellement de la sortie de commande brute avec
# parenthèses/guillemets/backticks) part UNIQUEMENT dans la ligne de log,
# jamais dans l'état — sourcer un fichier contenant des métacaractères shell
# arbitraires casserait (ou pire, exécuterait) le prochain passage.
skip_and_exit() {
  local code="$1" detail="$2"
  if [[ "$last_skip_reason" != "$code" || "$((now_epoch - last_skip_at))" -ge "$DEDUP_WINDOW_SEC" ]]; then
    log "$(now_iso) [${TRIGGER_SOURCE}] SKIP-DB-CHECK ${code}: ${detail}"
    last_skip_reason="$code"
    last_skip_at="$now_epoch"
    save_state
  fi
  exit 0
}

# AC2 — résolution par chemin ABSOLU, jamais `command -v` seul (le PATH cron
# n'a pas ~/.local/bin).
DDEV_CANDIDATES=(
  "${DDEV_BIN:-}"
  "$HOME/.local/bin/ddev"
  "/usr/local/bin/ddev"
  "/usr/bin/ddev"
  "/opt/ddev/bin/ddev"
)
ddev_bin=""
for candidate in "${DDEV_CANDIDATES[@]}"; do
  [[ -n "$candidate" && -x "$candidate" ]] && { ddev_bin="$candidate"; break; }
done
if [[ -z "$ddev_bin" ]]; then
  ddev_bin="$(command -v ddev 2>/dev/null || true)"
fi
if [[ -z "$ddev_bin" ]]; then
  skip_and_exit "DDEV-INTROUVABLE" "aucun binaire ddev résolu par chemin absolu ni PATH"
fi

module_xml="${MODULE_REPO}/Config/module.xml"
xml_version="$(sed -n 's:.*<version>\(.*\)</version>.*:\1:p' "$module_xml" 2>/dev/null | head -1)"
if [[ -z "$xml_version" ]]; then
  skip_and_exit "XML-VERSION-UNREADABLE" "impossible de lire <version> dans ${module_xml}"
fi

# Lecture seule stricte : SELECT uniquement, jamais d'écriture. `timeout`
# évite qu'un DDEV bloqué (démarrage lent, conteneur en train de s'arrêter)
# ne fasse tourner le cron indéfiniment.
db_query_output="$(timeout "$DDEV_TIMEOUT_SEC" "$ddev_bin" mysql -N -e "SELECT version FROM module WHERE code='${MODULE_CODE}' LIMIT 1;" 2>&1)"
db_query_rc=$?

if [[ "$db_query_rc" -ne 0 ]]; then
  skip_and_exit "DDEV-QUERY-FAILED" "requête DB impossible (ddev/projet arrêté ou inaccessible, rc=${db_query_rc}): $(printf '%s' "$db_query_output" | head -1 | tr -d '\n')"
fi

db_version="$(printf '%s' "$db_query_output" | tr -d '\r' | head -1)"
if [[ -z "$db_version" ]]; then
  skip_and_exit "NO-DB-ROW" "aucune ligne module pour code='${MODULE_CODE}' en base (module non installé dans cette instance ?)"
fi

if [[ "$xml_version" == "$db_version" ]]; then
  # État nominal : silence complet, y compris pour un ancien SKIP. Une
  # dérive a pu être résolue (module:refresh) depuis le dernier passage.
  rm -f "$STATE_FILE" "$ALERT_FILE"
  exit 0
fi

state_key="xml=${xml_version};db=${db_version}"
should_warn=1
if [[ "$last_warned_state" == "$state_key" ]]; then
  age=$((now_epoch - last_warned_at))
  [[ "$age" -lt "$DEDUP_WINDOW_SEC" ]] && should_warn=0
fi

if [[ "$should_warn" -eq 1 ]]; then
  log "$(now_iso) [${TRIGGER_SOURCE}] WARN-MODULE-DB-VERSION-STALE module.version en base = ${db_version} mais Config/module.xml déclare ${xml_version} pour code='${MODULE_CODE}' — module:refresh n'a probablement pas été rejoué après la dernière mise à jour de code (récidive du schéma MYO-461 si non traité). Voir README.md / docs/guide-exploitation.md pour la procédure."
  last_warned_state="$state_key"
  last_warned_at="$now_epoch"
fi

# Marqueur pour notify-version-drift.sh (routine Paperclip, 2e maillon de
# notification) — réécrit à chaque passage tant que la dérive persiste,
# indépendamment de la dédup du WARN ci-dessus (fichier idempotent, pas de
# bruit supplémentaire puisqu'il n'est pas journalisé dans auto-push.log).
python3 - "$ALERT_FILE" "$xml_version" "$db_version" "$now_epoch" "$MODULE_CODE" <<'PY'
import json
import sys

path, xml_version, db_version, detected_at, module_code = sys.argv[1:6]
with open(path, "w") as f:
    json.dump(
        {
            "module_code": module_code,
            "xml_version": xml_version,
            "db_version": db_version,
            "detected_at": int(detected_at),
        },
        f,
    )
    f.write("\n")
PY

save_state
exit 0
