# Compilation et sérialisation d'AST

## Vue d'ensemble

Cette catégorie regroupe la gestion du cycle de vie des AST (Abstract Syntax Trees) : compilation depuis une chaîne, cache interne, export pour stockage externe, import sécurisé. Ce sont les mécanismes qui permettent d'optimiser des évaluations répétées et de persister des expressions compilées.

Trois axes :
1. **Compilation à la demande** — `getAst()` parse une expression et met le résultat en cache pour réutilisation
2. **Cache interne** — LRU automatique, transparent, plafonné à 500 entrées
3. **Export / import** — `exportAst()` et `importAst()` permettent de stocker un AST compilé en base ou fichier, et de le recharger sans repasser par le Lexer/Parser

## API

```php
public function getAst(string $expression): Node
public function exportAst(string $expression): string
public function importAst(string $serialized): Node

public function clearCache(): self
public function cacheSize(): int
```

L'objet `Node` retourné est une interface implémentée par les classes :
`LiteralNode`, `VariableNode`, `UnaryNode`, `BinaryNode`, `InNode`, `FunctionNode`, `TernaryNode`.

## Comportements

### `getAst(string $expression): Node`

Compile l'expression et retourne l'AST. Met en cache le résultat pour réutilisation.

**Pipeline interne** :
1. Canonicalisation de l'expression pour la clé de cache (collapse de whitespaces hors littéraux)
2. Si la clé existe dans le cache, retour direct (et déplacement LRU)
3. Sinon : `Lexer::tokenize()` puis `Parser::parse()` ; mise en cache du résultat

**Exemples** :
```php
$ast1 = $eval->getAst('a > 0');
$ast2 = $eval->getAst('a > 0');
// $ast1 === $ast2 (même instance, servie depuis le cache)

$ast3 = $eval->getAst('a>0');   // pas d'espaces
// $ast3 !== $ast1 — clés de cache distinctes (voir canonicalisation)
```

**Exceptions levées** :
- `SyntaxErrorException` — erreur de lex ou de parse. Le cache n'est **pas modifié** dans ce cas (pas d'éviction préventive).

### Cache LRU

Caractéristiques :
- Taille maximale : configurable via le constructeur (défaut : **500 entrées**, voir ci-dessous)
- Politique d'éviction : LRU simple (la plus ancienne utilisation est supprimée quand le cache est plein)
- Implémentation : tableau PHP avec re-positionnement à chaque hit (unset + ré-assignation)
- Atomicité : si parsing échoue, aucune éviction n'a lieu (le cache reste cohérent)
- **`0` désactive le cache** : les expressions sont re-parsées à chaque appel (utile pour les tests ou les contextes mémoire contraints)

### Cache configurable via le constructeur

La taille du cache est paramétrée à la construction :

```php
// Défaut : 500 entrées
$eval = new ExpressionEvaluator();

// Cache large pour un batch avec beaucoup d'expressions distinctes
$eval = new ExpressionEvaluator(cacheMaxSize: 2000);

// Cache désactivé
$eval = new ExpressionEvaluator(cacheMaxSize: 0);
```

`$cacheMaxSize` doit être `>= 0` ; une valeur négative lève `\InvalidArgumentException`.

**Justification du défaut 500** : un seuil bas comme 50 obligerait à de fréquentes évictions sur les usages backoffice ; un seuil trop haut (10000+) consomme de la mémoire sans gain réel. 500 couvre largement les cas observés.

### Canonicalisation de la clé de cache

La clé de cache **n'est pas** l'expression brute, c'est une version normalisée :
- Les runs de whitespaces (espaces, tabs, NBSP) sont collapsés à un seul espace
- Les whitespaces de tête et de queue sont supprimés
- Les whitespaces **à l'intérieur de littéraux quotés** sont préservés

```php
// Toutes ces formes partagent la MÊME entrée de cache :
"a > 1"
"  a > 1  "
"a  >  1"
"a\t>\t1"
"a\xC2\xA0>\xC2\xA01"  // NBSP

// Mais ces deux-là sont DIFFÉRENTES :
"a = 'x  y'"
"a = 'x y'"
// → littéraux distincts (deux espaces vs un)

// Et ces deux-là aussi, malgré l'équivalence sémantique :
"1+1"   → clé "1+1"
"1 + 1" → clé "1 + 1"
```

**Justification de la limitation** : la canonicalisation ne normalise pas les espaces autour des opérateurs. Le faire imposerait une tokenisation partielle au niveau du cache, dupliquant la grammaire du Lexer. Le compromis : fragmentation sous-optimale du cache, mais simplicité et robustesse.

**Recommandation** : si vous générez des expressions programmatiquement et voulez maximiser le hit rate, utilisez un style d'espacement cohérent.

### Robustesse UTF-8

La canonicalisation utilise le mode `/u` de PCRE. Sur UTF-8 invalide :
- Fallback sur l'expression brute comme clé (plutôt que de risquer une clé vide qui collisionnerait avec toutes les autres entrées invalides)
- Le parsing downstream rejettera l'input invalide avec une erreur claire

### `exportAst(string $expression): string`

Compile l'expression et retourne sa forme sérialisée pour stockage externe. Le format est une **enveloppe JSON versionnée** :

```json
{"v": 1, "ast": "<PHP-serialized-AST>"}
```

Le champ `v` identifie la version du format. Tout changement incompatible de la structure des Node bumpe cette version, et `importAst()` rejettera les payloads d'anciennes versions avec un message clair.

```php
$serialized = $eval->exportAst('cart.total > threshold');
// Stocker $serialized en base...

$ast = $eval->importAst($serialized);
$result = $eval->evaluateAst($ast, $context);
```

**Exceptions levées** :
- `SyntaxErrorException` — comme `getAst()`

### `importAst(string $serialized): Node`

Désérialise et **valide** un AST exporté. Renvoie un `Node` utilisable avec les méthodes `evaluateAst*` / `explainAst`.

Validation effectuée :
1. **Décodage de l'enveloppe JSON** : vérifie que le payload est bien du JSON avec les champs `v` et `ast`.
2. **Vérification de version** : si `v` ne correspond pas à `AST_EXPORT_VERSION` (actuellement `1`), rejet immédiat avec un message demandant de re-compiler.
3. **Restriction des classes** : `unserialize()` est appelé avec `allowed_classes` limité à la hiérarchie de Node de la lib. Aucune classe externe ne peut être instanciée.
4. **Détection de cycles** : l'AST est walké via `SplObjectStorage` pour détecter toute référence cyclique. Les ASTs cycliques sont rejetés.
5. **Limite de profondeur** : 200 niveaux maximum (`IMPORT_AST_MAX_DEPTH`), miroir de la limite d'évaluation. Au-delà, rejet.

**Exceptions levées** :
- `\InvalidArgumentException` — payload invalide (JSON malformé, champs manquants, version incompatible, classe non autorisée, cycle détecté, profondeur dépassée)

```php
// Import d'un payload d'une ancienne version de la lib :
$ast = $eval->importAst($oldPayload);
// → InvalidArgumentException: 'importAst(): AST export version mismatch — got v0, expected v1.
//    Re-compile the expression with exportAst() to refresh the stored payload.'
```

### Politique de versioning

La constante interne `AST_EXPORT_VERSION` (actuellement `1`) est bumpée à chaque changement incompatible de la structure des Node (nouvelles propriétés, classes renommées, nœuds supprimés). Cela force le caller à re-compiler les expressions stockées plutôt que de laisser tourner un AST silencieusement mal-interprété.

### Sécurité d'`importAst()`

⚠️ **Ne JAMAIS appeler `importAst()` avec une donnée d'origine non sûre.**

Même avec `allowed_classes` restreint, `unserialize()` peut avoir d'autres surprises selon les versions de PHP. La validation effectuée par la lib est une **défense en profondeur**, pas un blanc-seing pour désérialiser des entrées utilisateur arbitraires.

Cas d'usage légitime : un AST exporté **par votre propre application**, stocké dans **votre propre base de données ou cache**, sous votre contrôle. Le caller assume la confiance dans la provenance.

### Partage de nœuds (DAG-like)

La détection de cycles utilise un suivi du **chemin actif** (`attach()` à la descente, `detach()` à la remontée). Conséquence : un même nœud référencé par deux frères (par exemple le même `LiteralNode` partagé entre deux arguments de fonction) **n'est pas** considéré comme cyclique.

```
                FunctionNode
               /            \
        LiteralNode(5)   LiteralNode(5)   ← même instance, OK
```

Cette tolérance est délibérée : elle laisse la porte ouverte à une éventuelle optimisation future qui internalises les littéraux identiques.

### `clearCache(): self` et `cacheSize(): int`

- `clearCache()` : vide intégralement le cache. Renvoie `$this` pour chaînage.
- `cacheSize()` : nombre d'entrées actuellement en cache.

Typiquement utile pour des tests, ou pour libérer de la mémoire après un batch volumineux.

```php
$eval->clearCache();
assert($eval->cacheSize() === 0);
```

## Décisions de design

### Cache transparent et automatique

Le cache fonctionne sans intervention de l'appelant. Évaluer la même expression 1000 fois n'entraîne qu'un seul parse, sans configuration. L'appelant peut ignorer son existence.

### Cache configurable

La taille du cache (défaut 500) est paramétrée via le constructeur. `0` désactive le cache. Voir la section "Cache LRU" pour les détails.

### Cache indépendant par instance

Le cache est une propriété d'instance, pas statique. Deux instances de `ExpressionEvaluator` ne partagent pas leur cache. C'est logique : les fonctions custom enregistrées via `registerFunction()` sont aussi par instance, donc deux instances peuvent avoir des résolutions différentes pour la même expression.

### Export : enveloppe JSON versionnée

L'export produit un JSON `{"v": 1, "ast": "<serialize()>"}`. Ce wrapping ajoute :
- **Versioning** : détection immédiate des payloads obsolètes au lieu d'un `unserialize()` silencieusement incorrect.
- **Interopérabilité minimale** : le JSON est lisible par des outils non-PHP pour inspecter la version, même si l'AST sérialisé reste opaque.

Inconvénients :
- Format légèrement plus volumineux (overhead de l'enveloppe JSON, marginal).
- Couplage à la structure interne des classes Node (un refactoring de signature peut invalider des AST exportés en base) — mais la version permet de détecter et gérer ce cas proprement.

### Validation séparée du désérialisation

La validation (cycles + profondeur) n'est **pas** déléguée à PHP — elle est faite explicitement après `unserialize()`. Raison : PHP supporte les références cycliques en sérialisation, donc `unserialize()` les recrée silencieusement. Sans validation, un AST cyclique resterait correct du point de vue PHP mais ferait stack-overflow l'évaluateur.

L'évaluateur a aussi son propre garde-fou (`MAX_EVAL_DEPTH`), mais la validation à l'import donne une erreur plus précoce et plus claire.

## Limitations connues

### Pas de cache distribué

Le cache est local à l'instance. Sur une architecture multi-process / multi-serveur, chaque process maintient son propre cache. Pour partager, utilisez `exportAst()` + un cache externe (Redis...) + `importAst()`.

### Taille de cache configurable, mais par instance uniquement

La taille du cache se règle via le constructeur (`cacheMaxSize`, défaut 500, `0` pour désactiver). Voir la section "Cache configurable via le constructeur". Elle reste néanmoins **par instance** : pas de réglage global, et chaque instance a son propre cache (voir "Cache indépendant par instance").

### Pas de TTL

Une entrée reste en cache tant qu'elle n'est pas évincée par LRU. Pas d'invalidation temporelle. En pratique, peu pertinent : un AST compilé est immuable (pas de dépendance externe).

### Couplage de format pour l'export

Le format `serialize()` est lié à la structure interne. Voir Décisions de design.

## Limites et constantes

| Constante | Valeur | Localisation | Description |
|---|---|---|---|
| `MAX_DEPTH` | 64 | `ContextResolver` | Profondeur max de contexte avant `CircularContextException` |
| `MAX_EVAL_DEPTH` | 200 | `Evaluator` | Profondeur max d'évaluation d'AST (modes strict et safe) |
| `IMPORT_AST_MAX_DEPTH` | 200 | `ExpressionEvaluator` | Profondeur max acceptée par `importAst()` |
| `AST_EXPORT_VERSION` | 1 | `ExpressionEvaluator` | Version du format d'export, vérifiée à l'import |
| `CACHE_MAX_SIZE` (défaut) | 500 | `ExpressionEvaluator` | Taille max du cache LRU (configurable via constructeur) |

Ces valeurs ne sont pas configurables à l'exception de la taille du cache (voir constructeur). Elles ont été choisies pour couvrir largement les cas réels tout en protégeant contre les abus — aucune n'a posé de problème en pratique.