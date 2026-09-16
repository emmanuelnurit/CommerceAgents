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
# ci-dessus, `try_fast_forward_main` réutilise les MÊMES credentials déjà
# récupérés (pas de second aller-retour secret) pour fast-forward `main` sur
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
# ── Credentials — jamais en clair dans une commande ou un log ─────────────
#
# Pattern GIT_ASKPASS éphémère (cf. historique MYO-241) : le token n'est
# jamais interpolé dans la ligne de commande `git push` ni dans l'URL remote,
# et le fichier askpass est un script temporaire (mode 600/700) supprimé dès
# usage. Deux sources essayées dans l'ordre :
#   1. $GITHUB_TOKEN si déjà injecté dans l'environnement du run courant
#      (secret Paperclip "github_token", delivery=env).
#   2. Sinon, récupéré à la demande via l'API Paperclip
#      (POST /agents/me/secrets/github_token/value) avec $PAPERCLIP_API_KEY —
#      seulement disponible si ce script tourne comme descendant d'un run
#      agent actif (le hook, appelé en enfant du process `git commit`,
#      hérite cet environnement au moment du commit).
#
# Si aucune des deux sources n'est disponible (ex. commit fait hors run
# agent), le script n'échoue pas : il journalise et laisse le filet cron
# (check-unpushed-myorg.sh) signaler le retard.
#
# ── Usage ────────────────────────────────────────────────────────────────
#
#   ./scripts/auto-push-myorg.sh [hook|manual]
#
#   Variables (toutes optionnelles) :
#     MODULE_REPO   défaut: dossier contenant ce script (résolu par lui-même)
#     REMOTE        défaut: origin
#     BRANCH        défaut: myorg
#     LOG_DIR       défaut: /home/enurit/backups/thelia3-bundles (même
#                   dossier que le filet de bundles MYO-369 — un seul endroit
#                   à surveiller pour tous les garde-fous de ce dépôt)
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
BRANCH="${BRANCH:-myorg}"
LOG_DIR="${LOG_DIR:-/home/enurit/backups/thelia3-bundles}"
TRIGGER_SOURCE="${1:-manual}"

LOG_FILE="${LOG_DIR}/auto-push.log"
LOCK_FILE="${LOG_DIR}/.auto-push.lock"

mkdir -p "$LOG_DIR"

log() { printf '%s\n' "$1" >>"$LOG_FILE"; }
now_iso() { date -u +%Y-%m-%dT%H:%M:%SZ; }

# MYO-426 — appelée uniquement après un push réussi de myorg ci-dessous.
# Réutilise le token/askpass déjà en main : ni second appel secret, ni
# re-demande de credentials.
try_fast_forward_main() {
  local token="$1" askpass_file="$2"
  local main_ref="${REMOTE}/main" myorg_ref="${REMOTE}/${BRANCH}"
  local main_sha myorg_sha

  git fetch "$REMOTE" main >/dev/null 2>&1

  main_sha="$(git rev-parse "$main_ref" 2>/dev/null || echo unknown)"
  myorg_sha="$(git rev-parse "$myorg_ref" 2>/dev/null || echo unknown)"

  if [[ "$main_sha" == "$myorg_sha" ]]; then
    return 0
  fi

  if ! git merge-base --is-ancestor "$main_ref" "$myorg_ref" 2>/dev/null; then
    log "$(now_iso) [${TRIGGER_SOURCE}] WARN-MAIN-DIVERGED ${main_ref} (${main_sha:0:12}) n'est pas un ancêtre de ${myorg_ref} (${myorg_sha:0:12}) — fast-forward refusé, AUCUN push tenté (jamais de --force)"
    return 0
  fi

  local ff_output ff_status
  ff_output="$(AUTO_PUSH_TOKEN="$token" GIT_ASKPASS="$askpass_file" GIT_TERMINAL_PROMPT=0 \
    git push "$REMOTE" "${myorg_ref}:refs/heads/main" 2>&1)"
  ff_status=$?

  if [[ $ff_status -eq 0 ]]; then
    log "$(now_iso) [${TRIGGER_SOURCE}] RESULT OK main fast-forward (MYO-426) ${main_sha:0:12} -> ${myorg_sha:0:12}"
  else
    local safe_tail
    safe_tail="$(printf '%s' "$ff_output" |
      sed -E 's#https://[^@[:space:]]+@#https://***REDACTED***@#g; s/gh[pousr]_[A-Za-z0-9]{20,}/***REDACTED***/g' |
      tail -5 | tr '\n' ' | ')"
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

before="$(git rev-parse "${REMOTE}/${BRANCH}" 2>/dev/null || echo unknown)"

push_output="$(AUTO_PUSH_TOKEN="$token" GIT_ASKPASS="$ASKPASS_FILE" GIT_TERMINAL_PROMPT=0 \
  git push "$REMOTE" "$BRANCH" --follow-tags 2>&1)"
status=$?

if [[ $status -eq 0 ]]; then
  after="$(git rev-parse "${REMOTE}/${BRANCH}" 2>/dev/null || echo unknown)"
  log "$(now_iso) [${TRIGGER_SOURCE}] RESULT OK (credentials: ${token_source}) ${before} -> ${after}"

  # MYO-426 — même credentials, encore valides à ce stade (nettoyées juste
  # après ce bloc, cf. plus bas).
  try_fast_forward_main "$token" "$ASKPASS_FILE"
else
  # git n'imprime normalement jamais la valeur GIT_ASKPASS dans sa sortie,
  # mais on filtre quand même par défense en profondeur avant de journaliser.
  safe_tail="$(printf '%s' "$push_output" |
    sed -E 's#https://[^@[:space:]]+@#https://***REDACTED***@#g; s/gh[pousr]_[A-Za-z0-9]{20,}/***REDACTED***/g' |
    tail -5 | tr '\n' ' | ')"
  log "$(now_iso) [${TRIGGER_SOURCE}] RESULT FAILED exit=${status} (pas de force, pas de retry) ${safe_tail}"
fi

token=""
AUTO_PUSH_TOKEN=""
rm -f "$ASKPASS_FILE"
ASKPASS_FILE=""

exit 0
