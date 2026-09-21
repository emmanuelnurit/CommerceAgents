# MYO-482 — Centre de notifications agents (topbar BO) — spec UX

Cadré par [MYO-481](/MYO/issues/MYO-481) (CEO). Périmètre : BO uniquement, thème
`default-twig`. Ce document couvre AC1, AC2, AC4, AC5, AC6 de
[MYO-482](/MYO/issues/MYO-482). AC3 (maquette markup) est livrée séparément dans
`myo-482-notifications-topbar.html`, à côté de ce fichier.

Je ne fais pas l'implémentation serveur (CTO, issue sœur) : cette spec décrit le
rendu, les états, l'accessibilité et les clés i18n à câbler, pas l'endpoint JSON ni
la migration `read_at`.

## 0. Point d'ancrage (rappel, ne pas rouvrir)

Le hook existe déjà et n'est pas modifié : `_top_nav.html.twig:31`
`{{ safe_hook('main.topbar-top') }}`, rendu à l'intérieur de
`<ul class="navbar-nav ms-auto align-items-md-center">`, **avant** le sélecteur de
langue. Le module s'y branche via un nouveau listener `main.topbar-top` dans
`AdminHookManager.php` (aujourd'hui ce module n'écoute que `main.in-top-menu-items`,
`main.footer-js`, `main.before-content` — vérifié dans le fichier actuel).
Conséquence pour la maquette : l'icône notifications apparaît **en premier** dans le
groupe d'actions de droite, avant le sélecteur de langue.

## 1. Anatomie

### 1.1 Icône + pastille (déclencheur)

- Un `<button>` réel (pas un `<a href="#">`) : le déclencheur ouvre un panneau
  local, ce n'est pas une navigation — cohérent avec Norman (affordance d'un bouton
  ≠ affordance d'un lien).
- Classes : `nav-link position-relative`, dans un `<li class="nav-item dropdown">`,
  même famille que les items `bo-top-nav-view-site` / `bo-top-nav-profile` voisins
  (Jakob's Law : même composant que ce que l'admin connaît déjà de cette barre).
- Icône `bi-bell` (`fs-5`), pas de variante "pleine" — au repos elle reste visuellement
  neutre, c'est la pastille qui porte le signal.
- Pastille : pattern Bootstrap standard `badge rounded-pill position-absolute
  top-0 start-100 translate-middle` — je réutilise le pattern documenté par
  Bootstrap plutôt que d'inventer un positionnement custom (Jakob's Law, Occam's
  Razor).
- **Couleur de la pastille = échelle de sévérité, pas une couleur fixe** (Von
  Restorff : le rouge ne doit rester rare et fiable que pour ce qui bloque
  réellement une action — sinon c'est de la fatigue d'alerte) :
  1. ≥ 1 item **à valider** → `bg-danger` (rouge, action bloquante réelle) ;
  2. sinon ≥ 1 **message** → `bg-primary` (couleur de marque, signal agent actif
     mais non bloquant) ;
  3. sinon ≥ 1 item **Brief** seul → `bg-secondary` (neutre, incitation douce) ;
  4. 0 item → **pas de pastille du tout**, pas de "0" affiché.
- Débordement : `9+` au-delà de 9, `99+` au-delà de 99. Le total exact reste
  toujours disponible dans le `aria-label` du bouton (voir §4 — jamais tronqué
  pour un lecteur d'écran, seulement à l'écran).

### 1.2 Panneau déroulant

- `dropdown-menu dropdown-menu-end shadow-sm p-0`, classe custom
  `.bo-agent-notif-panel` : `min-width: 340px; max-width: 400px` (mobile : pleine
  largeur, comme les autres dropdowns de cette barre).
- **En-tête** (sticky) : titre "Notifications agents" + à droite, un bouton texte
  "Tout marquer comme lu" — visible seulement si au moins un item est non lu
  (Occam's Razor : pas d'action affichée quand elle n'a aucun effet).
- **Corps** : liste défilante (`max-height: 420px; overflow-y: auto`), groupée par
  type dans un ordre fixe (voir §2). Chaque groupe : étiquette majuscule + puce de
  comptage + séparateur (Gestalt — proximité et région commune pour que les 3
  familles ne se lisent jamais comme une liste plate).
- **Pied** : lien "Voir tout dans Le Brief" (`commerceagents_changes`), affiché
  seulement si un groupe dépasse la limite d'affichage (5 items/groupe dans le
  panneau — au-delà, "+N autres" renvoie vers Le Brief plutôt que de faire défiler
  indéfiniment un menu, cf. Miller's Law).

### 1.3 Item de notification — 3 variantes

Toute la ligne est un seul `<a>` cliquable (Fitts's Law : zone de clic large, pas
juste l'icône ou le titre).

| Variante | Icône | Pastille de fond | Contenu | Destination |
|---|---|---|---|---|
| **À valider** (`agent_staged_change`, `approved_at IS NULL AND applied_at IS NULL`) | `bi-clipboard-check` | `bg-danger-subtle text-danger` | libellé du `target_type` (ex. "Prix produit") + extrait du changement proposé | `commerceagents_changes#change-{id}` (Le Brief, item ciblé) |
| **Message** (`agent_outbound_message`) | `bi-envelope` (canal email) ou `bi-chat-left-text` (autre canal) | `bg-primary-subtle text-primary` | agent + canal + `body_excerpt` | détail de l'exécution — route à câbler par le CTO |
| **Item du Brief** (décision non liée à un staged change) | `bi-lightbulb` | `bg-secondary-subtle text-secondary-emphasis` | intitulé de la décision | `commerceagents_changes` (Le Brief, groupe correspondant) |

`bg-*-subtle` / `text-*` sont des paires Bootstrap 5.3 natives, déjà calibrées par
Bootstrap pour rester lisibles en clair **et** en sombre (voir §4) — je réutilise
ces tokens plutôt que d'inventer une nouvelle palette (Reach for what exists
first).

## 2. Hiérarchie visuelle entre les 3 types

Ordre d'affichage fixe, du plus urgent au plus informatif : **À valider → Messages
→ Brief**.

Justification : un changement à valider porte un risque métier réel s'il reste
sans réponse (prix, stock…) ; un message est informatif mais déjà émis par
l'agent ; un item du Brief est déjà surfacé ailleurs (écran Le Brief lui-même),
donc ce n'est qu'un rappel de second niveau ici. Seul le groupe "À valider" porte
l'accent rouge — les deux autres partagent des tons plus froids pour que le rouge
reste un signal fiable (Serial Position : la position de tête va au groupe le
plus critique ; color-independence : la forme de l'icône diffère aussi entre
groupes, pas seulement la teinte, pour rester lisible en daltonisme).

## 3. Tous les états (AC2)

L'état "zéro" est celui vu 90 % du temps — traité avec le même soin que les
autres, pas une liste vide brute (Aesthetic-Usability Effect).

| État | Pastille | Contenu du panneau |
|---|---|---|
| **Zéro notification** | absente | icône `bi-check2-circle` + "Rien en attente. Tout est à jour." — ton positif, pas une absence de contenu |
| **1 item** | nombre "1", couleur du type de l'item | 1 groupe, 1 ligne |
| **Plusieurs items, types mélangés** | total, couleur = sévérité max (voir §1.1) | groupes dans l'ordre du §2, chacun avec sa puce de comptage |
| **Débordement > 9** | `9+` | inchangé, liste normale (le débordement ne concerne que l'affichage du nombre, pas la troncature de la liste tant qu'on reste sous la limite de 5/groupe) |
| **Débordement > 99** | `99+` | idem |
| **Chargement** (1er fetch ou rafraîchissement en cours) | **la pastille garde le dernier total connu**, pas de saut à vide puis retour (évite un faux "tout est traité" perçu pendant 1-2s, Doherty Threshold) | 3 lignes squelette (`placeholder-glow`) à la place des groupes |
| **Erreur de récupération** | dernier total connu, avec un petit point orange superposé signalant "donnée non fraîche" plutôt que de remettre à zéro (Loss Aversion : ne jamais afficher "aucune notification" quand on ne sait juste pas) | ligne unique : "Impossible de charger les notifications." + bouton "Réessayer" ; le polling continue en tâche de fond, ce bouton force un essai immédiat |
| **Panneau vidé après acquittement** | disparaît (repasse à l'état zéro) | même contenu que l'état zéro, mais copie légèrement différente : "Tout est traité — bravo." (clôture positive, Peak-End) |

Le polling (30–60 s, décision déjà tranchée en MYO-481) ne doit **jamais** faire
clignoter le panneau s'il est ouvert et que le total n'a pas changé — seul un total
different déclenche un re-rendu de la liste et l'annonce `aria-live` (§4).

## 4. Accessibilité — vérifiée (AC4)

### Rôles et structure

- Bouton : `aria-haspopup="dialog"` (pas `"menu"` — la liste mélange des liens de
  navigation hétérogènes et une action de groupe "Tout marquer comme lu", ce n'est
  pas un menu d'application à sémantique flèches-only ; Postel's Law — ne pas
  déclarer une sémantique ARIA plus forte que ce qui est réellement implémenté),
  `aria-expanded="false"/"true"`, `aria-controls="bo-agent-notif-panel"`.
- Panneau : `role="dialog" aria-modal="false" aria-labelledby="bo-agent-notif-title"`,
  `id="bo-agent-notif-panel"`.
- Bootstrap 5.3 dropdown natif gère déjà Echap → fermeture + retour du focus sur le
  bouton déclencheur, et Tab qui sort du menu le referme — je réutilise ce
  comportement plutôt que d'écrire un piège à focus custom (Jakob's Law : même
  comportement que le dropdown de langue juste à côté).
- À l'ouverture, le focus va sur le premier élément focusable du panneau
  ("Tout marquer comme lu" s'il est présent, sinon le premier item).

### Nommage lisible (recognition over recall)

- `aria-label` du bouton toujours parlé en toutes lettres, jamais juste le
  nombre :
  - 0 item → `"Notifications agents : aucune notification"` ;
  - 1 item à valider → `"Notifications agents : 1 action en attente"` ;
  - N items mélangés → `"Notifications agents : N actions en attente"` (le mot
    "actions" reste le terme le plus fort présent, cohérent avec la couleur de
    pastille du §1.1).
- Une région `aria-live="polite" aria-atomic="true"` **visually-hidden**, séparée
  de la pastille (jamais directement dedans), annonce uniquement un changement de
  total entre deux cycles de polling : `"3 nouvelles notifications agents"`. Piège
  à éviter explicitement pour le CTO : ne pas réémettre le même texte à chaque
  tick de polling quand le total n'a pas bougé — ça noierait tout le reste de la
  page sous des annonces vocales répétées.
- Focus visible : réutiliser l'anneau de focus existant du thème (ombre
  `--bs-primary` définie par `$input-focus-box-shadow` dans
  `_variables.scss`), pas de nouveau style de focus à inventer.

### Contraste — calculé, pas affirmé

Ratios calculés (formule WCAG, luminance relative sRGB) sur les paires réellement
utilisées ici :

- Pastille `bg-danger` (`#dc3545`) + texte blanc : **≈ 4,53:1** — passe AA texte
  normal (seuil 4,5:1).
- Pastille `bg-secondary`, mappé ici sur `$secondary: #4f4f4f` (token réel du
  thème) + texte blanc : **≈ 8,2:1** — passe AAA.
- Les paires `bg-*-subtle` / `text-*` (icônes de ligne, §1.3) sont des tokens
  Bootstrap 5.3 natifs, déjà calibrés par Bootstrap pour rester conformes AA en
  thème clair **et** sombre — je ne les recalcule pas, je les réutilise tels quels
  (Reach for what exists first).

Sur le thème sombre : **`base.html.twig:2` fige aujourd'hui
`data-bs-theme="light"`** — le BO n'expose actuellement aucun mode sombre, donc
aucune régression n'est possible en production tout de suite. L'AC4 demandant
malgré tout une vérification en sombre, la maquette (`myo-482-notifications-topbar.html`)
inclut un bascule de test qui applique `data-bs-theme="dark"` avec les variables
Bootstrap natives (`--bs-body-bg`, `--bs-border-color`, `--bs-emphasis-color`
s'auto-inversent) sans redéfinir aucune couleur de marque : les pastilles
`bg-danger`/`bg-secondary` ne changent pas entre les deux thèmes, donc les ratios
ci-dessus restent valides dans les deux cas. À revérifier avec un rendu réel en
DDEV si un mode sombre est un jour activé pour de vrai sur `/admin`.

### Mouvement

Pas d'animation décorative (pas de pastille "qui pulse") au-delà de la transition
d'ouverture native du dropdown Bootstrap — cohérent avec la règle déjà actée dans
`main.scss:1272-1280` et reprise par la maquette MYO-469 : toute transition
ajoutée doit être neutralisée sous `prefers-reduced-motion: reduce`.

## 5. Clés i18n — liste exacte (AC5)

Formulations FR/EN prêtes à injecter dans les 21 catalogues
`translations/messages.<locale>.php` (même convention pluriel que l'existant :
paires de clés séparées, pas de syntaxe ICU — cf. `'%count% product'` /
`'%count% products'` déjà présents dans `messages.fr_FR.php`).

| Clé (EN, source) | FR |
|---|---|
| `Agent notifications` | `Notifications agents` |
| `Notifications` *(libellé mobile court, à côté de l'icône dans le menu replié)* | `Notifications` |
| `Agent notifications: no notification` | `Notifications agents : aucune notification` |
| `Agent notifications: %count% pending action` | `Notifications agents : %count% action en attente` |
| `Agent notifications: %count% pending actions` | `Notifications agents : %count% actions en attente` |
| `%count% new agent notification` | `%count% nouvelle notification agent` |
| `%count% new agent notifications` | `%count% nouvelles notifications agents` |
| `Mark all as read` | `Tout marquer comme lu` |
| `Pending validation` | `À valider` |
| `Agent messages` | `Messages` |
| `From the Brief` | `Du Brief` |
| `Nothing pending. You're all caught up.` | `Rien en attente. Tout est à jour.` |
| `All caught up — nice work.` | `Tout est traité — bravo.` |
| `Loading notifications…` | `Chargement des notifications…` |
| `Notifications could not be loaded.` | `Impossible de charger les notifications.` |
| `Retry` | `Réessayer` |
| `See everything in the Brief` | `Voir tout dans Le Brief` |

Note : `%count% more` existe déjà (`messages.fr_FR.php:1527` → `%count% de plus`) —
à réutiliser tel quel pour le "+N autres" du pied de panneau, pas de doublon à
créer.

Piège rappelé par [MYO-444](/MYO/issues/MYO-444) : le libellé de repli en cas
d'échec de traduction (`Notifications could not be loaded.` lui-même) doit être
traduit dans les 21 locales au même titre que les autres — ce n'est pas un texte
"système" exempté.

## 6. Règle d'acquittement (AC6)

**Décision : la pastille ne s'éteint que sur action explicite — clic sur un item
(qui marque *cet item* lu puis navigue), ou clic sur "Tout marquer comme lu" (qui
marque tout lu d'un coup, avec un toast annulable ~5 s). L'ouverture seule du
panneau ne marque rien comme lu — elle change seulement l'apparence des items
déjà vus (graisse réduite) sans les retirer du compte.**

Justification (une phrase) : ouvrir le panneau, c'est jeter un œil, pas prendre
une décision — si la pastille s'éteignait à l'ouverture, un admin interrompu juste
après aurait perdu le seul signal qui lui restait qu'une action est encore en
attente (Zeigarnik : la boucle ne se referme que par une action réelle, pas par un
simple coup d'œil).

Conséquence pour le CTO : ceci confirme le trou identifié dans le schéma
(`agent_outbound_message` et `agent_staged_change` n'ont ni `read_at` ni
`acknowledged_at`) — la migration (`Config/update/` + régénération Propel) n'est
pas un détail d'implémentation, elle est requise par cette règle UX elle-même :
sans état de lecture persisté *par admin*, la pastille ne peut techniquement pas
respecter la règle ci-dessus.

## 7. Récapitulatif pour le CTO

- Hook à écouter : `main.topbar-top` (nouveau listener dans
  `AdminHookManager.php`, ne pas toucher `_top_nav.html.twig`).
- Endpoint JSON de comptage (polling 30–60 s, déjà tranché en MYO-481) : la forme
  exacte de la réponse est à sa main, mais elle doit au minimum porter, pour
  rendre tous les états du §3 : le total, la ventilation par type (validation /
  message / brief), et pour chaque item listé un identifiant stable + un état
  lu/non-lu par admin (cf. §6, nécessite la migration `read_at`/`acknowledged_at`).
- Destinations : `commerceagents_changes` (existe déjà, route confirmée dans
  `StagedChangesController.php:84`) pour "À valider" et "Brief" ; la route de
  détail d'exécution pour "Message" reste à confirmer côté CTO (non trouvée telle
  quelle dans le routing actuel).
- Markup de référence : `myo-482-notifications-topbar.html`, à côté de ce fichier
  — Bootstrap 5.3 + Bootstrap Icons, classes et tokens réels du thème, utilisable
  tel quel comme point de départ du template Twig du hook.
