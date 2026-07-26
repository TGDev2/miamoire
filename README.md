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
`precooked`, `sliced`… Ils sont déduits du libellé et de la catégorie à la création
(`ProductTraitDetector`), puis corrigeables d'un geste dans l'application.

Chaque trait porte sa propre consigne, dans `App\Enum\ProductTrait` :

```
canned (En conserve) : déjà cuit, pelé et paré — ne demande ni épluchage,
                       ni parage, ni cuisson longue.
```

Le glossaire envoyé au modèle est construit depuis l'enum : ajouter un trait suffit
à l'enseigner au générateur, sans toucher au prompt.

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

`DELETE /stock-items/{id}` **archive** désormais : ce qui restait est journalisé comme
retiré, la ligne quitte l'inventaire, et l'historique demeure. `POST
/stock-items/{id}/restore` défait le geste, `GET /stock-items?archived=1` liste les
archives.

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

À chaque passage sur l'onglet Stock, les alertes sont annulées puis reprogrammées
en bloc à partir de l'inventaire entier — jamais de la liste filtrée à l'écran.
L'inventaire reste la seule source de vérité ; aucun état parallèle ne peut mentir.

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

Une variable oubliée ne se voit pas au déploiement : `GET /api/health` répond `200`
même sans clé de recettes et même sans `TRUSTED_PROXIES`. Après chaque mise en ligne,
vérifier les deux points que la sonde ne couvre pas :

```bash
# L'onglet Cuisiner : doit renvoyer des recettes, pas une erreur de configuration
curl -X POST https://<votre-api>/api/recipes/suggest \
  -H "X-Household: <id>" -H "X-Household-Key: <code>" -H "X-Member: <id>" \
  -H "Content-Type: application/json" \
  -d '{"people":2,"equipment":[],"exclusions":[],"allowExtraIngredients":true}'
```

`TRUSTED_PROXIES` ne se teste pas de l'extérieur, mais son absence a une conséquence
précise : derrière le répartiteur de l'hébergeur, toutes les requêtes semblent venir
d'une seule adresse, et la limitation de débit — 5 créations de foyer par heure —
s'appliquerait alors au monde entier au lieu de chaque visiteur.

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

## Installer l'application sur les téléphones du foyer

`eas.json` décrit un profil `preview` en **distribution interne** : Expo compile
l'APK dans le cloud et renvoie un lien accompagné d'un QR code. Chaque membre le
scanne, télécharge et installe — sans câble, sans ordinateur.

```bash
npm install -g eas-cli
eas login
cd mobile
eas build --platform android --profile preview
```

Renseigner d'abord `EXPO_PUBLIC_API_URL` dans `eas.json` avec l'URL HTTPS publique
de l'API. Ce n'est qu'un défaut : **l'adresse du serveur reste modifiable dans
l'application**, donc un même build couvre les trois façons de joindre l'API.

| Contexte | Adresse à saisir | Prérequis |
| --- | --- | --- |
| Usage normal | `https://mon-app.exemple.dev` | aucun — Wi-Fi, 4G ou 5G |
| Développement, Wi-Fi | `192.168.x.x:8000` | Port 8000 autorisé en entrée dans le pare-feu |
| Développement, USB | `localhost:8000` | `adb reverse tcp:8000 tcp:8000` |

Chaque membre du foyer installe l'application, puis saisit le **code du foyer** que
vous lui transmettez depuis Profil → « Partager le code ».

`autoIncrement` est activé : sans un `versionCode` croissant, Android refuse
d'installer une nouvelle version par-dessus la précédente.

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
cd backend && php vendor/bin/phpunit    # 139 tests
cd mobile  && npx tsc --noEmit
```

Sept d'entre eux exigent une vraie base PostgreSQL — la concurrence ne se simule
pas avec des doublures. Ils sont **ignorés** si elle est absente, jamais rouges :

```bash
cd backend
php bin/console doctrine:database:create --env=test
php bin/console doctrine:migrations:migrate --env=test --no-interaction
```

Les tests couvrent la logique dont une erreur corromprait l'inventaire — conversion
d'unités, correction d'unité entre familles, normalisation des noms d'ingrédients,
identité des variantes, détection de l'état des produits, allocation FEFO,
consolidation d'une proposition, archivage, chaque mouvement de stock — celle dont
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

> **Ce que les tests ne couvrent toujours pas.** Le parcours des écrans sur un
> appareil : `tsc` et un bundle qui se construit ne prouvent rien sur l'ordre des
> rendus (leçon n° 9). Le bundle Metro compile bien les 1 410 modules, mais Expo Go
> segfault au lancement sur émulateur x86_64 (SDK 57 + Fabric) — un défaut
> d'environnement, pas de l'application. La vérification reste à faire sur un
> téléphone réel.

## API

Toutes les routes sont sous `/api`. Sauf les quatre exceptions ci-dessous — santé,
création, adhésion — elles exigent `X-Household` **et** `X-Household-Key` ; celles
qui écrivent exigent en plus `X-Member`.

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
| `POST` | `/recipes/suggest` | 3 propositions (aucune écriture) |
| `POST` | `/recipes/confirm` | Applique la déduction après confirmation |

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

## Hors périmètre du MVP

Reconnaissance de ticket de caisse, reconnaissance d'aliments par photo, calcul
nutritionnel, comparaison de prix, liste de courses automatique, partage entre
plusieurs logements, planification hebdomadaire.
