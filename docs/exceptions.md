# Exceptions et erreurs

## Vue d'ensemble

La lib utilise une hiérarchie d'exceptions cohérente, toutes dérivées de `EvaluatorException` (elle-même dérivée de `\RuntimeException`). Ce design permet :
- D'attraper toutes les erreurs de la lib avec un seul `catch (EvaluatorException $e)`
- D'attraper précisément un type d'erreur (`UnknownVariableException`) quand on veut le gérer spécifiquement
- De distinguer les erreurs de syntaxe (compile-time) des erreurs d'évaluation (runtime)

Aucune méthode publique ne propage d'exception PHP brute (`\TypeError`, `\DivisionByZeroError`...). Toute exception levée par le code utilisateur (fonctions custom) est rewrappée en `TypeErrorException`.

## Hiérarchie

```
\RuntimeException
  └── EvaluatorException                  (base)
        ├── SyntaxErrorException          (lex/parse)
        ├── TypeErrorException            (typage runtime)
        ├── UnknownVariableException      (variable absente)
        └── CircularContextException      (context circulaire en describe)
```

Toutes les classes vivent dans le namespace `Ols\PhpRuler\Exception`.

## `EvaluatorException`

**Classe de base** pour toutes les exceptions de la lib. Pas levée directement en pratique (toujours via une sous-classe), mais utile pour un catch-all.

Levée directement uniquement pour des cas d'AST corrompu ou de profondeur dépassée (où aucune des sous-classes plus précises ne s'applique) :
- Profondeur d'évaluation dépassée (200 niveaux)
- Nœud d'AST inconnu (corruption ou extension non implémentée)
- Opérateur unaire/binaire inconnu (idem)
- Fonction non enregistrée

```php
try {
    $eval->evaluate($expression, $context);
} catch (\Ols\PhpRuler\Exception\EvaluatorException $e) {
    // Attrape tout : syntaxe, type, variable manquante, AST corrompu...
}
```

## `SyntaxErrorException`

Levée à la phase de **lex ou de parse**. C'est-à-dire **avant toute évaluation**.

**Spécificité** : porte une propriété publique `$position` (int, en octets) indiquant l'offset dans la chaîne d'origine.

```php
final class SyntaxErrorException extends EvaluatorException
{
    public function __construct(string $message, public readonly int $position);
}
```

Cas typiques :
- Token inconnu (`'@'`, `'#'`, etc.)
- Parenthèse non fermée, crochet déséquilibré
- Opérande manquant après un opérateur (`'a + '`)
- Littéral d'entier dépassant `PHP_INT_MAX` (ou `PHP_INT_MIN` non précédé d'un unaire `-`)
- Littéral de float dépassant `PHP_FLOAT_MAX` (devient `INF` au cast, rejet immédiat)
- Virgule trailing dans une liste (`[1, 2,]`)
- Liste vide à droite d'`IN` (`x IN []`)
- Scalaire à droite d'`IN` (`x IN 5`)
- Token surplus en fin d'expression (`'a + b 5'`)

**Exemple** :
```php
try {
    $eval->evaluate('a + ', $context);
} catch (\Ols\PhpRuler\Exception\SyntaxErrorException $e) {
    echo $e->getMessage();   // 'Unexpected token "" at position 4'
    echo $e->position;       // 4
}
```

**Quand interpréter** :
- En backoffice : remonter le message + position à l'utilisateur pour l'aider à corriger sa règle
- Côté code : indique un bug de la chaîne fournie, pas du contexte. Re-fournir le même contexte ne corrigera pas le problème.

### `validate()` ne renvoie que ce type

`ExpressionEvaluator::validate($expression)` lève uniquement `SyntaxErrorException` (ou pas d'exception du tout). Pas de vérification sémantique (variables, fonctions, types) à ce niveau.

## `TypeErrorException`

Levée à **l'évaluation**, quand une opération rencontre un type incompatible.

Cas couverts (liste non exhaustive) :
- Opérateur logique AND/OR/NOT/ternaire avec opérande non-bool (`5 AND true`)
- Arithmétique avec opérande non-numérique (`null + 1`, `'5' * 2`)
- Comparaison de types incompatibles (`'a' > 5`)
- Égalité directe sur arrays (`[1,2] = [1,2]`) — utiliser `IN`
- Apparition de NaN ou INF dans le pipeline
- Division par zéro (`1 / 0`)
- Overflow d'entier (`PHP_INT_MAX + 1` produirait un downcast en float, rejet)
- `evaluateBoolean()` avec résultat non-bool
- `evaluateNumeric()` avec résultat non-numérique
- Fonction appelée avec mauvaise arity
- Fonction custom levant un `\Throwable` non-lib (encapsulé avec `previous`)
- Type non supporté dans le contexte (objet, closure, ressource...)
- Format de date invalide passé aux fonctions de dates
- Contraintes d'arguments de fonctions builtins (par ex. `clamp` avec `min > max`)

**Caractéristique partagée** : c'est presque toujours un **problème de typage logique** dans l'expression ou les données. Le caller doit corriger l'expression ou nettoyer son contexte.

```php
try {
    $eval->evaluate("'hello' AND true", []);
} catch (\Ols\PhpRuler\Exception\TypeErrorException $e) {
    echo $e->getMessage();
    // 'Operator "AND": expected boolean, string given. Use an explicit comparison...'
}
```

### Chaînage via `previous`

Quand une fonction custom lève un `\Throwable` non-lib, l'exception originelle est conservée dans `$e->getPrevious()` :

```php
$eval->registerFunction('boom', fn() => throw new \RuntimeException('boom'));
try {
    $eval->evaluate('boom()', []);
} catch (\Ols\PhpRuler\Exception\TypeErrorException $e) {
    echo $e->getMessage();              // 'Error in function "boom": boom'
    echo $e->getPrevious()->getMessage(); // 'boom'  ← l'originale
}
```

C'est utile pour le debug : on peut tracer la cause racine sans perdre le message wrappé pour l'API utilisateur.

## `UnknownVariableException`

Levée quand une variable référencée n'existe pas dans le contexte.

**Propriété structurée** : expose `public readonly string $variablePath` — le chemin exact demandé (tel qu'écrit dans l'expression). Utile pour du logging ou de l'affichage backoffice sans avoir à parser le message texte.

**Message** : indique le chemin demandé. Si la résolution a échoué après avoir traversé au moins un segment, indique le segment fautif :

- `'Unknown variable: "customer"'` — racine absente
- `'Unknown variable: "cart.shipping" (failed at "cart.shipping")'` — `cart` existe mais pas `cart.shipping`

```php
try {
    $eval->evaluate('cart.shipping > 0', ['cart' => ['total' => 100]]);
} catch (\Ols\PhpRuler\Exception\UnknownVariableException $e) {
    echo $e->getMessage();
    // 'Unknown variable: "cart.shipping" (failed at "cart.shipping")'
    echo $e->variablePath;  // 'cart.shipping'
}
```

### Captée et collectée par les modes safe / explain

- `evaluateSafe()` : attrape ces exceptions et collecte les chemins dans `SafeResult::missingVars`
- `ExpressionExplainer::explain()` : capture en `ExplainStatus::MISSING` avec le message dans `detail`

Toutes les autres exceptions (`TypeErrorException`, `EvaluatorException`...) traversent ces deux modes **inchangées**.

**Cas particulier** : une `UnknownVariableException` levée **depuis le corps d'une fonction custom** (par exemple si la fonction appelle `getContextValue('x', [])`) n'est pas convertie en "missing" par le mode safe — voir `functions.md` et `evaluate-safe.md`.

## `CircularContextException`

Spécifique à `describeContext()` (et à `ContextResolver::describe`). Levée quand la structure du contexte dépasse `MAX_DEPTH = 64` niveaux d'imbrication.

En pratique, signale presque toujours une **référence circulaire** :

```php
$ctx = ['data' => 'x'];
$ctx['self'] = &$ctx;

$eval->describeContext($ctx);
// CircularContextException: 'Context nesting exceeds 64 levels at "self.self.self..."'
```

**Pas levée par `evaluate()` ni `getContextValue()`** : ces méthodes font une descente bornée par le chemin demandé (par exemple 3 segments pour `a.b.c`), pas une exploration complète. Elles n'exposent pas au risque de cycle.

## Politique de propagation

### Quelles exceptions sortent de quelle méthode

| Méthode | Exceptions possibles |
|---|---|
| `evaluate(string)` | Toutes : `SyntaxErrorException`, `TypeErrorException`, `UnknownVariableException`, `EvaluatorException` |
| `evaluateAst(Node)` | Pas de `SyntaxErrorException` (déjà parsé) ; autres possibles |
| `evaluateBoolean()` / `evaluateNumeric()` | Tout ce qu'`evaluate()` peut lever + `TypeErrorException` si type final inattendu |
| `evaluateSafe(string)` | `SyntaxErrorException`, `TypeErrorException`, `EvaluatorException`. **Pas** `UnknownVariableException` (collectée). |
| `evaluateSafeAst(Node)` | Idem `evaluateSafe()` sans `SyntaxErrorException`. |
| `validate()` | `SyntaxErrorException` uniquement. |
| `getAst()` | `SyntaxErrorException` uniquement (pas d'évaluation). |
| `exportAst()` | Idem `getAst()`. |
| `importAst()` | `\InvalidArgumentException` (struct corrompue / cycle / profondeur). Pas `SyntaxError` (rien à parser). |
| `getContextValue()` | `UnknownVariableException` uniquement. |
| `getContextValueOrDefault()` | Aucune (renvoie le défaut). |
| `hasContextValue()` | Aucune (renvoie `false`). |
| `describeContext()` | `CircularContextException` possible. |
| `registerFunction()` | Aucune (la signature est introspectée mais pas appelée). |
| `getFunctions()` | Aucune. |
| `callFunction()` | `EvaluatorException` (fonction inconnue), `TypeErrorException` (arity ou type interne). |
| `extractVariables()`, `extractFunctions()` | `SyntaxErrorException` uniquement. |
| `ExpressionExplainer::explain()` | `SyntaxErrorException` ou `EvaluatorException` structurel uniquement. **Pas** missing/type errors d'évaluation (ils deviennent des nœuds `MISSING`/`ERROR`). |
| `AliasResolver::add()` | `\InvalidArgumentException` (validation des alias). |
| `AliasResolver::humanToExpression()` / `expressionToHuman()` | `\InvalidArgumentException` si UTF-8 invalide. |

### Garanties globales

1. **Aucune exception PHP brute ne sort des méthodes publiques** (sauf `\InvalidArgumentException` documentée dans `importAst()` et `AliasResolver`, et `\LogicException` documentée dans `SafeResult::getValue()`).

2. **Les exceptions de la lib transitent inchangées à travers les fonctions custom** : si une fonction custom appelle `evaluate()` en interne et que ça lève `UnknownVariableException`, ça remonte sans wrapping.

3. **Les exceptions hors lib levées par des fonctions custom sont wrappées en `TypeErrorException`** avec `previous` pointant sur l'originale.

4. **Les comptes de récursion sont remis à zéro sur exception** : un appel en échec ne laisse pas l'évaluateur dans un état incohérent.

## Exceptions hors hiérarchie

Trois exceptions ne dérivent pas d'`EvaluatorException` :

### `\InvalidArgumentException`

Levée par :
- `importAst()` quand la chaîne sérialisée est corrompue, contient une classe non autorisée, un cycle ou dépasse la profondeur
- `AliasResolver::add()` quand l'alias viole une règle de validation
- `AliasResolver::humanToExpression()` / `expressionToHuman()` sur UTF-8 invalide

Justification : ce sont des erreurs d'**API usage** (paramètres invalides côté caller), pas des erreurs d'évaluation à proprement parler. Cohérent avec la convention PHP standard.

### `\LogicException`

Levée par :
- `SafeResult::getValue()` quand `success === false`

Justification : c'est une erreur de **programmation côté caller** (oubli de vérifier `success` avant d'accéder à `value`). Pas une condition runtime imprévisible — d'où `LogicException` plutôt qu'une exception runtime.

## Décisions de design

### Base `\RuntimeException`

`EvaluatorException` dérive de `\RuntimeException`, pas de `\Exception` directement. Convention PHP : les exceptions liées à l'état runtime (par opposition aux erreurs de programmation, `\LogicException`) dérivent de `\RuntimeException`.

### Pas de codes d'erreur numériques

Aucune exception n'utilise le `$code` du constructeur d'`\Exception`. Le message texte est la source d'info. Si une intégration externe a besoin de distinguer programmatiquement, elle peut utiliser le **type** d'exception (`instanceof`) — qui est plus typé et plus refactor-friendly que des codes magiques.

### Granularité moyenne

La hiérarchie a 4 sous-classes (au-delà de la base). Choix d'équilibre :
- Suffisamment granulaire pour distinguer les cas "data" (`UnknownVariableException`), "logic" (`TypeErrorException`), "syntax" (`SyntaxErrorException`), "context" (`CircularContextException`)
- Pas trop pour éviter une explosion de catch (`AndOperatorException`, `OrOperatorException`...). Quand plusieurs cas partagent la même intention (problème de typage), une seule exception suffit, le message texte précise.

### Messages auto-suffisants

Les messages incluent le contexte nécessaire à l'utilisateur :
- Chemin de la variable (`UnknownVariableException`)
- Position dans l'expression (`SyntaxErrorException`)
- Nom de l'opérateur et types observés (`TypeErrorException`)
- Suggestion d'action quand pertinent (`Use an explicit comparison...`)

C'est pour permettre à un backoffice d'afficher le message brut à l'utilisateur sans traduction supplémentaire.

### Politique uniforme pour les fonctions custom

Toute erreur dans une fonction custom :
- soit elle est de la hiérarchie lib → propagée tel quel
- soit elle est autre → wrappée en `TypeErrorException` avec préfixe `Error in function "..."` et `previous` peuplée

Aucune exception PHP brute ne fuit. Le contrat d'API est uniforme.

### Pas d'exception "recovery"

La lib ne définit pas d'exception "recoverable" vs "non recoverable". C'est au caller d'attraper ce qu'il sait gérer. Le typage de la hiérarchie est l'outil principal de discrimination.

## Limitations connues

### Pas de localization des messages

Les messages sont en **anglais**, intentionnellement. Pas de mécanisme i18n intégré. Ce choix est définitif pour la lib elle-même.

Le point d'extension recommandé pour un backoffice qui a besoin de ses propres messages : utiliser le **type d'exception** (`instanceof`) et les **propriétés structurées** (ex. `$variablePath` sur `UnknownVariableException`, `$position` sur `SyntaxErrorException`) pour composer un message localisé côté appelant, sans parser le texte libre.

### Structured details : partiels

Les exceptions les plus utiles exposent déjà des propriétés publiques typées : `UnknownVariableException::$variablePath` et `SyntaxErrorException::$position`. Inutile de parser le texte du message pour ces deux cas.

En revanche, les autres exceptions (`TypeErrorException` notamment) restent du texte libre : distinguer programmatiquement "division par zéro" d'un "type incompatible" demande encore un pattern-match sur le message (voir ci-dessous).

### `TypeErrorException` un peu fourre-tout

Le type couvre beaucoup de cas (arithmétique, comparaison, fonction custom...). Un caller qui veut distinguer "division par zéro" de "type incompatible" doit pattern-matcher le message. Comportement actuel acceptable mais perfectible.