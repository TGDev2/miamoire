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

## L'IA ne modifie jamais l'inventaire

Le générateur de recettes reçoit un inventaire **structuré** (clé stable, quantité,
unité, urgence de péremption), pas une liste de noms. Il renvoie une
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
php bin/console lexik:jwt:generate-keypair    # une seule fois
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

### APK autonome

```bash
cd mobile
npx expo prebuild --platform android --clean
cd android
EXPO_PUBLIC_API_URL="http://<IP-DU-PC>:8000/api" ./gradlew assembleRelease
adb install -r app/build/outputs/apk/release/app-release.apk
```

**L'adresse du serveur reste modifiable dans l'application** (écran de connexion,
puis onglet Profil). Un même APK couvre donc les deux façons de joindre l'API :

| Contexte | Adresse à saisir | Prérequis |
| --- | --- | --- |
| Wi-Fi | `192.168.x.x:8000` | Port 8000 autorisé en entrée dans le pare-feu |
| USB | `localhost:8000` | `adb reverse tcp:8000 tcp:8000` |

La seconde option évite toute règle de pare-feu : le trafic passe par le câble.

## Tests

```bash
cd backend && php vendor/bin/phpunit    # 36 tests
cd mobile  && npx tsc --noEmit
```

Les tests couvrent la logique dont une erreur corromprait l'inventaire :
conversion d'unités, normalisation des noms d'ingrédients, allocation FEFO,
et chaque mouvement de stock.

## API

Toutes les routes sont sous `/api` et exigent un jeton JWT, sauf `/api/auth/*`.

| Méthode | Route | Rôle |
| --- | --- | --- |
| `POST` | `/auth/register` | Crée un compte et son foyer |
| `POST` | `/auth/login` | Renvoie un JWT |
| `GET` `PATCH` | `/me` | Profil, foyer, exclusions alimentaires |
| `GET` | `/reference` | Unités, emplacements, catégories, équipements |
| `GET` | `/products/barcode/{barcode}` | Résolution d'un code-barres (cache puis Open Food Facts) |
| `POST` | `/products` | Création manuelle d'un produit |
| `GET` `POST` | `/stock-items` | Inventaire filtrable ; ajout d'un lot |
| `GET` `PATCH` `DELETE` | `/stock-items/{id}` | Fiche d'un lot |
| `POST` | `/stock-items/{id}/consume` | `all` · `package` · `units` · `quantity` |
| `POST` | `/stock-items/{id}/add` | Réapprovisionnement |
| `POST` | `/stock-items/{id}/correct` | Quantité exacte ou estimation |
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
- **Plusieurs lots, plusieurs dates** — consommation FEFO par défaut.
- **Plat maison sans code-barres** — saisie libre avec nom, quantité et unité.
- **Consommation supérieure au stock** — bornée au restant ; le mouvement journalisé
  porte la quantité réellement retirée, jamais celle demandée.

## Hors périmètre du MVP

Reconnaissance de ticket de caisse, reconnaissance d'aliments par photo, calcul
nutritionnel, comparaison de prix, liste de courses automatique, partage entre
plusieurs logements, planification hebdomadaire.
