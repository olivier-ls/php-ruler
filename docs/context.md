# Accès au contexte

## Vue d'ensemble

Le contexte est un tableau PHP associatif fourni à chaque évaluation. Il contient les variables que l'expression peut référencer. Les chemins sont exprimés en **notation pointée** (dot-notation) : `cart.total` accède à `$context['cart']['total']`.

Cette catégorie regroupe les helpers pour interagir directement avec un contexte, en dehors d'une évaluation :
- Résolution explicite de chemin avec ou sans valeur par défaut
- Test d'existence
- Description structurée du contexte (typiquement pour un backoffice qui affiche les variables disponibles)

Les méthodes sont exposées sur `ExpressionEvaluator` pour la commodité mais délèguent toutes au `ContextResolver` (classe statique, sans état).

## API

```php
public function getContextValue(string $path, array $context): mixed
public function getContextValueOrDefault(string $path, array $context, mixed $default = null): mixed
public function hasContextValue(string $path, array $context): bool
public function describeContext(array $context): array
```

## Comportements

### `getContextValue(string $path, array $context): mixed`

Résout un chemin pointé dans le contexte. Lève si le chemin n'existe pas.

```php
$ctx = ['cart' => ['total' => 150.0, 'items' => ['apple', 'bread']]];

$eval->getContextValue('cart.total', $ctx);       // 150.0
$eval->getContextValue('cart.items', $ctx);       // ['apple', 'bread']
$eval->getContextValue('cart', $ctx);             // ['total' => 150.0, 'items' => [...]]
$eval->getContextValue('cart.shipping', $ctx);    // UnknownVariableException
$eval->getContextValue('customer.email', $ctx);   // UnknownVariableException
```

**Exceptions levées** :
- `UnknownVariableException` — chemin introuvable. Le message indique le chemin demandé et, si la résolution a partiellement réussi, le segment qui a échoué.

Exemple de message : `Unknown variable: "cart.shipping" (failed at "cart.shipping")`. Pour un chemin entièrement absent dès le premier segment : `Unknown variable: "customer.email"`.

### `getContextValueOrDefault(string $path, array $context, mixed $default = null): mixed`

Comme `getContextValue()`, mais retourne `$default` au lieu de lever en cas de chemin absent.

```php
$eval->getContextValueOrDefault('cart.shipping', $ctx);          // null (défaut implicite)
$eval->getContextValueOrDefault('cart.shipping', $ctx, 0);       // 0
$eval->getContextValueOrDefault('cart.total', $ctx, 0);          // 150.0 (présent, défaut ignoré)
```

⚠️ Le défaut est retourné aussi si la valeur trouvée est `null` ? **Non** : seul l'absence du chemin déclenche le défaut. Une valeur `null` présente est renvoyée telle quelle.

```php
$ctx = ['a' => null];
$eval->getContextValueOrDefault('a', $ctx, 'fallback');  // null (présent, même si null)
$eval->getContextValueOrDefault('b', $ctx, 'fallback');  // 'fallback' (absent)
```

### `hasContextValue(string $path, array $context): bool`

Test pur d'existence du chemin, sans déclencher d'exception ni allouer.

```php
$eval->hasContextValue('cart.total', $ctx);     // true
$eval->hasContextValue('cart.shipping', $ctx);  // false
$eval->hasContextValue('cart', $ctx);           // true (un sous-tableau compte comme une "présence")
```

### `describeContext(array $context): array`

Retourne une description structurée du contexte sous forme de tableau, prête à être JSON-sérialisée. Typiquement utilisé par un backoffice pour lister les variables disponibles à l'utilisateur (autocomplétion, documentation).

```php
$ctx = [
    'cart' => ['total' => 150.0, 'currency' => 'EUR'],
    'customer' => ['vip' => true],
    'tags' => ['php', 'js'],
];

$eval->describeContext($ctx);
// [
//   ['path' => 'cart.total',    'type' => 'number',  'value' => 150.0],
//   ['path' => 'cart.currency', 'type' => 'string',  'value' => 'EUR'],
//   ['path' => 'customer.vip',  'type' => 'boolean', 'value' => true],
//   ['path' => 'tags',          'type' => 'list', 'itemType' => 'string', 'value' => ['php','js']],
// ]
```

**Politique de flattening** :
- Les **tableaux associatifs** sont aplatis en chemins pointés
- Les **listes indexées** (au sens `array_is_list()`) sont conservées comme valeurs terminales et typées `list`

Types possibles dans le champ `type` :
- `'number'` (int et float confondus)
- `'string'`
- `'boolean'`
- `'null'`
- `'list'`
- `'unknown'` (cas non couvert)

Pour les listes, un champ supplémentaire `itemType` indique :
- Le type commun si tous les éléments le partagent
- `'mixed'` si les éléments sont de types différents
- `'unknown'` si la liste est vide

**Exceptions levées** :
- `CircularContextException` — la structure dépasse `MAX_DEPTH` niveaux d'imbrication (64). En pratique, cela signale une référence circulaire (`$ctx['self'] = &$ctx`), puisque les contextes métier légitimes ne dépassent jamais quelques niveaux.

## Décisions de design

### Notation pointée stricte

Le `.` est **toujours** un séparateur de chemin. Il n'y a pas de mécanisme d'échappement.

Conséquence : une clé contenant un `.` littéral est **inaccessible** via ces méthodes. Si vos données contiennent des clés à points (par exemple des FQDN), encapsulez-les sous un parent :

```php
// Inaccessible :
$ctx = ['foo.bar' => 'value'];
$eval->getContextValue('foo.bar', $ctx);  // UnknownVariableException

// Encapsulé :
$ctx = ['data' => ['foo.bar' => 'value']];
$value = $eval->getContextValue('data', $ctx);  // ['foo.bar' => 'value']
// Puis $value['foo.bar'] en PHP standard
```

C'est cohérent avec l'usage : la lib est conçue pour des contextes métier (panier, client, commande), pas pour des structures techniques avec des clés arbitraires.

### Listes traitées comme valeurs terminales

`describeContext()` ne descend **pas** dans les listes indexées. Une liste est exposée telle quelle, avec son type d'éléments. Justification :
- Une liste est sémantiquement une valeur agrégée, pas une structure à explorer
- Aplatir une liste en `tags.0`, `tags.1`, `tags.2`… n'a pas de sens pour la plupart des usages métier (et empêche le `IN` de fonctionner sur ces chemins)

`getContextValue('tags', $ctx)` retourne le tableau complet, qu'on peut ensuite utiliser avec `IN` :

```php
$eval->evaluate('"php" IN tags', $ctx);  // true
```

### `MAX_DEPTH = 64` pour la détection de cycles

`describeContext()` détecte les références cycliques via une limite de profondeur (64 niveaux). C'est généreux : les contextes métier réels dépassent rarement 5-10 niveaux. Au-delà de 64, on présume une boucle.

Cette limite **ne s'applique pas** aux autres méthodes (`getContextValue`, `has`, `getOrDefault`) : elles font une descente itérative bornée par le chemin demandé, donc pas exposées au risque de cycle dans le contexte.

### `ContextResolver` static, sans état

La classe `ContextResolver` n'a pas d'état d'instance — toutes ses méthodes sont statiques. Choix simple :
- Pas d'instance à passer en dépendance
- Pas de cache entre appels (chaque résolution part de zéro)
- Idempotent et thread-safe par construction

Les méthodes d'`ExpressionEvaluator` ne sont qu'une commodité d'API (`$eval->getContextValue(...)` est strictement équivalent à `ContextResolver::resolve(...)`).

### `array_key_exists` et non `isset`

La résolution utilise `array_key_exists()`, pas `isset()`. Conséquence : une clé présente avec la valeur `null` **est considérée présente**.

```php
$ctx = ['a' => null];
$eval->hasContextValue('a', $ctx);            // true
$eval->getContextValue('a', $ctx);            // null
$eval->getContextValueOrDefault('a', $ctx);   // null (pas le défaut)
```

C'est cohérent : `null` est une valeur légitime dans le langage d'expression (`a = null`, `a ?? defaultValue`...), pas un marqueur d'absence.

## Limitations connues

### Pas de wildcards ni d'expressions de chemin

`cart.items[*].price` ou `**.email` ne sont pas supportés. Les chemins sont des constantes textuelles. Pour des opérations sur des sous-collections, utilisez les fonctions agrégatives (`sum`, `min_of`...) ou structurez le contexte différemment.

### Pas de validation du contexte en amont

Aucune méthode du type "le contexte est-il valide ?". `evaluate()` détecte les valeurs non supportées (objets, closures...) à la **résolution** des variables, pas à l'entrée. Conséquence : si une variable d'un objet est dans le contexte mais jamais référencée par l'expression, son invalidité passe inaperçue.

Si vous voulez valider en amont, parcourez le contexte vous-même ou utilisez `describeContext()` (qui lèvera sur cycles mais pas sur types non supportés — voir Points à arbitrer).

### Insensibilité aux mots-clés en racine

Une clé racine du contexte qui collisionne avec un mot-clé du langage (`and`, `or`, `not`, `in`, `true`, `false`, `null`) reste accessible via `getContextValue('and', $ctx)` (qui passe par `ContextResolver`, pas par le Lexer). Mais elle est **inaccessible depuis une expression** :

```php
$ctx = ['in' => 5];
$eval->getContextValue('in', $ctx);    // 5 (OK)
$eval->evaluate('in', $ctx);           // SyntaxErrorException
```

Voir `language-reference.md` pour les mots-clés réservés.