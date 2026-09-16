#!/usr/bin/env bash
#
# MYO-452/MYO-453/MYO-454/MYO-455 — Détection de l'écart de version (dépôt
# module CommerceAgents), filet complémentaire à check-unpushed-myorg.sh.
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
# MYO-455 corrige 3 défauts de la 1re version (MYO-452/453) constatés en
# usage réel (cf. MYO-454) :
#
#   1. Faux positif sur commits d'outillage (AC1/AC2) — un commit qui ne
#      touche QUE scripts/, docs/, CHANGELOG.md, .github/, README.md,
#      Tests/, composer.json ne compte pas comme une dérive fonctionnelle :
#      seuls les chemins qui contiennent du code/des ressources livrées
#      déclenchent une release potentielle. `functional_commits_ahead` (et
#      non le total brut de commits) est la mesure utilisée partout.
#
#      Écart assumé avec la liste de chemins listée dans MYO-455
#      (src/, Config/schema.xml, Config/*.php, templates/, I18n/) : ce
#      module n'a PAS de dossier src/ — <fullnamespace> dans
#      Config/module.xml est `CommerceAgents\CommerceAgents`, la racine du
#      namespace PHP est la racine du module (layout Thelia classique), le
#      code vit directement sous Agent/, Channel/, Command/, Controller/,
#      EventListener/, Hook/, Mcp/, Model/, Service/, StagedChange/, Tool/,
#      CommerceAgents.php. Appliquer `src/` au pied de la lettre aurait
#      classé QUASIMENT TOUT commit fonctionnel réel comme non-fonctionnel
#      pour toujours (silence permanent, y compris sur une vraie dérive) —
#      pire que le faux positif que ce ticket corrige. La liste ci-dessous
#      remplace `src/` par les dossiers de code réels de ce module ; le
#      reste (Config/schema.xml, Config/*.php, Config/update/*.sql,
#      templates/, I18n/) suit l'esprit de MYO-455 sans changement.
#
#   2. Répétition à chaque passage cron (AC3) — un fichier d'état
#      (${LOG_DIR}/.check-version-drift.state, clé=valeur) mémorise
#      `last_warned_head_sha` et `last_warned_at`. Tant que HEAD ne change
#      pas et que < 24h se sont écoulées depuis le dernier WARN, le script
#      tourne normalement mais ne réécrit rien dans le log (silence, pas
#      d'échec). Un nouveau commit fonctionnel (HEAD change) redéclenche
#      immédiatement.
#
#   3. Aucun destinataire (AC4) — ce script cron tourne en shell nu, SANS
#      PAPERCLIP_API_KEY/PAPERCLIP_API_URL (ces variables ne sont injectées
#      que dans un run agent, jamais via crontab, cf. auto-push-myorg.sh).
#      Il ne peut donc pas parler à l'API Paperclip lui-même. Il se contente
#      de poser un marqueur de seuil franchi
#      (${LOG_DIR}/.check-version-drift.alert, JSON) quand la dérive
#      fonctionnelle dépasse 24h OU 3 commits fonctionnels cumulés (le
#      premier des deux). Le destinataire réel est une ROUTINE Paperclip
#      (agent BackendEngineer, trigger schedule quotidien) qui, elle,
#      tourne dans un run agent normal (credentials disponibles) : à son
#      réveil, elle exécute scripts/notify-version-drift.sh, qui lit ce
#      marqueur et crée/met à jour un ticket Paperclip idempotent assigné
#      au CTO pour arbitrage release. Voir ce script pour le détail de la
#      recherche/dédup côté API.
#
# Contrairement à check-unpushed-myorg.sh, aucun accès réseau n'est
# nécessaire ici : le tag le plus récent, les commits au-dessus et
# Config/module.xml sont tous des états locaux du dépôt. Un tag posé mais pas
# encore poussé reste couvert séparément par check-unpushed-myorg.sh
# (WARN-UNPUSHED-TAGS) — ne pas dupliquer cette vérification ici.
#
# Détecte et alerte (log WARN/ALERT) UNIQUEMENT : ne pose jamais de tag, ne
# modifie jamais Config/module.xml ni CHANGELOG.md. Le versionnement reste
# une décision humaine/agent, même contrat que check-unpushed-myorg.sh.
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
#   grep ALERT-VERSION-DRIFT /home/enurit/backups/thelia3-bundles/auto-push.log
#
set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
MODULE_REPO="${MODULE_REPO:-$(cd "$SCRIPT_DIR/.." && pwd)}"
LOG_DIR="${LOG_DIR:-/home/enurit/backups/thelia3-bundles}"
TRIGGER_SOURCE="${1:-manual}"

LOG_FILE="${LOG_DIR}/auto-push.log"
LOCK_FILE="${LOG_DIR}/.check-version-drift.lock"
STATE_FILE="${LOG_DIR}/.check-version-drift.state"
ALERT_FILE="${LOG_DIR}/.check-version-drift.alert"

# Seuil d'escalade AC4 : le premier des deux critères atteint déclenche
# l'écriture du marqueur d'alerte pour la routine de notification.
ALERT_AGE_THRESHOLD_SEC=86400
ALERT_COMMITS_THRESHOLD=3
# Dédup WARN/ALERT (AC3) : ne pas rappeler plus d'1x/24h pour le même HEAD.
WARN_DEDUP_WINDOW_SEC=86400

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

head_sha="$(git rev-parse --short HEAD 2>/dev/null || echo unknown)"
now_epoch="$(date -u +%s)"

# AC1/AC2 — un commit ne compte comme dérive « fonctionnelle » que s'il
# touche au moins un chemin de code/ressources livrées. Voir l'en-tête pour
# l'écart assumé avec la liste MYO-455 (pas de dossier src/ dans ce module).
# Les commits purement scripts/docs/CHANGELOG/.github/README/Tests/
# composer.json ne comptent pas.
FUNCTIONAL_PATH_REGEX='^(Agent/|Channel/|Command/|CommerceAgents\.php$|Controller/|EventListener/|Hook/|I18n/|Mcp/|Model/|Service/|StagedChange/|Tool/|templates/|Config/schema\.xml$|Config/.*\.php$|Config/update/.*\.sql$)'

functional_commits_ahead=0
while IFS= read -r commit_sha; do
  [[ -z "$commit_sha" ]] && continue
  if git diff-tree --no-commit-id --name-only -r "$commit_sha" 2>/dev/null |
    grep -Eq "$FUNCTIONAL_PATH_REGEX"; then
    functional_commits_ahead=$((functional_commits_ahead + 1))
  fi
done < <(git log --format='%H' "${latest_tag}..HEAD" -- 2>/dev/null)

# Garde-fou post-livraison (incident constaté sur ce dépôt : commit de test
# AC2 5c88693 touchant Service/, nettoyé par `git revert` a3c79dd au lieu
# de `git reset`/amend — les deux restent dans l'historique public). Un
# commit qui touche un chemin fonctionnel peut être intégralement annulé
# par un commit postérieur dans la même plage ; le compte par-commit
# ci-dessus les compterait alors POUR TOUJOURS (jusqu'au prochain tag),
# recréant exactement le faux positif permanent que AC1 corrige. On ne
# retient la dérive que si le diff NET entre le tag et HEAD touche
# réellement au moins un chemin fonctionnel — sinon on retombe à 0 et à
# l'état nominal silencieux (branche functional_commits_ahead -eq 0
# ci-dessous), même si des commits individuels matchaient en cours de
# route.
if [[ "$functional_commits_ahead" -gt 0 ]] &&
  ! git diff --name-only "${latest_tag}" HEAD 2>/dev/null | grep -Eq "$FUNCTIONAL_PATH_REGEX"; then
  functional_commits_ahead=0
fi

# État persisté entre passages (clé=valeur, valeurs sous notre contrôle donc
# `source` est sûr ici — pas d'entrée utilisateur non maîtrisée).
last_warned_head_sha=""
last_warned_at=0
first_functional_at=0
if [[ -f "$STATE_FILE" ]]; then
  # shellcheck disable=SC1090
  source "$STATE_FILE"
fi

if [[ "$functional_commits_ahead" -eq 0 ]]; then
  # État nominal (aucune dérive fonctionnelle) : silence complet, y compris
  # sur d'anciens commits d'outillage au-dessus du tag (AC1). Une release
  # a pu combler l'écart depuis le dernier passage : on repart à zéro.
  rm -f "$STATE_FILE" "$ALERT_FILE"
  exit 0
fi

if [[ "$first_functional_at" -eq 0 ]]; then
  first_functional_at="$now_epoch"
fi

# AC3 — dédup 24h par HEAD : pas de rappel avant 24h sauf si HEAD a changé
# (un nouveau commit fonctionnel redéclenche immédiatement).
should_warn=1
if [[ "$last_warned_head_sha" == "$head_sha" ]]; then
  age=$((now_epoch - last_warned_at))
  if [[ "$age" -lt "$WARN_DEDUP_WINDOW_SEC" ]]; then
    should_warn=0
  fi
fi

if [[ "$should_warn" -eq 1 ]]; then
  log "$(now_iso) [${TRIGGER_SOURCE}] WARN-VERSION-COMMITS-AHEAD ${functional_commits_ahead} commit(s) fonctionnel(s) au-dessus de ${latest_tag} (HEAD: ${head_sha}) — vérifier si une release est due (Config/module.xml + CHANGELOG.md + nouveau tag), voir README/CHANGELOG pour la procédure"

  module_xml="${MODULE_REPO}/Config/module.xml"
  module_version="$(sed -n 's:.*<version>\(.*\)</version>.*:\1:p' "$module_xml" 2>/dev/null | head -1)"
  tag_version="${latest_tag#v}"

  if [[ -z "$module_version" ]]; then
    log "$(now_iso) [${TRIGGER_SOURCE}] WARN-CHECK (impossible de lire <version> dans ${module_xml})"
  elif [[ "$module_version" == "$tag_version" ]]; then
    log "$(now_iso) [${TRIGGER_SOURCE}] WARN-VERSION-MODULE-XML-STALE Config/module.xml déclare toujours ${module_version} (= ${latest_tag}) alors que ${functional_commits_ahead} commit(s) fonctionnel(s) sont livrés au-dessus — récidive du schéma MYO-408/425/452 si non traité"
  fi

  last_warned_head_sha="$head_sha"
  last_warned_at="$now_epoch"
fi

# AC4 — seuil d'escalade : dérive non résolue depuis >= 24h OU >= 3 commits
# fonctionnels cumulés (le premier des deux). Pose le marqueur pour la
# routine Paperclip (voir notify-version-drift.sh) ; la ligne ALERT suit la
# même dédup que le WARN ci-dessus (pas de répétition à chaque passage une
# fois écrite).
drift_age=$((now_epoch - first_functional_at))
if [[ "$drift_age" -ge "$ALERT_AGE_THRESHOLD_SEC" || "$functional_commits_ahead" -ge "$ALERT_COMMITS_THRESHOLD" ]]; then
  # MYO-464/465 — garde-fou diffstat : le marqueur (et donc le ticket produit
  # par notify-version-drift.sh) doit porter le diff NET entre le tag et HEAD,
  # pas seulement un compte de commits. 5 arbitrages successifs
  # (MYO-456/457/458/460/462) ont clos la dérive « artefact de test » sans
  # voir que des lignes réelles montaient dessous, faute de ce diffstat dans
  # le ticket.
  diffstat="$(git diff --stat "${latest_tag}" HEAD -- 2>/dev/null || true)"

  python3 - "$ALERT_FILE" "$latest_tag" "$head_sha" "$functional_commits_ahead" "$first_functional_at" "$diffstat" <<'PY'
import json
import sys

path, latest_tag, head_sha, functional_commits_ahead, first_functional_at, diffstat = sys.argv[1:7]
with open(path, "w") as f:
    json.dump(
        {
            "latest_tag": latest_tag,
            "head_sha": head_sha,
            "functional_commits_ahead": int(functional_commits_ahead),
            "first_functional_at": int(first_functional_at),
            "diffstat": diffstat,
        },
        f,
    )
    f.write("\n")
PY

  if [[ "$should_warn" -eq 1 ]]; then
    log "$(now_iso) [${TRIGGER_SOURCE}] ALERT-VERSION-DRIFT-THRESHOLD dérive fonctionnelle non résolue depuis $((drift_age / 3600))h (>= 24h) ou ${functional_commits_ahead} commit(s) fonctionnel(s) cumulés (>= ${ALERT_COMMITS_THRESHOLD}) au-dessus de ${latest_tag} (HEAD: ${head_sha}) — marqueur ${ALERT_FILE} posé, la routine Paperclip de notification prendra le relais"
  fi
fi

{
  printf 'last_warned_head_sha=%s\n' "$last_warned_head_sha"
  printf 'last_warned_at=%s\n' "$last_warned_at"
  printf 'first_functional_at=%s\n' "$first_functional_at"
} >"$STATE_FILE"

exit 0
