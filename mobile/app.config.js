/**
 * Le manifeste reste `app.json` ; ce fichier n'y ajoute qu'une chose : le
 * `versionCode` de la diffusion.
 *
 * Expo lit `app.json` en premier et passe le résultat ici en `config`, ce qui
 * évite de dupliquer un manifeste de soixante lignes pour surcharger un entier.
 *
 * ## Pourquoi ce numéro ne vient plus d'Expo
 *
 * Il vivait sur les serveurs d'EAS (`appVersionSource: "remote"`), qui
 * l'incrémentaient à chaque compilation. Depuis que l'APK est compilé sur le
 * runner GitHub — pour ne plus attendre une heure et demie dans la file d'attente
 * du plan gratuit — la compilation ne passe plus par eux, et le compteur non plus.
 *
 * `scripts/distribute-android.mjs` le dérive donc du nombre de commits du dépôt.
 * Aucun appel à `git` ici : ce fichier est évalué par toutes les commandes Expo,
 * y compris hors d'un dépôt, et se contente de lire une variable.
 *
 * Sans elle — un `expo run:android` de développement — la valeur d'`app.json`
 * s'applique. Elle n'a alors aucune importance : rien ne se diffuse.
 */
export default ({ config }) => ({
  ...config,
  android: {
    ...config.android,
    // `Number(undefined)` vaut NaN, et `NaN || x` vaut x : une variable absente
    // ou illisible retombe sur le manifeste plutôt que de produire un APK dont
    // le numéro de version serait « NaN ».
    versionCode: Number(process.env.ANDROID_VERSION_CODE) || config.android.versionCode,
  },
});
