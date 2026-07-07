# Tests d'intégration — Polylang DeepL Translate

## Vue d'ensemble

Le dossier `tests/` contient un script d'intégration qui valide le comportement
de bout en bout du plugin : appel DeepL réel, création/mise à jour de traductions
Polylang, et traitement des données propres à chaque page builder.

Les tests s'appuient sur l'environnement WordPress actif — ils **n'utilisent pas de
mock** pour l'API DeepL, ce qui garantit que les vrais flux de données sont couverts.

---

## Prérequis

| Élément | Requis |
|---|---|
| WordPress | ≥ 6.0 |
| PHP | ≥ 8.0 |
| Polylang | Actif, avec langues **fr** (source) et **en** (cible) configurées |
| Plugin PDT | Actif, clé API DeepL renseignée dans Réglages → Polylang DeepL |
| WP-CLI | Disponible dans l'environnement |

> Le plugin doit être en **mode dev** (SDK Freemius absent) ou posséder une licence
> valide pour que les traductions s'exécutent.

---

## Lancer les tests

### Avec Docker Compose (env de dev standard)

```bash
docker compose run --rm wpcli eval-file \
  /var/www/html/wp-content/plugins/autolingo-polylang-translate/tests/integration.php
```

### Avec WP-CLI directement (WordPress installé localement)

```bash
wp eval-file wp-content/plugins/autolingo-polylang-translate/tests/integration.php \
  --path=/var/www/html
```

### Résultat attendu

```
======================================
  Polylang DeepL — Tests d'intégration
======================================

── 1. Flux principal ─────────────────────────────────────────────────
  ✅  Création d'une traduction EN
  ✅  Mise à jour d'une traduction existante
  ✅  Texte vide → retour immédiat sans appel DeepL
...
══════════════════════════════════════
  Résultats : 18/18 tests réussis
  Tous les tests sont passés.
══════════════════════════════════════
```

Le script retourne le code de sortie **0** si tous les tests passent, **1** si au
moins un échoue — compatible avec les pipelines CI.

---

## Variables d'environnement

| Variable | Valeur | Effet |
|---|---|---|
| `PDT_TEST_KEEP` | `1` | Conserve les posts créés après les tests (utile pour inspecter les données en base ou dans l'admin) |

Exemple :

```bash
PDT_TEST_KEEP=1 docker compose run --rm wpcli eval-file \
  /var/www/html/wp-content/plugins/autolingo-polylang-translate/tests/integration.php
```

---

## Couverture des tests

### 1. Flux principal (3 tests)

| Test | Ce qui est vérifié |
|---|---|
| Création d'une traduction EN | Post EN créé, langue = `en`, statut = `publish`, titre et extrait traduits |
| Mise à jour d'une traduction existante | Contenu mis à jour, langue toujours `en` après `wp_update_post()` |
| Texte vide | `pdt_call_deepl('')` retourne `''` sans appel API |

### 2. SEO metas (4 tests)

| Test | Ce qui est vérifié |
|---|---|
| Yoast SEO | `_yoast_wpseo_title`, `_yoast_wpseo_metadesc`, `_yoast_wpseo_focuskw` traduits |
| RankMath | `_rankmath_title`, `_rankmath_description` traduits |
| Variables Yoast `%%var%%` | `%%title%%` et `%%sep%%` conservés intacts après traduction |
| Variables RankMath `%var%` | `%title%` et `%sep%` conservés intacts après traduction |

### 3. Shortcodes (2 tests)

| Test | Ce qui est vérifié |
|---|---|
| Shortcodes avec attributs | `[contact-form-7 id="1" title="Contact"]` et `[gallery ids="10,11"]` intacts |
| Shortcode auto-fermant | `[vc_row /]` intact (Divi, WPBakery) |

### 4. Elementor (1 test)

| Test | Ce qui est vérifié |
|---|---|
| Widgets heading + text-editor | `title` et `sub_title` traduits ; balises HTML `<strong>` conservées dans `editor` |

> Les données Elementor sont simulées via `_elementor_data` (JSON) sans nécessiter
> l'installation du plugin Elementor.

### 5. Beaver Builder (2 tests)

| Test | Ce qui est vérifié |
|---|---|
| Modules traduits | `title`, `content`, `button_text` traduits ; `_fl_builder_draft` synchronisé avec `_fl_builder_data` |
| Nodes globaux ignorés | Un node avec `global = true` n'est pas modifié |

> Les données BB sont simulées via `_fl_builder_data` (PHP sérialisé) sans nécessiter
> l'installation de Beaver Builder.

### 6. Bricks Builder (2 tests)

| Test | Ce qui est vérifié |
|---|---|
| Éléments heading + text | `heading`, `subheading` traduits ; balise `<em>` conservée dans `text` |
| Items accordéon imbriqués | `title` et `content` de chaque item traduits |

> Les données Bricks sont simulées via `_bricks_page_content_2` (JSON) sans nécessiter
> l'installation de Bricks Builder.

### 7. WooCommerce (4 tests)

| Test | Ce qui est vérifié |
|---|---|
| Attributs personnalisés | `name` et valeurs pipe-séparées (`value`) traduits, séparateur `\|` conservé |
| Attributs taxonomie | Un attribut avec `is_taxonomy = 1` (slug WC) n'est pas modifié |
| Note d'achat | `_purchase_note` traduit |
| Exclusion prix/stock | `_price`, `_regular_price`, `_sku` non copiés sur le post EN |

---

## Comportement en cas de rate limit DeepL (429)

Le plugin intègre un mécanisme de retry automatique avec back-off exponentiel
(2 s → 4 s → 8 s, 3 tentatives max). Le runner de tests ajoute en outre une pause
d'1 seconde entre chaque test pour rester sous les seuils du plan gratuit DeepL.

Si des tests échouent avec le message `pdt_translate_post() a retourné false`, vérifier
les logs du plugin (tableau de bord WP → **Polylang DeepL → Logs**) : une série
d'erreurs HTTP 429 indique un dépassement de rate limit temporaire. Relancer les tests
après quelques secondes résout généralement le problème.

---

## Architecture du script

```
tests/integration.php
├── $GLOBALS['pdt_passed/failed/cleanup']   Compteurs partagés entre le scope eval et les fonctions
├── pdt_test(name, fn)                      Runner : reset cleanup → exécute fn → ✅/❌ → nettoyage
├── pdt_assert(bool, message)               Lance RuntimeException si la condition est fausse
├── pdt_make_fr_post(args)                  Crée un post FR temporaire, l'enregistre pour nettoyage
├── pdt_translate(src_id)                   Traduit + vérifie succès + retourne $en_id
└── Tests groupés par fonctionnalité        7 groupes, 18 tests
```

> **Note sur le scope `eval-file`** : WP-CLI exécute les fichiers via `eval()` dans un
> scope local. Les variables définies au niveau du fichier (`$x = 0`) **ne sont pas** dans
> `$GLOBALS`. Les fonctions déclarées dans le fichier accèdent à `$GLOBALS` via `global $x`,
> ce qui est un scope différent. Le script utilise donc `$GLOBALS['pdt_*']` directement
> pour garantir la cohérence entre le code au niveau fichier et les fonctions.

---

## Ajouter un test

```php
pdt_test( 'Description courte du test', function () {
    // 1. Créer un post source FR
    $src = pdt_make_fr_post( [ 'post_title' => 'Mon titre en français' ] );

    // 2. Optionnel : ajouter des metas spécifiques
    update_post_meta( $src, '_ma_meta', 'Valeur source.' );

    // 3. Traduire (lève une exception si ça échoue)
    $en_id = pdt_translate( $src );

    // 4. Vérifier les résultats
    $val = get_post_meta( $en_id, '_ma_meta', true );
    pdt_assert( ! empty( $val ),            'Meta vide sur le post EN' );
    pdt_assert( $val !== 'Valeur source.',  'Meta non traduite' );
} );
```

Les posts créés par `pdt_make_fr_post()` et l'ID retourné par `pdt_translate()` sont
automatiquement supprimés après chaque test (sauf si `PDT_TEST_KEEP=1`).
