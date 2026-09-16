#!/usr/bin/env bash
#
# MYO-455 (AC4) — notification Paperclip pour le filet version-drift.
#
# check-version-drift.sh tourne en cron nu, SANS PAPERCLIP_API_KEY ni
# PAPERCLIP_API_URL (ces variables ne sont injectées que dans un run agent,
# jamais via crontab). Il ne peut donc jamais parler à l'API Paperclip
# lui-même : il se contente de poser un marqueur JSON
# (${LOG_DIR}/.check-version-drift.alert) quand le seuil d'escalade est
# franchi (dérive fonctionnelle >= 24h ou >= 3 commits fonctionnels
# cumulés).
#
# Ce script-ci est le second maillon : il est destiné à être exécuté
# UNIQUEMENT depuis un run agent (typiquement une routine Paperclip
# "BackendEngineer — notification version-drift", trigger schedule
# quotidien) où PAPERCLIP_API_KEY est disponible. Il lit le marqueur et
# crée/met à jour un ticket Paperclip idempotent assigné au CTO pour
# arbitrage release (Config/module.xml + CHANGELOG.md + tag).
#
# Idempotence : la recherche se fait sur un marqueur texte fixe
# (WARN-VERSION-DRIFT-PERSISTANT) présent dans le corps du ticket, pas sur
# le head_sha (qui change à chaque nouveau commit fonctionnel) — un ticket
# déjà ouvert reçoit un commentaire de mise à jour au lieu d'être dupliqué.
#
# Ne pose jamais de tag, ne modifie jamais Config/module.xml/CHANGELOG.md —
# ce script ne fait que du reporting, l'arbitrage reste humain/agent (CTO).
#
# ── Usage (depuis un run agent uniquement) ─────────────────────────────
#
#   ./scripts/notify-version-drift.sh
#
#   Variables :
#     LOG_DIR              défaut: /home/enurit/backups/thelia3-bundles
#     PAPERCLIP_API_URL/PAPERCLIP_API_KEY/PAPERCLIP_COMPANY_ID : requis,
#                           fournis automatiquement par le run agent
#     NOTIFY_PROJECT_ID     optionnel: projet Paperclip associé au ticket
#                           créé (par défaut, aucun)
#     NOTIFY_CTO_AGENT_ID   défaut: 5a31c811-dcd2-4337-9706-599cb5dc7e53 (CTO)
#
set -uo pipefail

LOG_DIR="${LOG_DIR:-/home/enurit/backups/thelia3-bundles}"
ALERT_FILE="${LOG_DIR}/.check-version-drift.alert"
CTO_AGENT_ID="${NOTIFY_CTO_AGENT_ID:-5a31c811-dcd2-4337-9706-599cb5dc7e53}"
PROJECT_ID="${NOTIFY_PROJECT_ID:-}"

TICKET_TITLE="Dérive de version persistante — module CommerceAgents (auto)"
SEARCH_MARKER="WARN-VERSION-DRIFT-PERSISTANT"

if [[ ! -f "$ALERT_FILE" ]]; then
  echo "NOTIFY-VERSION-DRIFT: pas de marqueur d'alerte (${ALERT_FILE}) — rien à faire."
  exit 0
fi

if [[ -z "${PAPERCLIP_API_URL:-}" || -z "${PAPERCLIP_API_KEY:-}" || -z "${PAPERCLIP_COMPANY_ID:-}" ]]; then
  echo "NOTIFY-VERSION-DRIFT: PAPERCLIP_API_URL/PAPERCLIP_API_KEY/PAPERCLIP_COMPANY_ID absents de l'environnement — ce script doit tourner dans un run agent (routine), jamais en cron nu." >&2
  exit 1
fi

API_BASE="${PAPERCLIP_API_URL%/}"
API_BASE="${API_BASE%/api}"

read -r latest_tag head_sha functional_commits_ahead first_functional_iso <<<"$(python3 - "$ALERT_FILE" <<'PY'
import json
import sys
from datetime import datetime, timezone

with open(sys.argv[1]) as f:
    data = json.load(f)

first_functional_iso = datetime.fromtimestamp(
    int(data["first_functional_at"]), tz=timezone.utc
).strftime("%Y-%m-%dT%H:%M:%SZ")

print(data["latest_tag"], data["head_sha"], data["functional_commits_ahead"], first_functional_iso)
PY
)"

body="$(python3 - "$SEARCH_MARKER" "$latest_tag" "$head_sha" "$functional_commits_ahead" "$first_functional_iso" <<'PY'
import sys

marker, latest_tag, head_sha, functional_commits_ahead, first_functional_iso = sys.argv[1:6]
print(f"""{marker}

- Dernier tag : `{latest_tag}`
- HEAD actuel : `{head_sha}`
- Commits fonctionnels cumulés au-dessus du tag : {functional_commits_ahead}
- Dérive fonctionnelle détectée depuis : {first_functional_iso}

Filet automatique `check-version-drift.sh` (MYO-452/453/455), déclenché par
la routine Paperclip de notification. Contexte complet du mécanisme :
[MYO-454](/MYO/issues/MYO-454).

Arbitrage demandé : une release (Config/module.xml + CHANGELOG.md + tag
`vX.Y.Z`) est-elle due sur le dépôt module CommerceAgents ?""")
PY
)"

search_query="$(python3 -c 'import urllib.parse, sys; print(urllib.parse.quote(sys.argv[1]))' "$SEARCH_MARKER")"
search_url="${API_BASE}/api/companies/${PAPERCLIP_COMPANY_ID}/issues?q=${search_query}&status=todo,in_progress,in_review,blocked"

if ! search_response="$(curl -sS --fail --retry 2 --retry-delay 1 -H "Authorization: Bearer ${PAPERCLIP_API_KEY}" "$search_url")" || [[ -z "$search_response" ]]; then
  echo "NOTIFY-VERSION-DRIFT: échec de la recherche d'idempotence (GET issues?q=${SEARCH_MARKER}) — abandon SANS créer de ticket pour ne pas risquer un doublon. Réessayer au prochain déclenchement de la routine." >&2
  exit 1
fi

# NB : `python3 -` lit le SCRIPT lui-même depuis stdin (le heredoc) — on ne
# peut donc pas aussi y faire transiter le JSON via un pipe/sys.stdin (stdin
# serait déjà consommé par le heredoc). On passe la réponse par un fichier
# temporaire à la place.
search_response_file="$(mktemp)"
trap 'rm -f "$search_response_file"' EXIT
printf '%s' "$search_response" >"$search_response_file"

existing_issue_id="$(python3 - "$SEARCH_MARKER" "$search_response_file" <<'PY'
import json
import sys

marker, path = sys.argv[1], sys.argv[2]
with open(path) as f:
    data = json.load(f)
items = data if isinstance(data, list) else data.get("issues", data.get("data", []))
for it in items:
    haystack = (it.get("title") or "") + " " + (it.get("description") or "")
    if marker in haystack:
        print(it["id"])
        break
PY
)"

if [[ -n "$existing_issue_id" ]]; then
  comment_payload="$(python3 -c 'import json, sys; print(json.dumps({"body": sys.argv[1]}))' "$body")"
  response="$(curl -sS -X POST \
    -H "Authorization: Bearer ${PAPERCLIP_API_KEY}" \
    -H "Content-Type: application/json" \
    -d "$comment_payload" \
    "${API_BASE}/api/issues/${existing_issue_id}/comments")"
  echo "NOTIFY-VERSION-DRIFT: ticket existant mis à jour (id=${existing_issue_id})."
  echo "$response"
else
  create_payload="$(python3 -c '
import json, sys
title, description, assignee, project_id = sys.argv[1:5]
payload = {
    "title": title,
    "description": description,
    "assigneeAgentId": assignee,
    "priority": "medium",
    "status": "todo",
}
if project_id:
    payload["projectId"] = project_id
print(json.dumps(payload))
' "$TICKET_TITLE" "$body" "$CTO_AGENT_ID" "$PROJECT_ID")"
  response="$(curl -sS -X POST \
    -H "Authorization: Bearer ${PAPERCLIP_API_KEY}" \
    -H "Content-Type: application/json" \
    -d "$create_payload" \
    "${API_BASE}/api/companies/${PAPERCLIP_COMPANY_ID}/issues")"
  echo "NOTIFY-VERSION-DRIFT: nouveau ticket créé."
  echo "$response"
fi
