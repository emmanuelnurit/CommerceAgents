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
# MYO-426 — étend ces deux mêmes volets (aucun 3e mécanisme, aucune ligne
# crontab ni hook supplémentaire à installer) pour que `origin/main`
# (branche par défaut publique) ne reste plus jamais en retard sur
# `origin/myorg` :
#   - volet 1 (auto-push-myorg.sh) : après un push réussi de myorg, tente un
#     fast-forward de main avec les mêmes credentials, seulement si main est
#     un ancêtre strict de myorg (jamais de --force).
#   - volet 2 (check-unpushed-myorg.sh) : compare aussi main à myorg de façon
#     indépendante (WARN-MAIN-BEHIND / WARN-MAIN-DIVERGED), même sans nouveau
#     commit côté agent.
#
# MYO-443 — ajoute un 3e volet, sans toucher aux deux premiers : le remote
# SSH authentifié par deploy key dédiée qu'auto-push-myorg.sh essaie en
# premier (repli PAT inchangé si absent). Modèle direct de
# thelia3/scripts/install-offsite-backup.sh (MYO-428/433), à une différence
# près : `origin` de CE dépôt est déjà la cible d'écriture voulue (pas un
# upstream public à préserver comme `thelia/thelia`), donc pas besoin de le
# laisser intact — on ajoute juste un second remote (`origin-ssh`, même URL
# en SSH) dédié à l'auth par clé, `origin` (HTTPS) restant la voie de lecture
# et le repli PAT. Ce script ne génère PAS la clé ni ne l'enregistre côté
# GitHub (opérations ponctuelles, hors de ce script réexécutable) : il
# vérifie juste sa présence et normalise le remote de façon idempotente.
#
# MYO-452/MYO-453 — ajoute un 4e volet, sans toucher aux trois premiers :
# détection de l'écart de version/tag (cf. check-version-drift.sh), même
# mécanisme d'installation cron que le volet 2 (marqueur dédié, même
# LOG_DIR, ne duplique pas la ligne existante de check-unpushed-myorg.sh).
#
# MYO-463 — ajoute un 5e volet, sans toucher aux quatre premiers : détection
# de l'écart entre Config/module.xml (code) et module.version (base, cf.
# check-module-db-drift.sh) — le 2e maillon de la chaîne de version, qui
# nécessite un accès DDEV/DB vivant contrairement au 4e volet (100% git
# local). Vérifié réellement depuis crontab (pas seulement en manuel,
# cf. AC2 MYO-463) : `ddev` résolu par chemin absolu dans le script
# lui-même, le PATH cron n'a jamais ~/.local/bin (même gotcha que le 4e
# volet — cf. mémoire cron-path-ddev-introuvable.md, MYO-396/442). Même
# mécanisme d'installation cron, marqueur/log/état dédiés
# (.check-module-db-drift.*), ne duplique aucune ligne existante.
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
#     DEPLOY_KEY_PATH     défaut: $HOME/.ssh/commerceagents_myorg_deploy_key
#     KNOWN_HOSTS_FILE    défaut: $HOME/.ssh/known_hosts
#     REMOTE_SSH_URL      défaut: git@github.com:emmanuelnurit/CommerceAgents.git
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
MODULE_REPO="${MODULE_REPO:-$(cd "$SCRIPT_DIR/.." && pwd)}"
CRON_INTERVAL_MIN="${CRON_INTERVAL_MIN:-15}"
STALE_MINUTES="${STALE_MINUTES:-30}"
LOG_DIR="${LOG_DIR:-/home/enurit/backups/thelia3-bundles}"
DEPLOY_KEY_PATH="${DEPLOY_KEY_PATH:-$HOME/.ssh/commerceagents_myorg_deploy_key}"
KNOWN_HOSTS_FILE="${KNOWN_HOSTS_FILE:-$HOME/.ssh/known_hosts}"
REMOTE_SSH_URL="${REMOTE_SSH_URL:-git@github.com:emmanuelnurit/CommerceAgents.git}"
CHECK_SCRIPT="${MODULE_REPO}/scripts/check-unpushed-myorg.sh"
CHECK_VERSION_SCRIPT="${MODULE_REPO}/scripts/check-version-drift.sh"
CHECK_DB_DRIFT_SCRIPT="${MODULE_REPO}/scripts/check-module-db-drift.sh"
PUSH_SCRIPT="${MODULE_REPO}/scripts/auto-push-myorg.sh"

echo "── Scripts exécutables ─────────────────────────────────────────"
chmod +x "$CHECK_SCRIPT" "$CHECK_VERSION_SCRIPT" "$CHECK_DB_DRIFT_SCRIPT" "$PUSH_SCRIPT" "${MODULE_REPO}/scripts/git-hooks/post-commit"
echo "✓ ${PUSH_SCRIPT}"
echo "✓ ${CHECK_SCRIPT}"
echo "✓ ${CHECK_VERSION_SCRIPT}"
echo "✓ ${CHECK_DB_DRIFT_SCRIPT}"

echo
echo "── Hook post-commit (core.hooksPath) ───────────────────────────"
hooks_dir="${MODULE_REPO}/scripts/git-hooks"
git -C "$MODULE_REPO" config core.hooksPath "$hooks_dir"
echo "✓ ${MODULE_REPO} : core.hooksPath = ${hooks_dir} (push + bundles, même hook)"

echo
echo "── MYO-443 : deploy key SSH + remote 'origin-ssh' ────────────────"
if [[ ! -r "$DEPLOY_KEY_PATH" ]]; then
  echo "✗ deploy key introuvable (${DEPLOY_KEY_PATH}) — la générer et l'enregistrer côté GitHub (POST /repos/emmanuelnurit/CommerceAgents/keys) avant de relancer ; en son absence auto-push-myorg.sh utilise le repli PAT (SKIP-SSH journalisé, pas bloquant)" >&2
else
  key_perms="$(stat -c %a "$DEPLOY_KEY_PATH")"
  if [[ "$key_perms" != "600" ]]; then
    chmod 600 "$DEPLOY_KEY_PATH"
    echo "✓ permissions corrigées : ${DEPLOY_KEY_PATH} (${key_perms} -> 600)"
  else
    echo "✓ ${DEPLOY_KEY_PATH} (permissions 600)"
  fi

  if ! ssh-keygen -F github.com -f "$KNOWN_HOSTS_FILE" >/dev/null 2>&1; then
    echo "✗ clé d'hôte github.com absente de ${KNOWN_HOSTS_FILE} — StrictHostKeyChecking échouera. Pré-remplir avec : ssh-keyscan -t ed25519,rsa github.com >> ${KNOWN_HOSTS_FILE}" >&2
  else
    echo "✓ clé d'hôte github.com présente dans ${KNOWN_HOSTS_FILE}"
  fi

  if git -C "$MODULE_REPO" remote get-url origin-ssh >/dev/null 2>&1; then
    current_url="$(git -C "$MODULE_REPO" remote get-url origin-ssh)"
    if [[ "$current_url" != "$REMOTE_SSH_URL" ]]; then
      git -C "$MODULE_REPO" remote set-url origin-ssh "$REMOTE_SSH_URL"
      echo "✓ remote 'origin-ssh' réécrit : ${current_url} -> ${REMOTE_SSH_URL}"
    else
      echo "✓ remote 'origin-ssh' déjà présent : ${current_url}"
    fi
  else
    git -C "$MODULE_REPO" remote add origin-ssh "$REMOTE_SSH_URL"
    echo "✓ remote 'origin-ssh' créé : ${REMOTE_SSH_URL} ('origin' HTTPS inchangé — lecture + repli PAT)"
  fi
fi

echo
echo "── Cron utilisateur (détection bruyante) ───────────────────────"
mkdir -p "$LOG_DIR"

CRON_MARKER="# MYO-372 check-unpushed-myorg filet cron — géré par CommerceAgents/scripts/install-push-guard.sh, ne pas éditer à la main"
CRON_LINE="*/${CRON_INTERVAL_MIN} * * * * STALE_MINUTES=${STALE_MINUTES} LOG_DIR=${LOG_DIR} ${CHECK_SCRIPT} cron >>${LOG_DIR}/auto-push.log 2>&1"

CRON_VERSION_MARKER="# MYO-452/MYO-453 check-version-drift filet cron — géré par CommerceAgents/scripts/install-push-guard.sh, ne pas éditer à la main"
CRON_VERSION_LINE="*/${CRON_INTERVAL_MIN} * * * * LOG_DIR=${LOG_DIR} ${CHECK_VERSION_SCRIPT} cron >>${LOG_DIR}/auto-push.log 2>&1"

CRON_DB_DRIFT_MARKER="# MYO-463 check-module-db-drift filet cron — géré par CommerceAgents/scripts/install-push-guard.sh, ne pas éditer à la main"
CRON_DB_DRIFT_LINE="*/${CRON_INTERVAL_MIN} * * * * LOG_DIR=${LOG_DIR} ${CHECK_DB_DRIFT_SCRIPT} cron >>${LOG_DIR}/auto-push.log 2>&1"

existing_cron="$(crontab -l 2>/dev/null || true)"
filtered_cron="$(printf '%s\n' "$existing_cron" |
  grep -vF "$CHECK_SCRIPT" | grep -vF "$CRON_MARKER" |
  grep -vF "$CHECK_VERSION_SCRIPT" | grep -vF "$CRON_VERSION_MARKER" |
  grep -vF "$CHECK_DB_DRIFT_SCRIPT" | grep -vF "$CRON_DB_DRIFT_MARKER" || true)"
new_cron="$(printf '%s\n%s\n%s\n%s\n%s\n%s\n%s\n' "$filtered_cron" "$CRON_MARKER" "$CRON_LINE" "$CRON_VERSION_MARKER" "$CRON_VERSION_LINE" "$CRON_DB_DRIFT_MARKER" "$CRON_DB_DRIFT_LINE" | sed '/^[[:space:]]*$/d')"
printf '%s\n' "$new_cron" | crontab -

echo "✓ crontab installé/actualisé pour $(whoami) : toutes les ${CRON_INTERVAL_MIN} min"
crontab -l | grep -F "$CHECK_SCRIPT" | sed 's/^/  /'
crontab -l | grep -F "$CHECK_VERSION_SCRIPT" | sed 's/^/  /'
crontab -l | grep -F "$CHECK_DB_DRIFT_SCRIPT" | sed 's/^/  /'

echo
echo "✓ MYO-372 : auto-push (hook) + détection non-poussé (cron) installés."
echo "✓ MYO-452/MYO-453 : détection de l'écart de version/tag (cron) installée."
echo "✓ MYO-463 : détection de l'écart module.xml/module.version en base (cron) installée."
echo "  Logs : ${LOG_DIR}/auto-push.log"
