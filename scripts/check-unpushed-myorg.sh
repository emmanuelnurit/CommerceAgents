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
# MYO-443 — ce script n'avait donc PAS le trou que MYO-433 a fermé pour la
# détection côté site (`check-unpushed-offsite.sh` a besoin d'une deploy key
# rien que pour lire, dépôt privé) : rien à changer ici côté auth. Le trou
# réel était uniquement côté écriture (auto-push-myorg.sh, corrigé dans le
# même ticket par une deploy key SSH dédiée + repli PAT).
#
# Idempotent et non bloquant : verrou dédié (n'entre jamais en conflit avec
# le verrou de auto-push-myorg.sh, fichiers séparés), sortie silencieuse
# quand tout est à jour.
#
# MYO-376 — vérifie aussi les tags (pas seulement les commits de branche) :
# auto-push-myorg.sh pousse désormais avec --follow-tags, mais un tag posé
# hors d'un run agent (pas de credentials disponibles) resterait local-only
# sans que rien ne le signale. On compare `git tag` local à
# `git ls-remote --tags` (lecture publique, pas de credentials nécessaires,
# même raisonnement que le fetch des commits ci-dessus).
#
# MYO-426 — compare aussi `origin/main` à `origin/myorg`, INDÉPENDAMMENT de
# tout commit local en attente ci-dessus : auto-push-myorg.sh fast-forward
# main juste après avoir poussé myorg, mais ce filet doit aussi détecter le
# cas où main prend du retard sans nouveau commit côté agent (ex. quelqu'un
# pousse sur main directement, ou le retard existait déjà avant même
# l'installation de ce mécanisme). Journalise WARN-MAIN-BEHIND (N commits,
# depuis quand) sur le même modèle que WARN-UNPUSHED, ou WARN-MAIN-DIVERGED
# si main n'est plus un ancêtre strict de myorg (fast-forward impossible
# sans --force — jamais tenté ici ni ailleurs). Lecture seule, comme le reste
# de ce script.
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

if [[ -n "$oldest_unpushed" ]]; then
  commit_epoch="$(git log -1 --format=%ct "$oldest_unpushed" 2>/dev/null || echo 0)"
  now_epoch="$(date -u +%s)"
  age_minutes=$(( (now_epoch - commit_epoch) / 60 ))

  if [[ "$age_minutes" -ge "$STALE_MINUTES" ]]; then
    count="$(git log --oneline "${REMOTE}/${BRANCH}..${BRANCH}" -- 2>/dev/null | wc -l | tr -d ' ')"
    log "$(now_iso) [${TRIGGER_SOURCE}] WARN-UNPUSHED ${count} commit(s) non poussé(s) sur ${BRANCH} depuis ${age_minutes}min (plus ancien: ${oldest_unpushed:0:12}) — auto-push-myorg.sh a dû échouer, voir RESULT FAILED ci-dessus"
  fi
fi

# Vérification des tags : indépendante de l'état des commits ci-dessus (un
# tag peut rester local-only même quand la branche est entièrement à jour),
# donc jamais dans le `if` précédent ni derrière un `exit 0` anticipé.
local_tags="$(git tag 2>/dev/null | sort -u)"
if [[ -n "$local_tags" ]]; then
  remote_tags="$(git ls-remote --tags "$REMOTE" 2>/dev/null |
    awk '{print $2}' | sed -e 's#refs/tags/##' -e 's/\^{}$//' | sort -u)"
  missing_tags="$(comm -23 <(printf '%s\n' "$local_tags") <(printf '%s\n' "$remote_tags") | tr '\n' ' ')"
  missing_tags_trimmed="$(printf '%s' "$missing_tags" | tr -d '[:space:]')"
  if [[ -n "$missing_tags_trimmed" ]]; then
    log "$(now_iso) [${TRIGGER_SOURCE}] WARN-UNPUSHED-TAGS tag(s) absent(s) de ${REMOTE}: ${missing_tags}— auto-push-myorg.sh --follow-tags n'a pas (encore) couvert ce(s) tag(s), pousser manuellement (git push --tags)"
  fi
fi

# MYO-426 — comparaison main vs myorg, indépendante du bloc "commits non
# poussés" ci-dessus (peut alerter même quand myorg est entièrement à jour).
if git fetch "$REMOTE" main >/dev/null 2>&1; then
  main_ref="${REMOTE}/main"
  myorg_ref="${REMOTE}/${BRANCH}"
  main_sha="$(git rev-parse "$main_ref" 2>/dev/null || echo unknown)"
  myorg_sha="$(git rev-parse "$myorg_ref" 2>/dev/null || echo unknown)"

  if [[ "$main_sha" != "$myorg_sha" ]]; then
    if git merge-base --is-ancestor "$main_ref" "$myorg_ref" 2>/dev/null; then
      behind_count="$(git log --oneline "${main_ref}..${myorg_ref}" -- 2>/dev/null | wc -l | tr -d ' ')"
      oldest_behind="$(git log --reverse --format=%H "${main_ref}..${myorg_ref}" -- 2>/dev/null | head -1)"
      oldest_epoch="$(git log -1 --format=%ct "$oldest_behind" 2>/dev/null || echo 0)"
      now_epoch="$(date -u +%s)"
      behind_minutes=$(( (now_epoch - oldest_epoch) / 60 ))
      log "$(now_iso) [${TRIGGER_SOURCE}] WARN-MAIN-BEHIND ${behind_count} commit(s) : ${main_ref} (${main_sha:0:12}) en retard sur ${myorg_ref} (${myorg_sha:0:12}) depuis ${behind_minutes}min — auto-push-myorg.sh aurait dû fast-forward, voir RESULT ci-dessus/prochain push"
    else
      log "$(now_iso) [${TRIGGER_SOURCE}] WARN-MAIN-DIVERGED ${main_ref} (${main_sha:0:12}) n'est pas un ancêtre de ${myorg_ref} (${myorg_sha:0:12}) — fast-forward impossible sans --force (jamais tenté), intervention humaine requise"
    fi
  fi
else
  log "$(now_iso) [${TRIGGER_SOURCE}] WARN-CHECK (git fetch ${REMOTE} main a échoué, vérification main reportée)"
fi

exit 0
