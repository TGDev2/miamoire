#!/usr/bin/env node
/**
 * Compile l'APK avec EAS puis le dépose sur Firebase App Distribution.
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
import { mkdtempSync, writeFileSync } from 'node:fs';
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

const artifactUrl = options.artifactUrl ?? buildWithEas(options.profile);
const apkPath = download(artifactUrl, join(workDir, 'food.apk'));
const notesPath = writeReleaseNotes(join(workDir, 'notes.txt'), options.notes);

distribute(apkPath, notesPath);

console.log(`\nDiffusé aux groupes : ${groups}.`);
console.log('Les testeurs reçoivent une notification ; aucun QR code à faire scanner.');

/**
 * Lance la compilation et attend le lien de l'artefact.
 *
 * `--json` implique `--non-interactive` : la commande ne posera aucune question
 * et échouera plutôt que d'attendre une réponse que personne ne donnera.
 */
function buildWithEas(profile) {
  console.log(`Compilation EAS (profil « ${profile} »). Comptez une dizaine de minutes.`);

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
  const parsed = { profile: 'preview', notes: null, artifactUrl: null };

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
