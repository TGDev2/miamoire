# Food

Assistant de gestion domestique. L'application répond à trois questions :

1. **Qu'est-ce que j'ai réellement chez moi ?**
2. **Qu'est-ce que je dois consommer prochainement ?**
3. **Que puis-je cuisiner avec ces aliments, pour un nombre donné de personnes ?**

```text
Application mobile  ──▶  API REST Symfony  ──▶  PostgreSQL
React Native + Expo        (clés d'API                │
      TypeScript            côté serveur)             ├──▶ Open Food Facts
                                                      └──▶ Claude (claude-opus-5)
```

L'application mobile n'appelle jamais Open Food Facts ni Claude directement :
tout passe par l'API Symfony, qui garde les clés et met les produits en cache.

## Le modèle : produit ≠ stock

La distinction structurante du projet.

| `Product` | `StockItem` |
| --- | --- |
| La référence générale | Ce qui est réellement chez vous |
| « Lait demi-écrémé Lactel, bouteille de 1 L » | « 2 bouteilles, une expire le 27/07, l'autre le 03/08 » |
| Partagé entre tous les foyers, sert de cache Open Food Facts | Appartient à un foyer, porte la quantité restante |

Chaque lot est distinct, ce qui permet la consommation **FEFO** (*First Expired,
First Out*) : retirer 1,5 L de lait vide d'abord la bouteille qui périme le plus tôt.

Aucune quantité ne change sans qu'un `StockMovement` soit écrit. On n'écrase jamais
silencieusement « 1 000 g » par « 700 g » : le journal conserve que 300 g ont été
consommés, ce qui rend les corrections et l'audit possibles.

## Pas de comptes : des foyers et des prénoms

L'application est destinée à une seule famille sur son réseau local. Il n'y a donc
ni mot de passe, ni session, ni compte.

- Un **foyer** porte un nom et **isole complètement le stock**. Deux foyers ne
  partagent ni inventaire, ni mouvements, ni membres — seule la table `Product`,
  qui ne contient aucune quantité, leur est commune.
- Un **membre** est un simple prénom. Il ne donne aucun droit : il sert à savoir
  qui a enregistré ou consommé un produit.

L'application transmet deux en-têtes, `X-Household` et `X-Member`. La seule
garantie apportée est l'**isolation** : un foyer ne peut jamais lire ni modifier
le stock d'un autre, et un membre ne peut pas signer un mouvement dans un foyer
auquel il n'appartient pas (`RequestContext`, couvert par `RequestContextTest`).

> ⚠️ Toute personne ayant accès au réseau peut lister les foyers et lire leur stock.
> C'est un choix assumé pour un usage familial. Pour joindre l'API hors du domicile,
> passer par Tailscale (voir plus bas) : le réseau privé y tient lieu
> d'authentification. Ne jamais rediriger le port 8000 de la box vers le PC.

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
unité, état du produit, urgence de péremption), pas une liste de noms. Il renvoie une
`stockConsumptionProposal`.

Cette proposition est **revalidée intégralement côté serveur** avant toute écriture :
clé inexistante, unité incompatible ou quantité invalide sont rejetées. L'utilisateur
confirme ensuite explicitement dans l'application, puis seulement le stock est déduit.

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

## Utiliser l'application hors du domicile

L'API n'a ni compte ni mot de passe : la publier sur Internet reviendrait à ouvrir
son stock — et sa base — à qui trouve l'adresse. **Tailscale** résout le problème
sans rien changer à ce modèle : il crée un réseau privé chiffré entre les seuls
appareils que vous autorisez. L'API devient joignable en 4G, en 5G et depuis
n'importe quel Wi-Fi, tout en restant invisible du reste d'Internet.

1. Installer Tailscale sur le PC et sur chaque téléphone, se connecter avec le même
   compte : <https://tailscale.com/download>
2. Activer **MagicDNS** dans l'admin Tailscale — la machine devient joignable par son
   nom plutôt que par une IP.
3. Lancer l'API en écoutant sur toutes les interfaces :
   `php -S 0.0.0.0:8000 -t public public/index.php`
4. Dans l'application, onglet **Profil → Serveur**, saisir `<nom-machine>:8000`, puis
   **Tester la connexion**.

Aucune redirection de port sur la box, aucune règle de pare-feu entrante, aucun
certificat à gérer. Le bouton « Tester la connexion » interroge `GET /api/health` :
c'est ce qui distingue, depuis le téléphone, une adresse fausse d'un PC éteint.

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

Renseigner d'abord `EXPO_PUBLIC_API_URL` dans `eas.json` avec le nom Tailscale du PC.
Ce n'est qu'un défaut : **l'adresse du serveur reste modifiable dans l'application**,
donc un même build couvre les trois façons de joindre l'API.

| Contexte | Adresse à saisir | Prérequis |
| --- | --- | --- |
| Hors du domicile (4G/5G) | `<nom-machine>:8000` | Tailscale actif des deux côtés |
| Wi-Fi domestique | `192.168.x.x:8000` | Port 8000 autorisé en entrée dans le pare-feu |
| USB | `localhost:8000` | `adb reverse tcp:8000 tcp:8000` |

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
cd backend && php vendor/bin/phpunit    # 62 tests
cd mobile  && npx tsc --noEmit
```

Les tests couvrent la logique dont une erreur corromprait l'inventaire :
conversion d'unités, correction d'unité entre familles, normalisation des noms
d'ingrédients, détection de l'état des produits, allocation FEFO, et chaque
mouvement de stock.

## API

Toutes les routes sont sous `/api`. Les routes de stock exigent l'en-tête
`X-Household` ; celles qui écrivent exigent en plus `X-Member`.

| Méthode | Route | Rôle |
| --- | --- | --- |
| `GET` | `/health` | Serveur joignable ? Seule route sans en-tête `X-Household` |
| `GET` `POST` | `/households` | Liste et création de foyers |
| `GET` `PATCH` `DELETE` | `/households/{id}` | Nom et exclusions alimentaires du foyer |
| `POST` | `/households/{id}/members` | Ajoute un prénom au foyer |
| `DELETE` | `/households/{id}/members/{memberId}` | Retire un membre (l'historique subsiste) |
| `GET` | `/reference` | Unités, emplacements, catégories, équipements |
| `GET` | `/products/barcode/{barcode}` | Résolution d'un code-barres (cache puis Open Food Facts) |
| `POST` | `/products` | Création manuelle d'un produit |
| `GET` `POST` | `/stock-items` | Inventaire filtrable ; ajout d'un lot |
| `GET` `PATCH` `DELETE` | `/stock-items/{id}` | Fiche d'un lot |
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
- **Plusieurs lots, plusieurs dates** — consommation FEFO par défaut.
- **Plat maison sans code-barres** — saisie libre avec nom, quantité et unité.
- **Consommation supérieure au stock** — bornée au restant ; le mouvement journalisé
  porte la quantité réellement retirée, jamais celle demandée.

## Hors périmètre du MVP

Reconnaissance de ticket de caisse, reconnaissance d'aliments par photo, calcul
nutritionnel, comparaison de prix, liste de courses automatique, partage entre
plusieurs logements, planification hebdomadaire.
