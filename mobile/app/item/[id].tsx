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
} from '../../src/components/ui';
import { colors, radius, spacing, statusPresentation } from '../../src/theme';

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
      'Il quitte votre inventaire sans rien perdre : sa quantité et son historique sont conservés, et vous pourrez le restaurer depuis le filtre « Archivés ».',
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
      <Loader />
    );
  }

  const presentation = statusPresentation[item.status] ?? statusPresentation.sealed;

  // Deux unités d'une même famille se convertissent ; entre familles, il faut redonner la quantité.
  const currentUnitFamily = reference?.units.find((option) => option.value === item.unit)?.family;
  const traitLabels = (reference?.productTraits ?? [])
    .filter((option) => item.variant.traits.includes(option.value))
    .map((option) => option.label);

  return (
    <ScrollView contentContainerStyle={styles.container} keyboardShouldPersistTaps="handled">
      <ErrorBanner message={error} />

      <Card>
        <View style={styles.headerRow}>
          <View style={styles.flex}>
            <Text style={styles.name}>{item.variant.name}</Text>
            {item.variant.brand ? <Text style={styles.brand}>{item.variant.brand}</Text> : null}
          </View>
          <Badge
            label={presentation.label}
            color={presentation.color}
            background={presentation.background}
          />
        </View>

        <Text style={styles.quantity}>
          {item.displayQuantity} {item.displayUnit} disponibles
        </Text>
        <Text style={styles.meta}>{item.storageLocationLabel}</Text>
        <Text style={styles.meta}>
          {item.expirationDate === null
            ? 'Aucune date limite renseignée'
            : item.expirationIsEstimated
              ? `Fraîcheur estimée jusqu'au ${formatDate(item.expirationDate)} — à vérifier`
              : `À consommer avant le ${formatDate(item.expirationDate)}`}
        </Text>
        {item.addedBy ? (
          <Text style={styles.meta}>Enregistré par {item.addedBy}</Text>
        ) : null}
        {traitLabels.length > 0 ? (
          <Text style={styles.meta}>{traitLabels.join(' · ')}</Text>
        ) : null}
        {item.quantityIsEstimated ? (
          <Text style={styles.meta}>Quantité estimée, pas mesurée</Text>
        ) : null}
        {item.note ? <Text style={styles.note}>{item.note}</Text> : null}
      </Card>

      {/* Un lot n'est pas un tas : trois sachets de 500 g dont un entamé ne
          se résument pas à « 1,25 kg ». Voir quel exemplaire est ouvert évite
          d'en déballer un second alors qu'il en reste dans le premier. */}
      {item.packages.length > 0 ? (
        <>
          <SectionTitle>Exemplaires</SectionTitle>
          <View style={styles.packages}>
            {item.packages.map((entry) => (
              <View
                key={`package-${entry.position}`}
                style={[styles.package, entry.opened && styles.packageOpened]}
              >
                <Text style={[styles.packageState, entry.opened && styles.packageStateOpened]}>
                  {entry.opened ? 'Entamé' : 'Fermé'}
                </Text>
                <Text style={styles.packageQuantity}>
                  {formatNumber(entry.remaining)} {item.unitLabel}
                </Text>
              </View>
            ))}
          </View>
          <Text style={styles.help}>{packagingHint(item)}</Text>
        </>
      ) : null}

      {/* Les autres lots du même aliment : c'est ce qui permet de choisir le bon
          plutôt que de gonfler celui-ci avec une date qui ne lui correspond pas. */}
      {item.siblings && item.siblings.length > 0 ? (
        <>
          <SectionTitle>Autres lots de cet aliment</SectionTitle>
          <Text style={styles.help}>
            Le lot qui périme le plus tôt est consommé en premier. Pour un achat avec
            une autre date, ajoutez un lot plutôt que d'augmenter celui-ci.
          </Text>
          {item.siblings.map((sibling) => (
            <Pressable
              key={sibling.id}
              style={styles.sibling}
              onPress={() => router.push(`/item/${sibling.id}`)}
              accessibilityRole="button"
              accessibilityLabel={`Autre lot : ${sibling.displayQuantity} ${sibling.displayUnit}, ${
                sibling.expirationDate
                  ? `${sibling.expirationIsEstimated ? 'estimé' : 'à consommer avant'} le ${formatDate(sibling.expirationDate)}`
                  : 'sans date'
              }, ${sibling.storageLocationLabel}`}
              accessibilityHint="Ouvre la fiche de ce lot"
            >
              <Text style={styles.siblingQuantity}>
                {sibling.displayQuantity} {sibling.displayUnit}
              </Text>
              <Text style={styles.siblingMeta}>
                {sibling.expirationDate
                  ? `${sibling.expirationIsEstimated ? 'estimé' : 'avant'} le ${formatDate(sibling.expirationDate)}`
                  : 'sans date'}{' '}
                · {sibling.storageLocationLabel}
              </Text>
            </Pressable>
          ))}
        </>
      ) : null}

      <SectionTitle>Consommer</SectionTitle>

      {item.isDiscreteUnit ? (
        <View style={styles.stepper}>
          <Pressable
            style={styles.stepperButton}
            onPress={() =>
              run('consume-units', (operationKey) => api.consume(id, { mode: 'units', delta: -1 }, operationKey))
            }
            disabled={busy}
            accessibilityRole="button"
            accessibilityLabel={`Consommer un ${item.unitLabel}`}
            accessibilityState={{ disabled: busy }}
          >
            <Text style={styles.stepperGlyph} importantForAccessibility="no">
              −1
            </Text>
          </Pressable>
          <Text
            style={styles.stepperValue}
            accessibilityLabel={`Il reste ${item.remainingQuantity} ${item.unitLabel}`}
          >
            {item.remainingQuantity} {item.unitLabel}
          </Text>
          <Pressable
            style={styles.stepperButton}
            onPress={() =>
              run('add-unit', (operationKey) => api.addQuantity(id, { quantity: 1 }, operationKey))
            }
            disabled={busy}
            accessibilityRole="button"
            accessibilityLabel={`Ajouter un ${item.unitLabel}`}
            accessibilityState={{ disabled: busy }}
          >
            <Text style={styles.stepperGlyph} importantForAccessibility="no">
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
          style={styles.amountButton}
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
          style={styles.gridItem}
        />
        <Button
          label="Retirer un exemplaire"
          variant="secondary"
          onPress={() =>
            run('consume-package', (operationKey) => api.consume(id, { mode: 'package' }, operationKey))
          }
          disabled={busy}
          style={styles.gridItem}
        />
        {/* Le geste porte sur UN exemplaire : ouvrir un lot de trois sachets
            n'en entame qu'un, les deux autres restent scellés. */}
        <Button
          label={item.isOpened ? 'Refermer l\'exemplaire entamé' : 'Entamer un exemplaire'}
          variant="secondary"
          onPress={() =>
            run('toggle-opened', (operationKey) => api.setOpened(id, !item.isOpened, operationKey))
          }
          disabled={busy}
          style={styles.gridItem}
        />
        <Button
          label="Ajouter une quantité"
          variant="secondary"
          onPress={() => {
            const value = parsedAmount();
            if (value === null) {
              setError('Saisissez d\'abord une quantité dans le champ ci-dessus.');
              return;
            }
            run('add-quantity', (operationKey) => api.addQuantity(id, { quantity: value }, operationKey));
          }}
          disabled={busy}
          style={styles.gridItem}
        />
      </View>

      <SectionTitle>Corriger la quantité</SectionTitle>
      <Text style={styles.help}>
        Vous ne savez pas exactement ce qu'il reste ? Choisissez une estimation.
      </Text>
      <View style={styles.chips}>
        {reference?.fillLevels.map((level) => (
          <Chip
            key={level.value}
            label={level.label}
            selected={false}
            onPress={() =>
              run(`correct-fill-${level.value}`, (operationKey) =>
                api.correct(id, { fillLevel: level.value }, operationKey, item.revision),
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

          <Text style={styles.label}>Emplacement</Text>
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

          <Text style={styles.label}>Unité</Text>
          <Text style={styles.help}>
            Une unité choisie par erreur se répare ici. Passer d'une masse à des unités
            n'a pas d'équivalence : la quantité restante doit alors être redonnée.
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
                  style={styles.grow}
                />
                <Button label="Annuler" variant="ghost" onPress={() => setPendingUnit(null)} />
              </View>
            </View>
          ) : null}

          <Text style={styles.label}>État du produit</Text>
          <Text style={styles.help}>
            Ce que les recettes doivent savoir : une conserve ne s'épluche pas, un surgelé
            se décongèle.
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

          <Text style={styles.label}>Date de péremption</Text>
          <View style={styles.dateRow}>
            <Pressable
              style={styles.dateButton}
              onPress={() => setShowPicker(true)}
              accessibilityRole="button"
              accessibilityLabel={
                item.expirationDate
                  ? `Date de péremption : ${formatDate(item.expirationDate)}`
                  : 'Aucune date de péremption'
              }
              accessibilityHint="Ouvre le sélecteur de date"
            >
              <Text style={styles.dateText}>
                {item.expirationDate ? formatDate(item.expirationDate) : 'Aucune date'}
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
          />
          <Button
            label="Fermer"
            variant="ghost"
            onPress={() => setEditing(false)}
            style={styles.spaced}
          />
        </Card>
      ) : (
        <Button label="Modifier cet aliment" variant="secondary" onPress={() => setEditing(true)} />
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
        <Button
          label="Archiver la ligne"
          variant="ghost"
          onPress={confirmArchive}
          style={styles.spaced}
        />
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
            <Text style={styles.movementLabel}>{movement.typeLabel}</Text>
            {movement.reason ? (
              <Text style={styles.movementReason}>{movement.reason}</Text>
            ) : null}
            <Text style={styles.movementDate}>
              {formatDateTime(movement.createdAt)}
              {movement.author ? ` · par ${movement.author}` : ''}
            </Text>
            {/* Deux lots débités par la même recette portent le même identifiant :
                c'est ce qui permet de les rapprocher d'une fiche à l'autre. */}
            {movement.operationId ? (
              <Text style={styles.movementOperation}>
                Opération {shortOperation(movement.operationId)}
              </Text>
            ) : null}
          </View>
          <View style={styles.movementRight}>
            <Text
              style={[
                styles.movementQuantity,
                { color: movementColor(movement.quantity) },
              ]}
            >
              {formatDelta(movement)}
            </Text>
            <Text style={styles.movementAfter}>
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

  return `${movement.quantity > 0 ? '+' : ''}${formatNumber(movement.quantity)} ${movement.unit}`;
}

function movementColor(quantity: number): string {
  if (quantity === 0) return colors.inkMuted;

  return quantity < 0 ? colors.danger : colors.primary;
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

function toIsoDate(date: Date): string {
  const month = String(date.getMonth() + 1).padStart(2, '0');
  const day = String(date.getDate()).padStart(2, '0');
  return `${date.getFullYear()}-${month}-${day}`;
}

function formatDate(value: string): string {
  return new Date(value).toLocaleDateString('fr-FR', {
    day: 'numeric',
    month: 'long',
    year: 'numeric',
  });
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
  name: { fontSize: 22, fontWeight: '800', color: colors.ink },
  brand: { fontSize: 15, color: colors.inkMuted, marginTop: 2 },
  quantity: { fontSize: 26, fontWeight: '700', color: colors.primary, marginTop: spacing.lg },
  meta: { fontSize: 14, color: colors.inkMuted, marginTop: spacing.xs },
  note: { fontSize: 14, color: colors.ink, marginTop: spacing.md, fontStyle: 'italic' },
  help: { fontSize: 13, color: colors.inkMuted, marginBottom: spacing.md, lineHeight: 19 },
  label: { fontSize: 13, fontWeight: '600', color: colors.inkMuted, marginBottom: spacing.sm },
  stepper: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    backgroundColor: colors.surface,
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: radius.lg,
    padding: spacing.md,
    marginBottom: spacing.md,
  },
  stepperButton: {
    width: 56,
    height: 44,
    borderRadius: radius.md,
    backgroundColor: colors.primarySoft,
    alignItems: 'center',
    justifyContent: 'center',
  },
  stepperGlyph: { fontSize: 18, fontWeight: '700', color: colors.primary },
  stepperValue: { fontSize: 18, fontWeight: '700', color: colors.ink },
  amountRow: { flexDirection: 'row', gap: spacing.md, alignItems: 'center' },
  amountInput: {
    flex: 1,
    backgroundColor: colors.surface,
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: radius.md,
    paddingHorizontal: spacing.md,
    paddingVertical: 13,
    fontSize: 16,
    color: colors.ink,
  },
  amountButton: { paddingHorizontal: spacing.lg },
  actionGrid: { marginTop: spacing.md, gap: spacing.sm },
  gridItem: {},
  chips: { flexDirection: 'row', flexWrap: 'wrap' },
  spaced: { marginTop: spacing.sm },
  packages: { flexDirection: 'row', flexWrap: 'wrap', gap: spacing.sm },
  package: {
    backgroundColor: colors.surface,
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: radius.md,
    paddingHorizontal: spacing.md,
    paddingVertical: spacing.sm,
    minWidth: 92,
  },
  packageOpened: { borderColor: colors.info, backgroundColor: colors.infoSoft },
  packageState: { fontSize: 12, fontWeight: '700', color: colors.inkMuted },
  packageStateOpened: { color: colors.info },
  packageQuantity: { fontSize: 15, fontWeight: '700', color: colors.ink, marginTop: 2 },
  sibling: {
    backgroundColor: colors.surface,
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: radius.md,
    padding: spacing.md,
    marginBottom: spacing.sm,
  },
  siblingQuantity: { fontSize: 15, fontWeight: '700', color: colors.ink },
  siblingMeta: { fontSize: 13, color: colors.inkMuted, marginTop: 2 },
  grow: { flex: 1 },
  pendingUnit: {
    backgroundColor: colors.surfaceMuted,
    borderRadius: radius.md,
    padding: spacing.md,
    marginTop: spacing.sm,
    marginBottom: spacing.md,
  },
  pendingRow: { flexDirection: 'row', gap: spacing.sm, alignItems: 'center' },
  dateRow: { flexDirection: 'row', alignItems: 'center', gap: spacing.md, marginBottom: spacing.lg },
  dateButton: {
    flex: 1,
    backgroundColor: colors.surfaceMuted,
    borderRadius: radius.md,
    paddingHorizontal: spacing.md,
    paddingVertical: 12,
  },
  dateText: { fontSize: 15, color: colors.ink },
  clearText: { color: colors.danger, fontSize: 14, fontWeight: '600' },
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
  movementLabel: { fontSize: 15, fontWeight: '600', color: colors.ink },
  movementReason: { fontSize: 13, color: colors.inkMuted, marginTop: 1 },
  movementDate: { fontSize: 12, color: colors.inkMuted, marginTop: 2 },
  movementOperation: { fontSize: 11, color: colors.inkMuted, marginTop: 2, fontVariant: ['tabular-nums'] },
  movementRight: { alignItems: 'flex-end' },
  movementQuantity: { fontSize: 15, fontWeight: '700' },
  movementAfter: { fontSize: 12, color: colors.inkMuted, marginTop: 2 },
});
