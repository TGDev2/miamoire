<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Sépare le catalogue canonique des variantes de foyer, et rend le journal
 * de stock atomique et récupérable.
 *
 * Trois choses arrivent ici, dans cet ordre :
 *
 * 1. `household_product_variant` est créée, puis **peuplée depuis les lots
 *    existants**. Une variante par combinaison (foyer, produit, famille d'unité,
 *    traits) : c'est exactement le grain que l'ancien modèle confondait. Deux lots
 *    de tomates, l'un en conserve, l'autre frais, donnent donc deux variantes —
 *    la migration ne fusionne rien qu'elle ne saurait justifier.
 * 2. `stock_item.variant_id` remplace `product_id`.
 * 3. `stock_operation` apparaît, et `stock_movement` gagne son identifiant de
 *    corrélation.
 *
 * La reprise est écrite en SQL pur, sans passer par l'ORM : une migration qui
 * dépend des entités cesse de fonctionner à la première évolution de celles-ci.
 *
 * `down()` est complète : le déploiement doit rester réversible. Elle rend les
 * lots à leur produit canonique et détruit les variantes — ce qui reperd, par
 * construction, les corrections locales. C'est le prix d'un retour arrière, et il
 * vaut mieux qu'il soit écrit ici que découvert un soir de déploiement.
 */
final class Version20260726120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Variantes de foyer, dates de péremption estimées, archivage des lots et opérations de stock idempotentes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE household_product_variant (
                id UUID NOT NULL,
                household_id UUID NOT NULL,
                product_id UUID DEFAULT NULL,
                name VARCHAR(255) NOT NULL,
                ingredient_key VARCHAR(255) NOT NULL,
                slug VARCHAR(255) NOT NULL,
                identity_hash VARCHAR(64) NOT NULL,
                brand VARCHAR(180) DEFAULT NULL,
                category VARCHAR(120) DEFAULT NULL,
                traits JSON NOT NULL,
                unit_family VARCHAR(16) NOT NULL,
                name_origin VARCHAR(16) NOT NULL,
                traits_origin VARCHAR(16) NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        // Index de clé étrangère : les noms sont ceux que Doctrine génère, pour que
        // `doctrine:schema:validate` reste vert et qu'un futur `migrations:diff`
        // ne propose pas de renommages parasites.
        $this->addSql('CREATE INDEX IDX_8417C5D3E79FF843 ON household_product_variant (household_id)');
        $this->addSql('CREATE INDEX IDX_8417C5D34584665A ON household_product_variant (product_id)');
        $this->addSql('CREATE INDEX idx_variant_ingredient_key ON household_product_variant (household_id, ingredient_key)');
        $this->addSql('CREATE UNIQUE INDEX uniq_variant_identity ON household_product_variant (household_id, identity_hash)');
        $this->addSql('CREATE UNIQUE INDEX uniq_variant_slug ON household_product_variant (household_id, slug)');
        $this->addSql('ALTER TABLE household_product_variant ADD CONSTRAINT fk_variant_household FOREIGN KEY (household_id) REFERENCES household (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE household_product_variant ADD CONSTRAINT fk_variant_product FOREIGN KEY (product_id) REFERENCES product (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql(<<<'SQL'
            CREATE TABLE stock_operation (
                id UUID NOT NULL,
                household_id UUID NOT NULL,
                created_by_id UUID DEFAULT NULL,
                operation_key VARCHAR(64) NOT NULL,
                type VARCHAR(32) NOT NULL,
                request_hash VARCHAR(64) NOT NULL,
                status VARCHAR(16) NOT NULL,
                response JSON DEFAULT NULL,
                failure_reason TEXT DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                completed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_811C4085E79FF843 ON stock_operation (household_id)');
        $this->addSql('CREATE INDEX IDX_811C4085B03A8386 ON stock_operation (created_by_id)');
        // La garantie d'idempotence est portée par la base, pas par le code applicatif.
        $this->addSql('CREATE UNIQUE INDEX uniq_operation_key ON stock_operation (household_id, operation_key)');
        $this->addSql('ALTER TABLE stock_operation ADD CONSTRAINT fk_operation_household FOREIGN KEY (household_id) REFERENCES household (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        // `household_member`, pas `member` : la table porte déjà son préfixe.
        $this->addSql('ALTER TABLE stock_operation ADD CONSTRAINT fk_operation_author FOREIGN KEY (created_by_id) REFERENCES household_member (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('ALTER TABLE stock_item ADD variant_id UUID DEFAULT NULL');
        // La valeur par défaut ne sert qu'à remplir les lignes existantes ; elle est
        // retirée ensuite, l'entité ne la déclarant pas.
        $this->addSql('ALTER TABLE stock_item ADD expiration_is_estimated BOOLEAN DEFAULT FALSE NOT NULL');
        $this->addSql('ALTER TABLE stock_item ALTER expiration_is_estimated DROP DEFAULT');
        $this->addSql('ALTER TABLE stock_item ADD archived_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE stock_movement ADD operation_id UUID DEFAULT NULL');

        $this->migrateVariants();

        // Les lots repris, la colonne devient obligatoire : plus aucun lot ne peut
        // pointer directement vers une référence partagée entre foyers.
        $this->addSql('ALTER TABLE stock_item ALTER COLUMN variant_id SET NOT NULL');
        $this->addSql('CREATE INDEX idx_stock_variant_expiration ON stock_item (variant_id, expiration_date)');
        $this->addSql('ALTER TABLE stock_item ADD CONSTRAINT fk_stock_item_variant FOREIGN KEY (variant_id) REFERENCES household_product_variant (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE stock_item DROP CONSTRAINT IF EXISTS fk_5c1109c44584665a');
        $this->addSql('ALTER TABLE stock_item DROP COLUMN product_id');

        $this->addSql('CREATE INDEX IDX_BB1BC1B544AC3583 ON stock_movement (operation_id)');
        $this->addSql('ALTER TABLE stock_movement ADD CONSTRAINT fk_movement_operation FOREIGN KEY (operation_id) REFERENCES stock_operation (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    /**
     * L'ordre canonique des traits — celui de `ProductTrait::cases()`.
     *
     * `VariantIdentity::hash()` trie les traits dans cet ordre avant de les hacher.
     * La migration doit produire EXACTEMENT la même empreinte, sinon le résolveur
     * ne reconnaîtrait aucune variante reprise et en recréerait une à côté au
     * premier ajout — l'inventaire se scinderait en deux le lendemain de la mise
     * en production.
     */
    private const TRAIT_ORDER = "ARRAY['canned','frozen','dried','peeled','precooked','cooked','ready_to_eat','sliced','sweetened','unsweetened']";

    /**
     * Une variante par (foyer, clé d'ingrédient, famille d'unité, traits).
     *
     * Le grain est **l'identité culinaire**, pas le produit du catalogue. Deux
     * fiches distinctes — deux marques de penne, la même clé `pate-penne` — sont
     * un seul aliment pour qui cuisine : elles doivent donner UNE variante, sinon
     * l'inventaire afficherait deux lignes de pâtes que rien ne distingue. À
     * l'inverse, un même produit rangé en grammes ici et à la pièce là donne deux
     * variantes, parce que ces quantités ne s'additionnent pas.
     *
     * La famille d'unité vient donc des lots, et le produit canonique retenu est
     * le plus ancien de l'identité — un choix arbitraire mais déterministe, donc
     * rejouable à l'identique.
     *
     * Le slug reprend la construction de `VariantIdentity::slugBase()` : la clé
     * d'ingrédient, suffixée de l'état déterminant (`tomate-conserve`), numérotée
     * seulement en cas de collision résiduelle.
     */
    private function migrateVariants(): void
    {
        $order = self::TRAIT_ORDER;

        // Signature textuelle des traits, identique à celle produite en PHP :
        // « canned,precooked », dans l'ordre de déclaration de l'enum.
        $traitSignature = <<<SQL
            COALESCE((
                SELECT string_agg(t.value, ',' ORDER BY array_position({$order}, t.value))
                FROM json_array_elements_text(p.traits) AS t(value)
                WHERE t.value = ANY({$order})
            ), '')
        SQL;

        $unitFamily = <<<'SQL'
            CASE
                WHEN s.unit IN ('g', 'kg') THEN 'mass'
                WHEN s.unit IN ('ml', 'cl', 'l') THEN 'volume'
                WHEN s.unit = 'portion' THEN 'serving'
                ELSE 'count'
            END
        SQL;

        // Le trait de forme le plus déterminant, dans l'ordre de l'enum.
        // `jsonb_exists` plutôt que l'opérateur `?`, que DBAL prendrait pour un
        // paramètre lié.
        $formSuffix = <<<'SQL'
            CASE
                WHEN jsonb_exists(p.traits::jsonb, 'canned') THEN '-conserve'
                WHEN jsonb_exists(p.traits::jsonb, 'frozen') THEN '-surgele'
                WHEN jsonb_exists(p.traits::jsonb, 'dried') THEN '-sec'
                WHEN jsonb_exists(p.traits::jsonb, 'precooked') THEN '-precuit'
                WHEN jsonb_exists(p.traits::jsonb, 'cooked') THEN '-cuit'
                WHEN jsonb_exists(p.traits::jsonb, 'ready_to_eat') THEN '-pret'
                ELSE ''
            END
        SQL;

        $identityHash = <<<SQL
            ENCODE(SHA256((
                p.ingredient_key || '|' || {$unitFamily} || '|' || {$traitSignature}
            )::bytea), 'hex')
        SQL;

        // L'identité de chaque lot, calculée une fois et réutilisée pour la
        // création des variantes puis pour le rattachement.
        $this->addSql(<<<SQL
            CREATE TEMPORARY TABLE lot_identity AS
            SELECT
                s.id AS item_id,
                s.household_id,
                p.id AS product_id,
                p.created_at AS product_created_at,
                p.name,
                p.ingredient_key,
                p.brand,
                p.category,
                p.traits::jsonb AS traits,
                {$unitFamily} AS unit_family,
                p.ingredient_key || {$formSuffix} AS slug_base,
                {$identityHash} AS identity_hash
            FROM stock_item s
            JOIN product p ON p.id = s.product_id
        SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO household_product_variant (
                id, household_id, product_id, name, ingredient_key, slug, identity_hash,
                brand, category, traits, unit_family, name_origin, traits_origin,
                created_at, updated_at
            )
            SELECT
                gen_random_uuid(),
                ranked.household_id,
                ranked.product_id,
                ranked.name,
                ranked.ingredient_key,
                CASE
                    WHEN ranked.rank = 1 THEN ranked.slug_base
                    ELSE ranked.slug_base || '-' || ranked.rank
                END,
                ranked.identity_hash,
                ranked.brand,
                ranked.category,
                -- Le regroupement passe par jsonb, seul type doté d'un opérateur
                -- d'égalité ; la colonne cible, elle, reste du json.
                ranked.traits::text::json,
                ranked.unit_family,
                'catalog',
                'catalog',
                NOW(),
                NOW()
            FROM (
                SELECT
                    unique_identity.*,
                    ROW_NUMBER() OVER (
                        PARTITION BY unique_identity.household_id, unique_identity.slug_base
                        ORDER BY unique_identity.unit_family, unique_identity.identity_hash
                    ) AS rank
                FROM (
                    -- Une seule ligne par identité ; le produit canonique retenu est
                    -- le plus ancien, pour que la reprise soit reproductible.
                    SELECT DISTINCT ON (household_id, identity_hash)
                        household_id, identity_hash, product_id, name, ingredient_key,
                        brand, category, traits, unit_family, slug_base
                    FROM lot_identity
                    ORDER BY household_id, identity_hash, product_created_at, product_id
                ) AS unique_identity
            ) AS ranked
        SQL);

        $this->addSql(<<<'SQL'
            UPDATE stock_item s
            SET variant_id = v.id
            FROM lot_identity i
            JOIN household_product_variant v
              ON v.household_id = i.household_id
             AND v.identity_hash = i.identity_hash
            WHERE s.id = i.item_id
        SQL);

        // Filet de sécurité : un lot orphelin bloquerait le passage en NOT NULL
        // avec un message illisible. Mieux vaut échouer en le nommant.
        $this->addSql(<<<'SQL'
            DO $$
            DECLARE orphans INT;
            BEGIN
                SELECT COUNT(*) INTO orphans FROM stock_item WHERE variant_id IS NULL;
                IF orphans > 0 THEN
                    RAISE EXCEPTION 'Migration interrompue : % lot(s) sans variante. Exécutez app:variants:report avant de rejouer.', orphans;
                END IF;
            END $$;
        SQL);

        $this->addSql('DROP TABLE lot_identity');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE stock_item ADD product_id UUID DEFAULT NULL');
        $this->addSql(<<<'SQL'
            UPDATE stock_item s
            SET product_id = v.product_id
            FROM household_product_variant v
            WHERE v.id = s.variant_id
        SQL);

        // Les variantes nées d'une saisie libre n'ont pas de produit canonique :
        // le retour arrière ne peut pas les représenter, on les supprime avec leurs lots.
        $this->addSql('DELETE FROM stock_item WHERE product_id IS NULL');
        $this->addSql('ALTER TABLE stock_item ALTER COLUMN product_id SET NOT NULL');
        $this->addSql('ALTER TABLE stock_item ADD CONSTRAINT fk_5c1109c44584665a FOREIGN KEY (product_id) REFERENCES product (id) NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('ALTER TABLE stock_movement DROP CONSTRAINT IF EXISTS fk_movement_operation');
        $this->addSql('DROP INDEX IF EXISTS idx_movement_operation');
        $this->addSql('ALTER TABLE stock_movement DROP COLUMN operation_id');

        $this->addSql('ALTER TABLE stock_item DROP CONSTRAINT IF EXISTS fk_stock_item_variant');
        $this->addSql('DROP INDEX IF EXISTS idx_stock_variant_expiration');
        $this->addSql('ALTER TABLE stock_item DROP COLUMN variant_id');
        $this->addSql('ALTER TABLE stock_item DROP COLUMN expiration_is_estimated');
        $this->addSql('ALTER TABLE stock_item DROP COLUMN archived_at');

        $this->addSql('DROP TABLE stock_operation');
        $this->addSql('DROP TABLE household_product_variant');
    }
}
