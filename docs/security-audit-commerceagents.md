# Audit de sécurité — module CommerceAgents

**Ticket** : MYO-276 (parent MYO-275 « Commerce Agent security »)
**Date** : 2026-09-15
**Périmètre** : `local/modules/CommerceAgents` (260 fichiers PHP) — conformité Thelia 3 (ACL, CSRF, Propel, templates) et failles applicatives classiques + spécifiques LLM/agents.
**Méthode** : lecture exhaustive des zones ciblées par le pré-scan (A–G), traçage du flux de données réel pour chaque piste (pas de constat sans chemin d'exploitation vérifié dans le code), classification Critique/Haute/Moyenne/Basse/Info.

## Synthèse

| Sévérité | Nombre | Statut |
|---|---|---|
| Critique | 0 | — |
| Haute | 6 | **Toutes corrigées** (4 commits séparés par famille) |
| Moyenne | 6 | Listées ci-dessous, non corrigées dans ce ticket |
| Basse | 6 | Listées ci-dessous, non corrigées dans ce ticket |
| Info | 8 | Bonnes pratiques vérifiées / non-findings documentés |

Aucun constat Critique confirmé : aucune RCE, aucun contournement d'authentification, aucune fuite de masse de données tierces n'a été mise en évidence avec un chemin d'exploitation vérifié. Les deux axes explicitement demandés en périmètre G (injection SQL/Propel) et une bonne part de F (XSS) se sont révélés propres — voir le détail en fin de document.

---

## Constats Haute (corrigés dans ce ticket)

### H1 — Aucune limite réelle de messages sur `/agent/chat` (DoS financier)

- **Fichiers** : `Controller/Front/ChatController.php`, `Service/BudgetGuard.php`, `Service/AgentConfigService.php`
- **Constat** : les 4 routes front sont publiques et non authentifiées. Le seul garde-fou avant l'appel LLM était `BudgetGuard::status()->isBlocked()`, un contrôle *a posteriori* global à la boutique (pas par visiteur), inactif tant que l'admin n'a pas explicitement configuré un budget mensuel bloquant (`monthly_budget_usd` vaut `0` par défaut). Le réglage BO « Daily message limit » existait et se sauvegardait, mais **n'était lu nulle part** dans `ChatController` — un contrôle fantôme.
- **Exploitation** : un visiteur anonyme, en boucle sur `POST /agent/chat`, consomme des tokens réels facturés au fournisseur LLM (Anthropic/Mistral/OpenAI-compatible) sans aucune limite technique tant que le budget n'est pas configuré, et même configuré, seulement en fin de mois.
- **Correctif** (commit `6112d3e`) : `ConversationService::countUserMessagesToday()` compte les messages `role=user` du jour pour la conversation ; `ChatController::chat()` renvoie `429 Too Many Requests` au-delà de `AgentConfigService::getDailyMessageLimit()`. Le réglage BO existant est désormais réellement appliqué.
- **Limite du correctif** : la limite est par conversation (donc par session). Un attaquant qui régénère sa session à chaque appel contourne le plafond — un throttle IP/infra reste une amélioration Moyenne recommandée (voir M1).

### H2 — Absence de séparation demandeur/approbateur (maker-checker) sur les changements différés

- **Fichiers** : `StagedChange/StagedChangeManager.php`, `Service/Merchant/TheliaStagedChangeRepository.php`, `StagedChange/StagedChangeData.php`
- **Constat** : `StagedChangeManager::approve()` ne vérifiait que le statut `pending` de la proposition, jamais que l'admin qui approuve diffère de celui qui a fait proposer le changement par l'agent (`AgentStagedChange::adminId`, jamais comparé).
- **Exploitation** : un admin avec le seul droit `commerceagents:UPDATE` fait proposer par l'agent conversationnel une baisse de prix/hausse de stock (`update_price`/`update_stock`), puis approuve lui-même sa propre proposition sur `/admin/merchant-agent/changes/{id}/approve`. Le contrôle humain « à deux mains » était illusoire.
- **Correctif** (commit `284f03e`) : `StagedChangeData` porte désormais `proposedBy` (alimenté depuis `AgentStagedChange::adminId`) ; `StagedChangeManager::approve()` refuse (`error`) toute approbation où `proposedBy === adminId`.

### H3 — L'approbation ne revérifie que le droit module `commerceagents`, jamais le droit catalogue réel

- **Fichiers** : `Controller/Admin/StagedChangesController.php`
- **Constat** : `handleDecision()` appelait `access->check([], 'commerceagents', AccessManager::UPDATE)` — uniquement le droit sur le module, jamais `AdminResources::PRODUCT` alors que l'approbation écrit directement le prix/stock d'un `ProductSaleElement` (même effet que l'écran Catalogue natif, protégé par `catalog:UPDATE`).
- **Exploitation** : combiné à H2, un compte BO avec `commerceagents:UPDATE` mais **sans aucun droit Catalogue** pouvait proposer puis approuver lui-même une modification de prix/stock — escalade de privilèges complète en contournant le modèle ACL natif de Thelia pour ces ressources.
- **Correctif** (commit `284f03e`) : `StagedChangesController` récupère le `targetType` de la proposition avant décision et revérifie `AccessManager::UPDATE` sur `AdminResources::PRODUCT` pour `pse_price`/`pse_stock`, en plus du droit module.

### H4 — Clés API des fournisseurs LLM stockées en clair

- **Fichiers** : `Service/AgentConfigService.php`, `Service/Channel/ChannelSettingsEncryptor.php`
- **Constat** : `ChannelSettingsEncryptor` (sodium secretbox, clé dérivée de `kernel.secret`) ne protégeait que `agent_channel.settings` (URL webhook). Les clés `api_key_<provider>` passaient par `CommerceAgents::setConfigValue()`/`getConfigValue()` (`module_config` core Thelia), **en clair**.
- **Exploitation** : tout accès en lecture à la base (dump de sauvegarde, accès SQL via une autre faille, hébergeur mutualisé) expose directement une clé donnant accès à un compte Mistral/Anthropic/OpenAI facturé — sans avoir besoin de casser quoi que ce soit.
- **Correctif** (commit `ebdacf5`) : `ChannelSettingsEncryptor` gagne des primitives `encryptString`/`decryptString` (mêmes garanties, sans enveloppe JSON) ; `AgentConfigService::getApiKey()`/`setProviderSettings()` chiffrent à l'écriture et déchiffrent à la lecture. Une clé en clair d'avant ce correctif reste lisible (repli silencieux si le déchiffrement échoue) et se migre vers la forme chiffrée au prochain enregistrement BO — pas de rupture pour les installations existantes.

### H5 — SSRF via `base_url` LLM et `WebhookChannelConnector` non validés

- **Fichiers** : `Controller/Admin/ConfigSaveController.php`, `Agent/Llm/*Client.php`, `Agent/Llm/ModelDiscovery.php`, `Channel/Connector/WebhookChannelConnector.php`
- **Constat** : `base_url_<provider>` n'était protégé que par un `trim()` avant sauvegarde, puis dialé par les clients LLM et `ModelDiscovery` avec la clé API en en-tête (`Authorization`/`x-api-key`) — et la réponse (ou le message d'erreur JSON) était relayée telle quelle au BO via *test-connection* / *refresh models*. `WebhookChannelConnector::send()` ne validait l'URL que par `filter_var(FILTER_VALIDATE_URL)`, sans allowlist de schéma ni blocage des plages privées, et suivait les redirections par défaut.
- **Exploitation** : un admin (ou une session admin détournée) positionne `base_url_mistral = https://169.254.169.254` ou une adresse interne ; le serveur émet la requête **avec la clé API en en-tête** et **la réponse est lisible dans le BO** — SSRF non aveugle avec exfiltration de secret vers un service interne.
- **Correctif** (commit `182111e`) : nouveau `Service\Security\OutboundUrlValidator` (https uniquement, IP résolue hors plages privées/loopback/link-local/réservées, y compris `169.254.169.254`). Branché sur `ConfigSaveController::save()` (rejet silencieux d'un `base_url` invalide, repli sur l'endpoint officiel du fournisseur — déjà le comportement à vide) et sur `WebhookChannelConnector::send()` (exception si l'URL n'est pas autorisée, redirections désactivées `max_redirects: 0`).
- **Limite du correctif** : la résolution DNS a lieu à la validation, pas à chaque requête — un DNS rebinding (IP publique au moment du contrôle, privée quelques secondes plus tard) n'est pas couvert. Fermer complètement ce cas demanderait un client HTTP qui refuse les IP privées par connexion (ex. `NoPrivateNetworkHttpClient` de Symfony) — recommandé en durcissement complémentaire (voir M2).

### H6 — Fuite de conversation (PII) entre identités par réutilisation de session

- **Fichiers** : `Service/ConversationService.php`
- **Constat** : `getOrCreate()` retrouvait une conversation existante uniquement par `type` + `sessionRef`, jamais par `customerId`/`adminId`. Or Thelia ne régénère pas l'identifiant de session au login/logout (vérifié : aucun `migrate()`/`regenerate()` dans `core/lib/Thelia/Action/Customer.php`).
- **Exploitation** : sur un poste partagé, ou par fixation de session, un visiteur B connecté sur le même `sessionRef` qu'un visiteur A précédent hérite de la conversation de A — y compris l'historique des appels `get_orders`/`get_my_profile` (nom, email, adresse, commandes), transmis tel quel dans le contexte envoyé au LLM pour répondre à B.
- **Correctif** (commit `6112d3e`) : `getOrCreate()` ne réutilise une conversation que si `customerId` **et** `adminId` correspondent exactement à l'identité courante ; sinon une nouvelle conversation est créée (visiteur anonyme → client connecté, changement de client, changement d'admin).

---

## Constats Moyenne (non corrigés dans ce ticket)

| # | Constat | Fichier(s) |
|---|---|---|
| M1 | Le plafond quotidien de messages (H1) est par conversation/session, pas par IP — un attaquant qui régénère sa session à chaque appel le contourne. Un throttle infra (Symfony RateLimiter par IP, ou proxy) reste recommandé en complément. | `Controller/Front/ChatController.php` |
| M2 | La validation SSRF (H5) résout le DNS à l'écriture de la config / à l'appel, pas par connexion — un DNS rebinding n'est pas couvert. Recommandé : un client HTTP qui revalide l'IP à chaque connexion (`NoPrivateNetworkHttpClient`). | `Service/Security/OutboundUrlValidator.php` |
| M3 | Pas de token CSRF applicatif sur les 4 routes front JSON (`ChatController`). Mitigé aujourd'hui par `cookie_samesite: lax` + CORS restreint (config d'infra, hors garantie du module) ; recommandé d'ajouter un contrôle explicite (header dédié) pour ne pas dépendre uniquement d'un réglage externe. | `Controller/Front/ChatController.php` |
| M4 | `MerchantChatController::chat()` (chat BO) ne valide aucun jeton CSRF, contrairement à tous les autres contrôleurs admin du module. Un CSRF classique peut faire consommer le budget LLM/polluer l'historique au nom d'un admin connecté. | `Controller/Admin/MerchantChatController.php` |
| M5 | Application « à l'aveugle » de la valeur proposée lors de l'approbation d'un changement différé (`PsePriceApplier`/`PseStockApplier`) : aucune comparaison entre `payloadBefore` et l'état réel en base au moment de l'approbation (TOCTOU), ni expiration des propositions `pending`. Une proposition ancienne peut écraser un prix/stock modifié entretemps par un autre canal. | `Service/Merchant/PsePriceApplier.php`, `PseStockApplier.php` |
| M6 | Aucune séparation explicite instructions/données dans les 3 prompts système (`SystemPromptFactory`) : le contenu des tool results (descriptions produit, etc.) n'est pas délimité comme non fiable. Impact borné aujourd'hui par le contrôle serveur des capacités (voir Info), mais c'est une défense en profondeur manquante contre l'injection de prompt. | `Service/SystemPromptFactory.php` |

## Constats Basse

| # | Constat | Fichier(s) |
|---|---|---|
| B1 | Pas de limite de taille/profondeur sur `context` (payload `proactive-check`) ni sur `code` (payload `proactive-apply-coupon`) ; seule la taille du corps brut est bornée par la config serveur (PHP/nginx), pas par le module. | `Controller/Front/ChatController.php` |
| B2 | Aucun timeout explicite sur les appels aux clients LLM (`MistralClient`, `AnthropicClient`, `OpenAiCompatibleClient`) ni sur `ModelDiscovery::fetch()`, contrairement à `WebhookChannelConnector` (10 s). Combiné à un `base_url` interne qui accepte la connexion sans répondre, un worker peut rester bloqué. | `Agent/Llm/*Client.php`, `Agent/Llm/ModelDiscovery.php` |
| B3 | `Command/McpServeCommand.php --admin=<login>` résout l'admin **sans vérifier de mot de passe/token** — quiconque peut exécuter la commande console obtient le contexte complet de l'admin ciblé. Suppose déjà un accès shell (frontière de confiance existante), mais facilite une usurpation entre admins pour un compte système à accès shell partiel. | `Command/McpServeCommand.php` |
| B4 | Champ BO « Apply this agent's proposed changes automatically, without review » (`autoApply`) persisté mais **jamais lu** par `StagedChangeManager`/`AgentRunner` — fonctionnalité UI trompeuse, aucun impact aujourd'hui, mais risque latent si elle est un jour « terminée » sans reprendre les mêmes garde-fous que `StagedChangesController`. | `Service/AgentDefinitionManager.php`, `_step-channels.html.twig` |
| B5 | `templates/backOffice/default-twig/merchant-chat/mcp.html.twig:33` utilise `|raw` sur `protocolVersions|join(...)`. Non exploitable en l'état (la valeur vient de la constante PHP `ServerInfo::SUPPORTED_PROTOCOL_VERSIONS`, jamais d'un serveur MCP tiers ni d'une entrée utilisateur), mais à retirer par prudence défensive. | `templates/backOffice/default-twig/merchant-chat/mcp.html.twig` |
| B6 | La confidentialité des secrets chiffrés (`ChannelSettingsEncryptor`, et désormais les clés LLM) dépend entièrement de l'entropie de `kernel.secret` (pas de KDF lent). Une valeur par défaut/faible en production permettrait une attaque hors-ligne sur un dump. Documentation/rotation recommandée plutôt que correctif de code. | `Service/Channel/ChannelSettingsEncryptor.php` |

## Constats Info (bonnes pratiques vérifiées / non-findings documentés)

- **Coupons** (`proactive-apply-coupon`) : le client n'envoie qu'un `code` ; l'éligibilité et le montant sont entièrement recalculés côté serveur via `TheliaEvents::COUPON_CONSUME` (même chemin que le formulaire panier natif). Aucune faille de logique métier.
- **Isolation `conversationId`** : jamais accepté en entrée depuis le front, toujours dérivé côté serveur. Pas de chemin IDOR direct (distinct du problème de fond H6, qui porte sur le `sessionRef` lui-même).
- **Contrôle des capacités d'outils** : `ToolRegistry::execute()` revérifie `isAllowed()` juste avant l'exécution, à partir d'un `ToolContext` construit côté serveur (jamais influençable par le texte du LLM). C'est le vrai gate contre une éventuelle injection de prompt, pas un simple filtrage de la liste envoyée au prompt.
- **Isolation front/admin des outils mutatifs** : `UpdatePriceTool`/`UpdateStockTool` exigent `isAdmin && adminId !== null` ; un visiteur front ne peut jamais les déclencher, halluciné ou non.
- **Flux staging** : `update_price`/`update_stock` n'écrivent jamais directement en base — toujours une ligne `AgentStagedChange` en attente, appliquée seulement via `StagedChangesController` (désormais durci par H2/H3).
- **Serveur MCP** : transport stdio uniquement (pas de listener réseau), même `ToolRegistry`/capacités que le chat HTTP (en réalité plus restreint via `HIDDEN_TOOLS`).
- **XSS rendu agent** : le rendu markdown des réponses LLM (front et BO) construit le DOM exclusivement via `createElement`/`textContent`, jamais `innerHTML` ; `x-text` (jamais `x-html`) côté Alpine.js. Aucun autre `|raw` que B5 dans tout `templates/`.
- **Injection SQL/Propel** : aucune requête SQL brute, aucun `Criteria::CUSTOM`/`RAW`, aucune concaténation dans un `where()` trouvée dans `Service/`, `Agent/`, `Mcp/`, `Tool/`, `Controller/`. Les recherches texte libre (ex. `filterByTitle('%'.$search.'%', Criteria::LIKE)`) passent bien par un paramètre lié, pas par une chaîne SQL concaténée.

---

## Correctifs appliqués (commits séparés par famille, branche `myorg`)

| Commit | Famille |
|---|---|
| `284f03e` | H2 + H3 — durcissement du circuit d'approbation des changements différés (maker-checker + ACL catalogue) |
| `6112d3e` | H1 + H6 — isolation des conversations par identité + limite de messages/jour |
| `ebdacf5` | H4 — chiffrement des clés API LLM au repos |
| `182111e` | H5 — validation anti-SSRF des URL sortantes configurables (base_url LLM, webhook) |

## Gates exécutées

- **PHPUnit** (`local/modules/CommerceAgents/Tests`, via `phpunit.xml.dist` racine, DB de test réelle) : **395/395 verts**, y compris les nouveaux tests couvrant chaque correctif Haute (`StagedChangeManagerTest`, `ConversationServiceTest`, `ChannelSettingsEncryptorTest`, `OutboundUrlValidatorTest`, `WebhookChannelConnectorTest`).
- **PHPStan** (niveau 6, `local/modules/CommerceAgents`) : 342 constats pré-existants (essentiellement `missingType.iterableValue`, dette générale du module, hors périmètre de cet audit) — **zéro nouveau constat introduit par les fichiers modifiés/créés** pour cet audit (vérifié fichier par fichier).
- **PHP-CS-Fixer** : les seuls écarts détectés sur les fichiers touchés (en-tête de licence Thelia manquant sur les fichiers `Tests/`) sont une convention déjà absente de tout le dossier `Tests/` du module avant cet audit — non spécifiques à ce changement, non corrigés pour rester cohérent avec l'existant.

## Non corrigé dans ce ticket

Les constats Moyenne (M1–M6) et Basse (B1–B6) sont documentés ci-dessus mais non corrigés ici, conformément au périmètre du ticket (« corriger Critique/Haute, lister le reste »). Le volume ne justifie pas de sous-issues systématiques ; les plus actionnables à reprendre en premier si une suite est priorisée : M3/M4 (CSRF front + chat marchand) et M5 (TOCTOU staged changes), qui touchent des surfaces déjà modifiées par ce ticket.
