# Analyse statique de l'AST

## Vue d'ensemble

L'analyse statique permet d'inspecter une expression **sans l'évaluer** : extraire la liste des variables référencées, la liste des fonctions appelées, ou simplement valider qu'elle est syntaxiquement correcte. Aucun contexte n'est nécessaire — c'est une introspection purement structurelle.

Cas d'usage typiques :
- **Backoffice** : valider qu'une règle utilisateur ne référence que des variables/fonctions autorisées avant de la stocker en base
- **Documentation auto-générée** : lister les variables attendues par chaque règle
- **Vérification de couverture** : s'assurer que toutes les variables d'un contexte sont effectivement utilisées par les règles, ou inversement

## API

```php
public function extractVariables(string $expression): array  // string[]
public function extractFunctions(string $expression): array  // string[]
public function validate(string $expression): void
```

## Comportements

### `extractVariables(string $expression): string[]`

Retourne tous les chemins de variables référencés dans l'expression, dédupliqués et triés alphabétiquement.

Le parcours est **exhaustif** : toutes les branches de l'AST sont visitées, indépendamment du short-circuit ou du choix de branche ternaire à l'évaluation. C'est attendu : l'analyse statique ne connaît pas les valeurs runtime.

**Exemples** :
```php
$eval->extractVariables('a > 0 AND b.c = d ?? e');
// ['a', 'b.c', 'd', 'e']

$eval->extractVariables('cart.total + cart.shipping');
// ['cart.shipping', 'cart.total']  ← trié, dédupliqué

$eval->extractVariables('a > 0 ? b : c');
// ['a', 'b', 'c']  ← les DEUX branches sont visitées, même si l'évaluation n'en prend qu'une

$eval->extractVariables('round(cart.total, precision)');
// ['cart.total', 'precision']  ← descend dans les arguments de fonction

$eval->extractVariables('x IN list');
// ['list', 'x']

$eval->extractVariables('1 + 2');
// []  ← aucune variable
```

**Exceptions levées** :
- `SyntaxErrorException` — l'expression est mal formée (le parsing doit aboutir pour pouvoir analyser)

### `extractFunctions(string $expression): string[]`

Retourne tous les noms de fonctions appelées dans l'expression, dédupliqués et triés.

```php
$eval->extractFunctions('round(a, 2) > min(b, c)');
// ['min', 'round']

$eval->extractFunctions('upper(name) = "ALICE"');
// ['upper']

$eval->extractFunctions('1 + 2');
// []
```

Utile typiquement avant évaluation : on peut comparer le résultat à `getFunctions()` (liste des fonctions enregistrées) pour rejeter une expression appelant une fonction inconnue **avant** d'essayer de l'évaluer.

```php
$used      = $eval->extractFunctions($userRule);
$available = $eval->getFunctions();
$unknown   = array_diff($used, $available);
if (!empty($unknown)) {
    throw new \InvalidArgumentException('Fonctions non autorisées: ' . implode(', ', $unknown));
}
```

### `validate(string $expression): void`

Tente de parser l'expression. Ne renvoie rien en cas de succès, lève une exception sinon.

Équivalent fonctionnel à `getAst($expression)` mais sans retour — l'intention est exprimée plus clairement quand on veut juste valider.

```php
try {
    $eval->validate($userRule);
    // OK, syntaxe valide
} catch (SyntaxErrorException $e) {
    // Erreur de syntaxe à la position $e->position
}
```

**Exceptions levées** :
- `SyntaxErrorException` — l'expression contient une erreur de lex ou de parse. La propriété `$position` indique l'offset (en octets) dans la chaîne d'origine.

⚠️ `validate()` ne vérifie **que** la syntaxe. Elle ne vérifie pas que les fonctions appelées existent (utiliser `extractFunctions()` + `getFunctions()` pour ça), ni que les variables référencées seront présentes dans le contexte (utiliser `extractVariables()` + comparaison contre le contexte attendu).

## Décisions de design

### Pas d'évaluation, pas de contexte

Ces trois méthodes ne nécessitent **jamais** de contexte. C'est délibéré : leur rôle est de répondre à des questions structurelles sur l'expression, indépendamment des données.

Conséquence importante : `extractVariables('a ? b : c')` renvoie `['a', 'b', 'c']` même si à l'évaluation seule la branche `b` (ou `c`) sera visitée. L'analyse statique ne peut pas prédire ce qui sera réellement utilisé.

### Tri alphabétique

Les listes renvoyées par `extractVariables()` et `extractFunctions()` sont triées via `sort()`. C'est cohérent et facilite les tests d'égalité, mais perd l'ordre d'apparition dans l'expression. Si cet ordre devenait utile, il faudrait dupliquer l'API (ou retourner un objet plus riche).

### Cache d'AST réutilisé

Ces méthodes passent par `getAst()` en interne — elles bénéficient donc du cache LRU. Si vous validez puis évaluez la même expression, le parse n'a lieu qu'une fois. Voir `ast-management.md`.

## Limitations connues

### Pas d'inspection des littéraux ou opérateurs

Il n'existe pas d'équivalent `extractLiterals()` ou `extractOperators()`. Pour des analyses plus poussées (par exemple : "quelle est la valeur maximale comparée dans cette règle ?"), il faut écrire son propre walker sur l'AST exposé par `getAst()`.

L'AST est intentionnellement public (interfaces et classes `final` mais accessibles) pour permettre ces analyses custom.

### Pas de détection de chemins dynamiques

Les chemins de variables sont extraits littéralement. Une expression comme `prefix.field` est traitée comme un seul chemin `prefix.field`, sans tentative de résolution dynamique. C'est cohérent avec le reste de la lib : les chemins sont des constantes syntaxiques, pas des expressions.