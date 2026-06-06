# php-ruler

Librairie PHP pure (sans extension) pour l'évaluation d'expressions. Pas de dépendances. PHP 8.2+.

## En une phrase

Vous donnez une expression sous forme de chaîne (`"cart.total > 100 AND customer.vip = true"`), un contexte (tableau PHP de variables), et vous obtenez le résultat évalué. Le tout de façon stricte, prévisible, et sans surprise de typage.

## Installation

```bash
composer require expreval/expreval
```

## Démarrage rapide

```php
use Ols\PhpRuler\ExpressionEvaluator;

$eval = new ExpressionEvaluator();

// Évaluation basique
$eval->evaluate('cart.total > 100', ['cart' => ['total' => 150]]);   // true
$eval->evaluate('round(cart.total * 1.2, 2)', ['cart' => ['total' => 100]]);  // 120.0
$eval->evaluate('upper(customer.name)', ['customer' => ['name' => 'alice']]);  // 'ALICE'

// Forcer un type de retour
$eval->evaluateBoolean('a > 0 AND b < 10', ['a' => 5, 'b' => 7]);   // true (bool garanti)
$eval->evaluateNumeric('price * qty', ['price' => 9.99, 'qty' => 3]); // 29.97 (float garanti)
```

## Langage

Le langage est volontairement proche de PHP : mêmes précédences, même sémantique des opérateurs. Un développeur PHP doit se sentir chez lui.

```
cart.total > 100
customer.vip = true AND cart.total > 50
cart.country IN ['FR', 'BE', 'CH']
customer.score ?? 0 > 50       ← attention à la précédence, voir "Pièges courants"
(customer.score ?? 0) > 50     ← forme correcte
today() > '2026-01-01'
round(total * 0.9, 2)
```

Référence complète : [language-reference.md](language-reference.md).

## Strictement typé, pas de coercition

```php
// En PHP natif : "false" AND true → true (la string "false" est truthy)
// Dans php-ruler : TypeErrorException — "false" n'est pas un bool

// En PHP natif : null + 1 → 1
// Dans php-ruler : TypeErrorException — null n'est pas un nombre

// En PHP natif : '5' == 5 → true
// Dans php-ruler : TypeErrorException — string et int incomparables avec =
```

La seule tolérance est `int` vs `float` sur l'égalité (`5 = 5.0` → `true`) — utile en pratique.

## `evaluateNumeric()` retourne toujours `float`

Même pour un calcul entier :

```php
$eval->evaluateNumeric('5 + 3', []);  // 8.0, pas 8
```

C'est intentionnel : type de sortie uniforme pour les calculs métier (prix, taux). Normaliser en `int` après coup si nécessaire.

## Modes d'évaluation

### Mode strict (défaut)

Toute anomalie lève une exception.

```php
$eval->evaluate('a > 0', []);  // UnknownVariableException — 'a' manque
```

### Mode safe

Collecte les variables manquantes au lieu de lever.

```php
$result = $eval->evaluateSafe('a > 0 AND b < 10', ['a' => 5]);
$result->success;      // false — 'b' manquait
$result->missingVars;  // ['b']
$result->getValueOr(false);  // false (fallback)
```

Voir [evaluate-safe.md](evaluate-safe.md).

### Explainer

Diagnostic nœud par nœud — pourquoi une règle a passé ou échoué.

```php
$explainer = new \Ols\PhpRuler\Explainer\ExpressionExplainer($eval);
$result = $explainer->explain('a > 0 AND b < 10', ['a' => 5, 'b' => 20]);

$result->passed;           // false
$result->failures();       // [ExplainNode 'b < 10' — passed=false, leftValue=20, rightValue=10]
$result->successes();      // [ExplainNode 'a > 0' — passed=true]
$result->unresolved();     // combines missing() + errors() — tout ce qui a bloqué l'évaluation
```

⚠️ **Ne pas utiliser de fonctions à effet de bord avec l'Explainer** — chaque fonction peut être appelée deux fois par occurrence dans une expression compound. Voir [explainer.md](explainer.md).

Voir [explainer.md](explainer.md).

## Fonctions built-in

Arithmétique, chaînes, listes, dates, casting de types. Toutes surchargeables via `registerFunction()`.

```php
// Casting
int(3.7)          // 3 (troncature vers zéro)
float('3.14')     // 3.14
bool('true')      // true
str(1.5)          // '1.5'

// Maths
round(3.14159, 2) // 3.14  (precision max : 14)
abs(-5)           // 5
clamp(x, 0, 100)  // borne x dans [0, 100]
pow(2, 10)        // 1024

// Chaînes
upper(name)              // majuscules
contains(s, 'search')    // bool
concat(first, ' ', last) // concaténation

// Listes
count(tags)       // nombre d'éléments (alias tableau-only de length())
length(tags)      // idem, fonctionne aussi sur les strings
sum(amounts)      // somme
avg(scores)       // moyenne

// Dates (formats : Y-m-d, Y-m-d H:i, Y-m-d H:i:s, et variantes ISO 8601 avec T)
today()                         // '2026-01-15'
dateDiff(today(), created_at)   // jours depuis création
dateAdd(expiry, 30, 'day')      // ajouter 30 jours
```

Référence complète : [functions.md](functions.md).

## Fonctions custom

```php
$eval->registerFunction('discount', function(float $price, float $pct): float {
    return round($price * (1 - $pct / 100), 2);
});

$eval->evaluate("discount(cart.total, 10)", ['cart' => ['total' => 100.0]]);  // 90.0
```

## Cache et AST

Le cache LRU est automatique (défaut 500 entrées). Configurable via le constructeur :

```php
$eval = new ExpressionEvaluator(cacheMaxSize: 2000);  // cache plus grand
$eval = new ExpressionEvaluator(cacheMaxSize: 0);     // cache désactivé
```

Pour stocker un AST compilé en base (évite le parsing au prochain démarrage) :

```php
// Export : enveloppe JSON versionnée {"v":1,"ast":"..."}
$stored = $eval->exportAst('cart.total > threshold');

// Import : valide la version + la structure + les cycles
$ast  = $eval->importAst($stored);
$eval->evaluateAst($ast, $context);
```

⚠️ `importAst()` vérifie la version du format. Si la lib est mise à jour avec un changement de structure, les payloads stockés doivent être régénérés.

Voir [ast-management.md](ast-management.md).

## Architecture : `Node` et traversée

`Node` est une **interface vide** — intentionnellement. Pas de visitor pattern imposé : la traversée se fait par `instanceof` vers les classes concrètes (`BinaryNode`, `UnaryNode`, `LiteralNode`, `VariableNode`, `InNode`, `FunctionNode`, `TernaryNode`).

Pourquoi ? Un visitor formel aurait imposé une méthode par type de nœud dans l'interface. Toute nouvelle classe `Node` aurait cassé les implémentations tierces. Pour une lib dont l'AST peut encore évoluer, c'est une contrainte disproportionnée.

Pour une traversée custom, utilisez `getAst()` et inspectez par `instanceof` :

```php
$ast = $eval->getAst('a > 0 AND b < 10');
// Parcourir $ast par instanceof BinaryNode, VariableNode, etc.
```

`extractVariables()` et `extractFunctions()` sont des utilitaires de traversée prêts à l'emploi.

## Pièges courants

### Précédence de `??` — plus basse que les comparaisons

```php
// ❌ Pas ce qu'on pense
$eval->evaluate('a ?? 0 > 100', ['a' => null]);
// → null ?? (0 > 100) → null ?? false → false

// ✅ Forme correcte
$eval->evaluate('(a ?? 0) > 100', ['a' => null]);
// → (null ?? 0) > 100 → 0 > 100 → false
```

### `NOT a ?? b` — NOT lie très fort

```php
// NOT a une précédence très haute (aligné sur PHP !)
'NOT a ?? b'    →  '(NOT a) ?? b'    // PAS NOT (a ?? b)
'NOT (a ?? b)'  →  forme correcte
```

## Exceptions

Toutes dérivent de `Ols\PhpRuler\Exception\EvaluatorException` (`\RuntimeException`).

```
EvaluatorException
  ├── SyntaxErrorException    — lex/parse (propriété $position)
  ├── TypeErrorException      — typage runtime
  ├── UnknownVariableException — variable absente (propriété $variablePath)
  └── CircularContextException — contexte circulaire (describeContext)
```

Les messages sont en **anglais**, intentionnellement. Le point d'extension pour un backoffice francophone : utiliser le type d'exception et les propriétés structurées (`$variablePath`, `$position`) pour composer des messages localisés côté appelant.

Voir [exceptions.md](exceptions.md).

## Documentation complète

| Fichier | Contenu |
|---|---|
| [language-reference.md](language-reference.md) | Syntaxe, opérateurs, précédences, pièges |
| [evaluate.md](evaluate.md) | Mode strict, API, comportements |
| [evaluate-safe.md](evaluate-safe.md) | Mode safe, SafeResult, short-circuits |
| [explainer.md](explainer.md) | Diagnostic nœud par nœud |
| [functions.md](functions.md) | Catalogue des built-in, fonctions custom |
| [exceptions.md](exceptions.md) | Hiérarchie d'exceptions, politique de propagation |
| [ast-management.md](ast-management.md) | Cache, export/import, limites et constantes |
| [context.md](context.md) | Résolution des variables, dot-notation |
| [alias-resolver.md](alias-resolver.md) | Traduction chemin ↔ alias humain |
| [static-analysis.md](static-analysis.md) | extractVariables, extractFunctions, validate |
