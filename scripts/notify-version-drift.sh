#!/usr/bin/env bash
#
# MYO-455 (AC4) / MYO-463 (AC3) — notification Paperclip pour les DEUX
# filets version-drift de ce dépôt.
#
# Les scripts cron (check-version-drift.sh, check-module-db-drift.sh)
# tournent en cron nu, SANS PAPERCLIP_API_KEY ni PAPERCLIP_API_URL (ces
# variables ne sont injectées que dans un run agent, jamais via crontab).
# Ils ne peuvent donc jamais parler à l'API Paperclip eux-mêmes : ils se
# contentent de poser un marqueur JSON quand leur seuil d'escalade est
# franchi :
#
#   - .check-version-drift.state's ALERT_FILE  (check-version-drift.sh,
#     MYO-452/453/455)   -> dérive *code* : commits fonctionnels non suivis
#     d'un tag/Config/module.xml à jour (retard release).
#   - .check-module-db-drift.alert (check-module-db-drift.sh, MYO-463)
#     -> dérive *instance* : Config/module.xml et module.version en base
#     divergent (module:refresh jamais rejoué après une mise à jour de
#     code).
#
# Ce script-ci est le second maillon COMMUN aux deux : il est destiné à
# être exécuté UNIQUEMENT depuis un run agent (typiquement une routine
# Paperclip "BackendEngineer — notification version-drift", trigger
# schedule quotidien) où PAPERCLIP_API_KEY est disponible. Il lit chaque
# marqueur présent et crée/met à jour, pour CHACUN indépendamment, un
# ticket Paperclip idempotent assigné au CTO pour arbitrage. Un seul canal
# de notification (cette route API), deux tickets distincts si les deux
# dérives sont actives en même temps — ce sont deux diagnostics différents
# qui appellent des actions différentes (tag+release vs module:refresh).
#
# Idempotence (identique aux deux types) : la recherche se fait sur un
# marqueur texte fixe dans le corps du ticket, pas sur des valeurs qui
# changent à chaque passage (head_sha / db_version) — un ticket déjà ouvert
# reçoit un commentaire de mise à jour au lieu d'être dupliqué :
#   - WARN-VERSION-DRIFT-PERSISTANT       (dérive code, check-version-drift.sh)
#   - WARN-MODULE-DB-VERSION-PERSISTANT   (dérive instance, check-module-db-drift.sh)
#
# Ne pose jamais de tag, ne modifie jamais Config/module.xml/CHANGELOG.md,
# ne lance jamais module:refresh — ce script ne fait que du reporting,
# l'arbitrage reste humain/agent (CTO).
#
# ── Usage (depuis un run agent uniquement) ─────────────────────────────
#
#   ./scripts/notify-version-drift.sh
#
#   Variables :
#     LOG_DIR                     défaut: /home/enurit/backups/thelia3-bundles
#     PAPERCLIP_API_URL/PAPERCLIP_API_KEY/PAPERCLIP_COMPANY_ID : requis,
#                                  fournis automatiquement par le run agent
#     NOTIFY_PROJECT_ID           optionnel: projet Paperclip associé aux
#                                  tickets créés (par défaut, aucun)
#     NOTIFY_CTO_AGENT_ID         défaut: 5a31c811-dcd2-4337-9706-599cb5dc7e53 (CTO)
#
set -uo pipefail

LOG_DIR="${LOG_DIR:-/home/enurit/backups/thelia3-bundles}"
CTO_AGENT_ID="${NOTIFY_CTO_AGENT_ID:-5a31c811-dcd2-4337-9706-599cb5dc7e53}"
PROJECT_ID="${NOTIFY_PROJECT_ID:-}"

if [[ -z "${PAPERCLIP_API_URL:-}" || -z "${PAPERCLIP_API_KEY:-}" || -z "${PAPERCLIP_COMPANY_ID:-}" ]]; then
  echo "NOTIFY-VERSION-DRIFT: PAPERCLIP_API_URL/PAPERCLIP_API_KEY/PAPERCLIP_COMPANY_ID absents de l'environnement — ce script doit tourner dans un run agent (routine), jamais en cron nu." >&2
  exit 1
fi

API_BASE="${PAPERCLIP_API_URL%/}"
API_BASE="${API_BASE%/api}"

overall_rc=0

# Recherche + création/mise à jour idempotente d'un ticket Paperclip pour un
# marqueur donné. Commun aux deux types de dérive ; seuls title/marker/body
# changent entre les deux appels dans main() ci-dessous.
notify_one() {
  local ticket_title="$1" search_marker="$2" body="$3"

  local search_query search_url search_response
  search_query="$(python3 -c 'import urllib.parse, sys; print(urllib.parse.quote(sys.argv[1]))' "$search_marker")"
  search_url="${API_BASE}/api/companies/${PAPERCLIP_COMPANY_ID}/issues?q=${search_query}&status=todo,in_progress,in_review,blocked"

  if ! search_response="$(curl -sS --fail --retry 2 --retry-delay 1 -H "Authorization: Bearer ${PAPERCLIP_API_KEY}" "$search_url")" || [[ -z "$search_response" ]]; then
    echo "NOTIFY-VERSION-DRIFT: échec de la recherche d'idempotence (GET issues?q=${search_marker}) — abandon SANS créer de ticket pour ne pas risquer un doublon. Réessayer au prochain déclenchement de la routine." >&2
    return 1
  fi

  # NB : `python3 -` lit le SCRIPT lui-même depuis stdin (le heredoc) — on ne
  # peut donc pas aussi y faire transiter le JSON via un pipe/sys.stdin
  # (stdin serait déjà consommé par le heredoc). On passe la réponse par un
  # fichier temporaire à la place.
  local search_response_file
  search_response_file="$(mktemp)"
  printf '%s' "$search_response" >"$search_response_file"

  local existing_issue_id
  existing_issue_id="$(python3 - "$search_marker" "$search_response_file" <<'PY'
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
  rm -f "$search_response_file"

  if [[ -n "$existing_issue_id" ]]; then
    local comment_payload response
    comment_payload="$(python3 -c 'import json, sys; print(json.dumps({"body": sys.argv[1]}))' "$body")"
    response="$(curl -sS -X POST \
      -H "Authorization: Bearer ${PAPERCLIP_API_KEY}" \
      -H "Content-Type: application/json" \
      -d "$comment_payload" \
      "${API_BASE}/api/issues/${existing_issue_id}/comments")"
    echo "NOTIFY-VERSION-DRIFT: ticket existant mis à jour (id=${existing_issue_id}, marker=${search_marker})."
    echo "$response"
  else
    local create_payload response
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
' "$ticket_title" "$body" "$CTO_AGENT_ID" "$PROJECT_ID")"
    response="$(curl -sS -X POST \
      -H "Authorization: Bearer ${PAPERCLIP_API_KEY}" \
      -H "Content-Type: application/json" \
      -d "$create_payload" \
      "${API_BASE}/api/companies/${PAPERCLIP_COMPANY_ID}/issues")"
    echo "NOTIFY-VERSION-DRIFT: nouveau ticket créé (marker=${search_marker})."
    echo "$response"
  fi
}

# ── 1/2 — dérive *code* (check-version-drift.sh, MYO-452/453/455) ────────
CODE_ALERT_FILE="${LOG_DIR}/.check-version-drift.alert"
if [[ -f "$CODE_ALERT_FILE" ]]; then
  CODE_SEARCH_MARKER="WARN-VERSION-DRIFT-PERSISTANT"
  read -r latest_tag head_sha functional_commits_ahead first_functional_iso <<<"$(python3 - "$CODE_ALERT_FILE" <<'PY'
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

  code_body="$(python3 - "$CODE_SEARCH_MARKER" "$latest_tag" "$head_sha" "$functional_commits_ahead" "$first_functional_iso" <<'PY'
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

  notify_one "Dérive de version persistante — module CommerceAgents (auto)" "$CODE_SEARCH_MARKER" "$code_body" || overall_rc=1
else
  echo "NOTIFY-VERSION-DRIFT: pas de marqueur d'alerte code (${CODE_ALERT_FILE}) — rien à faire pour la dérive code."
fi

# ── 2/2 — dérive *instance* (check-module-db-drift.sh, MYO-463) ──────────
DB_ALERT_FILE="${LOG_DIR}/.check-module-db-drift.alert"
if [[ -f "$DB_ALERT_FILE" ]]; then
  DB_SEARCH_MARKER="WARN-MODULE-DB-VERSION-PERSISTANT"
  read -r module_code xml_version db_version detected_iso <<<"$(python3 - "$DB_ALERT_FILE" <<'PY'
import json
import sys
from datetime import datetime, timezone

with open(sys.argv[1]) as f:
    data = json.load(f)

detected_iso = datetime.fromtimestamp(
    int(data["detected_at"]), tz=timezone.utc
).strftime("%Y-%m-%dT%H:%M:%SZ")

print(data["module_code"], data["xml_version"], data["db_version"], detected_iso)
PY
  )"

  db_body="$(python3 - "$DB_SEARCH_MARKER" "$module_code" "$xml_version" "$db_version" "$detected_iso" <<'PY'
import sys

marker, module_code, xml_version, db_version, detected_iso = sys.argv[1:6]
print(f"""{marker}

- Module : `{module_code}`
- Config/module.xml (code) : `{xml_version}`
- module.version (base, instance) : `{db_version}`
- Dérive détectée depuis : {detected_iso}

Filet automatique `check-module-db-drift.sh` (MYO-463), déclenché par la
routine Paperclip de notification. C'est le 2e maillon de la chaîne de
version — complémentaire à `check-version-drift.sh` (dérive *code*
tag/Config/module.xml, notifiée séparément) : ici la dérive est entre le
code du module et l'INSTANCE installée dans cette base. Contexte complet :
[MYO-461](/MYO/issues/MYO-461).

Arbitrage demandé : un `php Thelia module:refresh` est-il dû sur cette
instance pour que module.version reflète Config/module.xml ?""")
PY
  )"

  notify_one "Dérive module.version en base — module CommerceAgents (auto)" "$DB_SEARCH_MARKER" "$db_body" || overall_rc=1
else
  echo "NOTIFY-VERSION-DRIFT: pas de marqueur d'alerte instance (${DB_ALERT_FILE}) — rien à faire pour la dérive module.version en base."
fi

exit "$overall_rc"
