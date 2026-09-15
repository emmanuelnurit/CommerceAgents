# Journal des modifications

Notes de version internes du module `CommerceAgents`, rédigées en français pour permettre de comprendre le périmètre livré sans ouvrir Paperclip. Le `README.md` (en anglais, destiné à la publication du module) décrit le fonctionnement détaillé ; ce document trace *ce qui a été livré et quand*.

## V1 — assistants IA pour la boutique (MYO-226 → MYO-257)

Version de module actuelle : **0.3.3** (voir `Config/module.xml` et `Config/update/*.sql` pour l'historique des migrations de schéma).

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

### Hors périmètre de ce module (pour mémoire)

Les tickets suivants livrent la carte et la popup elles-mêmes côté **thème back-office `default-twig`** (pas ce module) : MYO-250 (provider `AgentDashboardCard`), MYO-251 (bloc Twig/SCSS), MYO-252 (popup de suggestions sur la carte), MYO-256/257 (corrections visuelles et i18n de ce bloc). Voir la documentation du thème pour ce périmètre.

### Connu / non livré en V1

- Formulaire de réglages détaillé par connecteur de canal (MYO-231).
- Choix du fournisseur pour les agents paramétrables (Mistral uniquement pour l'instant).
- Publication de la branche `myorg` sur `origin/main` (MYO-241, bloqué sur le secret `github_token`, propriétaire : board).
- Pas d'écran listant l'historique des exécutions (`agent_run`) au-delà du badge « Dernière exécution » sur la carte de l'agent.
