import DateTimePicker from '@react-native-community/datetimepicker';
import { useLocalSearchParams, useRouter } from 'expo-router';
import React, { useCallback, useEffect, useRef, useState } from 'react';
import { Alert, Pressable, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';

import { ApiError, api, generateOperationKey } from '../../src/api/client';
import type { Reference, StockItem, StockMovement } from '../../src/api/types';

type ReferenceUnit = Reference['units'][number];
import {
  Badge,
  Button,
  Card,
  Chip,
  ErrorBanner,
  Field,
  Loader,
  SectionTitle,
  Stamp,
  StampHero,
  formatDisplayDate,
  toIsoDate,
} from '../../src/components/ui';
import { deadlineOf, deadlineOfSibling } from '../../src/deadline';
import { colors, font, radius, spacing, statusPresentation, text, thermal, thermalFor } from '../../src/theme';

export default function StockItemScreen() {
  const router = useRouter();
  const { id } = useLocalSearchParams<{ id: string }>();

  const [item, setItem] = useState<StockItem | null>(null);
  const [movements, setMovements] = useState<StockMovement[]>([]);
  const [reference, setReference] = useState<Reference | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [amount, setAmount] = useState('');
  const [editing, setEditing] = useState(false);
  const [editName, setEditName] = useState('');
  const [showPicker, setShowPicker] = useState(false);
  /** Date d'achat facultative pour « Ajouter une quantité » : `null` affirme implicitement le même achat. */
  const [addPurchaseDate, setAddPurchaseDate] = useState<string | null>(null);
  const [showAddDatePicker, setShowAddDatePicker] = useState(false);
  /** Unité choisie mais pas encore appliquée : elle attend une quantité. */
  const [pendingUnit, setPendingUnit] = useState<ReferenceUnit | null>(null);
  const [pendingQuantity, setPendingQuantity] = useState('');

  /**
   * Une clé par geste, conservée tant qu'une coupure réseau peut le faire
   * rejouer : le bouton pressé deux fois de suite après une panne de réseau
   * ne doit pas déduire deux fois. Elle n'est libérée qu'au succès, ou après
   * un échec dont on sait qu'il n'a rien écrit (le serveur a répondu).
   */
  const operationKeys = useRef(new Map<string, string>());

  function operationKeyFor(actionId: string): string {
    const existing = operationKeys.current.get(actionId);
    if (existing) return existing;

    const key = generateOperationKey();
    operationKeys.current.set(actionId, key);
    return key;
  }

  const load = useCallback(async () => {
    try {
      const [detail, history, ref] = await Promise.all([
        api.stockItem(id),
        api.movements(id),
        api.reference(),
      ]);
      setItem(detail);
      setEditName(detail.variant.name);
      setMovements(history.movements);
      setReference(ref);
    } catch (exception) {
      setError(exception instanceof Error ? exception.message : 'Chargement impossible.');
    }
  }, [id]);

  useEffect(() => {
    load();
  }, [load]);

  /**
   * Enveloppe commune : porte la clé d'opération du geste, exécute l'action,
   * rafraîchit, remonte l'erreur métier.
   *
   * Un 409 signifie que le lot a changé entre la lecture et l'écriture — une
   * correction fondée sur une révision périmée, ou un verrou qui a fait
   * attendre puis a découvert un état différent. On recharge le lot avant de
   * dire pourquoi, plutôt que de laisser l'écran mentir sur ce qu'il contient.
   */
  async function run(actionId: string, action: (operationKey: string) => Promise<unknown>) {
    setError(null);
    setBusy(true);
    const operationKey = operationKeyFor(actionId);

    try {
      await action(operationKey);
      operationKeys.current.delete(actionId);
      await load();
      setAmount('');
      setAddPurchaseDate(null);
    } catch (exception) {
      // Une panne de transport (status 0) n'a rien pu écrire : la même clé sert
      // au prochain essai. Toute autre réponse est déjà tranchée par le serveur.
      if (!(exception instanceof ApiError) || exception.status !== 0) {
        operationKeys.current.delete(actionId);
      }

      if (exception instanceof ApiError && exception.status === 409) {
        await load();
        setError('Ce lot a été modifié entre-temps : vérifiez son état avant de recommencer.');
      } else {
        setError(exception instanceof Error ? exception.message : 'Action impossible.');
      }
    } finally {
      setBusy(false);
    }
  }

  function parsedAmount(): number | null {
    const value = Number(amount.replace(',', '.'));
    return Number.isFinite(value) && value > 0 ? value : null;
  }

  /**
   * Archiver, pas supprimer — ni jeter.
   *
   * Le `DELETE` physique effaçait les mouvements en cascade : l'historique
   * disparaissait précisément dans le cas où il aurait servi — « qui a retiré ce
   * lot, et que restait-il dessus ? ». L'archivage a ensuite mis la quantité à
   * zéro, ce qui revenait à jeter ce qu'on prétendait ranger. Il ne touche plus
   * à rien : « Jeter le produit », juste au-dessus, est le geste qui déclare une
   * perte, et c'est le seul.
   */
  function confirmArchive() {
    Alert.alert(
      'Archiver cet aliment ?',
      'Il quitte votre inventaire sans rien perdre : sa quantité et son historique sont conservés, et vous pourrez le restaurer depuis le filtre « Archives ».',
      [
        { text: 'Annuler', style: 'cancel' },
        {
          text: 'Archiver',
          style: 'destructive',
          onPress: () =>
            run('archive', async (operationKey) => {
              await api.archiveStockItem(id, operationKey, 'Archivé depuis la fiche');
              router.back();
            }),
        },
      ],
    );
  }

  if (!item) {
    return error ? (
      <View style={styles.container}>
        <ErrorBanner message={error} />
      </View>
    ) : (
      <Loader label="Chargement de la fiche" />
    );
  }

  const presentation = statusPresentation[item.status] ?? statusPresentation.sealed;
  const deadline = deadlineOf(item);
  const level = thermalFor(deadline.days);

  /**
   * Un produit frais n'a pas d'emballage, donc pas d'exemplaire à entamer ni à
   * refermer. Tout le vocabulaire du conditionnement disparaît pour lui : parler
   * d'« exemplaire entamé » à propos d'une courgette ne décrit rien de réel, et
   * proposer de la « refermer » propose un geste qui n'existe pas.
   *
   * Le modèle de stock, lui, ne change pas : une courgette est un exemplaire
   * d'une pièce, ce qui rend l'état « entamé » naturellement inatteignable pour
   * un aliment compté (voir `add.tsx`).
   */
  const isFresh = item.variant.nature === 'fresh';

  // Deux unités d'une même famille se convertissent ; entre familles, il faut redonner la quantité.
  const currentUnitFamily = reference?.units.find((option) => option.value === item.unit)?.family;
  const traitLabels = (reference?.productTraits ?? [])
    .filter((option) => item.variant.traits.includes(option.value))
    .map((option) => option.label);

  const facts = [
    item.storageLocationLabel,
    item.addedBy ? `enregistré par ${item.addedBy}` : null,
    ...traitLabels,
  ].filter(Boolean);

  return (
    <ScrollView contentContainerStyle={styles.container} keyboardShouldPersistTaps="handled">
      <ErrorBanner message={error} />

      <Card level={level}>
        <View style={styles.headerRow}>
          <View style={styles.flex}>
            <Text style={text.title}>{item.variant.name}</Text>
            {item.variant.brand ? (
              <Text style={[text.small, styles.brand]}>{item.variant.brand}</Text>
            ) : null}
          </View>
          <Badge label={presentation.label} level={presentation.level} />
        </View>

        {/* Deux mesures du même lot, côte à côte : ce qu'il en reste, et ce
            qu'il reste de temps pour l'utiliser. */}
        <View style={styles.gauges}>
          <View style={styles.gauge}>
            <Text style={styles.gaugeValue}>
              {item.quantityIsEstimated ? '~' : ''}
              {item.displayQuantity} {item.displayUnit}
            </Text>
            {/* Même voix que la légende du cachet, en face : deux mesures
                lues côte à côte ne doivent pas parler deux langues. */}
            <Text style={[text.small, styles.gaugeCaption]}>
              {item.quantityIsEstimated ? 'Quantité estimée' : 'Disponible'}
            </Text>
          </View>
          <View style={styles.gaugeRule} />
          <View style={styles.gauge}>
            <StampHero days={deadline.days} date={deadline.date} estimated={deadline.estimated} />
          </View>
        </View>

        <Text style={[text.small, styles.facts]}>{facts.join(' · ')}</Text>
        <Text style={[text.small, styles.facts]}>{effectiveUseByText(item)}</Text>
        {item.note ? <Text style={[text.body, styles.note]}>{item.note}</Text> : null}
      </Card>

      {/* Un lot n'est pas un tas : trois sachets de 500 g dont un entamé ne
          se résument pas à « 1,25 kg ». Voir quel exemplaire est ouvert évite
          d'en déballer un second alors qu'il en reste dans le premier. */}
      {item.packages.length > 0 && !isFresh ? (
        <>
          <SectionTitle>Exemplaires</SectionTitle>
          <View style={styles.packages}>
            {item.packages.map((entry) => (
              <View
                key={`package-${entry.position}`}
                style={[styles.package, entry.opened && styles.packageOpened]}
              >
                <Text style={[text.label, entry.opened && styles.packageStateOpened]}>
                  {entry.opened ? 'Entamé' : 'Fermé'}
                </Text>
                <Text style={styles.packageQuantity}>
                  {formatNumber(entry.remaining)} {item.unitLabel}
                </Text>
              </View>
            ))}
          </View>
          <Text style={[text.small, styles.help]}>{packagingHint(item)}</Text>
        </>
      ) : null}

      {/* Les autres lots du même aliment : c'est ce qui permet de choisir le bon
          plutôt que de gonfler celui-ci avec une date qui ne lui correspond pas. */}
      {item.siblings && item.siblings.length > 0 ? (
        <>
          <SectionTitle>Autres lots de cet aliment</SectionTitle>
          <Text style={[text.small, styles.help]}>
            Le lot qui périme le plus tôt est consommé en premier. Pour un achat avec une autre
            date, ajoutez un lot plutôt que d'augmenter celui-ci.
          </Text>
          {item.siblings.map((sibling) => {
            const siblingDeadline = deadlineOfSibling(sibling);

            return (
              <Pressable
                key={sibling.id}
                style={({ pressed }) => [styles.sibling, pressed && styles.siblingPressed]}
                onPress={() => router.push(`/item/${sibling.id}`)}
                accessibilityRole="button"
                accessibilityLabel={`Autre lot : ${sibling.displayQuantity} ${sibling.displayUnit}, ${
                  sibling.expirationDate
                    ? `${sibling.expirationIsEstimated ? 'estimé' : 'à consommer avant'} le ${formatDisplayDate(sibling.expirationDate)}`
                    : 'sans date'
                }, ${sibling.storageLocationLabel}`}
                accessibilityHint="Ouvre la fiche de ce lot"
              >
                <View style={styles.flex}>
                  <Text style={styles.siblingQuantity}>
                    {sibling.displayQuantity} {sibling.displayUnit}
                  </Text>
                  <Text style={[text.small, styles.siblingMeta]}>
                    {sibling.expirationDate
                      ? `${sibling.expirationIsEstimated ? 'estimé' : 'avant'} le ${formatDisplayDate(sibling.expirationDate)}`
                      : 'sans date'}{' '}
                    · {sibling.storageLocationLabel}
                  </Text>
                </View>
                <Stamp
                  days={siblingDeadline.days}
                  date={siblingDeadline.date}
                  estimated={siblingDeadline.estimated}
                  decorative
                />
              </Pressable>
            );
          })}
        </>
      ) : null}

      <SectionTitle>Consommer</SectionTitle>

      {item.isDiscreteUnit ? (
        <View style={styles.unitStepper}>
          <Pressable
            style={({ pressed }) => [styles.unitButton, pressed && styles.unitButtonPressed]}
            onPress={() =>
              run('consume-units', (operationKey) => api.consume(id, { mode: 'units', delta: -1 }, operationKey))
            }
            disabled={busy}
            accessibilityRole="button"
            accessibilityLabel={`Consommer un ${item.unitLabel}`}
            accessibilityState={{ disabled: busy }}
          >
            <Text style={styles.unitGlyph} importantForAccessibility="no">
              −1
            </Text>
          </Pressable>
          <Text
            style={styles.unitValue}
            accessibilityLabel={`Il reste ${item.remainingQuantity} ${item.unitLabel}`}
          >
            {item.remainingQuantity} {item.unitLabel}
          </Text>
          <Pressable
            style={({ pressed }) => [styles.unitButton, pressed && styles.unitButtonPressed]}
            onPress={() =>
              run('add-unit', (operationKey) => api.addQuantity(id, { quantity: 1 }, operationKey))
            }
            disabled={busy}
            accessibilityRole="button"
            accessibilityLabel={`Ajouter un ${item.unitLabel}`}
            accessibilityState={{ disabled: busy }}
          >
            <Text style={styles.unitGlyph} importantForAccessibility="no">
              +1
            </Text>
          </Pressable>
        </View>
      ) : null}

      <View style={styles.amountRow}>
        <TextInput
          value={amount}
          onChangeText={setAmount}
          keyboardType="decimal-pad"
          placeholder={`Quantité en ${item.unitLabel}`}
          placeholderTextColor={colors.inkMuted}
          accessibilityLabel={`Quantité en ${item.unitLabel}`}
          style={styles.amountInput}
        />
        <Button
          label="Consommer"
          onPress={() => {
            const value = parsedAmount();
            if (value === null) {
              setError('Indiquez une quantité valide.');
              return;
            }
            run('consume-quantity', (operationKey) =>
              api.consume(id, { mode: 'quantity', quantity: value }, operationKey),
            );
          }}
          disabled={busy}
        />
      </View>

      <View style={styles.actionGrid}>
        <Button
          label="Tout consommer"
          variant="secondary"
          onPress={() =>
            run('consume-all', (operationKey) => api.consume(id, { mode: 'all' }, operationKey))
          }
          disabled={busy}
        />
        {/* Un produit frais compté se retire déjà à l'unité par le −1 ci-dessus :
            « retirer un exemplaire » ferait deux boutons pour un seul geste. */}
        {isFresh && item.isDiscreteUnit ? null : (
          <Button
            label={isFresh ? 'Retirer une portion' : 'Retirer un exemplaire'}
            variant="secondary"
            onPress={() =>
              run('consume-package', (operationKey) => api.consume(id, { mode: 'package' }, operationKey))
            }
            disabled={busy}
          />
        )}
        {/* Le geste porte sur UN exemplaire : ouvrir un lot de trois sachets
            n'en entame qu'un, les deux autres restent scellés. Sans objet sur un
            produit frais, qui n'a pas d'emballage. */}
        {isFresh ? null : (
          <Button
            label={item.isOpened ? 'Refermer l\'exemplaire entamé' : 'Entamer un exemplaire'}
            variant="secondary"
            onPress={() =>
              run('toggle-opened', (operationKey) => api.setOpened(id, !item.isOpened, operationKey))
            }
            disabled={busy}
          />
        )}
        <Button
          label="Ajouter une quantité"
          variant="secondary"
          onPress={() => {
            const value = parsedAmount();
            if (value === null) {
              setError('Saisissez d\'abord une quantité dans le champ ci-dessus.');
              return;
            }
            run('add-quantity', (operationKey) =>
              api.addQuantity(
                id,
                { quantity: value, purchaseDate: addPurchaseDate ?? undefined },
                operationKey,
              ),
            );
          }}
          disabled={busy}
        />
      </View>

      {/* Distinct de « Refermer », juste au-dessus : celui-ci garde la trace de
          l'exemplaire entamé, ce geste-ci l'efface — réservé à une vraie erreur
          de saisie, pas à un simple couvercle remis en place. */}
      {item.isOpened && !isFresh ? (
        <Button
          label="Corriger : annuler l'ouverture"
          variant="ghost"
          onPress={() =>
            Alert.alert(
              'Annuler l\'ouverture ?',
              'À utiliser seulement si ce lot n\'a jamais été réellement entamé — une erreur de saisie. Pour simplement remettre le couvercle, utilisez plutôt « Refermer l\'exemplaire entamé ».',
              [
                { text: 'Annuler', style: 'cancel' },
                {
                  text: 'Confirmer',
                  style: 'destructive',
                  onPress: () =>
                    run('cancel-opening', (operationKey) => api.cancelOpening(id, operationKey)),
                },
              ],
            )
          }
          disabled={busy}
          style={styles.spaced}
        />
      ) : null}

      {/* Un lot est homogène : un ajout dont la date d'achat diffère de celle
          déjà connue est refusé plutôt que mélangé — c'est ce champ qui permet
          de le déclarer, et donc au serveur de le vérifier. */}
      <Pressable
        onPress={() => setShowAddDatePicker(true)}
        accessibilityRole="button"
        accessibilityLabel={
          addPurchaseDate
            ? `Date d'achat de cet ajout : ${formatDisplayDate(addPurchaseDate)}`
            : 'Préciser la date d\'achat de cet ajout'
        }
        style={styles.addDateTrigger}
      >
        <Text style={text.small}>
          {addPurchaseDate
            ? `Date d'achat de cet ajout : ${formatDisplayDate(addPurchaseDate)}`
            : 'Cet ajout vient d\'un autre achat ? Préciser sa date'}
        </Text>
      </Pressable>
      {addPurchaseDate ? (
        <Pressable
          onPress={() => setAddPurchaseDate(null)}
          accessibilityRole="button"
          accessibilityLabel="Retirer cette date d'achat"
        >
          <Text style={styles.clearText}>Retirer cette date</Text>
        </Pressable>
      ) : null}
      {showAddDatePicker ? (
        <DateTimePicker
          value={addPurchaseDate ? new Date(addPurchaseDate) : new Date()}
          mode="date"
          onChange={(event, selected) => {
            setShowAddDatePicker(false);
            if (event.type === 'set' && selected) {
              setAddPurchaseDate(toIsoDate(selected));
            }
          }}
        />
      ) : null}

      <SectionTitle>Corriger la quantité</SectionTitle>
      <Text style={[text.small, styles.help]}>
        Vous ne savez pas exactement ce qu'il reste ? Choisissez une estimation.
      </Text>
      <View style={styles.chips}>
        {reference?.fillLevels.map((fillLevel) => (
          <Chip
            key={fillLevel.value}
            label={fillLevel.label}
            selected={false}
            onPress={() =>
              run(`correct-fill-${fillLevel.value}`, (operationKey) =>
                api.correct(id, { fillLevel: fillLevel.value }, operationKey, item.revision),
              )
            }
          />
        ))}
      </View>
      <Button
        label="Corriger avec la quantité saisie"
        variant="secondary"
        onPress={() => {
          const value = parsedAmount();
          if (value === null) {
            setError('Saisissez la quantité exacte dans le champ « Consommer » ci-dessus.');
            return;
          }
          run('correct-quantity', (operationKey) =>
            api.correct(id, { quantity: value }, operationKey, item.revision),
          );
        }}
        disabled={busy}
      />

      <SectionTitle>Modifier</SectionTitle>
      {editing ? (
        <Card>
          <Field
            label="Nom dans mon foyer"
            value={editName}
            onChangeText={setEditName}
            hint={
              item.variant.catalogName && item.variant.catalogName !== item.variant.name
                ? `Fiche partagée : « ${item.variant.catalogName} ». La renommer ici ne change rien pour les autres foyers.`
                : 'Ce nom n\'existe que dans votre foyer.'
            }
          />

          <Text style={[text.label, styles.editLabel]}>Emplacement</Text>
          <View style={styles.chips}>
            {reference?.storageLocations.map((option) => (
              <Chip
                key={option.value}
                label={option.label}
                selected={item.storageLocation === option.value}
                onPress={() =>
                  run(`location-${option.value}`, (operationKey) =>
                    api.updateStockItem(id, { storageLocation: option.value }, operationKey),
                  )
                }
              />
            ))}
          </View>

          <Text style={[text.label, styles.editLabel]}>Unité</Text>
          <Text style={[text.small, styles.help]}>
            Une unité choisie par erreur se répare ici. Passer d'une masse à des unités n'a pas
            d'équivalence : la quantité restante doit alors être redonnée.
          </Text>
          <View style={styles.chips}>
            {reference?.units.map((option) => (
              <Chip
                key={option.value}
                label={option.label}
                selected={item.unit === option.value}
                onPress={() => {
                  if (item.unit === option.value) return;

                  if (option.family === currentUnitFamily) {
                    setPendingUnit(null);
                    run(`unit-${option.value}`, (operationKey) =>
                      api.changeUnit(id, option.value as StockItem['unit'], undefined, operationKey, item.revision),
                    );
                    return;
                  }

                  setPendingUnit(option);
                  setPendingQuantity(String(item.remainingQuantity));
                }}
              />
            ))}
          </View>

          {pendingUnit ? (
            <View style={styles.pendingUnit}>
              <Field
                label={`Quantité restante en ${pendingUnit.label}`}
                value={pendingQuantity}
                onChangeText={setPendingQuantity}
                keyboardType="decimal-pad"
                hint={`Combien reste-t-il réellement, exprimé en ${pendingUnit.label} ?`}
              />
              <View style={styles.pendingRow}>
                <Button
                  label={`Passer en ${pendingUnit.label}`}
                  onPress={() => {
                    const value = Number(pendingQuantity.replace(',', '.'));
                    if (!Number.isFinite(value) || value < 0) {
                      setError('Indiquez une quantité valide.');
                      return;
                    }
                    const unit = pendingUnit.value as StockItem['unit'];
                    setPendingUnit(null);
                    run('unit-pending', (operationKey) =>
                      api.changeUnit(id, unit, value, operationKey, item.revision),
                    );
                  }}
                  disabled={busy}
                  style={styles.flex}
                />
                <Button label="Annuler" variant="ghost" onPress={() => setPendingUnit(null)} />
              </View>
            </View>
          ) : null}

          {/* La nature décide de ce que cette fiche montre — exemplaires ou non —
              et du rang de l'aliment quand on demande une recette : un légume frais
              passe devant une conserve. La corriger ici la fixe définitivement. */}
          <Text style={[text.label, styles.editLabel]}>Nature de l'aliment</Text>
          <Text style={[text.small, styles.help]}>
            Un produit frais se dégrade dès l'achat et n'a pas d'emballage à entamer ; les recettes
            le proposent en priorité.
          </Text>
          <View style={styles.chips}>
            {(['fresh', 'packaged'] as const).map((option) => (
              <Chip
                key={option}
                label={option === 'fresh' ? 'Produit frais' : 'Produit conditionné'}
                selected={item.variant.nature === option}
                onPress={() =>
                  run(`nature-${option}`, (operationKey) =>
                    api.updateStockItem(id, { nature: option }, operationKey),
                  )
                }
              />
            ))}
          </View>

          <Text style={[text.label, styles.editLabel]}>État du produit</Text>
          <Text style={[text.small, styles.help]}>
            Ce que les recettes doivent savoir : une conserve ne s'épluche pas, un surgelé se
            décongèle.
          </Text>
          <View style={styles.chips}>
            {reference?.productTraits.map((option) => {
              const selected = item.variant.traits.includes(option.value);

              return (
                <Chip
                  key={option.value}
                  label={option.label}
                  selected={selected}
                  onPress={() =>
                    run(`trait-${option.value}`, (operationKey) =>
                      api.updateStockItem(
                        id,
                        {
                          traits: selected
                            ? item.variant.traits.filter((trait) => trait !== option.value)
                            : [...item.variant.traits, option.value],
                        },
                        operationKey,
                      ),
                    )
                  }
                />
              );
            })}
          </View>

          <Text style={[text.label, styles.editLabel]}>Date de péremption</Text>
          <View style={styles.dateRow}>
            <Pressable
              style={({ pressed }) => [styles.dateButton, pressed && styles.dateButtonPressed]}
              onPress={() => setShowPicker(true)}
              accessibilityRole="button"
              accessibilityLabel={
                item.expirationDate
                  ? `Date de péremption : ${formatDisplayDate(item.expirationDate)}`
                  : 'Aucune date de péremption'
              }
              accessibilityHint="Ouvre le sélecteur de date"
            >
              <Text style={text.body}>
                {item.expirationDate ? formatDisplayDate(item.expirationDate) : 'Aucune date'}
              </Text>
            </Pressable>
            {item.expirationDate ? (
              <Pressable
                onPress={() =>
                  run('clear-expiration', (operationKey) =>
                    api.updateStockItem(id, { expirationDate: null }, operationKey),
                  )
                }
                accessibilityRole="button"
                accessibilityLabel="Effacer la date de péremption"
              >
                <Text style={styles.clearText}>Effacer</Text>
              </Pressable>
            ) : null}
          </View>
          {/* Fruits, légumes, restes : rien n'est imprimé. La date est alors déduite
              de l'aliment, de son état et de l'endroit où il est rangé — et signalée
              comme estimée. */}
          <Button
            label="Pas de date imprimée : estimer la fraîcheur"
            variant="secondary"
            onPress={() =>
              run('estimate-expiration', (operationKey) =>
                api.updateStockItem(id, { estimateExpiration: true }, operationKey),
              )
            }
            disabled={busy}
            style={styles.spaced}
          />

          {showPicker ? (
            <DateTimePicker
              value={item.expirationDate ? new Date(item.expirationDate) : new Date()}
              mode="date"
              onChange={(event, selected) => {
                setShowPicker(false);
                if (event.type === 'set' && selected) {
                  run('set-expiration', (operationKey) =>
                    api.updateStockItem(id, { expirationDate: toIsoDate(selected) }, operationKey),
                  );
                }
              }}
            />
          ) : null}

          <Button
            label="Enregistrer le nom"
            onPress={() =>
              run('rename', (operationKey) => api.updateStockItem(id, { name: editName }, operationKey))
            }
            disabled={busy}
            style={styles.spaced}
          />
          <Button label="Fermer" variant="ghost" onPress={() => setEditing(false)} style={styles.spaced} />
        </Card>
      ) : (
        <Button
          label="Modifier cet aliment"
          variant="secondary"
          icon="pencil-outline"
          onPress={() => setEditing(true)}
        />
      )}

      <SectionTitle>Retirer</SectionTitle>
      <Button
        label="Jeter le produit"
        variant="danger"
        onPress={() => run('discard', (operationKey) => api.discard(id, operationKey, 'Produit jeté'))}
        disabled={busy || item.remainingQuantity <= 0}
      />
      {item.isArchived ? (
        <Button
          label="Restaurer ce lot"
          variant="secondary"
          onPress={() => run('restore', (operationKey) => api.restoreStockItem(id, operationKey))}
          style={styles.spaced}
        />
      ) : (
        <Button label="Archiver la ligne" variant="ghost" onPress={confirmArchive} style={styles.spaced} />
      )}

      <SectionTitle>Historique</SectionTitle>
      {movements.map((movement) => (
        <View
          key={movement.id}
          style={styles.movement}
          accessible
          accessibilityLabel={describeMovement(movement)}
        >
          <View style={styles.flex}>
            <Text style={text.subheading}>{movement.typeLabel}</Text>
            {movement.reason ? (
              <Text style={[text.small, styles.movementReason]}>{movement.reason}</Text>
            ) : null}
            <Text style={[text.data, styles.movementDate]}>
              {formatDateTime(movement.createdAt)}
              {movement.author ? ` · ${movement.author}` : ''}
            </Text>
            {/* Deux lots débités par la même recette portent le même identifiant :
                c'est ce qui permet de les rapprocher d'une fiche à l'autre. */}
            {movement.operationId ? (
              <Text style={[text.data, styles.movementOperation]}>
                op. {shortOperation(movement.operationId)}
              </Text>
            ) : null}
          </View>
          <View style={styles.movementRight}>
            {/* Le signe suffit à dire le sens : colorer une consommation en rouge
                ferait passer un geste normal pour un incident. */}
            <Text style={styles.movementQuantity}>{formatDelta(movement)}</Text>
            <Text style={[text.data, styles.movementAfter]}>
              reste {formatNumber(movement.remainingAfter)} {movement.unit}
            </Text>
          </View>
        </View>
      ))}
    </ScrollView>
  );
}

/**
 * Un delta nul n'est pas une quantité, c'est un changement d'état.
 *
 * Archiver un lot ou le restaurer ne fait rien sortir du stock : afficher
 * « 0 g » laisserait croire à une opération sur la quantité, et surtout à une
 * perte. Le tiret dit qu'il ne s'est rien passé de ce côté-là ; la ligne de
 * gauche, elle, dit ce qui s'est passé.
 */
function formatDelta(movement: StockMovement): string {
  if (movement.quantity === 0) return '—';

  return `${movement.quantity > 0 ? '+' : '−'}${formatNumber(Math.abs(movement.quantity))} ${movement.unit}`;
}

/** Assez pour rapprocher deux lignes à l'œil, trop court pour être lu comme une donnée. */
function shortOperation(operationId: string): string {
  return operationId.slice(0, 8);
}

/** Une ligne de journal se lit d'un bloc au lecteur d'écran, pas colonne par colonne. */
function describeMovement(movement: StockMovement): string {
  const parts = [movement.typeLabel];

  if (movement.reason) parts.push(movement.reason);
  parts.push(
    movement.quantity === 0
      ? 'aucune quantité modifiée'
      : `${formatNumber(movement.quantity)} ${movement.unit}`,
  );
  parts.push(`reste ${formatNumber(movement.remainingAfter)} ${movement.unit}`);
  if (movement.author) parts.push(`par ${movement.author}`);
  parts.push(formatDateTime(movement.createdAt));

  return parts.join(', ');
}

/** Ce que le découpage en exemplaires signifie concrètement, en une phrase. */
function packagingHint(item: StockItem): string {
  if (item.openPackageQuantity === null) {
    return item.packages.length > 1
      ? `${item.packages.length} exemplaires fermés. Prélever une quantité en ouvrira un — un seul.`
      : 'Aucun exemplaire entamé. Prélever une quantité en ouvrira un automatiquement.';
  }

  if (item.sealedPackageCount === 0) {
    return 'C\'est le dernier exemplaire, et il est entamé.';
  }

  return item.sealedPackageCount === 1
    ? 'Un exemplaire est entamé, l\'autre reste fermé. Les recettes puisent d\'abord dans celui qui est ouvert.'
    : `Un exemplaire est entamé, les ${item.sealedPackageCount} autres restent fermés. Les recettes puisent d'abord dans celui qui est ouvert.`;
}

/**
 * L'échéance qui pilote réellement le stock : la plus proche de la date
 * imprimée/estimée et de la durée de conservation après ouverture. Remplace
 * la lecture directe de `expirationDate`, qui ignorait qu'un lot entamé peut
 * se dégrader plus vite que sa date ne le dit.
 */
function effectiveUseByText(item: StockItem): string {
  if (item.effectiveUseBy === null) {
    return item.isOpened && item.afterOpeningDurationKnown === false
      ? 'Entamé — durée de conservation après ouverture inconnue'
      : 'Aucune date limite renseignée';
  }

  const date = formatDisplayDate(item.effectiveUseBy);

  switch (item.effectiveUseByBasis) {
    case 'after_opening':
      return `Entamé : à utiliser avant le ${date}`;
    case 'estimated':
      return `Fraîcheur estimée jusqu'au ${date} — à vérifier`;
    default:
      return `À consommer avant le ${date}`;
  }
}

function formatDateTime(value: string): string {
  return new Date(value).toLocaleString('fr-FR', {
    day: 'numeric',
    month: 'short',
    hour: '2-digit',
    minute: '2-digit',
  });
}

function formatNumber(value: number): string {
  return Number.isInteger(value) ? String(value) : String(Math.round(value * 100) / 100);
}

const styles = StyleSheet.create({
  container: { padding: spacing.xl, paddingBottom: spacing.xxl * 2 },
  flex: { flex: 1 },
  headerRow: { flexDirection: 'row', alignItems: 'flex-start', gap: spacing.md },
  brand: { marginTop: 2 },

  gauges: { flexDirection: 'row', alignItems: 'flex-start', gap: spacing.lg, marginTop: spacing.lg },
  gauge: { flex: 1 },
  gaugeRule: { width: 1, alignSelf: 'stretch', backgroundColor: colors.border },
  gaugeValue: { fontFamily: font.monoSemiBold, fontSize: 24, lineHeight: 30, letterSpacing: -0.8, color: colors.ink },
  gaugeCaption: { marginTop: spacing.xs },

  facts: { marginTop: spacing.md },
  note: { marginTop: spacing.md, fontStyle: 'italic' },
  help: { marginBottom: spacing.md },
  editLabel: { marginTop: spacing.md, marginBottom: spacing.sm },

  unitStepper: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    backgroundColor: colors.surface,
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: radius.md,
    padding: spacing.sm,
    marginBottom: spacing.md,
  },
  unitButton: {
    minWidth: 56,
    height: 44,
    borderRadius: radius.sm,
    backgroundColor: colors.surfaceSunk,
    alignItems: 'center',
    justifyContent: 'center',
  },
  unitButtonPressed: { backgroundColor: colors.border },
  unitGlyph: { fontFamily: font.monoMedium, fontSize: 14, color: colors.ink },
  unitValue: { fontFamily: font.monoMedium, fontSize: 15, color: colors.ink },

  amountRow: { flexDirection: 'row', gap: spacing.sm, alignItems: 'center' },
  amountInput: {
    flex: 1,
    backgroundColor: colors.surface,
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: radius.md,
    paddingHorizontal: spacing.md,
    minHeight: 48,
    ...text.body,
  },
  actionGrid: { marginTop: spacing.sm, gap: spacing.sm },
  addDateTrigger: { marginTop: spacing.md },
  chips: { flexDirection: 'row', flexWrap: 'wrap' },
  spaced: { marginTop: spacing.sm },

  packages: { flexDirection: 'row', flexWrap: 'wrap', gap: spacing.sm, marginBottom: spacing.md },
  package: {
    backgroundColor: colors.surface,
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: radius.md,
    paddingHorizontal: spacing.md,
    paddingVertical: spacing.sm,
    minWidth: 96,
  },
  packageOpened: { borderColor: thermal.soon.line, backgroundColor: thermal.soon.wash },
  packageStateOpened: { color: thermal.soon.line },
  packageQuantity: { fontFamily: font.monoMedium, fontSize: 14, color: colors.ink, marginTop: 4 },

  sibling: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.md,
    backgroundColor: colors.surface,
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: radius.md,
    padding: spacing.md,
    marginBottom: spacing.sm,
  },
  siblingPressed: { backgroundColor: colors.surfaceSunk },
  siblingQuantity: { fontFamily: font.monoMedium, fontSize: 14, color: colors.ink },
  siblingMeta: { marginTop: 2 },

  pendingUnit: {
    backgroundColor: colors.surfaceSunk,
    borderRadius: radius.md,
    padding: spacing.md,
    marginTop: spacing.sm,
    marginBottom: spacing.md,
  },
  pendingRow: { flexDirection: 'row', gap: spacing.sm, alignItems: 'center' },

  dateRow: { flexDirection: 'row', alignItems: 'center', gap: spacing.md, marginBottom: spacing.md },
  dateButton: {
    flex: 1,
    backgroundColor: colors.surface,
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: radius.md,
    paddingHorizontal: spacing.md,
    justifyContent: 'center',
    minHeight: 48,
  },
  dateButtonPressed: { backgroundColor: colors.surfaceSunk },
  clearText: { ...text.small, color: thermal.critical.line, fontFamily: font.sansMedium },

  movement: {
    flexDirection: 'row',
    backgroundColor: colors.surface,
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: radius.md,
    padding: spacing.md,
    marginBottom: spacing.sm,
    gap: spacing.md,
  },
  movementReason: { marginTop: 1 },
  movementDate: { marginTop: 4 },
  movementOperation: { marginTop: 2 },
  movementRight: { alignItems: 'flex-end' },
  movementQuantity: { fontFamily: font.monoSemiBold, fontSize: 13, color: colors.ink },
  movementAfter: { marginTop: 4 },
});
