# Fonctions

## Vue d'ensemble

La lib expose un système d'appel de fonctions dans les expressions. Deux catégories :
- **Fonctions built-in** : enregistrées automatiquement par l'évaluateur (arithmétique, dates, chaînes, listes, casting de types…)
- **Fonctions custom** : enregistrées dynamiquement via `registerFunction()` à l'initialisation

Toutes les fonctions partagent la même mécanique d'appel et la même politique de gestion d'erreurs : du point de vue d'une expression, un built-in et un custom sont indistinguables.

## API

```php
public function registerFunction(string $name, callable $fn): self
public function getFunctions(): array          // string[], trié
public function callFunction(string $name, array $resolvedArgs): mixed
```

## Comportements généraux

### `registerFunction(string $name, callable $fn): self`

Enregistre une fonction sous le nom donné. **Écrase silencieusement** une fonction existante du même nom, qu'elle soit built-in ou précédemment custom. C'est délibéré : on peut spécialiser `round()` ou `today()` pour un projet sans toucher au code de la lib.

```php
$eval->registerFunction('greet', fn(string $name): string => "Hello, $name!");
$eval->evaluate('greet(user.name)', ['user' => ['name' => 'Alice']]);
// → "Hello, Alice!"

// Override d'un built-in :
$eval->registerFunction('today', fn(): string => '2026-01-01');  // pour tests
```

**Arity capturée à l'enregistrement** : via réflexion, la lib stocke `min` (paramètres requis) et `max` (total des paramètres, ou `PHP_INT_MAX` si variadique). Cette arity est validée à chaque appel — pas de réflexion à runtime.

### `getFunctions(): string[]`

Retourne la liste des noms de fonctions enregistrées (built-in + custom), triée alphabétiquement.

```php
$eval->getFunctions();
// ['abs', 'avg', 'bool', 'ceil', 'clamp', 'coalesce', 'concat', 'contains', ...]
```

Utile pour la validation d'expressions utilisateur en backoffice (avant évaluation), ou pour l'autocomplétion d'éditeurs.

### `callFunction(string $name, array $resolvedArgs): mixed`

Appelle une fonction par son nom avec des arguments **déjà résolus** (valeurs PHP, pas des nœuds d'AST).

Cette méthode est exposée principalement pour l'`ExpressionExplainer` : elle évite la double évaluation des arguments quand on a déjà tracé les valeurs intermédiaires. Pour l'usage courant, c'est l'évaluation de `FunctionNode` qui s'en charge en interne.

**Validations effectuées** :
1. La fonction existe (`EvaluatorException` sinon)
2. Le nombre d'arguments respecte les bornes (`TypeErrorException` sinon)
3. Si le corps de la fonction lève, l'exception est gérée selon la politique ci-dessous

### Politique d'exceptions des fonctions

Quand le corps d'une fonction (built-in ou custom) lève une exception, le traitement dépend du type :

| Type d'exception levée | Traitement |
|---|---|
| `EvaluatorException` et descendantes (`TypeErrorException`, `UnknownVariableException`, etc.) | Propagée **inchangée** |
| Tout autre `\Throwable` (`RuntimeException`, `LogicException`, `Error`, `TypeError` natif, etc.) | Encapsulée dans `TypeErrorException` avec l'originale en `previous` |

**Justification** :
- Les exceptions de la lib transitent telles quelles, ce qui permet à une fonction custom de déléguer proprement à `evaluate()` ou `getContextValue()` en interne
- Toute autre exception PHP brute est wrappée pour garantir une API d'erreur cohérente — `evaluate()` ne laisse jamais fuiter du `TypeError` natif ou autre

**Conséquence pratique** : du code utilisateur dans une fonction custom peut lever ce qu'il veut, l'appelant de `evaluate()` n'aura affaire qu'à des exceptions de la lib.

### Validation d'arity

PHP est silencieusement permissif avec les arguments en trop sur les closures non-variadiques (il les ignore). La lib **rejette** ce comportement : passer trop d'arguments lève une `TypeErrorException` claire.

```php
$eval->evaluate('round(1, 2, 3)', []);
// TypeErrorException: Function "round" expects between 1 and 2 arguments, 3 given
```

Le message inclut les bornes selon les cas :
- `exactly N` si min == max
- `at least N` si variadique (max == PHP_INT_MAX)
- `between N and M` sinon

## Catalogue des built-in

Les built-in sont enregistrés à la construction de l'Evaluator. Ils peuvent être surchargés via `registerFunction()`.

### Casting de types

#### `int(val)` → `int`

Convertit en entier. **Tronque vers zéro** pour les floats (comportement de `(int)` PHP) :
- `int(3.7) → 3`, `int(3.5) → 3`, `int(-3.7) → -3`, `int(-3.5) → -3`
- Acceptés : `int` (passthrough), `float` (truncation), `string` entière (regex `/^-?[0-9]+$/`)
- Rejetés : `string` flottante (`'3.7'`), `bool`, `null`, autre

⚠️ Si vous voulez de l'arrondi, utilisez `round()`. Pour floor/ceil, utilisez `floor()` / `ceil()`.

#### `float(val)` → `float`

Convertit en flottant. Accepte `int`, `float`, et toute chaîne `is_numeric()` (donc `'3.14'`, `'1e5'`, etc.). Rejette le reste.

#### `bool(val)` → `bool`

**Stricte**, plus restrictive que `(bool)` PHP natif. Accepte uniquement :
- `bool` (passthrough)
- `int` strictement 0 ou 1
- `float` strictement 0.0 ou 1.0
- `string` `'0'`, `'1'`, `'true'`, `'false'` (la dernière est insensible à la casse)

Tout le reste (2, [], null, autre chaîne) → `TypeErrorException`.

#### `str(val)` → `string`

Convertit en chaîne. Pour les floats, formatage **sans notation scientifique** :
- Adapté à la magnitude (14 décimales en deçà de 1, ajusté au-dessus pour totaliser 15 chiffres significatifs)
- Trailing zeros supprimés
- Rejette NaN, INF, |val| >= 1e15, |val| < 1e-10 (voir `formatFloatForString()`)

Exemples : `str(1.0) → '1'`, `str(1.50) → '1.5'`, `str(0.000001) → '0.000001'`

### Arithmétique

#### `round(val, precision = 0)` → `float`

Arrondit avec `precision` décimales. Borne : `0 <= precision <= 14` (aligné sur `PHP_FLOAT_DIG = 15` chiffres significatifs — au-delà de 14, `round()` n'a plus de sens physiquement).

#### `floor(val)` → `float`, `ceil(val)` → `float`

Arrondi vers le bas / vers le haut.

#### `abs(val)` → `int|float`

Valeur absolue. Type préservé.

#### `min(a, b)` → `int|float`, `max(a, b)` → `int|float`

⚠️ **Exactement 2 arguments**. Pour minimum/maximum d'une liste, utiliser `min_of()` / `max_of()`. C'est délibéré : `min(a, b, c)` peut prêter à confusion (variadique fixe ou liste ?), on force l'explicite.

#### `pow(base, exp)` → `int|float`

Puissance. Renvoie `int` si les deux opérandes sont `int` et que le résultat tient dans `PHP_INT_MAX`, sinon `float`.

**Gardes spécifiques** :
- Base négative avec exposant non-entier → rejet (résultat serait NaN)
- Base zéro avec exposant négatif → rejet (division par zéro)
- Résultat NaN ou INF → rejet avec message clair

C'est la **seule** fonction où NaN/INF sont rattrapés **dans la fonction** (et pas seulement à l'opérateur en aval). Justification : `pow(10, 1000)` est le cas le plus courant de débordement, le message côté `pow()` est plus actionnable que "operator '+' value is INF".

#### `sqrt(val)` → `float`

Racine carrée. Rejette les valeurs négatives.

#### `clamp(val, min, max)` → `int|float`

Borne `val` dans `[min, max]`. Rejette si `min > max`.

#### `is_finite(val)` → `bool`

**Échappatoire pour inspecter NaN/INF** : la seule façon de tester ces valeurs depuis une expression, puisque tout opérateur arithmétique ou de comparaison les rejette.

```php
'is_finite(x)'  // true si x est un nombre fini, false si NaN/INF, TypeError si pas un nombre
```

### Chaînes

| Fonction | Description |
|---|---|
| `length(val)` | Longueur en caractères UTF-8 (string) ou nombre d'éléments (array) |
| `count(list)` | Nombre d'éléments d'un tableau (équivalent de `length()` pour les listes uniquement — `TypeError` si non-array). Rend l'intention explicite quand on travaille sur des listes. |
| `upper(s)` | Majuscules (`mb_strtoupper`) |
| `lower(s)` | Minuscules (`mb_strtolower`) |
| `trim(s)` | Trim PHP standard |
| `contains(haystack, needle)` | `str_contains` |
| `startsWith(haystack, needle)` | `str_starts_with` |
| `endsWith(haystack, needle)` | `str_ends_with` |
| `substr(s, start, length?)` | `mb_substr` |
| `concat(a, b, ...)` | Concatène, accepte string/int/float (float formaté comme `str()`) |
| `replace(subject, search, replace)` | `str_replace` |

### Listes (agrégats)

| Fonction | Description | Liste vide |
|---|---|---|
| `sum(list)` | Somme | `0` |
| `avg(list)` | Moyenne | `TypeErrorException` |
| `min_of(list)` | Minimum | `TypeErrorException` |
| `max_of(list)` | Maximum | `TypeErrorException` |

Toutes valident que les éléments sont numériques ; rejettent l'élément fautif avec son index.

### Autres

#### `coalesce(a, b, c, ...)` → `mixed`

Renvoie le premier argument non-`null`. Complément n-aire de l'opérateur `??`.

```php
'coalesce(a, b, c, 0)'  // 0 si a, b, c sont tous null
```

### Dates

Formats supportés : `Y-m-d`, `Y-m-d H:i`, `Y-m-d H:i:s`, `Y-m-d\TH:i`, `Y-m-d\TH:i:s` (ISO 8601 avec séparateur `T`, courant dans les APIs JSON/REST).

| Fonction | Description |
|---|---|
| `today()` | Date courante au format `Y-m-d` |
| `now()` | Date+heure courante au format `Y-m-d H:i:s` |
| `year(date)` | Année (int) |
| `month(date)` | Mois (int) |
| `day(date)` | Jour (int) |
| `hour(date)` | Heure (int) |
| `minute(date)` | Minute (int) |
| `dateDiff(date1, date2)` | Différence en jours entiers, négatif si `date1 > date2` |
| `dateAdd(date, n, unit)` | Ajoute `n` unités à la date. `unit` ∈ `{day, month, year, hour, minute}`. `n` peut être négatif. |

**Format préservé par `dateAdd()`** : `Y-m-d` reste `Y-m-d`, `Y-m-d H:i` reste `Y-m-d H:i`, `Y-m-d H:i:s` reste `Y-m-d H:i:s`, et les variantes ISO 8601 avec `T` restent avec `T`.

**Timezone de `today()` et `now()`** : utilisent la timezone du serveur PHP (`date_default_timezone_get()`). Ce comportement est intentionnel et documenté. Pour une timezone spécifique, enregistrez une fonction custom via `registerFunction()` — c'est le point d'extension recommandé.

**Overflow mois/année** : pas de "snap to end of month" :
```
dateAdd('2026-01-31',  1, 'month') → '2026-03-03'   (et non '2026-02-28')
dateAdd('2024-02-29',  1, 'year')  → '2025-03-01'   (et non '2025-02-28')
dateAdd('2026-03-31', -1, 'month') → '2026-03-03'   (et non '2026-02-28')
```

Si vous avez besoin d'une logique "fin de mois", elle doit être implémentée au niveau de l'expression (fonction custom).

## Décisions de design

### Tous les built-in sont surchargeables

Pas de "fonction protégée" : un projet qui veut redéfinir `today()` (pour les tests) ou `round()` (pour des règles métier spécifiques) le fait sans gymnastique.

**Conséquence à connaître** : un `registerFunction('round', ...)` invalide les hypothèses du reste du projet si plusieurs codes partagent l'instance d'`ExpressionEvaluator`. À utiliser avec discernement.

### `min` / `max` à exactement 2 arguments

Délibéré pour l'explicite : `min(a, b)` opère sur deux valeurs, `min_of([a, b, c])` opère sur une liste. Pas d'ambiguïté variadique.

### NaN / INF rejetés au plus tôt

La politique générale est : NaN/INF ne transitent jamais **à travers un opérateur** d'évaluation. Conséquence :
- À chaque opérateur arithmétique, comparaison, égalité (et `-` unaire) → rejet `TypeErrorException`
- Dans `pow()` spécifiquement (rejet en interne pour message clair)
- **En revanche**, la résolution d'une variable et le retour d'une fonction ne rejettent **pas** : une valeur NaN/INF peut transiter jusqu'à `is_finite()` sans entrer dans un opérateur (c'est ce qui rend `is_finite()` exploitable).

Seule échappatoire pour inspecter : `is_finite()`, qui inspecte sans faire transiter dans un opérateur.

### Politique uniforme `TypeErrorException`

Tous les rejets de typage (mauvaise arity, type incorrect, valeur hors borne, NaN/INF, etc.) lèvent `TypeErrorException` — pas `InvalidArgumentException` ou autre. Permet une distinction nette côté caller entre :
- `SyntaxErrorException` : problème de syntaxe (compile-time)
- `UnknownVariableException` : problème de contexte (runtime, données)
- `TypeErrorException` : problème de typage (runtime, code/logique)
- `EvaluatorException` : problème structurel (AST corrompu, profondeur, fonction inconnue)

### Arity capturée une seule fois

La réflexion (`ReflectionFunction`) est utilisée une seule fois, à l'enregistrement, pour capturer `min` et `max`. Plus de réflexion à chaque appel — l'overhead est concentré sur `registerFunction()`.

## Limitations connues

### Pas d'introspection de la signature

`getFunctions()` retourne uniquement les noms. Pas d'API pour récupérer la signature (types des paramètres, nom des arguments, description…). Pour de l'aide contextuelle dans un éditeur, il faudrait l'ajouter — c'est conceptuellement faisable via la réflexion mais aucune API n'est exposée à ce jour.

### Pas de versioning des built-in

Une mise à jour de la lib peut changer le comportement d'un built-in (par exemple si on corrige un edge case de `dateAdd`). Pas de mécanisme de version par fonction.

### Pas de namespacing

Les noms de fonctions sont à plat dans un dictionnaire unique. Pas de `math.round` vs `string.length`. Une fonction `length` enregistrée par l'utilisateur écrase la built-in.