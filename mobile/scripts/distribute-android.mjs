#!/usr/bin/env node
/**
 * Compile l'APK puis le dépose sur Firebase App Distribution.
 *
 * Ce qu'il remplace : compiler, récupérer le lien d'Expo, envoyer le QR code à
 * chacun, et attendre que tout le monde pense à le scanner. Les testeurs
 * inscrits reçoivent désormais une notification, et l'application « App Tester »
 * de Firebase installe la nouvelle version comme n'importe quelle mise à jour.
 *
 * Le même fichier sert en local (`npm run distribute:android`) et dans GitHub
 * Actions. C'est délibéré : deux implémentations d'une même diffusion, c'est
 * une des deux qui dérive sans qu'on s'en aperçoive — celle qu'on lance le
 * moins souvent, donc celle qui casse le jour où on en a besoin.
 *
 * ## La compilation se fait ici, et non sur les serveurs d'Expo
 *
 * Une diffusion a mis **1 h 35**, dont quarante secondes de travail : le reste
 * était l'attente dans la file du plan gratuit d'EAS, `Waiting for build to
 * complete`. La compilation elle-même dure vingt minutes ; c'est la place dans
 * la queue qui coûtait une heure et quart, pendant laquelle le runner GitHub
 * tournait à ne rien faire.
 *
 * `eas build --local` exécute exactement le même processus — même `eas.json`,
 * mêmes identifiants téléchargés depuis Expo, même keystore — sur la machine
 * qui lance déjà le script. Plus de file, aucun crédit consommé.
 *
 * `--remote` rétablit l'ancien comportement, pour le jour où l'environnement de
 * compilation manque quelque chose.
 *
 * Variables attendues :
 *
 *   FIREBASE_ANDROID_APP_ID       1:123456789:android:abcdef — Firebase, paramètres du projet
 *   FIREBASE_TESTER_GROUPS        alias des groupes, séparés par des virgules (défaut : testeurs)
 *   GOOGLE_APPLICATION_CREDENTIALS  chemin vers la clé JSON du compte de service
 *   FIREBASE_TOKEN                 solution de repli, voir plus bas
 *   EXPO_TOKEN                     jeton Expo (indispensable en CI, inutile en local après `eas login`)
 *
 * À propos de l'authentification : le compte de service via
 * `GOOGLE_APPLICATION_CREDENTIALS` est le chemin recommandé, `firebase login:ci`
 * étant déprécié. Il reste des cas où la CLI Firebase ignore ces identifiants en
 * intégration continue ; `FIREBASE_TOKEN` est alors accepté ici comme repli,
 * explicitement et non par accident.
 */

import { execFileSync, spawnSync } from 'node:child_process';
import { mkdtempSync, readFileSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

const options = parseArguments(process.argv.slice(2));

const appId = required('FIREBASE_ANDROID_APP_ID');
const groups = process.env.FIREBASE_TESTER_GROUPS ?? 'testeurs';
const hasServiceAccount = Boolean(process.env.GOOGLE_APPLICATION_CREDENTIALS);
const hasToken = Boolean(process.env.FIREBASE_TOKEN);

if (!hasServiceAccount && !hasToken) {
  fail(
    'Aucune identité Firebase. Renseignez GOOGLE_APPLICATION_CREDENTIALS (clé JSON du compte\n'
      + 'de service, chemin d\'un fichier) ou, à défaut, FIREBASE_TOKEN.',
  );
}

const workDir = mkdtempSync(join(tmpdir(), 'food-distribution-'));

const apkPath = resolveApk(join(workDir, 'food.apk'));
const notesPath = writeReleaseNotes(join(workDir, 'notes.txt'), options.notes);

distribute(apkPath, notesPath);

console.log(`\nDiffusé aux groupes : ${groups}.`);
console.log('Les testeurs reçoivent une notification ; aucun QR code à faire scanner.');

/** Où qu'il vienne, un APK sur le disque, prêt à être diffusé. */
function resolveApk(destination) {
  if (options.artifactUrl) {
    return download(options.artifactUrl, destination);
  }

  return options.remote
    ? download(buildOnEasServers(options.profile), destination)
    : buildHere(options.profile, destination);
}

/**
 * Compile sur cette machine, sans passer par la file d'attente d'EAS.
 *
 * La sortie n'est pas capturée : une compilation de vingt minutes sans le
 * moindre signe de vie serait indiscernable d'un blocage.
 */
function buildHere(profile, destination) {
  const versionCode = versionCodeFromHistory();

  console.log(`Compilation sur cette machine (profil « ${profile} », versionCode ${versionCode}).`);
  console.log('Comptez vingt à trente-cinq minutes la première fois, moins ensuite : Gradle garde son cache.');

  const result = spawnSync(
    npx(),
    [
      'eas-cli', 'build',
      '--platform', 'android',
      '--profile', profile,
      '--local',
      '--non-interactive',
      '--output', destination,
    ],
    {
      cwd: projectRoot(),
      stdio: 'inherit',
      env: { ...process.env, ANDROID_VERSION_CODE: String(versionCode) },
    },
  );

  if (result.status !== 0) {
    fail(
      'La compilation a échoué. Le détail est au-dessus.\n'
        + 'Si l\'environnement de compilation est en cause et non le code, `--remote`\n'
        + 'la confie de nouveau aux serveurs d\'Expo — au prix de leur file d\'attente.',
    );
  }

  return destination;
}

/**
 * Compile sur les serveurs d'Expo et attend le lien de l'artefact.
 *
 * Repli explicite, plus le chemin par défaut : sur le plan gratuit, l'attente
 * en file dépasse largement la compilation elle-même.
 *
 * `--json` implique `--non-interactive` : la commande ne posera aucune question
 * et échouera plutôt que d'attendre une réponse que personne ne donnera.
 */
function buildOnEasServers(profile) {
  console.log(`Compilation sur les serveurs d'Expo (profil « ${profile} »).`);
  console.log('La compilation dure une dizaine de minutes ; l\'attente en file, parfois beaucoup plus.');

  const result = spawnSync(
    npx(),
    ['eas-cli', 'build', '--platform', 'android', '--profile', profile, '--json', '--wait'],
    { cwd: projectRoot(), encoding: 'utf8', stdio: ['ignore', 'pipe', 'inherit'] },
  );

  if (result.status !== 0) {
    fail('La compilation EAS a échoué. Le détail est au-dessus.');
  }

  const builds = parseJson(result.stdout);
  const build = Array.isArray(builds) ? builds[0] : builds;
  // Le nom du champ a changé selon les versions de la CLI ; les deux sont lus
  // plutôt que de dépendre d'une version précise d'eas-cli.
  const url = build?.artifacts?.applicationArchiveUrl ?? build?.artifacts?.buildUrl;

  if (!url) {
    fail('Compilation terminée mais aucun artefact dans la réponse EAS.');
  }

  return url;
}

/**
 * Le numéro de version de l'APK : le nombre de commits du dépôt.
 *
 * Il vivait sur les serveurs d'EAS, qui l'incrémentaient à chaque compilation.
 * Une compilation locale ne passe plus par eux, et l'historique git fait un
 * meilleur compteur qu'un fichier à éditer : monotone tant que l'historique
 * reste linéaire, identique ici et en intégration continue, et **reproductible**
 * — le même commit produit le même numéro, ce qu'un compteur serveur ne
 * garantit pas.
 *
 * Le garde-fou ci-dessous attrape le piège qui a le plus de chances de se
 * produire : `actions/checkout` clone en `fetch-depth: 1` par défaut, et le
 * compte vaudrait alors 1. Android refuse d'installer une version dont le
 * numéro recule ; mieux vaut échouer ici, bruyamment, que livrer aux testeurs
 * un APK qu'ils ne pourront pas installer.
 */
function versionCodeFromHistory() {
  let count;

  try {
    count = Number(execFileSync('git', ['rev-list', '--count', 'HEAD'], { encoding: 'utf8' }).trim());
  } catch {
    fail('Impossible de compter les commits : la diffusion doit être lancée depuis le dépôt.');
  }

  const floor = manifestVersionCode();

  if (!Number.isInteger(count) || count <= floor) {
    fail(
      `Le nombre de commits (${count}) ne dépasse pas le versionCode du manifeste (${floor}).\n`
        + 'Android refuserait l\'installation par-dessus la version déjà diffusée.\n\n'
        + 'Cause la plus probable en intégration continue : un clone superficiel.\n'
        + 'Le workflow doit demander `fetch-depth: 0` à actions/checkout.',
    );
  }

  return count;
}

/** Le plancher : le dernier numéro réellement diffusé, écrit dans app.json. */
function manifestVersionCode() {
  try {
    const manifest = JSON.parse(readFileSync(join(projectRoot(), 'app.json'), 'utf8'));

    return Number(manifest?.expo?.android?.versionCode) || 0;
  } catch {
    return 0;
  }
}

function download(url, destination) {
  console.log('Téléchargement de l\'APK…');

  // `curl` plutôt que fetch : il suit les redirections signées d'Expo et écrit
  // directement sur le disque, sans charger l'APK entier en mémoire.
  execFileSync('curl', ['--fail', '--location', '--silent', '--show-error', '--output', destination, url], {
    stdio: 'inherit',
  });

  return destination;
}

/**
 * Ce que les testeurs liront dans la notification : à défaut, le dernier commit.
 *
 * `RELEASE_NOTES` double l'option `--notes` parce qu'une phrase passée en
 * argument depuis un workflow se ferait découper aux espaces.
 */
function writeReleaseNotes(path, provided) {
  let notes = provided || process.env.RELEASE_NOTES;

  if (!notes) {
    try {
      notes = execFileSync('git', ['log', '-1', '--pretty=%s'], { encoding: 'utf8' }).trim();
    } catch {
      notes = 'Nouvelle version.';
    }
  }

  writeFileSync(path, notes, 'utf8');

  return path;
}

function distribute(apkPath, notesPath) {
  console.log('Dépôt sur Firebase App Distribution…');

  const args = [
    'firebase-tools',
    'appdistribution:distribute',
    apkPath,
    '--app',
    appId,
    '--groups',
    groups,
    '--release-notes-file',
    notesPath,
  ];

  if (!hasServiceAccount && hasToken) {
    args.push('--token', process.env.FIREBASE_TOKEN);
  }

  const result = spawnSync(npx(), args, { stdio: 'inherit' });

  if (result.status !== 0) {
    fail('Le dépôt sur App Distribution a échoué. Le détail est au-dessus.');
  }
}

function parseArguments(argv) {
  const parsed = { profile: 'preview', notes: null, artifactUrl: null, remote: false };

  for (let index = 0; index < argv.length; index += 1) {
    const value = argv[index + 1];

    switch (argv[index]) {
      case '--profile':
        parsed.profile = value;
        index += 1;
        break;
      case '--notes':
        parsed.notes = value;
        index += 1;
        break;
      // Diffuser un APK déjà compilé, sans repayer une compilation : utile
      // quand seule la distribution a échoué.
      case '--artifact-url':
        parsed.artifactUrl = value;
        index += 1;
        break;
      // Repli : rendre la compilation aux serveurs d'Expo, le jour où
      // l'environnement local ne suffit plus.
      case '--remote':
        parsed.remote = true;
        break;
      default:
        fail(`Option inconnue : ${argv[index]}`);
    }
  }

  return parsed;
}

function parseJson(raw) {
  try {
    return JSON.parse(raw);
  } catch {
    fail('Réponse EAS illisible : attendu du JSON.');
  }
}

/** `fileURLToPath` et non `.pathname` : celui-ci laisse un `/` en tête sous Windows. */
function projectRoot() {
  return fileURLToPath(new URL('..', import.meta.url));
}

function npx() {
  return process.platform === 'win32' ? 'npx.cmd' : 'npx';
}

function required(name) {
  const value = process.env[name];

  if (!value) {
    fail(`Variable ${name} manquante.`);
  }

  return value;
}

function fail(message) {
  console.error(`\n${message}\n`);
  process.exit(1);
}
