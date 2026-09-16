#!/usr/bin/env bash
#
# MYO-372 — Auto-push non-bloquant vers origin/myorg (dépôt module CommerceAgents).
#
# Objectif : fermer le trou de sauvegarde distante sur ce dépôt (5 rechutes
# documentées : MYO-339/341/359/366/368) en tentant un `git push origin
# myorg` après CHAQUE commit sur cette branche, déclenché par
# scripts/git-hooks/post-commit — le même hook que MYO-369 (bundles), étendu
# plutôt que dupliqué. Un dépôt n'a qu'un seul hook post-commit exécutable
# via core.hooksPath ; "ajouter un second mécanisme" se traduit donc par un
# second appel en tâche de fond ajouté dans ce même fichier de hook, pas par
# un second fichier de hook. Le script de bundles (backup-bundles.sh) et son
# wrapper (trigger-backup-bundles.sh) ne sont pas touchés.
#
# Ne fait JAMAIS de --force ni de rebase : un push qui échoue (non
# fast-forward, réseau, credentials absents) est simplement journalisé,
# jamais retenté en boucle ni transformé en écrasement distant. La détection
# bruyante des commits qui resteraient non poussés malgré ça est prise en
# charge séparément par check-unpushed-myorg.sh (cron), volontairement un
# script distinct : celui-ci pousse (a besoin de credentials), l'autre ne
# fait que lire (n'en a pas besoin, ce dépôt GitHub est public en lecture).
#
# MYO-376 — `git push` seul ne pousse AUCUN tag, même sur la branche
# poussée : un `git tag -a` posé localement (ex. tag de release v0.3.x)
# reste invisible sur origin tant que personne ne pense à `--tags`. On
# ajoute donc `--follow-tags` à l'appel de push (pas `--tags` brut, qui
# pousserait aussi des tags locaux volontairement non publiés) : ne pousse
# que les tags *annotés* déjà atteignables depuis la branche poussée et
# absents du remote — exactement le cas des tags de release. Le filet
# check-unpushed-myorg.sh vérifie séparément qu'aucun tag ne reste local-only.
#
# MYO-426 — `origin/myorg` avance et se pousse tout seul, mais rien ne
# rattrapait `origin/main` (branche par défaut, publique) derrière : 3e
# occurrence du même trou (MYO-415/420). Après un push réussi de myorg
# ci-dessus, `try_fast_forward_main` réutilise le MÊME remote/credentials déjà
# établis (pas de second aller-retour secret) pour fast-forward `main` sur
# `myorg` si et seulement si `origin/main` est un ancêtre strict de
# `origin/myorg` (`git merge-base --is-ancestor`). Le push utilisé est un
# refspec normal (`origin/myorg:refs/heads/main`, sans `--force`) : git
# refuse déjà tout seul un update non fast-forward, donc aucune protection
# supplémentaire n'est nécessaire pour interdire l'écrasement de main. Si
# main a divergé (n'est pas un ancêtre strict — ex. quelqu'un a poussé dessus
# directement), on ne pousse RIEN et on journalise WARN-MAIN-DIVERGED avec
# les deux SHA en cause ; check-unpushed-myorg.sh détecte aussi ce cas de
# façon indépendante (cron, lecture seule).
#
# ── MYO-443 — chemin nominal : deploy key SSH dédiée, PAT en repli ────────
#
# Constat MYO-442 : le cron (check-unpushed-myorg.sh) détecte bien un retard,
# mais ce script-ci ne pouvait réparer QUE depuis un run agent vivant (seul
# contexte où $GITHUB_TOKEN/$PAPERCLIP_API_KEY existent) — exactement la
# panne déjà fermée pour le dépôt SITE par MYO-428/433 (deploy key SSH,
# `scripts/auto-push-offsite.sh`). Ce dépôt module a son propre `origin`
# (`emmanuelnurit/CommerceAgents`, pas un upstream public à préserver comme
# `thelia/thelia`), donc pas besoin d'un second remote nommé "backup" : une
# deploy key SSH dédiée en écriture est enregistrée sur ce même dépôt
# (`POST /repos/emmanuelnurit/CommerceAgents/keys`) et un remote SSH séparé
# (`$REMOTE_SSH`, même URL que `$REMOTE` mais en `git@github.com:...`) sert
# uniquement de cible de push authentifiée par clé — `$REMOTE` (origin,
# HTTPS) n'est pas modifié, `git fetch`/`git ls-remote` en lecture seule
# restent inchangés partout ailleurs (dépôt public en lecture).
#
# Ordre essayé à CHAQUE push :
#   1. Deploy key SSH ($DEPLOY_KEY_PATH, clé hors dépôt, jamais commitée,
#      `StrictHostKeyChecking=yes` avec `known_hosts` pré-rempli) — chemin
#      nominal, fonctionne aussi bien depuis un hook post-commit que depuis
#      un cron sans aucun run agent vivant.
#   2. Repli PAT (pattern GIT_ASKPASS éphémère, cf. historique MYO-241) si la
#      clé est absente OU si le push SSH échoue : le token n'est jamais
#      interpolé dans la ligne de commande `git push` ni dans l'URL remote,
#      et le fichier askpass est un script temporaire (mode 600/700) supprimé
#      dès usage. Deux sources essayées dans l'ordre :
#      a. $GITHUB_TOKEN si déjà injecté dans l'environnement du run courant
#         (secret Paperclip "github_token", delivery=env).
#      b. Sinon, récupéré à la demande via l'API Paperclip
#         (POST /agents/me/secrets/github_token/value) avec
#         $PAPERCLIP_API_KEY — seulement disponible si ce script tourne comme
#         descendant d'un run agent actif. C'est un repli, pas le chemin
#         nominal : il reste utile en secours (ex. deploy key révoquée), mais
#         hérite de la même limite qu'avant MYO-443 (indisponible en cron
#         pur).
#
# Si aucune des deux voies n'est disponible (clé absente ET commit fait hors
# run agent), le script n'échoue pas : il journalise et laisse le filet cron
# (check-unpushed-myorg.sh) signaler le retard.
#
# ── Usage ────────────────────────────────────────────────────────────────
#
#   ./scripts/auto-push-myorg.sh [hook|manual|cron]
#
#   Variables (toutes optionnelles) :
#     MODULE_REPO       défaut: dossier contenant ce script (résolu par lui-même)
#     REMOTE            défaut: origin (HTTPS, lecture + repli PAT)
#     REMOTE_SSH        défaut: origin-ssh (même dépôt, écriture par deploy key)
#     BRANCH            défaut: myorg
#     DEPLOY_KEY_PATH   défaut: $HOME/.ssh/commerceagents_myorg_deploy_key
#     KNOWN_HOSTS_FILE  défaut: $HOME/.ssh/known_hosts
#     LOG_DIR           défaut: /home/enurit/backups/thelia3-bundles (même
#                       dossier que le filet de bundles MYO-369 — un seul
#                       endroit à surveiller pour tous les garde-fous de ce
#                       dépôt)
#
# ── Où regarder en cas d'échec ──────────────────────────────────────────────
#
#   $LOG_DIR/auto-push.log — une ligne "RESULT FAILED" signale un push en
#   échec : grep RESULT /home/enurit/backups/thelia3-bundles/auto-push.log
#
set -uo pipefail
# Pas de `set -e` : ce script est lancé en tâche de fond depuis un hook,
# il doit toujours atteindre la ligne de log et jamais faire échouer
# `git commit` de l'appelant.

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
MODULE_REPO="${MODULE_REPO:-$(cd "$SCRIPT_DIR/.." && pwd)}"
REMOTE="${REMOTE:-origin}"
REMOTE_SSH="${REMOTE_SSH:-origin-ssh}"
BRANCH="${BRANCH:-myorg}"
DEPLOY_KEY_PATH="${DEPLOY_KEY_PATH:-$HOME/.ssh/commerceagents_myorg_deploy_key}"
KNOWN_HOSTS_FILE="${KNOWN_HOSTS_FILE:-$HOME/.ssh/known_hosts}"
LOG_DIR="${LOG_DIR:-/home/enurit/backups/thelia3-bundles}"
TRIGGER_SOURCE="${1:-manual}"

LOG_FILE="${LOG_DIR}/auto-push.log"
LOCK_FILE="${LOG_DIR}/.auto-push.lock"

mkdir -p "$LOG_DIR"

log() { printf '%s\n' "$1" >>"$LOG_FILE"; }
now_iso() { date -u +%Y-%m-%dT%H:%M:%SZ; }
redact() {
  sed -E 's#https://[^@[:space:]]+@#https://***REDACTED***@#g; s/gh[pousr]_[A-Za-z0-9]{20,}/***REDACTED***/g'
}

# MYO-426 — appelée uniquement après un push réussi de myorg ci-dessous.
# $1 = remote à travers lequel pousser le fast-forward de main ; l'appelant a
# déjà exporté l'auth adéquate (GIT_SSH_COMMAND ou AUTO_PUSH_TOKEN/GIT_ASKPASS)
# avant l'appel — ni second appel secret, ni re-demande de credentials.
try_fast_forward_main() {
  local push_remote="$1"
  # MYO-443 — le SHA de myorg vient du HEAD local ($BRANCH), pas de la ref de
  # suivi "${REMOTE}/${BRANCH}" : un push réussi via $REMOTE_SSH ne met à
  # jour QUE "refs/remotes/${REMOTE_SSH}/${BRANCH}" côté git (remote-tracking
  # opportuniste scopé au remote effectivement poussé), donc
  # "refs/remotes/${REMOTE}/${BRANCH}" resterait périmée après un push SSH et
  # ce garde-fou croirait main déjà à jour sans rien faire. Sans risque : un
  # push non-force qui vient de réussir garantit remote == HEAD local.
  local main_ref="${REMOTE}/main"
  local main_sha myorg_sha

  git fetch "$REMOTE" main >/dev/null 2>&1

  main_sha="$(git rev-parse "$main_ref" 2>/dev/null || echo unknown)"
  myorg_sha="$(git rev-parse "$BRANCH" 2>/dev/null || echo unknown)"

  if [[ "$main_sha" == "$myorg_sha" ]]; then
    return 0
  fi

  if ! git merge-base --is-ancestor "$main_ref" "$BRANCH" 2>/dev/null; then
    log "$(now_iso) [${TRIGGER_SOURCE}] WARN-MAIN-DIVERGED ${main_ref} (${main_sha:0:12}) n'est pas un ancêtre de ${BRANCH} local (${myorg_sha:0:12}) — fast-forward refusé, AUCUN push tenté (jamais de --force)"
    return 0
  fi

  local ff_output ff_status
  ff_output="$(git push "$push_remote" "${BRANCH}:refs/heads/main" 2>&1)"
  ff_status=$?

  if [[ $ff_status -eq 0 ]]; then
    log "$(now_iso) [${TRIGGER_SOURCE}] RESULT OK main fast-forward (MYO-426) ${main_sha:0:12} -> ${myorg_sha:0:12}"
  else
    local safe_tail
    safe_tail="$(printf '%s' "$ff_output" | redact | tail -5 | tr '\n' ' | ')"
    log "$(now_iso) [${TRIGGER_SOURCE}] RESULT FAILED main fast-forward exit=${ff_status} (pas de force, pas de retry) ${safe_tail}"
  fi
}

cd "$MODULE_REPO" 2>/dev/null || {
  log "$(now_iso) [${TRIGGER_SOURCE}] SKIP (dépôt module introuvable: ${MODULE_REPO})"
  exit 0
}

current_branch="$(git rev-parse --abbrev-ref HEAD 2>/dev/null || true)"
if [[ "$current_branch" != "$BRANCH" ]]; then
  log "$(now_iso) [${TRIGGER_SOURCE}] SKIP (branche courante '${current_branch}' != '${BRANCH}')"
  exit 0
fi

# Verrou : jamais deux push en parallèle (hook peut se chevaucher avec un
# push manuel, ou avec lui-même si deux commits arrivent coup sur coup).
exec 9>"$LOCK_FILE"
if ! flock -n 9; then
  log "$(now_iso) [${TRIGGER_SOURCE}] SKIP (push déjà en cours, verrou ${LOCK_FILE} occupé)"
  exit 0
fi

ASKPASS_FILE=""
cleanup() { [[ -n "$ASKPASS_FILE" && -f "$ASKPASS_FILE" ]] && rm -f "$ASKPASS_FILE"; }
trap cleanup EXIT

before="$(git rev-parse "${REMOTE}/${BRANCH}" 2>/dev/null || echo unknown)"
pushed=0

# ── MYO-443 : chemin nominal — deploy key SSH dédiée ──────────────────────
if [[ -r "$DEPLOY_KEY_PATH" ]] && git remote get-url "$REMOTE_SSH" >/dev/null 2>&1; then
  GIT_SSH_COMMAND="ssh -i ${DEPLOY_KEY_PATH} -o IdentitiesOnly=yes -o StrictHostKeyChecking=yes -o UserKnownHostsFile=${KNOWN_HOSTS_FILE} -o BatchMode=yes"
  export GIT_SSH_COMMAND

  push_output="$(git push "$REMOTE_SSH" "${BRANCH}:refs/heads/${BRANCH}" --follow-tags 2>&1)"
  status=$?

  if [[ $status -eq 0 ]]; then
    after="$(git rev-parse "${REMOTE_SSH}/${BRANCH}" 2>/dev/null || echo unknown)"
    log "$(now_iso) [${TRIGGER_SOURCE}] RESULT OK (credentials: ssh-deploy-key) ${before} -> ${after}"
    try_fast_forward_main "$REMOTE_SSH"
    pushed=1
  else
    safe_tail="$(printf '%s' "$push_output" | redact | tail -5 | tr '\n' ' | ')"
    log "$(now_iso) [${TRIGGER_SOURCE}] RESULT FAILED (credentials: ssh-deploy-key) exit=${status} (pas de force, pas de retry — repli PAT ci-dessous) ${safe_tail}"
  fi

  unset GIT_SSH_COMMAND
else
  log "$(now_iso) [${TRIGGER_SOURCE}] SKIP-SSH (deploy key ou remote '${REMOTE_SSH}' absent — voir scripts/install-push-guard.sh — repli PAT)"
fi

# ── Repli PAT — seulement si la voie SSH n'a pas abouti ───────────────────
if [[ "$pushed" -eq 0 ]]; then
  token=""
  token_source=""
  if [[ -n "${GITHUB_TOKEN:-}" ]]; then
    token="$GITHUB_TOKEN"
    token_source="env"
  elif [[ -n "${PAPERCLIP_API_KEY:-}" && -n "${PAPERCLIP_API_URL:-}" ]]; then
    api_base="${PAPERCLIP_API_URL%/}"
    api_base="${api_base%/api}"
    token="$(curl -s -X POST -H "Authorization: Bearer ${PAPERCLIP_API_KEY}" \
      "${api_base}/api/agents/me/secrets/github_token/value" 2>/dev/null |
      python3 -c 'import sys,json
try:
    print(json.load(sys.stdin).get("value") or "")
except Exception:
    print("")' 2>/dev/null || true)"
    [[ -n "$token" ]] && token_source="paperclip-secret-api"
  fi

  if [[ -z "$token" ]]; then
    log "$(now_iso) [${TRIGGER_SOURCE}] SKIP (aucun credential github_token disponible dans ce contexte — filet: check-unpushed-myorg.sh)"
    exit 0
  fi

  ASKPASS_FILE="$(mktemp)"
  chmod 600 "$ASKPASS_FILE"
  {
    printf '#!/usr/bin/env bash\n'
    printf 'printf %%s "$AUTO_PUSH_TOKEN"\n'
  } >"$ASKPASS_FILE"
  chmod 700 "$ASKPASS_FILE"

  push_output="$(AUTO_PUSH_TOKEN="$token" GIT_ASKPASS="$ASKPASS_FILE" GIT_TERMINAL_PROMPT=0 \
    git push "$REMOTE" "$BRANCH" --follow-tags 2>&1)"
  status=$?

  if [[ $status -eq 0 ]]; then
    after="$(git rev-parse "${REMOTE}/${BRANCH}" 2>/dev/null || echo unknown)"
    log "$(now_iso) [${TRIGGER_SOURCE}] RESULT OK (credentials: ${token_source}, repli PAT) ${before} -> ${after}"

    # MYO-426 — même credentials, encore valides à ce stade (nettoyées juste
    # après ce bloc, cf. plus bas).
    AUTO_PUSH_TOKEN="$token" GIT_ASKPASS="$ASKPASS_FILE" GIT_TERMINAL_PROMPT=0 \
      try_fast_forward_main "$REMOTE"
  else
    # git n'imprime normalement jamais la valeur GIT_ASKPASS dans sa sortie,
    # mais on filtre quand même par défense en profondeur avant de journaliser.
    safe_tail="$(printf '%s' "$push_output" | redact | tail -5 | tr '\n' ' | ')"
    log "$(now_iso) [${TRIGGER_SOURCE}] RESULT FAILED exit=${status} (pas de force, pas de retry) ${safe_tail}"
  fi

  token=""
  AUTO_PUSH_TOKEN=""
  rm -f "$ASKPASS_FILE"
  ASKPASS_FILE=""
fi

exit 0
