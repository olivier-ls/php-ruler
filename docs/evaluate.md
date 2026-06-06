# Évaluation principale

## Vue d'ensemble

L'évaluation principale est le point d'entrée le plus courant de la lib : on donne une expression sous forme de chaîne, un contexte (tableau de variables), et on récupère le résultat évalué. C'est le **mode strict** : toute anomalie (variable manquante, erreur de type, division par zéro, etc.) lève une exception.

Pour un mode tolérant qui collecte les variables manquantes au lieu de lever, voir `evaluate-safe.md`.

L'évaluation principale s'utilise via la classe `ExpressionEvaluator` (point d'entrée public de la lib).

## API

Toutes les méthodes sont publiques, exposées sur `ExpressionEvaluator`.

### Évaluation à partir d'une expression (chaîne)

```php
public function evaluate(string $expression, array $context): mixed
public function evaluateBoolean(string $expression, array $context): bool
public function evaluateNumeric(string $expression, array $context): float
```

### Évaluation à partir d'un AST pré-compilé

```php
public function evaluateAst(Node $ast, array $context): mixed
public function evaluateAstBoolean(Node $ast, array $context): bool
public function evaluateAstNumeric(Node $ast, array $context): float
```

Les variantes `*Ast()` permettent de court-circuiter le Lexer et le Parser quand on a déjà un AST compilé (typiquement via `getAst()` ou `importAst()`, voir `ast-management.md`). Sémantique d'évaluation strictement identique aux variantes "expression".

## Comportements

### `evaluate(string $expression, array $context): mixed`

Évalue l'expression dans le contexte donné et renvoie le résultat. Le type de retour dépend de l'expression : `int`, `float`, `string`, `bool`, `null`, ou `array` (uniquement quand l'expression est un littéral liste ou une fonction renvoyant un tableau).

**Pipeline interne** : lexing → parsing (avec mise en cache de l'AST, voir `ast-management.md`) → évaluation.

**Exemples** :
```php
$eval = new ExpressionEvaluator();

$eval->evaluate('1 + 2', []);                         // 3 (int)
$eval->evaluate('cart.total > 100', ['cart' => ['total' => 150]]);  // true (bool)
$eval->evaluate('upper(name)', ['name' => 'alice']);  // 'ALICE' (string)
$eval->evaluate('a ?? 0', ['a' => null]);             // 0
$eval->evaluate('[1, 2, 3]', []);                     // [1, 2, 3]
```

**Exceptions levées** :
- `SyntaxErrorException` — expression mal formée (lex ou parse)
- `UnknownVariableException` — variable référencée mais absente du contexte
- `TypeErrorException` — opération sur des types incompatibles, NaN/INF, division par zéro, overflow d'entier, mauvaise arity de fonction
- `EvaluatorException` — profondeur d'AST dépassée (200 niveaux), nœud d'AST inconnu (corruption)
- `CircularContextException` — non levée par `evaluate()` directement (uniquement par `describeContext()`)

### `evaluateBoolean(string $expression, array $context): bool`

Comme `evaluate()`, mais valide que le résultat est un booléen strict.

**Exemples** :
```php
$eval->evaluateBoolean('a > 0', ['a' => 5]);    // true
$eval->evaluateBoolean('1 + 1', []);            // TypeErrorException — résultat int, pas bool
$eval->evaluateBoolean('a = 1', ['a' => 1]);    // true (l'opérateur '=' renvoie bien un bool)
```

**Exceptions supplémentaires** :
- `TypeErrorException` si le résultat n'est pas strictement un `bool` (pas de coercition truthy/falsy)

### `evaluateNumeric(string $expression, array $context): float`

Comme `evaluate()`, mais valide que le résultat est numérique (int ou float) et le caste en `float`.

**Retour toujours `float`** : même pour un calcul entier, le retour est un `float`. `evaluateNumeric('5 + 3', [])` retourne `8.0`, pas `8`. C'est intentionnel : le type de sortie est uniforme et prévisible. Dans un contexte métier (prix, taux, quantités), le `float` est la valeur d'échange standard. Si l'appelant a besoin d'un `int` exact, il doit normaliser après coup.

**Exemples** :
```php
$eval->evaluateNumeric('1 + 2', []);         // 3.0 (float, même si calcul int)
$eval->evaluateNumeric('cart.total * 1.2', ['cart' => ['total' => 100]]);  // 120.0
$eval->evaluateNumeric('upper(name)', ['name' => 'x']);  // TypeErrorException
```

**Exceptions supplémentaires** :
- `TypeErrorException` si le résultat n'est ni `int` ni `float`

### Variantes `evaluateAst*` 

Sémantique identique aux trois méthodes ci-dessus, mais prennent un objet `Node` (AST) en premier argument au lieu d'une chaîne. Évite la phase lex/parse. Utile pour :
- Évaluer plusieurs fois la même expression avec des contextes différents (parse une seule fois)
- Évaluer un AST chargé depuis un cache externe via `importAst()`

```php
$ast = $eval->getAst('a > threshold');  // parse une fois
foreach ($items as $item) {
    if ($eval->evaluateAstBoolean($ast, ['a' => $item, 'threshold' => 10])) { /* ... */ }
}
```

## Décisions de design

### Mode strict par défaut

L'évaluation principale ne tolère **aucune anomalie silencieuse**. Cette politique est appliquée de façon systématique :

- Une variable absente lève (pas de fallback implicite à `null`)
- Les opérateurs logiques (`AND`, `OR`, `NOT`, ternaire) exigent un `bool` strict — pas de coercition truthy/falsy à la PHP (où `"false" AND true` vaudrait `true`)
- Les opérateurs arithmétiques exigent `int|float` — `-null` n'est pas `0`, c'est une `TypeErrorException`
- Les comparaisons de types incompatibles lèvent (par exemple `'a' > 5`)
- Une comparaison directe d'arrays (`= / !=`) est interdite (utiliser `IN`)

L'objectif est le principe "no surprise" : un développeur PHP est habitué aux coercitions silencieuses, ce qui rend les expressions difficiles à auditer. La lib refuse cette tolérance.

### Cache d'AST transparent

`evaluate()` (et toutes les variantes "expression") passent par `getAst()`, qui maintient un cache LRU interne (taille max 500 entrées) indexé par l'expression canonicalisée. Évaluer deux fois la même expression ne re-parse pas.

La canonicalisation se limite à un collapse des runs de whitespace **hors littéraux quotés**. Conséquence : `"1+1"` et `"1 + 1"` produisent deux entrées de cache distinctes (mais le même AST). Voir `ast-management.md` pour les détails et la justification.

### Garde-fou de profondeur

Une profondeur d'évaluation maximale de 200 niveaux est imposée (`MAX_EVAL_DEPTH` dans `Evaluator`). Au-delà, `EvaluatorException` est levée. Cette limite protège contre :
- Les expressions pathologiques (imbrication excessive)
- Les AST cycliques qui auraient pu passer la validation d'`importAst()`

Le compteur est partagé entre les modes strict et safe et il est remis à zéro en cas d'exception, donc une évaluation échouée ne "pollue" pas la suivante.

### Validation des valeurs entrantes du contexte

Quand une variable est résolue depuis le contexte (`resolveVariable`), sa valeur est validée : seuls les scalaires, `null`, et tableaux de scalaires/null sont acceptés. Les objets, closures, ressources lèvent une `TypeErrorException` dès la résolution avec un message identifiant le chemin fautif (par exemple `Variable "cart.items[0]"`).

Cette validation descend récursivement dans les tableaux indexés (les tableaux associatifs sont déjà décomposés en chemins dot-notation par le `ContextResolver`).

### NaN et INF interdits dans le pipeline

NaN et INF ne sont pas autorisés à participer aux calculs ou comparaisons. Cette règle est appliquée **à chaque opérateur** (arithmétique, comparaison, égalité, `-` unaire) :
- Une valeur NaN/INF venant du contexte (`resolveVariable`) ou d'un retour de fonction custom n'est **pas** rejetée à ce stade : elle peut transiter jusqu'à `is_finite()`.
- Dès qu'elle entre dans un opérateur, `TypeErrorException` est levée.

**Échappatoire** : la fonction `is_finite()` permet de tester explicitement (renvoie `bool` sans lever). C'est le seul moyen d'inspecter un NaN/INF venant du contexte ou d'une fonction custom.

### Gestion des exceptions des fonctions custom

Quand une fonction enregistrée via `registerFunction()` lève une exception pendant son exécution :
- Les exceptions de la lib (`EvaluatorException` et descendantes : `TypeErrorException`, `UnknownVariableException`, etc.) transitent **inchangées**. Cela permet à une fonction custom de déléguer à `evaluate()` ou `getContextValue()` en interne.
- **Toute autre exception** (`RuntimeException`, `LogicException`, `Error`, etc.) est encapsulée dans une `TypeErrorException` avec l'exception d'origine en `previous`.

Garantie : `evaluate()` ne propage **jamais** d'exception PHP brute depuis du code utilisateur. Voir `functions.md` pour les détails sur l'enregistrement et l'arity.

## Limitations connues

### Variables nommées comme des mots-clés

Les identifiants `and`, `or`, `not`, `in` (insensibles à la casse) sont tokenisés comme opérateurs logiques avant d'être interprétés comme identifiants. Conséquence : une variable racine nommée `In`, `AND`, etc. est inaccessible directement.

```php
$eval->evaluate('In', ['In' => 5]);  // SyntaxErrorException
```

Les chemins composés ne sont pas concernés tant que le segment de tête n'est pas un mot-clé : `user.in` fonctionne. Pour exposer une donnée dont la clé racine collisionne avec un mot-clé, l'encapsuler sous un parent : `['root' => ['in' => $value]]` accessible via `root.in`.

Mots-clés réservés (insensibles à la casse) : `and`, `or`, `not`, `in`, `true`, `false`, `null`.

### Clés contenant un point littéral

Le `.` est toujours interprété comme séparateur de chemin. Une clé de contexte qui contient un `.` littéral est inaccessible via la notation pointée. Voir `context.md`.

### UnknownVariableException levée depuis une fonction custom

Si une fonction custom appelle `getContextValue('x', [])` en interne et lève `UnknownVariableException`, cette exception traverse `evaluate()` inchangée — mais le mode safe (`evaluateSafe()`) **ne pourra pas collecter** cette variable dans `missingVars` (la lib ne peut pas savoir si la variable manquante était attendue ou non). Les auteurs de fonctions custom doivent soit passer un défaut à `getContextValue()`, soit attraper l'exception eux-mêmes.