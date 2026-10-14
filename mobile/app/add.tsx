import DateTimePicker from '@react-native-community/datetimepicker';
import { useLocalSearchParams, useRouter } from 'expo-router';
import React, { useEffect, useMemo, useRef, useState } from 'react';
import {
  Image,
  KeyboardAvoidingView,
  Platform,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  View,
} from 'react-native';

import { ApiError, api, generateOperationKey } from '../src/api/client';
import { useFeedback } from '../src/components/Feedback';
import * as haptics from '../src/haptics';
import type {
  FoodNature,
  FoodProfile,
  Product,
  ProductCutValue,
  ProductTraitValue,
  Reference,
  Unit,
} from '../src/api/types';
import {
  Button,
  Card,
  Chip,
  ErrorBanner,
  Field,
  Icon,
  Loader,
  SectionTitle,
  Stepper,
} from '../src/components/ui';
import { colors, font, radius, spacing, text, thermal } from '../src/theme';

/**
 * Un lot à créer : une date de péremption et le nombre d'exemplaires qui la portent.
 *
 * Trois yaourts pour le 27 et deux pour le 3 août, c'est un seul passage en caisse
 * mais deux lots. Les fondre en un seul ferait mentir la date la plus proche —
 * donc les alertes, et la consommation FEFO.
 *
 * Pour un aliment frais compté à la pièce, `packages` EST le nombre de courgettes :
 * une courgette est un exemplaire d'une pièce. Rien à convertir, et la notion
 * d'exemplaire cesse d'elle-même d'avoir un sens visible.
 */
type Batch = {
  id: number;
  packages: string;
  expiration: Date | null;
  /** Aucune date imprimée : le serveur la déduit d'une durée de conservation. */
  estimate: boolean;
};

let nextBatchId = 1;

function newBatch(packages = '1'): Batch {
  return { id: nextBatchId++, packages, expiration: null, estimate: false };
}

/** Les unités qui se comptent : la saisie y devient un compteur, pas un clavier. */
function isCountedUnit(unit: Unit): boolean {
  return unit === 'piece' || unit === 'portion';
}

export default function AddScreen() {
  const router = useRouter();
  const { notify } = useFeedback();
  const { barcode } = useLocalSearchParams<{ barcode?: string }>();

  const [reference, setReference] = useState<Reference | null>(null);
  const [product, setProduct] = useState<Product | null>(null);
  const [lookupMessage, setLookupMessage] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);

  const [name, setName] = useState('');
  const [brand, setBrand] = useState('');
  const [quantity, setQuantity] = useState('');
  const [unit, setUnit] = useState<Unit>('g');
  const [location, setLocation] = useState('pantry');
  const [traits, setTraits] = useState<ProductTraitValue[]>([]);
  /**
   * La forme de l'aliment, quand elle change quelque chose. `null` : rien n'a été
   * choisi — le serveur la déduira du libellé plutôt que de la vider.
   */
  const [cut, setCut] = useState<ProductCutValue | null>(null);
  const [cutTouched, setCutTouched] = useState(false);
  const [batches, setBatches] = useState<Batch[]>([newBatch()]);
  const [pickerFor, setPickerFor] = useState<number | null>(null);
  const [note, setNote] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  /** Ce que le serveur sait de l'aliment : sa nature, son unité naturelle, sa conservation. */
  const [profile, setProfile] = useState<FoodProfile | null>(null);
  /** Nature corrigée à la main — elle prime toujours sur la déduction. */
  const [natureOverride, setNatureOverride] = useState<FoodNature | null>(null);
  /**
   * L'unité a été choisie explicitement : le profil ne doit plus la remplacer.
   * Sans ce drapeau, passer une courgette « en grammes » se défaisait tout seul
   * au prochain rafraîchissement du profil.
   */
  const unitTouched = useRef(false);

  /**
   * Conservée jusqu'au succès : un double appui, ou une réponse réseau perdue
   * suivie d'une nouvelle tentative, ne doit pas créer les mêmes lots deux fois.
   */
  const operationKey = useRef<string | null>(null);

  useEffect(() => {
    let cancelled = false;

    (async () => {
      try {
        const ref = await api.reference();
        if (!cancelled) setReference(ref);

        if (barcode) {
          try {
            const found = await api.lookupBarcode(barcode);
            if (cancelled) return;

            setProduct(found.product);
            setName(found.product.name);
            setBrand(found.product.brand ?? '');
            // L'état déjà déduit du libellé par le serveur reste modifiable ici.
            setTraits(found.product.traits);
            // Un code-barres décrit un conditionnement : sa contenance est une
            // vraie information, elle prime donc sur l'unité naturelle du profil.
            if (found.product.packageQuantity) setQuantity(String(found.product.packageQuantity));
            if (found.product.packageUnit) {
              setUnit(found.product.packageUnit);
              unitTouched.current = true;
            }
          } catch (exception) {
            // La base est collaborative : un produit absent bascule sur la saisie manuelle.
            if (!cancelled) {
              setLookupMessage(
                exception instanceof ApiError && exception.status === 404
                  ? `Code-barres ${barcode} inconnu d'Open Food Facts. Complétez les informations ci-dessous.`
                  : 'Recherche du produit impossible. Complétez les informations ci-dessous.',
              );
            }
          }
        }
      } catch (exception) {
        if (!cancelled) {
          setError(exception instanceof Error ? exception.message : 'Chargement impossible.');
        }
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();

    return () => {
      cancelled = true;
    };
  }, [barcode]);

  const effectiveName = (product?.name ?? name).trim();

  /**
   * Demande au serveur de quel genre d'aliment il s'agit, dès que son nom est
   * connu — et à chaque fois que ce qui pourrait changer la réponse change.
   *
   * C'est cette réponse qui décide du formulaire. La poser après coup, comme le
   * faisait l'ancienne estimation de fraîcheur, revenait à demander « combien
   * d'exemplaires, et quel poids chacun ? » pour une courgette : deux questions
   * qui n'existent pas.
   */
  useEffect(() => {
    if (effectiveName.length < 3) {
      setProfile(null);
      return;
    }

    let cancelled = false;
    const timer = setTimeout(async () => {
      try {
        const found = await api.foodProfile({
          name: effectiveName,
          category: product?.category,
          storageLocation: location,
          traits,
          scanned: Boolean(product?.barcode),
        });
        if (cancelled) return;

        setProfile(found);
        // « Escalopes de poulet » nomme déjà sa forme, et ce foyer a peut-être
        // déjà dit la sienne : la puce suggérée arrive cochée — c'est celle que
        // le serveur enregistrerait. Un choix fait à la main tient, sauf s'il
        // n'a plus de sens pour l'aliment qu'on est en train de saisir.
        const suggested = found.suggestedCut ?? found.detectedCut;
        setCut((current) => {
          if (!cutTouched) return suggested;

          return null !== current && found.plausibleCuts.includes(current) ? current : suggested;
        });
      } catch {
        // Un profil indisponible ne bloque pas une saisie : on retombe sur le
        // formulaire du conditionné, qui est le comportement d'avant.
        if (!cancelled) setProfile(null);
      }
    }, 350);

    return () => {
      cancelled = true;
      clearTimeout(timer);
    };
  }, [effectiveName, product?.category, product?.barcode, location, traits, cutTouched]);

  const nature: FoodNature = natureOverride ?? profile?.nature ?? 'packaged';
  const isFresh = nature === 'fresh';

  // Le serveur dit lesquelles ont un sens pour CET aliment ; la référence donne
  // leurs libellés. Vide, aucune rubrique « Sous quelle forme ? » n'apparaît.
  const cutOptions = (reference?.productCuts ?? []).filter((option) =>
    (profile?.plausibleCuts ?? []).includes(option.value),
  );

  // Un aliment frais adopte l'unité que le lexique lui connaît, tant que personne
  // n'en a choisi une autre. Faute de lexique, les grammes restent le repli.
  useEffect(() => {
    if (!isFresh || unitTouched.current || !profile?.naturalUnit) return;
    setUnit(profile.naturalUnit);
  }, [isFresh, profile?.naturalUnit]);

  const counted = isFresh && isCountedUnit(unit);

  /**
   * Une date estimée, posée d'office et affichée.
   *
   * Un aliment frais ne porte aucune date imprimée. Tant qu'il fallait penser à
   * toucher « Pas de date : estimer », il entrait le plus souvent sans date : pas
   * d'alerte, pas de « Aujourd'hui », pas de priorité en cuisine. L'aliment qui
   * pourrit était exactement celui que l'application ne voyait pas.
   *
   * Elle n'écrase jamais une date choisie à la main — seulement une case vide ou
   * une estimation précédente, que le changement de rangement rend caduque.
   */
  useEffect(() => {
    if (!isFresh || !profile) return;

    const until = fromIsoDate(profile.shelfLife.until);
    setBatches((current) =>
      current.map((batch) =>
        batch.expiration === null || batch.estimate
          ? { ...batch, expiration: until, estimate: true }
          : batch,
      ),
    );
  }, [isFresh, profile?.shelfLife.until]);

  const total = useMemo(() => {
    const count = batches.reduce(
      (sum, batch) => sum + Math.max(1, Math.round(Number(batch.packages.replace(',', '.')) || 1)),
      0,
    );

    // Un aliment frais compté : chaque exemplaire vaut une pièce, le total EST
    // le compte. Sinon, c'est bien « exemplaires × contenance ».
    if (counted) return count;

    const per = Number(quantity.replace(',', '.')) || 0;
    return count * per;
  }, [batches, quantity, counted]);

  /** « courgettes », « portions » — ce que le compteur compte réellement. */
  const countedLabel = useMemo(() => {
    if (unit === 'portion') return 'portions';
    const label = (product?.name ?? name).trim().toLowerCase();
    return label.length > 0 ? label : 'unités';
  }, [unit, product?.name, name]);

  const unitLabel = reference?.units.find((option) => option.value === unit)?.label ?? unit;

  /** Les unités proposées au frais pesé : sa famille, et rien d'autre. */
  const freshWeighedUnits = useMemo(
    () =>
      (reference?.units ?? []).filter((option) =>
        unit === 'ml' || unit === 'cl' || unit === 'l'
          ? option.value === 'ml' || option.value === 'cl' || option.value === 'l'
          : option.value === 'g' || option.value === 'kg',
      ),
    [reference?.units, unit],
  );

  function updateBatch(id: number, changes: Partial<Batch>) {
    setBatches((current) =>
      current.map((batch) => (batch.id === id ? { ...batch, ...changes } : batch)),
    );
  }

  /** Applique au lot demandé l'estimation déjà connue du profil. */
  function estimateFor(batchId: number) {
    if (!profile) {
      setError('Indiquez d\'abord le nom de l\'aliment pour estimer sa fraîcheur.');
      return;
    }

    updateBatch(batchId, { estimate: true, expiration: fromIsoDate(profile.shelfLife.until) });
  }

  /** Repasse un aliment frais du compteur à la balance, et inversement. */
  function switchCounting(to: Unit) {
    unitTouched.current = true;
    setUnit(to);
    if (isCountedUnit(to)) setQuantity('');
  }

  /**
   * Vide ce qui décrit UN article, garde ce qui décrit la session.
   *
   * L'emplacement reste : on range en général un sac de courses au même endroit,
   * et le redemander à chaque article serait la question de trop.
   */
  function resetForNext() {
    setName('');
    setBrand('');
    setQuantity('');
    setTraits([]);
    setCut(null);
    setCutTouched(false);
    setNote('');
    setBatches([newBatch()]);
    setProfile(null);
    setNatureOverride(null);
    setProduct(null);
    unitTouched.current = false;
  }

  /**
   * @param andAnother Enchaîner un second article sans repasser par le stock.
   *   Rentrer six courses demandait six fois le parcours complet, navigation
   *   comprise, alors que le geste réel est de vider un sac de courses.
   */
  async function submit(andAnother = false) {
    setError(null);

    // Un aliment compté n'a rien à saisir de plus : chaque exemplaire vaut une pièce.
    const perPackage = counted ? 1 : Number(quantity.replace(',', '.'));

    if (!Number.isFinite(perPackage) || perPackage <= 0) {
      setError(
        isFresh
          ? 'Indiquez une quantité strictement positive.'
          : 'Indiquez une quantité par exemplaire strictement positive.',
      );
      return;
    }
    if (!product && !name.trim()) {
      setError('Indiquez au minimum un nom d\'aliment.');
      return;
    }

    setBusy(true);
    if (!operationKey.current) operationKey.current = generateOperationKey();

    try {
      await api.createStockItem(
        {
          productId: product?.id,
          barcode: product ? undefined : barcode,
          name: product ? undefined : name.trim(),
          brand: product ? undefined : brand.trim() || undefined,
          numberOfPackages: 1,
          quantityPerPackage: perPackage,
          unit,
          storageLocation: location,
          // Sans sélection, le serveur déduit l'état du libellé plutôt que de le vider.
          traits: traits.length > 0 ? traits : undefined,
          // Envoyée seulement si quelqu'un a corrigé la nature détectée : sinon
          // le serveur la déduit lui-même, et retient sa propre déduction.
          nature: natureOverride ?? undefined,
          // Même règle : omise, elle est déduite du libellé côté serveur.
          cut: cut ?? undefined,
          note: note.trim() || undefined,
          // Une ligne par date : le serveur crée les lots en une seule transaction.
          batches: batches.map((batch) => ({
            numberOfPackages: Math.max(1, Math.round(Number(batch.packages.replace(',', '.')) || 1)),
            expirationDate: batch.estimate || !batch.expiration ? null : toIsoDate(batch.expiration),
            estimateExpiration: batch.estimate,
          })),
        },
        operationKey.current,
      );
      operationKey.current = null;

      // L'écran était remplacé sans un mot : rien ne confirmait que le lot
      // existait, et l'on atterrissait à la racine des onglets.
      const added = product?.name ?? name.trim();
      haptics.success();
      notify(`${added} ajouté au stock`, 'safe');

      if (andAnother) resetForNext();
      else router.replace('/(tabs)');
    } catch (exception) {
      // Une panne de transport n'a rien pu écrire : la même clé sert au prochain essai.
      if (!(exception instanceof ApiError) || exception.status !== 0) {
        operationKey.current = null;
      }
      setError(exception instanceof Error ? exception.message : 'Ajout impossible.');
    } finally {
      setBusy(false);
    }
  }

  if (loading) return <Loader label="Recherche du produit" />;

  const compatibleUnits = reference?.units ?? [];

  return (
    // « Note » et le bouton d'envoi sont les deux derniers éléments de la page :
    // ouvrir le clavier sur la note masquait le bouton qui valide le formulaire.
    <KeyboardAvoidingView
      style={styles.flex}
      behavior={Platform.select({ ios: 'padding', default: undefined })}
    >
      <ScrollView contentContainerStyle={styles.container} keyboardShouldPersistTaps="handled">
      <ErrorBanner message={error} />

      {product ? (
        <Card style={styles.productCard}>
          <View style={styles.productRow}>
            {product.imageUrl ? (
              <Image source={{ uri: product.imageUrl }} style={styles.thumb} />
            ) : (
              <View style={[styles.thumb, styles.thumbEmpty]}>
                <Icon name={isFresh ? 'sprout-outline' : 'package-variant-closed'} size={24} />
              </View>
            )}
            <View style={styles.flex}>
              <Text style={text.heading}>{product.name}</Text>
              {product.brand ? <Text style={[text.small, styles.productBrand]}>{product.brand}</Text> : null}
              <Text style={[text.data, styles.productMeta]}>
                {product.packageQuantity
                  ? `${product.packageQuantity} ${product.packageUnit ?? ''} par exemplaire`
                  : 'Conditionnement non renseigné'}
              </Text>
              {product.category ? (
                <Text style={[text.small, styles.productMeta]}>{product.category}</Text>
              ) : null}
            </View>
          </View>
        </Card>
      ) : (
        <>
          {lookupMessage ? (
            <View style={styles.notice}>
              <Icon name="information-outline" size={18} color={thermal.soon.line} />
              <Text style={styles.noticeText}>{lookupMessage}</Text>
            </View>
          ) : null}
          <Field
            label="Nom de l'aliment"
            value={name}
            onChangeText={setName}
            placeholder="Courgettes, riz, reste de gratin…"
            // C'est le seul champ vraiment obligatoire, et l'on arrive ici pour
            // le remplir : deux appuis pour commencer à taper étaient deux de trop.
            autoFocus
            returnKeyType="next"
          />
          <Field label="Marque (facultatif)" value={brand} onChangeText={setBrand} />
        </>
      )}

      <SectionTitle>Combien en ajoutez-vous ?</SectionTitle>

      {counted ? (
        /* Le cas de la courgette : un seul chiffre, et rien d'autre. Un exemplaire
           vaut une pièce, donc le compteur du lot EST le nombre de courgettes. */
        <>
          {batches.length === 1 ? (
            <Stepper
              label="Quantité"
              value={Math.max(1, Math.round(Number(batches[0].packages) || 1))}
              unitLabel={countedLabel}
              onChange={(value) => updateBatch(batches[0].id, { packages: String(value) })}
            />
          ) : (
            /* Plusieurs dates : chaque lot porte sa propre quantité plus bas. Un
               compteur en tête ne piloterait que le premier, et mentirait sur le total. */
            <Text style={styles.help}>
              {formatNumber(total)} {countedLabel} au total, réparties par date ci-dessous.
            </Text>
          )}
          <Pressable
            onPress={() => switchCounting('g')}
            accessibilityRole="button"
            accessibilityLabel="Compter en grammes plutôt qu'à l'unité"
            style={styles.switchLink}
          >
            <Text style={styles.switchLinkText}>Je préfère le peser ›</Text>
          </Pressable>
        </>
      ) : isFresh ? (
        /* Frais mais pesé — 500 g de champignons, 1,5 kg de pommes de terre.
           Toujours une seule quantité : il n'y a pas d'emballage à décrire. */
        <>
          <Field
            label="Quantité"
            value={quantity}
            onChangeText={setQuantity}
            keyboardType="decimal-pad"
            placeholder="ex. 500"
          />
          <Text style={styles.fieldLabel}>Unité</Text>
          <View style={styles.chips}>
            {freshWeighedUnits.map((option) => (
              <Chip
                key={option.value}
                label={option.label}
                selected={unit === option.value}
                onPress={() => switchCounting(option.value as Unit)}
              />
            ))}
          </View>
          {profile?.naturalUnit && isCountedUnit(profile.naturalUnit) ? (
            <Pressable
              onPress={() => switchCounting(profile.naturalUnit as Unit)}
              accessibilityRole="button"
              accessibilityLabel="Compter à l'unité plutôt que peser"
              style={styles.switchLink}
            >
              <Text style={styles.switchLinkText}>Je préfère les compter ›</Text>
            </Pressable>
          ) : null}
        </>
      ) : (
        /* Produit conditionné : « exemplaires × contenance » décrit exactement ce
           qu'on rapporte des courses, et reste donc inchangé.

           « 500 » en gris se lisait comme une valeur déjà saisie : on appuyait sur
           « Ajouter au stock » et le formulaire refusait un champ qui semblait
           rempli. Un exemple explicite ne peut plus être confondu avec une saisie. */
        <>
          <Field
            label="Quantité par exemplaire"
            value={quantity}
            onChangeText={setQuantity}
            keyboardType="decimal-pad"
            placeholder="ex. 500"
            hint="Le contenu d'UN paquet, pas le total : 3 sachets de 500 g, c'est 500."
          />

          <Text style={styles.fieldLabel}>Unité</Text>
          <View style={styles.chips}>
            {compatibleUnits.map((option) => (
              <Chip
                key={option.value}
                label={option.label}
                selected={unit === option.value}
                onPress={() => switchCounting(option.value as Unit)}
              />
            ))}
          </View>
        </>
      )}

      {total > 0 && !counted ? (
        <Text style={styles.total}>
          Total ajouté au stock : {formatNumber(total)} {unitLabel}
        </Text>
      ) : null}

      <SectionTitle>Où est-il rangé ?</SectionTitle>
      <View style={styles.chips}>
        {reference?.storageLocations.map((option) => (
          <Chip
            key={option.value}
            label={option.label}
            selected={location === option.value}
            onPress={() => setLocation(option.value)}
          />
        ))}
      </View>

      <SectionTitle>{isFresh ? 'À consommer avant' : 'Dates de péremption'}</SectionTitle>
      <Text style={styles.help}>
        {isFresh
          ? 'Un produit frais ne porte pas de date : celle-ci est estimée, et signalée comme telle dans votre stock. Corrigez-la si vous en savez plus.'
          : 'Une ligne par date. Trois yaourts pour le 27 et deux pour le 3 août font deux lots : c\'est ce qui permet de consommer le plus urgent en premier.'}
      </Text>

      {batches.map((batch, position) => (
        <Card key={batch.id} style={styles.batchCard}>
          {batches.length > 1 ? (
            <View style={styles.batchHeader}>
              <Text style={styles.batchTitle}>Lot {position + 1}</Text>
              <Pressable
                onPress={() => setBatches((current) => current.filter((entry) => entry.id !== batch.id))}
                accessibilityRole="button"
                accessibilityLabel={`Retirer le lot ${position + 1}`}
              >
                <Text style={styles.clearDateText}>Retirer</Text>
              </Pressable>
            </View>
          ) : null}

          <View style={styles.pair}>
            {/* Le nombre d'exemplaires est déjà porté par le compteur du haut pour
                un aliment frais compté : le redemander ici serait la question de
                trop, celle-là même qu'on a retirée. */}
            {counted && batches.length === 1 ? null : (
              <Field
                label={counted ? 'Quantité' : 'Exemplaires'}
                value={batch.packages}
                onChangeText={(value) => updateBatch(batch.id, { packages: value })}
                keyboardType="number-pad"
                containerStyle={styles.pairInput}
              />
            )}
            <View style={styles.pairInput}>
              <Text style={styles.fieldLabel}>À consommer avant</Text>
              <Pressable
                style={styles.dateButton}
                onPress={() => setPickerFor(batch.id)}
                accessibilityRole="button"
                accessibilityLabel={
                  batch.expiration
                    ? `Lot ${position + 1}, à consommer avant le ${formatDate(batch.expiration)}`
                    : `Lot ${position + 1}, aucune date de péremption`
                }
                accessibilityHint="Ouvre le sélecteur de date"
              >
                <Text style={styles.dateText}>
                  {batch.expiration ? formatDate(batch.expiration) : 'Aucune date'}
                </Text>
              </Pressable>
            </View>
          </View>

          <View style={styles.chips}>
            {[
              { label: 'Dans 3 jours', days: 3 },
              { label: 'Dans 1 semaine', days: 7 },
              { label: 'Dans 1 mois', days: 30 },
            ].map((shortcut) => (
              <Chip
                key={shortcut.days}
                label={shortcut.label}
                selected={false}
                onPress={() =>
                  updateBatch(batch.id, { expiration: addDays(shortcut.days), estimate: false })
                }
              />
            ))}
            {/* Le conditionné n'est pas daté d'office : sa date est imprimée, et
                l'estimer à sa place l'écraserait par une valeur moins fiable. */}
            {!isFresh ? (
              <Chip
                label="Pas de date : estimer"
                selected={batch.estimate}
                onPress={() => estimateFor(batch.id)}
              />
            ) : null}
            {batch.expiration ? (
              <Chip
                label="Effacer"
                selected={false}
                onPress={() => updateBatch(batch.id, { expiration: null, estimate: false })}
              />
            ) : null}
          </View>

          {batch.estimate && profile ? (
            <Text style={styles.estimateHint}>
              Estimation, pas une date lue : {profile.shelfLife.explanation} Elle sera
              signalée comme telle dans votre stock.
            </Text>
          ) : null}
        </Card>
      ))}

      <Button
        label="Ajouter une autre date"
        variant="secondary"
        onPress={() =>
          setBatches((current) => [
            ...current,
            // Le nouveau lot hérite de l'estimation en cours : deux achats du même
            // aliment le même jour se conservent aussi longtemps l'un que l'autre.
            isFresh && profile
              ? { ...newBatch(), expiration: fromIsoDate(profile.shelfLife.until), estimate: true }
              : newBatch(),
          ])
        }
      />

      {pickerFor !== null ? (
        <DateTimePicker
          value={batches.find((batch) => batch.id === pickerFor)?.expiration ?? addDays(7)}
          mode="date"
          onChange={(event, selected) => {
            const target = pickerFor;
            setPickerFor(null);
            if (event.type === 'set' && selected && target !== null) {
              updateBatch(target, { expiration: selected, estimate: false });
            }
          }}
        />
      ) : null}

      <SectionTitle>Dans quel état est-il ?</SectionTitle>
      <Text style={styles.help}>
        Ce que les recettes doivent savoir : des pommes de terre en conserve ne s'épluchent
        pas, un légume surgelé se décongèle.
      </Text>

      {/* La nature est déduite, et se corrige d'un appui. Elle décide du formulaire
          ci-dessus, mais aussi du rang de l'aliment quand on demande une recette :
          un légume frais passe devant une conserve. */}
      <View style={styles.chips}>
        {(['fresh', 'packaged'] as const).map((option) => (
          <Chip
            key={option}
            label={option === 'fresh' ? 'Produit frais' : 'Produit conditionné'}
            selected={nature === option}
            onPress={() => setNatureOverride(option)}
          />
        ))}
      </View>

      <View style={styles.chips}>
        {reference?.productTraits.map((option) => (
          <Chip
            key={option.value}
            label={option.label}
            selected={traits.includes(option.value)}
            onPress={() =>
              setTraits((current) =>
                current.includes(option.value)
                  ? current.filter((trait) => trait !== option.value)
                  : [...current, option.value],
              )
            }
          />
        ))}
      </View>

      {/* Posée uniquement quand la réponse change une recette : un blanc de poulet
          a quatre formes possibles, une farine n'en a aucune. Le serveur décide de
          la liste (`plausibleCuts`), l'écran n'affiche rien s'il la renvoie vide. */}
      {cutOptions.length > 0 ? (
        <>
          <SectionTitle>Sous quelle forme ?</SectionTitle>
          <Text style={styles.help}>
            C'est elle qui décide de la cuisson : une escalope se saisit trois minutes par
            face, de l'émincé saute deux minutes à feu vif.
          </Text>
          <View style={styles.chips}>
            {cutOptions.map((option) => (
              <Chip
                key={option.value}
                label={option.label}
                selected={cut === option.value}
                onPress={() => {
                  setCutTouched(true);
                  setCut(cut === option.value ? null : option.value);
                }}
              />
            ))}
          </View>
        </>
      ) : null}

      <Field
        label="Note (facultatif)"
        value={note}
        onChangeText={setNote}
        placeholder="Reste du repas de dimanche…"
      />

      <Button label="Ajouter au stock" onPress={() => submit()} loading={busy} disabled={busy} />
      <Button
        label="Ajouter et en saisir un autre"
        variant="secondary"
        icon="playlist-plus"
        onPress={() => submit(true)}
        disabled={busy}
        hint="Garde l'emplacement et rouvre un formulaire vierge"
        style={styles.submitAgain}
      />
      </ScrollView>
    </KeyboardAvoidingView>
  );
}

function addDays(days: number): Date {
  const date = new Date();
  date.setDate(date.getDate() + days);
  return date;
}

function toIsoDate(date: Date): string {
  const month = String(date.getMonth() + 1).padStart(2, '0');
  const day = String(date.getDate()).padStart(2, '0');
  return `${date.getFullYear()}-${month}-${day}`;
}

/** « 2026-08-02 » interprété localement : `new Date(iso)` serait en UTC, donc décalé d'un jour. */
function fromIsoDate(value: string): Date {
  const [year, month, day] = value.split('-').map(Number);
  return new Date(year, month - 1, day);
}

function formatDate(date: Date): string {
  return date.toLocaleDateString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' });
}

function formatNumber(value: number): string {
  return Number.isInteger(value) ? String(value) : value.toFixed(2).replace(/\.?0+$/, '');
}

const styles = StyleSheet.create({
  container: { padding: spacing.xl, paddingBottom: spacing.xxl * 2 },
  flex: { flex: 1 },
  submitAgain: { marginTop: spacing.sm },
  productCard: { marginBottom: spacing.lg },
  productRow: { flexDirection: 'row', gap: spacing.md },
  thumb: { width: 64, height: 64, borderRadius: radius.md, backgroundColor: colors.surfaceSunk },
  thumbEmpty: { alignItems: 'center', justifyContent: 'center' },
  productBrand: { marginTop: 1 },
  productMeta: { marginTop: spacing.xs },
  notice: {
    flexDirection: 'row',
    alignItems: 'flex-start',
    gap: spacing.sm,
    backgroundColor: thermal.soon.wash,
    borderRadius: radius.md,
    padding: spacing.md,
    marginBottom: spacing.lg,
  },
  noticeText: { ...text.small, flex: 1, color: thermal.soon.line },
  pair: { flexDirection: 'row', gap: spacing.md },
  pairInput: { flex: 1 },
  fieldLabel: { ...text.label, marginBottom: spacing.sm },
  chips: { flexDirection: 'row', flexWrap: 'wrap' },
  help: { ...text.small, marginBottom: spacing.md },
  switchLink: { alignSelf: 'flex-end', paddingVertical: spacing.sm, marginBottom: spacing.md },
  switchLinkText: { ...text.small, color: colors.ink, fontFamily: font.sansMedium },
  /** Le total est une donnée calculée : c'est la voix machine qui la porte. */
  total: {
    fontFamily: font.monoMedium,
    fontSize: 13,
    lineHeight: 18,
    color: colors.ink,
    marginTop: spacing.xs,
    marginBottom: spacing.sm,
  },
  batchCard: { marginBottom: spacing.md },
  batchHeader: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    marginBottom: spacing.md,
  },
  batchTitle: text.label,
  estimateHint: { ...text.small, color: thermal.soon.line, marginTop: spacing.sm },
  dateButton: {
    backgroundColor: colors.surface,
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: radius.md,
    paddingHorizontal: spacing.md,
    justifyContent: 'center',
    minHeight: 48,
  },
  dateText: text.body,
  clearDateText: { ...text.small, color: thermal.critical.line, fontFamily: font.sansMedium },
});
