# Journal des modifications

Notes de version internes du module `CommerceAgents`, rédigées en français pour permettre de comprendre le périmètre livré sans ouvrir Paperclip. Le `README.md` (en anglais, destiné à la publication du module) décrit le fonctionnement détaillé ; ce document trace *ce qui a été livré et quand*.

## V1 — assistants IA pour la boutique (MYO-226 → MYO-257)

Version de module au moment de la clôture V1 : **0.3.3** (voir `Config/module.xml` et `Config/update/*.sql` pour l'historique des migrations de schéma). Le module est passé à **0.3.8** depuis — voir le chapitre « Post-V1 » ci-dessous.

La V1 couvre trois surfaces : l'assistant shopping (front), l'assistant marchand (back-office + MCP), et les agents paramétrables (automatisations hors chat). Le détail fonctionnel de chaque point est dans le `README.md`.

### Fournisseur par défaut et catalogue de modèles — MYO-228

- Bascule du fournisseur par défaut d'Anthropic vers **Mistral, modèle `ministral-3b-latest`** (tier « fast »).
- Catalogue de modèles à trois fournisseurs (Mistral, Anthropic, compatible OpenAI) avec tarifs par million de tokens, **en EUR pour Mistral, en USD pour les autres** — conversion interne systématique vers l'USD pour la comptabilité de coûts (`Service/ModelCatalog::toUsd()`).
- Correctif : le seed du catalogue réactive une ligne fournisseur déjà adoptée par le catalogue embarqué au lieu de la dupliquer.

### Agents paramétrables — socle, écran et assistants prêts à l'emploi — MYO-226, MYO-227, MYO-229, MYO-232

- Nouveau schéma Propel : `agent_definition`, `agent_capability`, `agent_trigger`, `agent_run` (MYO-229).
- Runtime « par capacités » : un agent ne peut appeler que les outils couverts par ses capacités cochées (lecture catalogue/contenu/clients/commandes/analytics, écriture prix/stock/panier/checkout, envoi sur un canal), avec budget mensuel propre à l'agent et commande de purge `commerce-agents:run-due`.
- Écran back-office **Agents IA** (`/admin/module/CommerceAgents/agents`) : liste en cartes, assistant de création en trois étapes (rôle → déclencheurs → canaux), édition, activation/désactivation, suppression, exécution manuelle immédiate (« Exécuter maintenant »).
- Quatre modèles d'agent prêts à l'emploi (« presets ») pré-remplissent le formulaire : relance panier abandonné, accueil nouveau client, résumé de ventes quotidien, ou formulaire vierge.
- Écran de configuration du module refondu autour d'une carte « héros » Mistral (clé API, modèle par défaut, test de connexion) et d'un panneau « Réglages généraux » accessible par une roue crantée, avec les onglets Fournisseurs / Modèles / Assistants / Budget / Usage.

### Déclencheurs et exécution différée — MYO-230

- Quatre types de déclencheur (`agent_trigger.type`) : `event` (commande payée, changement de statut, nouveau client), `cron` (planification libre), `abandoned_cart`, `low_stock`.
- Aucun déclencheur n'exécute un agent en ligne dans une requête HTTP : il insère un `agent_run` en file, dédupliqué par `dedup_key`. La commande `commerce-agents:run-due` vide la file.
- Filet de sécurité « pseudo-cron » : si aucun vrai cron n'a tourné depuis 10 minutes, le trafic back-office déclenche un passage de la file (au plus une fois toutes les 5 minutes) — pour ne pas bloquer les tests sur un environnement sans accès crontab, jamais un substitut à un vrai cron en production.

### Canaux de sortie (mail, webhook) — connecteurs V1

- Outil `send_to_channel` exposé au modèle (capacité `channels.send`), le canal effectif étant choisi par la configuration de l'agent (`agent_channel`), jamais par le modèle.
- Deux connecteurs livrés : `mail` (mailer Thelia) et `webhook` (POST générique compatible Mattermost/Slack).
- Mode `draft` (défaut) : le message part en proposition (`agent_staged_change`, `target_type = channel_message`) à approuver ; mode `direct` : envoi immédiat.
- Réglages de canal (URL de webhook, jetons…) chiffrés en base avec sodium `secretbox` avant écriture (`ChannelSettingsEncryptor`).
- **Limite connue** : l'étape « Canaux » de l'assistant de création d'agent n'enregistre que l'intention (email/webhook) ; le formulaire de réglages détaillé par connecteur est prévu pour MYO-231, pas encore livré.

### Assistant proactif — MYO-236, MYO-245, MYO-246, MYO-247, MYO-248, MYO-249

- Infrastructure de garde-fous (MYO-246) : `ProactiveGuard` (session déjà rejetée → délai minimal 90 s depuis la dernière bulle → pas de répétition du même scénario → budget mensuel non dépassé), état persisté sur `agent_conversation`.
- Instrumentation JS côté widget (MYO-248) pour détecter l'hésitation (ouvertures répétées du panier, inactivité sur une fiche produit) et le panier laissé inactif.
- Sept scénarios livrés au total : hésitation, panier abandonné, cross-sell promotionnel, rupture de stock, coupon éligible, palier de coupon (barre de progression vers la remise suivante), coupon de bienvenue (MYO-247, MYO-249).
- Nouvelles routes front : `POST /agent/chat/proactive-check`, `/proactive-apply-coupon`, `/proactive-dismiss`.
- Spécifications UX (MYO-245) livrées en amont : bulle proactive, carte produit, carte coupon, animation — un seul jeu de composants couvrant les sept scénarios.

### Console des changements proposés et bulle « Agents IA » du dashboard — MYO-237, MYO-243

- Nouvelle route `GET /admin/merchant-agent/changes/agent/{agentDefinitionId}/suggestions` : contrat de données JSON pré-formaté (icône, texte, CTA) consommé par la carte « Agents IA » et sa popup de suggestions sur l'accueil back-office (thème `default-twig`, hors périmètre de ce module).
- `agent_staged_change.agent_definition_id` devient une clé étrangère directe (évite une jointure ambiguë via `agent_run`).
- Variante AJAX/JSON des routes d'approbation/rejet, avec distinction HTTP 409 (`already_handled`, déjà traité) vs 422 (erreur réelle), pour une popup non bloquante.
- Maquette HTML de référence commitée sous `docs/design/myo-237-agent-suggestions-popup.html`.

### Prérequis de déploiement en production documentés — MYO-296

- Nouvelle section « Production deployment prerequisites » dans le `README.md`, atteignable depuis l'installation : les trois constats d'infra écartés du code par l'audit sécurité (MYO-275/276) — **M1** rate-limit par IP sur `/agent/chat*`, **B1** taille max du corps de requête, **B6** entropie et rotation de `kernel.secret` — avec pour chacun le risque, une valeur recommandée et un exemple de configuration copiable (nginx pour M1/B1, commande + procédure pour B6).
- Avertissement visible en tête de la section installation : ne pas exposer `/agent/chat*` sans rate-limit en amont.
- Le lien B6 → chiffrement (`ChannelSettingsEncryptor`, clés API LLM du correctif H4 comprises) est écrit explicitement, avec la conséquence concrète d'une rotation (les secrets déjà chiffrés deviennent illisibles, re-saisie manuelle requise, pas de re-chiffrement automatique en V1).
- Correctif au passage : la note de branche `myorg`/`main` et l'entrée « connu / non livré » ci-dessous ne mentionnent plus MYO-241 comme bloqué — la branche est publiée sur GitHub depuis le 2026-09-15.

### Hors périmètre de ce module (pour mémoire)

Les tickets suivants livrent la carte et la popup elles-mêmes côté **thème back-office `default-twig`** (pas ce module) : MYO-250 (provider `AgentDashboardCard`), MYO-251 (bloc Twig/SCSS), MYO-252 (popup de suggestions sur la carte), MYO-256/257 (corrections visuelles et i18n de ce bloc). Voir la documentation du thème pour ce périmètre.

### Connu / non livré en V1

- Formulaire de réglages détaillé par connecteur de canal (MYO-231). **Livré en post-V1** sous une forme centralisée (panneau « Canaux », MYO-300) plutôt que par agent — voir le chapitre Post-V1.
- Choix du fournisseur pour les agents paramétrables (Mistral uniquement pour l'instant). Toujours vrai en 0.3.8.
- Fusion de la branche `myorg` sur `origin/main` : `myorg` est publiée sur GitHub depuis MYO-241 (2026-09-15) mais `main` reste un cran derrière tant que la fusion n'a pas de ticket dédié. Toujours vrai en 0.3.8.
- Pas d'écran listant l'historique des exécutions (`agent_run`) au-delà du badge « Dernière exécution » sur la carte de l'agent. **Livré en post-V1** (MYO-319/MYO-321) — voir le chapitre Post-V1.

## Post-V1 (MYO-297 → MYO-348)

Cinq migrations de schéma (`Config/update/0.3.4.sql` → `0.3.8.sql`) portent le module de **0.3.3** à **0.3.8**. Ce chapitre couvre tout ce qui a été livré depuis la clôture de la V1 ci-dessus.

### Panneau de canaux centralisé et connecteurs dédiés Mattermost/Slack — MYO-300, MYO-332

- Remplace le connecteur `webhook` générique unique par trois connecteurs dédiés et autonomes : `Channel/Connector/MailChannelConnector.php`, `MattermostChannelConnector.php`, `SlackChannelConnector.php` (plus `AbstractWebhookChannelConnector.php` pour la base commune Mattermost/Slack).
- Nouvel onglet « Canaux » de la configuration du module (`Controller/Admin/ChannelsConfigController.php`, routes `commerceagents_channels_test` / `commerceagents_channels_save`) : réglages saisis une fois pour toute la boutique par connecteur (adresse d'expédition mail, URL de webhook Mattermost/Slack…), testables sans enregistrer, persistés chiffrés (`ChannelConnectorConfigService`, toujours sodium `secretbox`).
- L'étape « Canaux » de l'assistant de création d'agent (`_step-channels.html.twig`) ne fait plus que cocher les connecteurs à utiliser pour cet agent ; elle renvoie vers ce panneau centralisé pour le réglage détaillé (lien « Configure channel connectors »).
- Correctif MYO-332 : `store_email` vide ou absent devient un `ChannelException` explicite (au lieu d'un « From » silencieusement absent laissé au transport), à la fois dans `send()` et dans `test()` — le connecteur mail refuse d'envoyer sans expéditeur configuré.

### Presets de catalogue d'agents par spécialité — MYO-301, MYO-326

- Le créateur d'agent propose désormais **six** presets (`Service/AgentPresets.php`), deux de plus que les quatre de la V1 : `STOCK_WATCH_RESTOCK` (surveillance de stock avec proposition de réassort) et `CUSTOMER_REVIEWS_REPLY` (réponse aux avis clients).
- Le preset `CUSTOMER_REVIEWS_REPLY` a été reformulé (4 locales) pour ne plus promettre une publication automatique de la réponse — l'agent reste au stade de proposition à valider, jamais de publication directe (MYO-348).

### Écran d'historique des exécutions — MYO-319, MYO-321, MYO-325

- Nouvel écran dédié à l'historique des `agent_run` (`Controller/Admin/AgentRunsController.php`, routes `commerceagents_agents_runs` liste et `commerceagents_agents_runs_show` détail), qui n'existait pas en V1 : le seul repère était alors le badge « Dernière exécution » sur la carte de l'agent.
- Nouvelle page par agent (`commerceagents_agents_show`) avec un aperçu compact des dernières exécutions et un lien « Ouvrir l'historique » vers l'écran complet.

### Panes par spécialité et traçabilité des envois — MYO-327, MYO-328, MYO-334 → MYO-338

- Nouveau socle `Service/SpecialtyPane/` (`SpecialtyResultsPaneRegistry`, `SpecialtyResultsPaneInterface`) : chaque preset a désormais son rendu de résultats dédié sur la page de l'agent — `DailySalesSummaryResultsPane`, `CartAbandonedResultsPane`, `WelcomeNewCustomerResultsPane`, `StockWatchRestockResultsPane`, `CustomerReviewsReplyResultsPane`, et `GenericResultsPane` en repli pour un agent « from scratch » ou dont le preset ne peut pas être résolu sans ambiguïté.
- Nouvelle table `agent_outbound_message` (`Config/schema.xml`, `Model/AgentOutboundMessageQuery.php`) : trace chaque message sortant (canal, destinataire, statut, référence métier, extrait du corps, erreur) rattaché à son `agent_run`/`agent_definition`, base des panes « Propositions à valider » (stock/avis, MYO-336/MYO-338) et « Messages envoyés » (MYO-340).
- Nouvel outil `send_email_to_customer` (`Tool/Admin/SendCustomerEmailTool.php`) : premier chemin d'envoi d'e-mail *au client* (jusqu'ici les agents ne pouvaient écrire que vers les canaux internes mail/Mattermost/Slack côté marchand) — couvre notamment la relance de réponse à avis client (MYO-337/MYO-340).

### Garde-fous des runs automatiques et de l'exécution des agents — MYO-330, MYO-343, MYO-345, MYO-347

- Retrait du garde-fou self-approval de `StagedChangeManager` (MYO-330/MYO-333) : une proposition est toujours créée par un agent, jamais par l'administrateur qui l'approuve, donc bloquer `proposedBy === adminId` n'avait plus de sens.
- Correctif d'un trou majeur : `AgentRunQueue::enqueue()` (déclencheurs `event`/`cron`/`abandoned_cart`/`low_stock`) ne renseigne jamais `admin_id` — seule l'exécution manuelle (« Exécuter maintenant ») le fait. Les quatre `stageXxx()` de `TheliaStagingGateway` (MYO-343) et `TheliaChannelGateway::stageMessage()` (MYO-345) exigeaient `admin_id` en plus de l'identifiant de conversation et refusaient donc silencieusement toute proposition issue d'un run automatique : les panes stock/avis (MYO-336) et le renvoi de rapport restaient vides en usage réel hors clic manuel. `admin_id` reste désormais nullable sur la proposition (distinct de `approved_by`, toujours renseigné par l'admin qui approuve).
- Garde-fou système explicite (`Service/SystemPromptFactory`, MYO-347) : sur refus (capacité manquante) ou échec d'un appel outil, l'agent ne doit jamais deviner la valeur que l'outil aurait renvoyée ni la réutiliser dans un autre appel outil d'écriture — il doit s'arrêter sur cette partie de la mission et signaler précisément quel outil a été refusé et pourquoi.
- Correctif du mappage des déclencheurs du wizard (MYO-344) : `parseTriggers()` écrivait un `type`/`event_name` faux pour cinq des six déclencheurs proposés ; `TriggerCatalogMapping` devient la source unique de vérité entre le formulaire et `agent_trigger`.

### Autres correctifs de fiabilité — MYO-297, MYO-346

- Écran « Agents IA » : le picker de modèles restait vide tant que son `init()` JS n'était pas appelé explicitement (MYO-297).
- Garde-fou anti-désync entre le JS source et le JS publié des étapes du wizard (MYO-346) : l'asset publié pouvait rester obsolète (ancien libellé « canal e-mail/webhook ») après une modification du JS source ; test de garde comparant un hash source/publié.

## Post-V1, suite (MYO-372 → MYO-407)

Le module passe de **0.3.8** à **0.4.0** (pas 0.3.9) : ce lot ajoute `bin/console commerce-agents:rotate-secrets`, une capacité opérationnelle nouvelle exposée aux administrateurs système (rotation de `kernel.secret` sans réécriture manuelle des secrets chiffrés en base), et pas seulement des correctifs — même si le lot en contient aussi (MYO-372, 373, 378, 379, 382, 384, 406, 407). **Aucun changement de schéma** dans ce lot : `Config/update/` s'arrête toujours à `0.3.8.sql`, il n'y a pas de `0.4.0.sql` (voir « Migrations » ci-dessous).

### Garde-fou auto-push étendu aux tags — MYO-372

- `scripts/auto-push-myorg.sh` et `scripts/check-unpushed-myorg.sh` couvrent désormais aussi les tags git, pas seulement les commits sur `myorg` : un tag posé localement (`git tag -a`) sans être poussé sur `origin` est maintenant détecté par le même garde-fou qui protège déjà les commits.

### Fiabilité des runs d'agent — MYO-373, MYO-378, MYO-379, MYO-382

- **MYO-373** — L'outil `report` halluciné par certains modèles (jamais exposé par `ToolRegistry`) provoquait un échec silencieux : `AgentRunner`/`AgentRunsController` distinguent maintenant ce cas et le signalent explicitement au lieu de le laisser disparaître dans les logs.
- **MYO-378** — Les migrations `Config/update/*.sql` sont rendues idempotentes (`CREATE TABLE IF NOT EXISTS`, `ADD COLUMN`/`ADD INDEX IF NOT EXISTS`, `ADD CONSTRAINT` gardé par une procédure `information_schema`), car `CommerceAgents::update()` ne peut pas se fier à `$currentVersion` (fourni par `Thelia\Module\ModuleManagement`) pour savoir quels fichiers ont réellement tourné — voir [MYO-408](/MYO/issues/MYO-408) et la section « Migrations » ci-dessous pour un cas concret. Un test de non-régression dédié (`Tests/Config/UpdateSqlIdempotencyTest.php`, découverte des fichiers par `glob()`) vérifie ces trois garanties sur chaque fichier de `Config/update/`, y compris les futurs.
- **MYO-379** — `ReviewsTabHook` autowirait en dur le modèle `Comment` du module core `Comment` ; sur une installation où ce module n'est pas actif (ou son code absent, cf. [MYO-403](/MYO/issues/MYO-403)), le conteneur d'injection de dépendances refusait de se compiler. Le service est désormais résolu en `nullOnInvalid()`, dégradé proprement (onglet Avis masqué) plutôt que de bloquer tout le DI du module.
- **MYO-382** — `AgentRuntime::runTurn()` ne recouvrait que `ToolException` pendant l'exécution d'un tool ; toute autre exception (`\Throwable` générique) traversait le générateur et faisait échouer le run entier au lieu d'être recouvrée en `tool_result` comme les `ToolException` le sont déjà depuis MYO-373. Recouvrement généralisé, avec stacktrace complète journalisée (nouveau logger injecté dans `AgentRuntime`) puisqu'il ne s'agit pas d'un échec typé attendu.

### Vendor du module désynchronisé — MYO-384

- `vendor/thelia/modules/CommerceAgents` était une seconde copie git indépendante (branche `main`, plusieurs commits en retard sur `local/modules/CommerceAgents`), jamais resynchronisée. `ModuleManagement::updateModules()` scanne les deux répertoires : le `module.xml` vendor périmé pouvait donc réécrire `module.version` avec une valeur obsolète juste après un `module:refresh` pourtant réussi côté local.
- Corrigé en remplaçant `vendor/thelia/modules/CommerceAgents` par un symlink vers `local/modules/CommerceAgents` — une seule copie du code, de `module.xml` et de `Config/update/*.sql` sur le disque, donc plus aucun désaccord possible entre les deux scans. Vérifié en réel dans un projet DDEV isolé : `module:refresh` depuis `module.version = 0.3.1` converge vers `0.3.8`, et deux `module:refresh` consécutifs supplémentaires laissent `module.version` stable à `0.3.8` (voir `README.md` pour la procédure si ce répertoire redevient un jour une copie physique).

### Rotation de `kernel.secret` et audit d'entropie — MYO-406, MYO-407

- Nouvelle commande `bin/console commerce-agents:rotate-secrets --old-secret=<...> --new-secret=<...>` (`--dry-run` disponible) : re-chiffre en base, ligne par ligne, chaque clé API fournisseur et chaque réglage de connecteur de canal (`ChannelSettingsEncryptor`, sodium `secretbox`), une ligne qui ne se déchiffre pas avec l'ancien secret étant journalisée et laissée intacte plutôt que silencieusement perdue. Documente la procédure complète de rotation B6 dans le `README.md`, y compris l'ordre des étapes (rotation en base *avant* d'écraser `APP_SECRET`, sous peine de compiler le kernel contre un secret que la commande ne peut alors plus utiliser pour déchiffrer l'ancien).
- README mis à jour pour marquer **M1** (rate-limit IP sur `/agent/chat*`) et **B1** (plafond de taille de corps de requête) comme en place et prouvés (MYO-406), et **B6** comme prouvé par exécution réelle (MYO-407) : rotation bout-en-bout vérifiée sur worktree + base isolées (jamais la base partagée), et audit d'entropie de l'`APP_SECRET` de cet environnement de dev documenté (jugé non acceptable pour la production, rotation appliquée à titre d'exemple).

### Migrations

Aucun fichier `Config/update/0.4.0.sql` n'est ajouté par ce lot : le diff des 9 commits contre `Config/schema.xml` (`git diff --stat v0.3.8..HEAD -- Config/schema.xml`) ne montre aucun changement de schéma — seuls des fichiers de test et une nouvelle commande console sont ajoutés, et les `Config/update/0.x.sql` existants sont réécrits pour l'idempotence (MYO-378) sans changer leur effet. `Tests/Config/UpdateSqlIdempotencyTest.php` découvre les fichiers par `glob()`, donc un futur `0.4.0.sql` (ou toute version suivante) serait automatiquement couvert par ses trois garanties sans modification du test.
