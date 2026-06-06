# Explainer

## Vue d'ensemble

L'Explainer est un outil de **diagnostic** : il évalue une expression comme `evaluate()` mais produit en plus une représentation arborescente de chaque nœud avec :
- L'expression locale reconstituée (forme humaine de ce que fait ce nœud)
- Le statut : évalué / court-circuité / variable manquante / erreur
- Les valeurs intermédiaires (opérandes gauche/droite, résultat)
- La structure parent/enfant pour AND/OR/NOT/ternaire

Cas d'usage typique : afficher à un utilisateur backoffice **pourquoi** une règle a passé ou échoué, condition par condition. Identifier que `cart.total > 100` a échoué parce que `cart.total = 85`, ou que `customer.vip = true` n'a pas pu être évaluée parce que `customer.vip` manque du contexte.

⚠️ **L'Explainer n'est pas conçu pour de la production à fort débit**. C'est un outil de diagnostic. Voir les sections "Coût" et "Limitations".

## API

```php
namespace Ols\PhpRuler\Explainer;

final class ExpressionExplainer
{
    public function __construct(ExpressionEvaluator $eval);
    public function explain(string $expression, array $context): ExplainResult;
    public function explainAst(Node $ast, array $context): ExplainResult;
}

final class ExplainResult
{
    public readonly ?bool $passed;     // null si non évalué (missing/error/short-circuit racine)
    public readonly ExplainNode $root;

    public function failures(): array;   // leaves évaluées, passed === false
    public function successes(): array;  // leaves évaluées, passed === true
    public function leaves(): array;     // toutes les leaves
    public function skipped(): array;    // leaves court-circuitées
    public function missing(): array;    // leaves bloquées par variable manquante
    public function errors(): array;     // leaves bloquées par erreur (type, division, etc.)
    public function unresolved(): array; // combine missing() + errors() — tout ce qui a bloqué l'évaluation
}

final class ExplainNode
{
    public readonly string $expression;     // expression locale reconstituée
    public readonly ?bool $passed;
    public readonly string $operator;       // '=', 'AND', 'NOT', '?:', 'value', 'skipped', 'missing', 'error'
    public readonly mixed $leftValue;
    public readonly mixed $rightValue;
    public readonly array $children;        // ExplainNode[]
    public readonly ExplainStatus $status;
    public readonly ?string $detail;        // path manquant ou message d'erreur
    public readonly bool $leftMissing;      // spécifique aux nœuds ?? : true si la gauche était absente

    public function isLeaf(): bool;
    public function isCompound(): bool;
    public function isSkipped(): bool;
    public function isMissing(): bool;
    public function isError(): bool;
    public function isEvaluated(): bool;
}

enum ExplainStatus
{
    case EVALUATED;
    case SHORT_CIRCUITED;
    case MISSING;
    case ERROR;
}
```

## Comportements

### `explain(string $expression, array $context): ExplainResult`

Construit l'arbre d'explication complet pour l'expression dans le contexte donné.

**Ne lève jamais sur** : variable manquante, erreur de type, division par zéro, NaN/INF. Ces conditions deviennent des nœuds `MISSING` ou `ERROR` dans l'arbre.

**Lève toujours sur** : erreur de syntaxe (compile-time), profondeur d'AST dépassée, AST corrompu. Ces erreurs structurelles ne peuvent pas être "expliquées" — il n'y a rien à traverser.

### Structure de l'arbre

L'arbre miroir la structure AST avec une **vue orientée diagnostic** :

| Type d'AST | Représentation Explainer |
|---|---|
| `BinaryNode` opérateur AND/OR | Compound, 2 enfants |
| `UnaryNode` NOT | Compound, 1 enfant (sauf NOT IN, voir ci-dessous) |
| `TernaryNode` | Compound, 3 enfants (condition + 2 branches) |
| `BinaryNode` opérateur de comparaison `=`, `!=`, `>`, `>=`, `<`, `<=` | Leaf avec `leftValue` et `rightValue` |
| `InNode` (et `NOT IN` : `UnaryNode(NOT, InNode)`) | Leaf unique, `operator: 'IN'` ou `'NOT IN'` |
| `BinaryNode` opérateur `??` | Leaf, distingue "gauche absente" vs "gauche null" via `leftMissing` |
| Arithmétique, fonctions, littéraux, variables seules | Leaf, `operator: 'value'`, `leftValue` = la valeur |

### Nœuds compound vs leaves

- **Compound** : AND, OR, NOT, ternaire. Ont des enfants. `passed` est calculé selon la logique de l'opérateur sur les enfants évalués.
- **Leaves** : tout le reste. Pas d'enfants. `passed` reflète la "vérité" du nœud (un comparison qui retourne true, un IN qui matche, une valeur truthy...).

`ExplainResult::failures()`, `successes()`, etc. **n'opèrent que sur les leaves**. Pour un résumé top-level, regarder `ExplainResult::root` directement.

### Statuts des nœuds

#### `EVALUATED`
Le nœud a été visité, son résultat est dans `passed`.

#### `SHORT_CIRCUITED`
Le nœud n'a pas été visité parce qu'une branche frère/parent a résolu l'expression sans lui. Spécifiquement :
- Branche droite d'un AND avec gauche `false`
- Branche droite d'un OR avec gauche `true`
- Branche non-prise d'un ternaire

`passed` est `null`, `operator` est `'skipped'`.

#### `MISSING`
Le nœud a tenté de résoudre une variable absente du contexte. `detail` contient le message d'`UnknownVariableException`, `operator` est `'missing'`.

#### `ERROR`
Le nœud a levé une exception non-récupérable (erreur de type, division par zéro, NaN/INF, fonction inconnue, arity invalide...). `detail` contient le message, `operator` est `'error'`.

### Propagation d'erreurs

Quand un enfant est en `MISSING` ou `ERROR`, le parent (AND, OR, ternaire...) propage ce statut **vers le haut** plutôt que de tenter d'évaluer (ce qui re-lèverait la même exception).

Exemple :
```php
$result = $explainer->explain('a > 0 AND b < 100', ['a' => 5]);
// La feuille 'b < 100' a status=MISSING (detail: 'Unknown variable: "b"')
// Le compound 'a > 0 AND b < 100' a aussi status=MISSING (propagation)
// La feuille 'a > 0' a status=EVALUATED, passed=true
```

Pour AND/OR, le frère est **quand même tracé** pour donner un diagnostic complet :
- Si gauche manquante et droite peut être résolue, droite est tracée
- Si gauche peut être résolue et c'est court-circuit, droite n'est pas tracée (status: SHORT_CIRCUITED)

### Cas particulier : `??` (null-coalescing)

Le `??` "absorbe" l'absence ou la nullité de la gauche :
- Si gauche absente ou null → droite évaluée
- Si gauche présente et non-null → droite skippée

Le nœud `??` est représenté comme un **leaf unique** (pas un compound), avec :
- `leftValue` : la valeur résolue à gauche (`null` si absente, `null` si `null`)
- `rightValue` : la valeur de droite si la gauche a été remplacée (`null` sinon)
- `leftMissing` : `true` si la gauche était **absente** (variable manquante), `false` si la gauche était présente mais valait `null`. Permet de distinguer les deux cas.

```php
$explainer->explain('a ?? 10', ['a' => null]);
// leftValue: null, rightValue: 10, leftMissing: false, passed: true (10 truthy)

$explainer->explain('a ?? 10', []);
// leftValue: null, rightValue: 10, leftMissing: true, passed: true
```

### `ExplainResult::unresolved(): array`

Combine `missing()` et `errors()` : retourne toutes les leaves qui ont empêché l'évaluation de se compléter, quelle qu'en soit la raison.

```php
$result = $explainer->explain('a > 0 AND b < 100', []);
// a et b sont tous les deux manquants

count($result->missing());    // 2
count($result->errors());     // 0
count($result->unresolved()); // 2 — équivalent à array_merge(missing, errors)
```

C'est la méthode la plus utile en backoffice pour répondre à "qu'est-ce qui a bloqué cette expression ?", sans avoir à merger manuellement les deux collections.

Une expression `x NOT IN [...]` est représentée dans l'AST comme `UnaryNode(NOT, InNode(...))`. L'Explainer la traite comme un **leaf unique** avec `operator: 'NOT IN'` plutôt que de produire un compound NOT contenant un IN — c'est plus naturel pour l'utilisateur.

L'expression reconstituée est `"x NOT IN [...]"` (et non `"NOT x IN [...]"` qui serait invalide).

## Pipeline interne

L'Explainer fonctionne en **deux phases** :

1. **`buildTrace()`** — parcours préliminaire de l'AST qui évalue chaque nœud et stocke son résultat (ou son statut MISSING/ERROR) dans des maps indexées par `spl_object_id()`. Respecte les short-circuits (AND/OR/ternaire) pour ne pas évaluer ce qui ne doit pas l'être.

2. **`explainNode()`** — parcours producteur qui construit l'arbre `ExplainNode` en consultant les valeurs déjà tracées. Pas de ré-évaluation des fonctions à ce stade.

Ce design en deux phases permet d'avoir :
- Une trace complète des valeurs avant la production de l'arbre (pour distinguer "skipped" de "not yet reached")
- Une protection contre la double évaluation des `FunctionNode` (les fonctions sont appelées une fois via `callFunction()` avec leurs arguments déjà tracés)

## ⚠️ Coût : double évaluation des fonctions dans les compounds

C'est la limitation **structurelle** la plus importante de l'Explainer.

### Le mécanisme

Pendant `buildTrace()` :
- Les `FunctionNode` sont appelés via `callFunction()` avec leurs arguments **déjà tracés** : un seul appel
- Les autres nœuds (`BinaryNode`, `UnaryNode`, `InNode`, `TernaryNode`) sont évalués via `evaluateAst()` qui **re-walk le sous-arbre complet**

Conséquence : toute fonction imbriquée dans un nœud compound est **appelée une deuxième fois** quand le compound parent évalue son sous-arbre via `evaluateAst()`.

### Quantification

Le multiplicateur est **exactement 2 par occurrence**, pas plus. Pas multiplicatif avec la profondeur d'imbrication :

```
counter() + counter()        → 4 appels  (2 occurrences × 2)
counter() > 0 AND counter()  → 6 appels  (3 occurrences × 2)
now() seul à la racine       → 1 appel   (pas de compound parent)
```

### Conséquence

**⚠️ Les fonctions à effet de bord (compteurs, écritures base, envois mail, etc.) ne doivent JAMAIS être utilisées dans des expressions passées à `explain()` / `explainAst()`.**

Cette interdiction vaut aussi pour les fonctions dont l'idempotence n'est pas garantie à 100% (lecture avec side-effect, génération d'UUID, horodatage, etc.). En cas de doute : ne pas utiliser avec l'Explainer.

Cette restriction **ne s'applique pas** aux autres modes d'évaluation (`evaluate()`, `evaluateSafe()`, etc.) où chaque fonction est appelée exactement une fois.

### Pourquoi pas un fix ?

Le design alternatif (single-pass évaluation qui produit l'arbre au fur et à mesure) aurait des inconvénients :
- Plus difficile de gérer les short-circuits proprement (il faut savoir si une branche a été visitée avant de produire la `ExplainNode` parent)
- Plus difficile de distinguer "skipped" de "errored" sans le contexte complet

Le compromis actuel : l'Explainer est un outil de diagnostic, pas un évaluateur de production. La contrainte "pas d'effets de bord dans les fonctions explainées" est documentée et acceptable.

## Reconstruction d'expression : `printNode()`

Chaque `ExplainNode` contient une chaîne `expression` qui est l'expression locale **reconstruite depuis l'AST** (pas extraite de la chaîne d'origine).

Avantages :
- Disponible même pour des nœuds dont l'expression source originale a été perdue (typiquement avec `explainAst()` sur un AST chargé depuis `importAst()`)
- Normalisée (espaces, parenthèses) : `'1+1'` et `'1 + 1'` donnent le même rendu

Le printer gère :
- La précédence des opérateurs (parenthèses ajoutées au minimum nécessaire)
- Le cas `NOT IN` (rendu propre, ré-injectable dans le Parser)
- Les littéraux quotés avec échappement (`L'Oréal` → `'L''Oréal'`)
- Les listes `[1, 2, 3]`, les booléens, `null`

Le tableau de précédence du printer est **synchronisé** avec celui du Parser. Si les niveaux changent dans le Parser, ils doivent changer ici aussi.

## Décisions de design

### Indexation par `spl_object_id`

Les maps `$trace` et `$errors` sont indexées par `spl_object_id($node)`. Garantit l'unicité même quand le même nœud est référencé plusieurs fois (cas du DAG-like sharing toléré par `importAst`).

Pas de fuite mémoire : les maps sont réinitialisées à chaque appel à `explainAst()` (`$this->trace = []`).

### Mutually exclusive : trace OU error

Un nœud est soit dans `$trace` (évaluation réussie), soit dans `$errors` (status MISSING ou ERROR), **jamais les deux**. Permet de distinguer sans ambiguïté "valeur connue" et "résolution impossible".

### Court-circuit fidèle au mode strict

Les short-circuits dans `buildTrace()` reproduisent **exactement** la sémantique d'`Evaluator::evaluate()` :
- AND avec gauche false → droite non visitée
- OR avec gauche true → droite non visitée
- Ternaire → seule la branche choisie est visitée

Pas de comportement "best-effort" qui explorerait les branches non prises pour donner plus d'information : ce serait une divergence dangereuse avec l'évaluation réelle.

### Erreur de fonction inconnue : `ERROR` pas `MISSING`

Une fonction non enregistrée produit `EvaluatorException("Unknown function...")`, classée comme `ERROR`. C'est cohérent : une fonction inconnue est un problème d'expression (ou de configuration), pas de données.

### `passed` du root : `null` si non-évalué

`ExplainResult::passed` est `null` quand la racine n'a pas pu être pleinement évaluée (manquant, erreur, court-circuité). C'est distinct de `false`. Permet à l'appelant de distinguer "règle évaluée et fausse" vs "règle non évaluable".

### Collecte des leaves : O(n)

`failures()`, `successes()`, etc. utilisent un accumulateur passé par référence (pas de `array_merge` récursif). Important pour rester linéaire en taille d'arbre.

### Pas de "warnings"

L'Explainer ne signale pas les anomalies "soft" (par exemple : un littéral comparé à lui-même `5 = 5` qui sera toujours vrai). Le diagnostic est purement run-time : ce qui s'est passé, pas ce qui aurait pu être suspect.

## Limitations connues

### Side-effects et double évaluation

Voir la section dédiée plus haut. **Critique** : ne pas utiliser de fonctions à effets de bord dans une expression explainée.

### Pas d'historique d'évaluation

L'Explainer décrit **un** appel, pas un historique. Pour comparer deux évaluations (même règle, deux contextes), il faut deux appels et un diff côté caller.

### Sérialisation de l'arbre

`ExplainNode` et `ExplainResult` sont des objets standards, sérialisables via `json_encode()` directement (toutes les propriétés sont publiques readonly).

⚠️ Les `leftValue` / `rightValue` peuvent contenir des valeurs PHP arbitraires (tableaux, etc.). La sérialisation JSON gère bien les scalaires et tableaux ; pas de cas d'objet à signaler aujourd'hui puisque l'évaluateur rejette les objets en amont.

### Pas de diff sémantique entre nœuds

Deux expressions sémantiquement équivalentes (`'a > 0 AND b > 0'` et `'b > 0 AND a > 0'`) produisent des arbres différents. C'est attendu : l'arbre suit la structure AST, pas une forme normalisée.