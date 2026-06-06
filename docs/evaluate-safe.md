# Évaluation safe

## Vue d'ensemble

Le mode safe est une variante tolérante de l'évaluation : au lieu de lever `UnknownVariableException` quand une variable est absente du contexte, le moteur collecte la liste des variables manquantes et renvoie un objet `SafeResult` qui distingue trois cas :
- **Succès** : toutes les variables nécessaires étaient présentes → le résultat est disponible
- **Échec partiel** : au moins une variable nécessaire était manquante → la liste est exposée
- **Erreur fatale** : un problème non récupérable (erreur de type, AST corrompu, etc.) → l'exception est levée comme en mode strict

Le mode safe est typiquement utile dans un back-office pour valider des règles utilisateur : "cette règle peut-elle s'évaluer dans le contexte courant, ou manque-t-il des données pour la trancher ?". Pour un diagnostic plus détaillé (nœud par nœud, avec valeurs intermédiaires), voir l'Explainer dans `explainer.md`.

**Principe central** : `missingVars` répond à la question *"qu'est-ce qui était nécessaire mais absent ?"*, pas *"qu'est-ce qui était absent dans l'expression ?"*. Cette distinction est subtile mais structurante — un nœud court-circuité ne contribue pas à la liste des manquants, parce que son absence n'a pas empêché le calcul.

## API

```php
public function evaluateSafe(string $expression, array $context): SafeResult
public function evaluateSafeAst(Node $ast, array $context): SafeResult
```

Et la classe `SafeResult` :

```php
final class SafeResult
{
    public readonly bool  $success;
    public readonly mixed $value;
    public readonly array $missingVars;  // string[]

    public function getValue(): mixed;              // lève LogicException si !success
    public function getValueOr(mixed $default): mixed;
}
```

## Comportements

### `evaluateSafe(string $expression, array $context): SafeResult`

Évalue l'expression. Renvoie toujours un `SafeResult` (jamais d'exception sur variable manquante).

**Forme du retour** :
- Si aucune variable n'a manqué : `SafeResult(success: true, value: <résultat>, missingVars: [])`
- Si une ou plusieurs variables nécessaires étaient manquantes : `SafeResult(success: false, value: null, missingVars: ['path1', 'path2', ...])`

La liste `missingVars` est dédupliquée et **triée alphabétiquement** (comme `extractVariables()` et `getFunctions()`).

**Exceptions levées** :
- `SyntaxErrorException` — l'expression est mal formée (même politique qu'en mode strict)
- `TypeErrorException` — erreur de type **non liée à une variable manquante** (par exemple `"hello" AND true` lève toujours)
- `EvaluatorException` — profondeur dépassée, AST corrompu
- `CircularContextException` — non levée directement

**Garantie fondamentale** : `UnknownVariableException` est attrapée et transformée en entrée dans `missingVars`. Toutes les autres exceptions remontent.

### `evaluateSafeAst(Node $ast, array $context): SafeResult`

Identique mais sur un AST déjà compilé. Sémantique strictement identique.

### `SafeResult::getValue(): mixed`

Renvoie `$this->value` si `success` est `true`. Lève `LogicException` sinon, avec un message listant les variables manquantes.

Cette méthode existe pour **forcer le caller à gérer explicitement le cas d'échec**. Recevoir silencieusement `null` serait ambigu — `null` peut être la valeur réelle d'une expression réussie (par exemple `evaluateSafe('a ?? null', ['a' => null])` renvoie `SafeResult(true, null, [])`).

### `SafeResult::getValueOr(mixed $default): mixed`

Renvoie `$this->value` si `success` est `true`, sinon `$default`. Alternative sans `try/catch`.

```php
$result = $eval->evaluateSafe('cart.total > 100', $context);
$shouldDisplay = $result->getValueOr(false);  // false si la donnée manque
```

⚠️ Attention : `getValueOr(null)` est ambigu (impossible de distinguer "succès avec null" de "échec"). Dans ce cas, vérifier `$result->success` explicitement.

## Sémantique détaillée

C'est la partie la plus subtile. Les règles ci-dessous sont **assumées et figées** ; elles sont documentées dans le code de façon explicite.

### Variable seule

```php
evaluateSafe('a', ['a' => 5]);    // SafeResult(true,  5,    [])
evaluateSafe('a', []);            // SafeResult(false, null, ['a'])
evaluateSafe('a', ['a' => null]); // SafeResult(true,  null, [])  ← null est une valeur, pas une absence
```

### Opérateur `??` (null-coalescing)

Le `??` est conçu pour gérer l'absence : si l'opérande gauche est absent ou `null`, l'opérande droit est évalué. Les variables absentes du **côté gauche** ne sont **pas** reportées dans `missingVars` — leur absence est le cas nominal de `??`, pas un échec.

```php
evaluateSafe('a ?? b', ['a' => 5]);                  // SafeResult(true,  5,   [])
evaluateSafe('a ?? b', ['a' => null, 'b' => 10]);    // SafeResult(true,  10,  [])
evaluateSafe('a ?? b', ['b' => 10]);                 // SafeResult(true,  10,  [])  ← 'a' absent NON reporté
evaluateSafe('a ?? b', []);                          // SafeResult(false, null, ['b'])  ← seul 'b' est reporté
evaluateSafe('a ?? b', ['a' => null]);               // SafeResult(false, null, ['b'])  ← 'b' était nécessaire car 'a' était null
```

**Justification** : seul ce qui était *ultimement nécessaire* et manquant est reporté. Si `a` est absent, `??` fait son travail et passe à droite — pas de raison de remonter `a` comme manquant.

### Short-circuit AND / OR

Les opérateurs logiques court-circuitent comme en PHP natif (et comme en mode strict). En mode safe, **un nœud court-circuité ne contribue pas à `missingVars`** : son absence n'a pas empêché de trancher.

```php
// AND avec gauche certaine = false → droite non évaluée, ses manquants ne sont pas reportés
evaluateSafe('false AND <missing>', []);     // SafeResult(true, false, [])

// OR avec gauche certaine = true → droite non évaluée
evaluateSafe('true OR <missing>', []);       // SafeResult(true, true, [])

// Gauche manquante → on évalue quand même droite, gauche est reportée
evaluateSafe('a AND false', []);             // SafeResult(false, null, ['a'])
evaluateSafe('a OR true', []);               // SafeResult(false, null, ['a'])
evaluateSafe('a AND b', []);                 // SafeResult(false, null, ['a', 'b'])
```

**Cas particulier** : `<missing> AND false` ou `<missing> OR true`. Le résultat est *déterminé* par la droite (false force AND à false, true force OR à true), mais la gauche manquante est **quand même reportée** :

```php
evaluateSafe('a AND false', []);   // SafeResult(false, null, ['a'])  — pas SafeResult(true, false, [])
evaluateSafe('a OR true', []);     // SafeResult(false, null, ['a'])  — pas SafeResult(true, true,  [])
```

**Justification** : `success` répond à la question *"le contexte était-il complet ?"*, pas seulement *"le résultat est-il calculable ?"*. Un appelant qui reçoit `success: false` doit savoir que le contexte était incomplet, indépendamment du fait que la valeur ait pu être inférée.

### Ternaire

Seule la branche prise est visitée. Les manquants de la branche non prise ne sont pas reportés.

```php
evaluateSafe('a > 0 ? b : c', ['a' => 5, 'b' => 'yes']);      // SafeResult(true,  'yes', [])
evaluateSafe('a > 0 ? b : c', ['a' => -1, 'c' => 'no']);      // SafeResult(true,  'no',  [])
evaluateSafe('a > 0 ? b : c', ['a' => 5]);                    // SafeResult(false, null,  ['b'])  ← 'c' non reporté
evaluateSafe('a > 0 ? b : c', []);                            // SafeResult(false, null,  ['a'])  ← condition manquante, branches non visitées
```

Si la **condition** est manquante, aucune branche n'est visitée — leurs manquants éventuels ne sont pas reportés.

### Erreurs de type non absorbées

Le mode safe gère **uniquement** les variables manquantes. Toute autre erreur (type incompatible, division par zéro, NaN/INF, mauvaise arity de fonction…) lève comme en mode strict.

```php
evaluateSafe('"hello" AND true', []);     // TypeErrorException — "hello" n'est pas un bool
evaluateSafe('1 / 0', []);                // TypeErrorException — division par zéro
evaluateSafe('a AND true', ['a' => 5]);   // TypeErrorException — 5 n'est pas un bool
```

**Justification** : "safe" signifie *"`UnknownVariableException` est attrapée et collectée, pas supprimée globalement"*. Une erreur de type est un bug de code, pas un problème de données — la masquer enverrait un faux signal à l'appelant.

### Priorité type-error > missing dans AND/OR/ternaire

Quand le côté gauche d'un AND/OR (ou la condition d'un ternaire) **est résolu** mais n'est pas un booléen, la `TypeErrorException` est levée **avant** d'envisager la droite. C'est cohérent avec le mode strict.

```php
evaluateSafe('5 AND b', ['b' => true]);    // TypeErrorException — pas SafeResult(false, ..., ['b'])
evaluateSafe('a AND b', []);               // SafeResult(false, null, ['a', 'b']) — 'a' n'a pas été résolu, pas d'assertion bool sur lui
```

Quand le côté gauche **n'a pas pu être résolu** (manquant), aucune assertion de type n'est faite dessus — on ne sait simplement pas ce qu'il aurait valu.

## Décisions de design

### Compteur de profondeur partagé

Le mode safe partage le même compteur `evalDepth` que le mode strict. La limite `MAX_EVAL_DEPTH` (200) s'applique aux deux. Garantit aussi que la récursion croisée (`evalSafe` → `callFunction` → fonction custom qui appelle `evaluate()` → `evalSafe`) reste bornée.

### `null` ambigu en valeur sentinelle interne

En interne, `evalSafe` renvoie `null` comme sentinelle quand un sous-arbre a accumulé des manquants. Mais comme `null` peut aussi être une valeur légitime (résultat de `a ?? null` par exemple), l'appelant **doit toujours regarder `missingVars`** pour distinguer les deux cas. C'est pour cela que `SafeResult` ne renvoie `value: null` qu'en cas d'échec — le contrat de sortie est sans ambiguïté pour l'utilisateur final.

### Fonctions custom : pas de collecte transparente

Si une fonction custom enregistrée appelle `getContextValue('x', [])` en interne et que `'x'` est absent, l'exception `UnknownVariableException` qui en résulte **traverse `evaluateSafe` inchangée**. Elle n'est pas convertie en entrée dans `missingVars`.

**Raison** : la lib ne peut pas savoir si la variable lookup-ée à l'intérieur de la fonction faisait conceptuellement partie de l'expression — peut-être que c'est un détail d'implémentation de la fonction. Silencieusement transformer ça en "missing" cacherait potentiellement un vrai bug.

Les auteurs de fonctions custom qui veulent participer au protocole "missing" doivent soit utiliser `getContextValueOrDefault()`, soit attraper l'exception et la regérer eux-mêmes.

### Pas de mode safe pour `evaluateBoolean` / `evaluateNumeric`

Il n'y a pas de méthode `evaluateSafeBoolean` ou `evaluateSafeNumeric`. Pour valider le type du résultat en mode safe, il faut le faire après coup :

```php
$result = $eval->evaluateSafe('a > 0', $context);
if ($result->success && is_bool($result->value)) {
    // ...
}
```

## Limitations connues

### Pas de "missing" pour les fonctions inconnues

Si l'expression appelle une fonction non enregistrée (par exemple `unknownFn(a)`), une `EvaluatorException` est levée — pas reportée en "missing". C'est cohérent : une fonction inconnue est un problème d'expression (ou de configuration), pas de données.

### Pas de granularité par chemin partiel

Si `cart.total` manque, la liste reporte `'cart.total'` (le chemin demandé), pas `'cart'` (le parent absent). Cela peut être contre-intuitif si le contexte fourni ne contient même pas la clé `cart`, mais c'est le chemin référencé dans l'expression qui fait foi. Cohérent avec `UnknownVariableException` en mode strict.