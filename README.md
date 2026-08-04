# Food

Assistant de gestion domestique. L'application répond à trois questions :

1. **Qu'est-ce que j'ai réellement chez moi ?**
2. **Qu'est-ce que je dois consommer prochainement ?**
3. **Que puis-je cuisiner avec ces aliments, pour un nombre donné de personnes ?**

```text
Application mobile  ──HTTPS──▶  API REST Symfony  ──▶  PostgreSQL managé
React Native + Expo             hébergement permanent        │
      TypeScript                (clés d'API côté serveur)    ├──▶ Open Food Facts
                                                             └──▶ Gemini ou Claude
```

L'application mobile n'appelle jamais Open Food Facts ni le générateur de recettes
directement : tout passe par l'API Symfony, qui garde les clés et met les produits
en cache. L'API et la base vivent sur un hébergement permanent — **l'application ne
dépend d'aucun ordinateur allumé à la maison, et ne demande aucun VPN**.

## Le modèle : catalogue → variante → lot

La distinction structurante du projet, en trois niveaux.

| `Product` | `HouseholdProductVariant` | `StockItem` |
| --- | --- | --- |
| Le catalogue canonique | L'aliment **dans votre foyer** | Ce qui est réellement chez vous |
| « Lait demi-écrémé Lactel, 1 L » | « Lait » — clé culinaire, état, unité | « 2 bouteilles, une expire le 27/07, l'autre le 03/08 » |
| Partagé par tous les foyers, cache Open Food Facts | Appartient à un foyer, porte tout ce qui est corrigeable | Appartient à un foyer, porte la quantité restante |

Le niveau du milieu a été ajouté après coup, parce que son absence produisait trois
défauts qu'aucun réglage ne pouvait corriger :

- corriger le nom ou l'état d'un aliment modifiait le `Product` **partagé**, donc
  ce que voyaient les autres foyers ;
- des tomates fraîches et des tomates en conserve, partageant la clé `tomate`,
  étaient présentées au générateur de recettes comme **une seule quantité** portant
  le trait `canned` — et les lots exprimés dans une autre famille d'unité étaient
  **ignorés en silence** ;
- renommer un aliment ne régénérait pas sa clé culinaire, qui restait attachée à
  l'ancien nom.

Une variante est identifiée par trois choses, et il faut les trois : la **clé
d'ingrédient**, la **famille d'unité** — 3 pièces et 400 g ne s'additionnent pas —
et les **traits**. Cette identité est hachée, et la base la rend unique par foyer :
deux saisies équivalentes retombent sur la même variante, deux stocks distincts ne
peuvent plus fusionner.

Chaque variante porte un identifiant lisible — `tomate`, `tomate-conserve`,
`tomate-piece` — et c'est **lui** que le générateur de recettes recopie. Un modèle
recopie fidèlement un mot ; il abîme un identifiant de trente-six caractères.

Chaque lot reste distinct, ce qui permet la consommation **FEFO** (*First Expired,
First Out*) : retirer 1,5 L de lait vide d'abord la bouteille qui périme le plus tôt.

Aucune quantité ne change sans qu'un `StockMovement` soit écrit. On n'écrase jamais
silencieusement « 1 000 g » par « 700 g » : le journal conserve que 300 g ont été
consommés, ce qui rend les corrections et l'audit possibles.

```bash
# Les aliments qu'un foyer possède sous plusieurs variantes — exactement ce que
# l'ancien modèle fusionnait en une ligne.
php bin/console app:variants:report
```

## Un lot n'est pas un tas : le suivi des exemplaires

Trois sachets de pâtes de 500 g dont 250 g ont servi, ce n'est pas « 1,25 kg de
pâtes entamées ». C'est **un sachet ouvert à 250 g et deux encore scellés**. Un
lot porte donc deux informations de conditionnement, et tout le reste s'en déduit :

| | Rôle |
| --- | --- |
| `packageSize` | la contenance d'un exemplaire — 500 g |
| `openPackageQuantity` | ce qu'il reste dans celui qui est entamé, `null` si aucun ne l'est |

```text
reste hors exemplaire entamé = remainingQuantity − openPackageQuantity
exemplaires fermés           = ce reste ÷ packageSize
```

**Pourquoi deux colonnes plutôt qu'une table d'exemplaires.** Les exemplaires d'un
lot sont homogènes par construction : même variante, même date de péremption, même
contenance, même achat. Une table les stockerait un par un pour ne jamais rien
pouvoir en dire de plus, tout en faisant de `remainingQuantity` une valeur dérivée —
donc deux sources de vérité à tenir d'accord à chaque écriture. La seule chose que
ce modèle interdit est d'avoir deux sachets ouverts en même temps, et c'est
exactement la règle que l'application défend : on finit le paquet entamé avant d'en
ouvrir un autre.

Deux conséquences directes.

- **Prélever une quantité entame le produit.** `StockItem::withdraw()` est la seule
  porte par laquelle une quantité quitte un lot ; elle vide l'exemplaire ouvert, en
  ouvre un autre quand il est fini, et en ouvre un s'il n'y en avait aucun. Le
  défaut corrigé était là : la quantité partait, le produit restait affiché
  « fermé ».
- **Retirer un exemplaire retire le bon.** La contenance était jusqu'ici recalculée
  par `initialQuantity / numberOfPackages`, donc fausse dès la première correction
  de quantité.

## Ce qui périme d'abord, puis ce qui est ouvert

Un paquet entamé se dégrade pendant qu'un paquet scellé attend. Les recettes le
privilégient donc — mais **après** la date de péremption, jamais avant :

| Situation | Ce qui part en premier |
| --- | --- |
| Trois sachets identiques, un entamé | l'entamé |
| Un lot qui périme demain, un lot ouvert qui périme dans un mois | celui qui périme demain |

L'inverse se paierait en produits jetés, c'est-à-dire exactement ce que la priorité
aux produits entamés prétend éviter. La règle vit à deux endroits : dans le tri SQL
de `applyFefoOrder()` pour la déduction, et dans le champ `alreadyOpened` transmis
au générateur pour le choix des ingrédients.

## Plusieurs dates, et les aliments qui n'en ont aucune

Trois yaourts périmant le 27 et deux le 3 août, c'est un seul passage en caisse mais
**deux lots**. Les fondre en un seul ferait mentir la date la plus proche — donc les
alertes, et FEFO. L'écran d'ajout accepte donc une ligne par date, et l'API crée les
lots en une seule transaction : un ajout partiel laisserait un inventaire que
personne n'a saisi.

Les fruits et légumes frais, le vrac, un reste de gratin n'ont **aucune date
imprimée**. Ils entraient jusqu'ici sans date : aucune alerte, aucune priorité, et ils
pourrissaient dans le bac à légumes — précisément le gaspillage que l'application
existe pour éviter.

`ShelfLifeEstimator` en déduit une, à partir de l'aliment, de son état et de l'endroit
où il est rangé : une salade au réfrigérateur tient 4 jours, des pommes de terre au
placard 30. Deux garde-fous :

- la date est **marquée comme estimée** (`expirationIsEstimated`) et l'application ne
  l'affiche jamais comme une date lue — « à vérifier vers le 30/07 », pas « périmé
  depuis 2 jours » ;
- elle est **volontairement courte**. Se tromper court fait vérifier un aliment encore
  bon ; se tromper long fait manger un aliment qui ne l'est plus.

Le rangement prime sur l'aliment : au congélateur, un légume se garde des mois, quel
que soit le légume. `GET /products/shelf-life` prévisualise l'estimation **avec sa
justification** — une estimation qu'on ne peut pas discuter finirait par n'être crue
par personne.

## Pas de comptes : un code de foyer et des prénoms

Il n'y a ni e-mail, ni mot de passe, ni session. Le foyer est **une porte dont
chaque membre a la clé** — c'est le modèle mental de la maison, pas celui d'un
service en ligne.

- Un **foyer** porte un nom, un code d'accès, et **isole complètement le stock**.
  Deux foyers ne partagent ni inventaire, ni mouvements, ni membres — seule la
  table `Product`, qui ne contient aucune quantité, leur est commune.
- Un **membre** est un simple prénom. Il ne donne aucun droit : il sert à savoir
  qui a enregistré ou consommé un produit.

Le **code d'accès** fait 20 caractères tirés d'un alphabet sans ambiguïté visuelle
— ni `O`/`0`, ni `I`/`1`, ni `B`/`8` — soit près de 100 bits d'aléa, formatés
`K7M2-9QXR-4TVB-8NHD-3JPC` pour être dictés au téléphone. Il est stocké **haché en
SHA-256** : une fuite de la base n'ouvrirait aucun foyer, et le serveur lui-même est
incapable de le réafficher. Il n'apparaît en clair qu'à la création et à la
régénération ; c'est l'application qui le conserve, pour permettre de le repartager.

> Pourquoi SHA-256 et non bcrypt ? Un hachage lent protège un mot de passe humain,
> court et devinable. Ici le secret est tiré au hasard sur 100 bits : le deviner est
> hors d'atteinte, et un hachage rapide permet en prime de retrouver un foyer par
> son code en une lecture indexée.

L'application transmet trois en-têtes, `X-Household`, `X-Household-Key` et
`X-Member`. Deux garanties sont apportées, et elles le sont **en un seul endroit**,
`RequestContext`, couvert par `RequestContextTest` :

- **authentification** — sans le code, aucune donnée n'est lisible ni modifiable.
  L'identifiant du foyer ne suffit pas : il n'est pas un secret, il circule dans
  toutes les réponses de l'API ;
- **isolation** — un foyer ne peut jamais lire ni modifier le stock d'un autre, et
  un membre ne peut pas signer un mouvement dans un foyer auquel il n'appartient pas.

Il n'existe **aucune route qui énumère les foyers** : on rejoint un foyer par son
code, jamais en le choisissant dans une liste. La création et l'adhésion, seuls
points d'entrée ouverts sans code, sont limitées en débit par adresse IP.

Un foyer dépourvu de code — antérieur à cette version — est **refusé**, jamais
laissé passer. `php bin/console app:households:issue-keys` lui en délivre un et
l'affiche ; c'est le seul instant où il est lisible.

## Un produit n'est pas qu'un nom

Des pommes de terre en conserve sont déjà pelées et cuites. Tant que le générateur
de recettes ne recevait que le libellé, il répondait « Épluchez les pommes de terre ».

Chaque `Product` porte donc une liste de **traits** — `canned`, `frozen`, `peeled`,
`precooked`… Ils sont déduits du libellé et de la catégorie à la création
(`ProductTraitDetector`), puis corrigeables d'un geste dans l'application.

Chaque trait porte sa propre consigne, dans `App\Enum\ProductTrait` :

```
canned (En conserve) : déjà cuit, pelé et paré — ne demande ni épluchage,
                       ni parage, ni cuisson longue.
```

Le glossaire envoyé au modèle est construit depuis l'enum : ajouter un trait suffit
à l'enseigner au générateur, sans toucher au prompt.

### La forme, quatrième axe

Un trait dit ce qu'on a **fait** à l'aliment ; il ne dit pas la **forme** qu'il a. Le
générateur a écrit une recette d'escalopes avec du blanc de poulet en tranches :
les deux portaient la clé `blanc-de-poulet` et le même libellé, et c'est pourtant
la forme qui décide de la cuisson — une escalope se saisit trois minutes par face,
un blanc entier en cuit vingt.

`App\Enum\FoodCut` porte donc onze découpes — `escalope`, `strips`, `slices`,
`ground`… — chacune avec sa consigne de cuisson, et **elle fait partie de
l'identité de la variante** : « Blanc de poulet (escalopes) » et « Blanc de poulet
(émincé) » sont deux clés, deux lignes d'inventaire, deux stocks.

Trois mécanismes la renseignent, du plus automatique au plus humain :

1. **`FoodCutDetector`** la déduit du libellé — « Escalopes de poulet » arrive
   pré-cochée ;
2. **`FoodCutLexicon`** dit, par famille d'aliment, quelles découpes sont
   plausibles. L'écran d'ajout ne pose donc la question **que lorsqu'elle change
   une recette** : quatre puces pour un blanc de poulet, aucune pour une farine ;
3. **la fiche recette** : quand la découpe est inconnue, le modèle déclare celle
   qu'il a supposée (`assumedCut`), l'écran l'affiche — « Forme supposée : pavé /
   côte — corriger » — et un appui l'enregistre sur la variante, définitivement.

`php bin/console app:variants:detect-cut --dry-run` reprend le parc existant et
absorbe l'ancien trait `sliced`, qui disait qu'on avait coupé sans jamais dire en
quoi. À passer en lecture seule d'abord, et à lire ligne à ligne.

### La saison et la date du jour

Le générateur ne recevait aucune date : il lisait `useBy: 2026-08-06` sans savoir
si c'était demain. Le payload porte désormais `today`, `season` et
`seasonalProduce` (`SeasonalContext`, `SeasonalProduceCalendar`) — de quoi situer
les échéances, adapter le registre des plats, et ne jamais faire acheter une
fraise en décembre.

Un garde-fou explicite prime sur tout cela : **l'inventaire l'emporte toujours sur
la saison.** Une courge encore en stock en juillet se cuisine ; la saison oriente
le choix et les courses, jamais le gaspillage.

### Les équipements

`App\Enum\Equipment` — plaque, four, micro-ondes, mixeur, autocuiseur, air fryer,
**barbecue, plancha, robot cuiseur**. Comme les traits et les découpes, chacun
porte la consigne transmise au modèle : ce qu'il permet, ce qu'il interdit, son
temps de chauffe. Seuls les équipements réellement cochés entrent dans le prompt.

La détection reste volontairement conservatrice. `boîte` seul n'est pas une conserve
(une « boîte de 6 œufs » n'en est pas une), `sucre` seul n'est pas un produit sucré,
et la frontière de mot autour de `cuit` évite de déclarer un biscuit « déjà cuit ».
Un faux positif égarerait le générateur aussi sûrement qu'une absence d'information.

Pour compléter les produits enregistrés avant cette fonctionnalité :

```bash
php bin/console app:products:detect-traits --dry-run   # aperçu
php bin/console app:products:detect-traits             # application
```

## L'IA ne modifie jamais l'inventaire

Le générateur de recettes reçoit un inventaire **structuré** (clé stable, quantité,
unité, état du produit, urgence de péremption, fiabilité de la quantité), pas une
liste de noms. Il renvoie une `stockConsumptionProposal`.

Cette proposition est **revalidée intégralement côté serveur** avant toute écriture :
clé inexistante, unité incompatible ou quantité invalide sont rejetées. L'utilisateur
confirme ensuite explicitement dans l'application, puis seulement le stock est déduit.

### Les quantités réellement utilisées

La recette dit 250 g de crème ; on en a mis 180, ou 300. Chaque ligne de la
proposition est donc **modifiable avant la déduction**, et une ligne ramenée à zéro
n'est pas déduite du tout. Retirer systématiquement la quantité annoncée ferait
mentir le stock à chaque repas — or c'est le stock qui pilote ensuite les alertes,
les propositions suivantes, et donc tout le reste.

Le serveur n'a rien eu à changer : il validait déjà une proposition arbitraire,
plutôt que de faire confiance à celle qu'il avait lui-même renvoyée.

### Imposer un aliment

On peut désigner **un** aliment de l'inventaire que les trois recettes devront
utiliser — la botte de radis, le reste de crème. C'est facultatif : demander des
recettes sans rien imposer reste le cas courant.

Comme pour les exclusions, la consigne au modèle ne suffit pas : il en propose deux
sur trois qui l'utilisent, et la troisième arrive quand même. `RequiredIngredientFilter`
écarte donc les recettes qui ne l'emploient pas, avec leur motif, et le critère est
la **clé** de la variante — une recette qui ouvre une boîte de tomates ne vide pas
la barquette de tomates fraîches qu'on voulait écouler.

Côté écran, la sélection est **repliée** derrière une seule ligne. La première
version affichait une puce par ingrédient, comme pour le type de repas ou les
équipements — sauf que ces listes-là comptent cinq entrées courtes, et l'inventaire
d'un vrai foyer en compte 115 aux noms de fiches Open Food Facts. Chaque puce
prenait toute la largeur : il fallait **huit balayages** pour atteindre « Proposer
3 recettes ». Un réglage facultatif ne doit pas enterrer l'action principale de
l'écran qui le porte.

## Enregistrer une recette

Une proposition qui plaisait était perdue en quittant l'écran : elle ne vivait que
dans la mémoire de l'application. Elle est désormais conservée **côté serveur**,
comme le stock — tout le foyer y accède, et changer de téléphone ne l'efface pas.

La recette est stockée en JSON, ce qui n'est pas de la paresse : c'est un document
dont aucune partie n'est jamais interrogée séparément, et le normaliser en tables
coûterait cinq jointures sans autoriser une seule requête qu'on veuille écrire. Ce
que la base garantit, c'est **l'unicité** — un index sur l'empreinte du contenu, par
foyer. Appuyer deux fois sur « Enregistrer », ou revenir sur la même proposition le
lendemain, ne crée pas de doublon, y compris depuis deux téléphones au même instant.

Ce que le serveur accepte de conserver est en revanche filtré à l'entrée
(`RecipeNormalizer`) : c'est du JSON venu du client, qui sera ensuite renvoyé à tous
les membres du foyer. Les lignes de déduction inexploitables — sans clé, sans unité
connue, sans quantité — sont retirées à l'enregistrement plutôt que découvertes six
mois plus tard, au moment où l'utilisateur appuie sur « retirer du stock ».

## Les exclusions alimentaires sont vérifiées, pas demandées

Écrire « ne propose aucune recette avec des arachides » dans la consigne du modèle
n'est pas une garantie : rien ne l'oblige à la suivre, et il suffit qu'il l'ignore une
fois. Une allergie ne se demande pas poliment, elle se vérifie.

L'exclusion est donc appliquée **deux fois**, et cette redondance est délibérée :

- **en amont** — un aliment exclu ne figure pas dans l'inventaire transmis au modèle.
  Le meilleur moyen qu'une recette ne propose pas un allergène est qu'il ne lui soit
  jamais montré ;
- **en aval** — chaque recette renvoyée est relue : titre, résumé, ingrédients,
  ingrédients à acheter, substitutions, **chaque étape**, et la proposition de
  consommation. Une recette qui touche un aliment exclu est **écartée**, pas annotée.
  Afficher « attention, contient des arachides » reviendrait à déléguer la
  vérification à celui qui lit.

Un troisième contrôle a lieu **à la confirmation** : une proposition ancienne, reçue
avant qu'une allergie ne soit déclarée, ne peut pas déduire un aliment exclu.

Un allergène ne se nomme presque jamais par son nom — personne n'écrit « arachide »
dans une recette, on y lit « beurre de cacahuète » ou « satay ». `App\Enum\Allergen`
fait cette traduction pour les 14 allergènes réglementaires et quelques régimes, et
c'est elle qui fait tout le travail.

Deux pièges symétriques, tous deux couverts par des contre-exemples testés un par un :

| Le contrôle doit trouver | Le contrôle ne doit PAS trouver |
| --- | --- |
| « beurre de cacahuète » → arachide | « lait de coco » → lait |
| « satay » → arachide | « farine de riz » → gluten |
| « semoule » → gluten | « pain **sans** gluten » → gluten |
| « moules marinières » → mollusque | « moule à gâteau » → mollusque |
| « noix de cajou » → fruits à coque | « noix de coco », « noix de muscade » |
| « vin blanc » → alcool | « vinaigre de vin » → alcool |

Le faux négatif met quelqu'un en danger ; le faux positif rend le contrôle
inutilisable, donc pousse à le désactiver — ce qui revient au premier cas.

Les recettes écartées sont **annoncées** à l'utilisateur, avec le terme trouvé et
l'endroit où il l'a été : une proposition qui disparaît sans explication ressemble à
une panne, alors que c'est le contrôle qui a fonctionné.

## Une confirmation de recette ne peut pas déduire deux fois

Le mobile confirmait une recette avec son seul nom. Une réponse réseau perdue, suivie
d'une nouvelle tentative, déduisait donc **deux fois** — et rien dans la requête ne
permettait au serveur de reconnaître qu'il s'agissait du même geste.

Chaque confirmation porte désormais une **clé d'opération** choisie par l'application,
et `stock_operation` la rend unique par foyer. Quatre garanties en découlent :

1. **Idempotence** — rejouer la même clé ne réécrit rien : le serveur relit la réponse
   figée de la première exécution et le dit (`replayed: true`).
2. **Atomicité** — les lots candidats sont verrouillés, FEFO est recalculé **à
   l'intérieur** de la transaction, et un plan devenu incomplet n'écrit **rien du
   tout**. Auparavant les plans étaient calculés avant la transaction, et une
   allocation partielle suffisait à déclencher l'écriture. Le serveur répond alors
   `409` avec ce qui reste réellement disponible.
3. **Honnêteté** — deux lignes du même ingrédient sont fusionnées avant planification.
   Sans cela, chacune réservait le même lot, et le plafonnement silencieux faisait
   annoncer une quantité qui n'avait pas été retirée.
4. **Traçabilité** — tous les mouvements d'une même recette partagent l'identifiant de
   l'opération : le journal se lit geste par geste.

`StockService::transactional()` remonte la frontière transactionnelle au niveau de
l'opération complète : un service bas niveau ne décide plus seul de quand écrire.

## Archiver plutôt que supprimer

Supprimer un lot effaçait ses mouvements en cascade — le journal disparaissait
précisément dans le cas où il aurait servi : « qui a retiré ce lot, et que restait-il
dessus ? ».

`DELETE /stock-items/{id}` **archive** donc : la ligne quitte l'inventaire, et
l'historique demeure. `POST /stock-items/{id}/restore` défait le geste, `GET
/stock-items?archived=1` liste les archives, et l'onglet Stock les atteint par le
filtre « Archivés ».

**L'archivage ne touche pas à la quantité.** Il l'a fait un temps, et c'était une
contradiction : le geste s'annonçait réversible alors que la restauration ne rendait
qu'un lot vide. Deux règles séparées, chacune raisonnable, se neutralisaient même
complètement — la recherche écarte par défaut les lots vides, si bien qu'un stock
archivé, donc mis à zéro, n'était **listé nulle part** : ni dans l'inventaire, ni
dans les archives. Le geste destructeur reste `POST /stock-items/{id}/discard`,
« Jeter le produit », et c'est le seul.

Le mouvement écrit à l'archivage porte donc un delta nul : rien n'est sorti du stock.
C'est `remainingAfter` qui dit ce que le lot contenait quand on l'a rangé, et
l'interface affiche « — » plutôt qu'un « 0 g » qui se lirait comme une perte.

> Les lots archivés **avant** ce correctif restent à zéro. Leur quantité d'origine
> figure dans le journal, mais la déduire automatiquement reviendrait à deviner :
> `discard()` écrit un mouvement de même nature, et rien ne les distingue avec
> certitude. Ils sont laissés tels quels plutôt que reconstitués à l'aveugle.

## Démarrer

### Prérequis

Docker, PHP 8.2+ (avec `pdo_pgsql`, `intl`, `zip`, `sodium`), Composer, Node 20+.

### Base de données

```bash
docker compose up -d          # PostgreSQL 16 sur le port 5433
```

### API

```bash
cd backend
composer install
php bin/console doctrine:migrations:migrate
php -S 0.0.0.0:8000 -t public public/index.php
```

### Clé du générateur de recettes

Deux fournisseurs sont branchés, au choix, dans `backend/.env.local` — jamais
versionné, jamais embarqué dans l'application mobile :

```dotenv
# Gratuit, sans carte bancaire : https://aistudio.google.com/apikey
RECIPE_PROVIDER=gemini
GEMINI_API_KEY=AIza...
```

```dotenv
# Payant, propositions de meilleure qualité
RECIPE_PROVIDER=claude
ANTHROPIC_API_KEY=sk-ant-...
```

Sans clé, tout fonctionne sauf l'onglet « Cuisiner », qui affiche un message
nommant précisément la variable à renseigner.

L'appel au modèle est isolé derrière `RecipeGeneratorInterface` : la logique
métier — inventaire structuré, consignes, validation de la proposition de
consommation — est identique quel que soit le fournisseur. En ajouter un
troisième revient à écrire une classe.

### Application mobile

```bash
cd mobile
npm install
npx expo start          # puis scanner le QR code avec Expo Go
```

En développement, l'adresse de l'API est déduite de celle du bundler Expo :
rien à configurer tant que le téléphone et l'ordinateur sont sur le même Wi-Fi.

## Alertes de péremption

Trois jours avant la date limite, à 9 h, le téléphone prévient de ce qu'il faut
consommer. Toucher l'alerte ouvre directement la fiche de l'aliment.

Les notifications sont **locales** : chaque téléphone programme les siennes à partir
du stock qu'il vient d'afficher, sans jeton d'appareil ni tâche planifiée côté
serveur. Elles fonctionnent donc hors connexion, et chaque membre du foyer autorise
les siennes depuis l'onglet Profil.

Les alertes sont annulées puis reprogrammées en bloc à partir de l'inventaire entier
— jamais de la liste filtrée à l'écran. L'inventaire reste la seule source de vérité ;
aucun état parallèle ne peut mentir.

Deux moments déclenchent cette reprogrammation : le passage sur l'onglet Stock, et
**le retour de l'application au premier plan**. Le second est celui qui compte pour
un stock partagé : une consommation faite depuis un autre téléphone ne se voyait
qu'en repassant par l'onglet Stock, c'est-à-dire potentiellement jamais, puisque
l'application s'ouvre aussi sur Cuisiner, sur Profil, ou directement sur une fiche
depuis une notification.

> **Ce que les notifications locales ne peuvent pas faire.** Tant que l'application
> dort, l'appareil n'apprend rien : si quelqu'un consomme le dernier yaourt pendant
> la nuit, l'alerte du matin part quand même. La corriger demanderait des
> notifications serveur, donc des jetons d'appareil — un autre chantier, pas une
> option de celui-ci. La limite est écrite ici plutôt que suggérée par l'interface.

> Un lot enregistré alors qu'il périme dans moins de trois jours n'a pas d'alerte :
> l'instant est déjà passé. Il apparaît en revanche d'emblée en rouge dans la liste
> et sous le filtre « Bientôt périmés ».

## Héberger l'API — l'application ne dépend plus d'un PC allumé

Tant que l'API tournait sur un ordinateur personnel, l'application s'éteignait avec
lui, et un VPN était imposé à chaque utilisateur. L'API et la base vivent désormais
sur un hébergement permanent, joignable en HTTPS depuis n'importe quel réseau. Les
membres du foyer n'installent rien d'autre que **Food**.

### L'image

`backend/Dockerfile` produit une image de production autonome, bâtie sur
[FrankenPHP](https://frankenphp.dev) — Caddy avec PHP embarqué. Elle remplace à la
fois `php -S`, que la documentation PHP réserve au développement, et l'attelage
nginx + PHP-FPM + superviseur.

Elle ne contient **aucune instruction propre à un hébergeur** : les migrations
tournent au démarrage du conteneur plutôt que par un mécanisme maison (`release
command` chez l'un, `preDeploy` chez l'autre, rien du tout chez un troisième). La
même image se déploie donc sur Fly.io, Railway, Render, Koyeb ou Clever Cloud.

```bash
cd backend
docker build -t food-api .
```

### Le déploiement

Quelle que soit la plateforme, la marche à suivre est la même :

1. Créer une base **PostgreSQL managée** ; elle fournit un `DATABASE_URL`.
2. Déployer le dossier `backend/` — toutes ces plateformes détectent le `Dockerfile`.
3. Définir les variables ci-dessous.
4. Relever l'URL HTTPS attribuée (`mon-app.fly.dev`, `…up.railway.app`, …).

| Variable | Valeur |
| --- | --- |
| `APP_ENV` | `prod` |
| `APP_SECRET` | **un nouveau secret**, jamais celui du dépôt : `openssl rand -hex 32` |
| `DATABASE_URL` | fourni par la base managée ; ajouter `?sslmode=require` |
| `TRUSTED_PROXIES` | `REMOTE_ADDR` — sans quoi la limitation de débit compte tous les visiteurs comme un seul |
| `CORS_ALLOW_ORIGIN` | `^$` : l'application mobile n'est pas un navigateur, aucune origine n'a à être autorisée |
| `GEMINI_API_KEY` | la clé du générateur de recettes — **sans elle, tout fonctionne sauf l'onglet Cuisiner** |
| `PORT` | imposé par la plateforme, repli `8080` |

Une variable oubliée ne se voyait auparavant pas au déploiement : `GET /api/health`
répondait `200` même sans clé de recettes et même sans `TRUSTED_PROXIES` (leçon nº 10,
`tasks/lessons.md`). Deux filets la remplacent désormais :

- **Au démarrage du conteneur**, `docker/entrypoint.sh` exécute `app:config:check`
  avant toute migration. `APP_SECRET` identique à celui du dépôt ou `DATABASE_URL`
  absente **empêchent le conteneur de démarrer**. `CORS_ALLOW_ORIGIN` permissif ou
  `TRUSTED_PROXIES` vide se contentent d'un avertissement dans les journaux : ce sont
  des configurations parfois légitimes selon l'hébergeur, pas des pannes.
- **`GET /api/health`** exécute un `SELECT 1` réel et déclare, sans jamais l'afficher,
  si la clé du générateur de recettes actif est présente :
  `{"status":"ok|degraded|error","database":true,"recipeProvider":"Google Gemini","recipeProviderConfigured":false}`.
  Base injoignable → `503` et `status: "error"` : plus aucun déploiement cassé ne
  répond `200`. Clé de recettes absente → `200` et `status: "degraded"` — ce n'est pas
  une panne du serveur, seul l'onglet Cuisiner est touché.

Ce que la sonde ne couvre toujours pas : une clé **présente mais révoquée**, et les
autres services tiers (Open Food Facts, Firebase). Pour vérifier une clé pour de bon,
un appel réel reste nécessaire :

```bash
# L'onglet Cuisiner : doit renvoyer des recettes, pas une erreur de configuration
curl -X POST https://<votre-api>/api/recipes/suggest \
  -H "X-Household: <id>" -H "X-Household-Key: <code>" -H "X-Member: <id>" \
  -H "Content-Type: application/json" \
  -d '{"people":2,"equipment":[],"exclusions":[],"allowExtraIngredients":true}'
```

L'application mobile n'a **jamais** connaissance de ces valeurs : les clés restent
côté serveur.

### La bascule des données

```bash
# Sauvegarde de la base locale
docker exec food_postgres pg_dump -U food -d food --no-owner > food.sql

# Restauration vers la base managée
psql "<DATABASE_URL de la base managée>" < food.sql

# Délivrance des codes aux foyers repris
docker run --rm -e DATABASE_URL="…" food-api php bin/console app:households:issue-keys
```

Puis, dans `mobile/eas.json`, remplacer `EXPO_PUBLIC_API_URL` par l'URL HTTPS
publique et rediffuser un build. L'adresse reste modifiable dans l'application, ce
qui permet de repointer vers le PC pour développer.

### Tailscale, désormais

Tailscale garde son utilité — administrer le serveur, ou joindre l'API du PC pendant
le développement — mais **n'est plus imposé aux membres du foyer**. Le bouton
« Tester la connexion » (Profil → Serveur) interroge `GET /api/health` : c'est ce qui
distingue, depuis le téléphone, une adresse fausse d'un serveur qui ne répond pas.

## Installer et mettre à jour l'application — Firebase App Distribution

Le QR code d'un build Expo installe une version **et une seule**. Chaque correctif
demandait ensuite de recompiler, renvoyer un lien à chacun, et espérer que tout le
monde pense à le scanner : une mise à jour manuelle par personne et par version.

Firebase App Distribution renverse la charge. Les testeurs installent une fois
l'application **App Tester** de Firebase ; chaque nouvelle version leur arrive
ensuite en notification, avec ses notes de version, et s'installe comme n'importe
quelle mise à jour.

```bash
cd mobile
npm run distribute:android              # compile puis diffuse
npm run distribute:android -- --notes "Correction de l'écran Cuisiner"
```

La même commande est appelée par le workflow
[`android-distribution.yml`](.github/workflows/android-distribution.yml), déclenché
à la main depuis l'onglet Actions. Une seule implémentation pour les deux chemins :
deux scripts de diffusion, c'est celui qu'on lance le moins souvent qui dérive, donc
celui qui casse le jour où on en a besoin.

Le déclenchement est **manuel et non sur `push`** : une compilation EAS consomme un
crédit et prend une dizaine de minutes. Diffuser à chaque commit épuiserait le quota
et noierait les testeurs sous les notifications — deux façons de rendre la mise à
jour automatique plus pénible que le QR code qu'elle remplace.

### Mise en place, une fois

1. Créer un projet sur [console.firebase.google.com](https://console.firebase.google.com),
   y ajouter une application **Android** avec le paquet `com.theo.food`.
   Aucun `google-services.json` n'est nécessaire : App Distribution ne demande pas
   d'intégrer un SDK dans l'application.
2. Relever l'**App ID** (`1:123456789:android:abcdef`) dans les paramètres du projet.
3. Dans App Distribution, créer un **groupe de testeurs** — alias `testeurs` par
   défaut ici — et y inscrire les adresses e-mail du foyer.
4. Créer un **compte de service** Google Cloud avec le rôle
   *Firebase App Distribution Admin*, et télécharger sa clé JSON.
5. Déclarer les secrets du dépôt (Settings → Secrets and variables → Actions) :

| Secret | Contenu |
| --- | --- |
| `EXPO_TOKEN` | jeton d'accès Expo (expo.dev → Access tokens) |
| `FIREBASE_ANDROID_APP_ID` | l'App ID relevé à l'étape 2 |
| `FIREBASE_SERVICE_ACCOUNT` | le **contenu** de la clé JSON, pas son chemin |
| `FIREBASE_TESTER_GROUPS` *(variable)* | alias des groupes, défaut `testeurs` |

En local, les mêmes valeurs se passent par l'environnement, `GOOGLE_APPLICATION_CREDENTIALS`
pointant cette fois vers le **fichier** de clé.

> **Si la CLI Firebase refuse le compte de service.** `firebase login:ci` est
> déprécié et le compte de service est le chemin recommandé, mais la CLI ignore
> encore `GOOGLE_APPLICATION_CREDENTIALS` dans certains environnements
> d'intégration continue ([firebase-tools#8505](https://github.com/firebase/firebase-tools/issues/8505),
> [#10726](https://github.com/firebase/firebase-tools/issues/10726)). Le script
> accepte alors `FIREBASE_TOKEN` comme repli — explicitement, et non par accident.

> **Non vérifié de bout en bout.** Le script et le workflow n'ont pas pu être
> exercés contre un vrai projet Firebase : il n'en existe pas encore, et aucun
> secret n'est déclaré. Seules les gardes d'entrée et la validité du YAML ont été
> contrôlées. La première diffusion est donc à surveiller.

### L'adresse de l'API

`EXPO_PUBLIC_API_URL` dans `eas.json` porte l'URL HTTPS publique de l'API. Ce n'est
qu'un défaut : **l'adresse du serveur reste modifiable dans l'application**, donc un
même build couvre les trois façons de joindre l'API.

| Contexte | Adresse à saisir | Prérequis |
| --- | --- | --- |
| Usage normal | `https://mon-app.exemple.dev` | aucun — Wi-Fi, 4G ou 5G |
| Développement, Wi-Fi | `192.168.x.x:8000` | Port 8000 autorisé en entrée dans le pare-feu |
| Développement, USB | `localhost:8000` | `adb reverse tcp:8000 tcp:8000` |

Chaque membre du foyer installe l'application depuis App Tester, puis saisit le
**code du foyer** que vous lui transmettez depuis Profil → « Partager le code ».

`autoIncrement` est activé dans `eas.json` : sans un `versionCode` croissant,
Android refuse d'installer une nouvelle version par-dessus la précédente — et la
notification de mise à jour mènerait à une installation qui échoue.

### APK compilé localement

```bash
cd mobile
npx expo prebuild --platform android
cd android
EXPO_PUBLIC_API_URL="http://<IP-DU-PC>:8000/api" ./gradlew assembleRelease
adb install -r app/build/outputs/apk/release/app-release.apk
```

Deux réglages indispensables vivent dans `app.json`, via `expo-build-properties`, et
non dans le dossier `android/` — celui-ci est régénéré à chaque `prebuild`, ce qui
effacerait toute correction faite à la main :

- `usesCleartextTraffic` — sans lui, l'APK de production rejette silencieusement
  tous les appels HTTP vers l'API locale ;
- `buildArchs: ["arm64-v8a"]` — compiler les quatre ABI est du temps perdu.

## Tests

```bash
cd backend && php vendor/bin/phpunit    # 230 tests
cd mobile  && npm run typecheck
```

`.github/workflows/ci.yml` exécute les deux commandes ci-dessus sur chaque `push` et
chaque *pull request*, contre une vraie base PostgreSQL de service — jamais nulle
part auparavant. `vendor/bin/phpunit --fail-on-skipped` y remplace la commande
locale : en local, sans base, les tests suivants doivent continuer à s'ignorer
plutôt qu'échouer ; en intégration continue, un seul test ignoré fait échouer le
job, pour qu'un défaut de migration ne se déguise plus en suite verte.

Trente-deux d'entre eux exigent une vraie base PostgreSQL — ni la concurrence ni un
`ORDER BY` ne se simulent avec des doublures. Ils sont **ignorés** si elle est
absente, jamais rouges :

```bash
cd backend
php bin/console doctrine:database:create --env=test
php bin/console doctrine:migrations:migrate --env=test --no-interaction
```

Les tests couvrent la logique dont une erreur corromprait l'inventaire — conversion
d'unités, correction d'unité entre familles, normalisation des noms d'ingrédients,
identité des variantes, détection de l'état des produits, allocation FEFO,
suivi des exemplaires, consolidation d'une proposition, archivage, chaque mouvement
de stock — celle dont
une erreur ouvrirait un foyer — génération du code, tolérance de saisie, refus d'un
code absent, faux, ou appartenant à un autre foyer — et celle dont une erreur
mettrait quelqu'un en danger : chaque contre-exemple du tableau des exclusions
alimentaires a son test.

### La concurrence, testée pour de bon

`RecipeConfirmationConcurrencyTest` lance **un second processus PHP** : dans un
programme mono-thread, un verrou qu'on prend soi-même ne bloque jamais, et un test
qui reste dans un seul processus passerait sans rien démontrer.

Ce second processus verrouille un lot de 500 g, le tient, puis le descend à 50 g et
valide — pendant qu'une confirmation de 200 g arrive dessus. Le stock final départage
les deux implémentations possibles :

| | Stock final | Ce qui s'est passé |
| --- | --- | --- |
| **Avec** le verrou avant planification | **50 g** | La confirmation attend, relit 50 g, constate que son plan n'est plus réalisable, n'écrit rien |
| **Sans** le verrou | **300 g** | Elle avait planifié sur les 500 g lus avant l'attente et a écrit 500 − 200 par-dessus : 250 g de tomates apparus de nulle part |

Le test a été vérifié par mutation — verrou retiré, il échoue avec exactement
`300.0 !== 50.0`. Un test de concurrence qui passe dans les deux cas ne prouve rien,
et la première version de celui-ci était dans ce cas : elle n'observait qu'une
exception, or l'écriture bloque de toute façon sur le verrou de ligne. C'est la
quantité finale, et elle seule, qui distingue les deux comportements.

### L'ordre de consommation, et les exemplaires

Même méthode pour les deux règles ajoutées ensuite. En retirant l'ouverture
automatique d'un exemplaire et le tri « entamé d'abord », **dix tests** passent au
rouge — dont `OpenedLotPriorityTest`, qui interroge l'ordre réellement produit par
PostgreSQL et non une liste rendue par une doublure. Sa contre-épreuve compte autant
que son cas nominal : un lot ouvert ne doit **jamais** passer devant un lot qui
périme plus tôt.

> **Ce que les tests ne couvrent toujours pas.** Le parcours des écrans sur un
> appareil : `tsc` et un bundle qui se construit ne prouvent rien sur l'ordre des
> rendus (leçon n° 9). Le bundle Metro compile bien les 1 410 modules, mais Expo Go
> segfault au lancement sur émulateur x86_64 (SDK 57 + Fabric) — un défaut
> d'environnement, pas de l'application. La vérification reste à faire sur un
> téléphone réel.

## API

Toutes les routes sont sous `/api`. **Trois** sont publiques, et elles seules : santé,
création, adhésion — c'est-à-dire ce qui précède l'accès à un foyer. Toutes les autres
exigent `X-Household` **et** `X-Household-Key` ; celles qui écrivent exigent en plus
`X-Member`.

Cette règle était écrite ici avant d'être tenue : les trois routes `/products` ne
demandaient rien, dont `POST /products`, une écriture dans le catalogue partagé par
tous les foyers. Elle est désormais vérifiée route par route par
`ApiAuthenticationTest`, qui attaque l'API en HTTP — aucun test de service ne peut
voir une dépendance qu'un contrôleur a simplement oublié de déclarer.

| Méthode | Route | Rôle |
| --- | --- | --- |
| `GET` | `/health` | Serveur joignable ? Seule route sans en-tête `X-Household` |
| `POST` | `/households` | Crée un foyer et délivre son code, une seule fois |
| `POST` | `/households/join` | Ouvre un foyer à partir de son code |
| `POST` | `/households/{id}/access-key` | Délivre un nouveau code (perdu ou divulgué) |
| `GET` `PATCH` `DELETE` | `/households/{id}` | Nom et exclusions alimentaires du foyer |
| `POST` | `/households/{id}/members` | Ajoute un prénom au foyer |
| `DELETE` | `/households/{id}/members/{memberId}` | Retire un membre (l'historique subsiste) |
| `GET` | `/reference` | Unités, emplacements, catégories, équipements |
| `GET` | `/products/barcode/{barcode}` | Résolution d'un code-barres (cache puis Open Food Facts) |
| `GET` | `/products/shelf-life` | Durée de conservation estimée d'un aliment, et sa justification |
| `POST` | `/products` | Contribue une fiche au catalogue — **code-barres obligatoire** |
| `GET` `POST` | `/stock-items` | Inventaire filtrable ; ajout d'un ou plusieurs lots (`batches`) |
| `GET` `PATCH` | `/stock-items/{id}` | Fiche d'un lot et ses lots frères |
| `DELETE` | `/stock-items/{id}` | Archive le lot ; l'historique est conservé |
| `POST` | `/stock-items/{id}/restore` | Sort un lot des archives |
| `POST` | `/stock-items/{id}/consume` | `all` · `package` · `units` · `quantity` |
| `POST` | `/stock-items/{id}/add` | Réapprovisionnement |
| `POST` | `/stock-items/{id}/correct` | Quantité exacte ou estimation |
| `POST` | `/stock-items/{id}/unit` | Répare une unité mal saisie |
| `POST` | `/stock-items/{id}/open` | Entamé / fermé |
| `POST` | `/stock-items/{id}/discard` | Produit jeté |
| `GET` | `/stock-items/{id}/movements` | Journal du lot |
| `GET` | `/inventory/summary` | Inventaire agrégé par ingrédient |
| `POST` | `/recipes/suggest` | 3 propositions (aucune écriture) ; `priorityKey` impose un aliment |
| `POST` | `/recipes/confirm` | Applique la déduction après confirmation |
| `GET` `POST` | `/recipes/saved` | Recettes conservées par le foyer ; enregistrement idempotent |
| `GET` `DELETE` | `/recipes/saved/{id}` | Une recette enregistrée, et son retrait |

## Cas limites traités

- **Produit absent d'Open Food Facts** — la base est collaborative ; la saisie
  manuelle prend le relais avec un message explicite.
- **Limite de 15 requêtes/minute/IP d'Open Food Facts** — les fiches produit sont
  mises en cache 30 jours en base ; une fiche périmée reste servie si l'API est
  injoignable.
- **Quantité inconnue** — « plein », « environ la moitié », « presque vide » ou
  « quantité inconnue » ; ce dernier choix marque le lot comme estimé sans inventer
  de chiffre.
- **Unités hétérogènes** — conversions uniquement à l'intérieur d'une famille
  (masse, volume, unité, portion). Convertir des grammes en litres demanderait une
  densité inconnue : c'est refusé plutôt que deviné.
- **Unité mal choisie à la saisie** — « g » au lieu de « unité » se répare depuis la
  fiche. Entre deux familles, aucune conversion n'existe : la quantité restante est
  redemandée plutôt que devinée. Un mouvement de correction en garde la trace, et le
  conditionnement de référence d'un produit saisi à la main suit la correction.
- **Plusieurs lots, plusieurs dates** — saisis en un geste, consommés FEFO.
- **Aucune date imprimée** — fruits, légumes, vrac, restes : la durée de conservation
  est estimée à partir de l'aliment et de son rangement, et signalée comme estimation.
- **Plat maison sans code-barres** — saisie libre : elle crée une variante du foyer,
  et ne pollue plus le catalogue partagé mondialement.
- **Même aliment sous deux formes** — tomates fraîches et en conserve font deux lignes
  d'inventaire, avec leurs quantités et leurs états propres.
- **Consommation supérieure au stock** — bornée au restant ; le mouvement journalisé
  porte la quantité réellement retirée, jamais celle demandée. Pour une recette, un
  plan incomplet n'écrit rien du tout et renvoie ce qui reste disponible.
- **Confirmation envoyée deux fois** — la clé d'opération la rend rejouable sans
  seconde déduction.
- **Recette proposant un allergène** — écartée par un contrôle automatique, jamais
  seulement signalée.
- **Plusieurs exemplaires d'un même lot** — trois sachets de 500 g dont 250 g ont
  servi : un seul est entamé, les deux autres restent fermés, et c'est l'entamé qui
  repart en premier.
- **Quantité prélevée sur un produit fermé** — l'exemplaire s'ouvre du même geste ;
  le stock ne peut plus baisser sur un produit affiché comme scellé.
- **Quantité utilisée différente de la recette** — chaque ligne est modifiable avant
  la déduction, et une ligne à zéro n'est pas déduite.
- **Aliment imposé ignoré par le modèle** — la recette est écartée avec son motif,
  comme pour les exclusions ; la clé fait foi, pas le libellé.
- **Même recette enregistrée deux fois** — l'empreinte du contenu porte un index
  unique par foyer : aucun doublon, même depuis deux téléphones au même instant.
- **Lot archivé par erreur** — l'archivage ne retire rien : le lot revient avec sa
  quantité, son exemplaire entamé et son historique. Le filtre « Archivés » de
  l'onglet Stock le retrouve.
- **Serveur injoignable pendant le chargement d'une recette enregistrée** — un 404
  seul conclut à « Recette introuvable » ; une panne s'affiche comme une panne, avec
  un bouton « Réessayer ». Annoncer la disparition d'une recette qui n'a pas bougé
  ferait chercher un problème là où il n'y en a pas.
- **Réponse qui n'est pas du JSON** — page d'erreur d'un répartiteur, portail Wi-Fi
  captif, passerelle en 502 : le client renvoyait le `SyntaxError` brut de
  `JSON.parse` (« Unexpected token < »). C'est une panne de transport, et elle se
  présente comme telle.

## Accessibilité

Les composants partagés — `Button`, `Chip`, `Field`, `Badge`, `ErrorBanner`,
`EmptyState`, `Loader` — portent leur rôle, leur libellé et leur état. C'est là que
le travail est le plus rentable : tout l'écran en hérite.

Trois cas demandaient davantage, parce que l'information n'existait que visuellement :

- un `Chip` sélectionné ne l'était que par sa couleur, et une ligne du sélecteur
  d'aliment que par un « ✓ » — `accessibilityState={{ selected }}` le dit désormais,
  et le glyphe est marqué comme décoratif ;
- les boutons `−` et `+` n'avaient pour libellé que leur glyphe : ils annoncent leur
  action, et la valeur courante est portée par `accessibilityValue` ;
- une ligne de stock ou de journal est lue d'un bloc plutôt qu'en cinq textes
  séparés, et un `Pressable` imbriqué dans une carte (« Retirer de mes recettes »)
  nomme la recette qu'il retire.

> Vérifié par lecture et par `tsc`, **pas encore avec TalkBack sur un appareil**.
> Comme pour l'ordre des rendus, seul le téléphone fait foi.

