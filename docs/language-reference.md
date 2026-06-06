# Référence du langage

## Vue d'ensemble

Cette doc décrit la **syntaxe** acceptée par la lib : ce qu'on peut écrire dans une expression et ce que ça veut dire. C'est la référence pour quiconque rédige des expressions, qu'il soit développeur intégrant la lib ou utilisateur final dans un backoffice.

Le langage est volontairement **proche de PHP** (opérateurs, précédences, sémantique des littéraux) tout en étant **strict** (pas de coercition silencieuse, pas d'overflow accepté, etc.). L'intuition pour un développeur PHP doit être correcte par défaut.

## Vue d'ensemble syntaxique

```
expression := ternary
ternary    := or ('?' ternary ':' ternary)?
or         := and ('OR' and)*
and        := coalesce ('AND' coalesce)*
coalesce   := comparison ('??' coalesce)?
comparison := addSub (comp_op addSub | 'IN' rhs | 'NOT' 'IN' rhs)?
addSub     := mulDiv (('+' | '-') mulDiv)*
mulDiv     := not (('*' | '/' | '%') not)*
not        := 'NOT' not | unary
unary      := '-' unary | '+' unary | primary
primary    := literal | identifier ('(' args ')')? | '(' ternary ')' | '[' list ']'
```

## Littéraux

### Entiers

Suite de chiffres décimaux. Bornés par `PHP_INT_MAX` (positif) et `PHP_INT_MIN` (négatif).

```
0, 42, 1000000
-1, -42      (le '-' est un opérateur unaire, pas partie du littéral)
```

**Au-delà de `PHP_INT_MAX`** : levé en `SyntaxErrorException` à la phase de lex/parse. Exception : la valeur exacte `|PHP_INT_MIN|` (= `PHP_INT_MAX + 1`) est acceptée **uniquement** comme opérande d'un `-` unaire, ce qui permet d'écrire littéralement `PHP_INT_MIN`.

### Flottants

Notation décimale (`.` obligatoire) ou scientifique (`e` ou `E`).

```
0.5, 3.14, 1.0
1e10, 2.5E-3, -1.5e6
```

**Restrictions** :
- Pas de forme `.5` (sans entier devant) : doit être `0.5`
- Pas de forme `5.` (sans décimale derrière) : doit être `5.0`
- Pas d'`Infinity` ni `NaN` littéraux. Si un littéral float dépasse `PHP_FLOAT_MAX`, levé en `SyntaxErrorException` au lex-time.

### Booléens

`true` ou `false`, **insensibles à la casse** : `TRUE`, `True`, `tRuE` sont équivalents.

### Null

`null`, insensible à la casse.

### Chaînes

Délimitées par `'` simple ou `"` double quote. **Échappement** : doubler le délimiteur.

```
'hello world'
"hello world"
'L''Oréal'             →  L'Oréal
"He said ""hi"""       →  He said "hi"
```

**Pas d'autres échappements** : `'\n'` n'est pas un retour ligne, c'est littéralement les deux caractères `\` et `n`. Les chaînes multi-lignes sont possibles mais sans interprétation.

**Pas d'interpolation** : `'Hello $name'` est la chaîne littérale.

### Listes

Notation `[...]` avec virgules. Acceptent des littéraux primitifs (pas de variables, pas d'expressions calculées).

```
[1, 2, 3]
['a', 'b', 'c']
[1, 'mixed', true, null]
[]                  # liste vide
[-1, -2.5]          # négatifs OK (folding du - dans le parser)
```

**Restrictions** :
- Pas d'expressions calculées : `[1+1, 2]` lève
- Pas de variables : `[a, b]` lève (sauf via une fonction custom qui construit une liste)
- Pas de virgule trailing : `[1, 2,]` lève
- `[]` interdit comme opérande droite d'un `IN` (cas dégénéré sans intérêt)

## Variables (chemins)

Notation pointée. Chaque segment est un identifiant valide PHP (lettre ou `_` en tête, puis lettres/chiffres/`_`).

```
total
cart.total
customer.address.country
```

**Mots-clés réservés** (insensibles à la casse) : `and`, `or`, `not`, `in`, `true`, `false`, `null`. Un identifiant racine qui correspond à un de ces mots-clés est tokenisé comme mot-clé, donc inaccessible directement :

```
'In'      →  SyntaxErrorException
'user.in' →  OK (le 'in' est un sous-segment, pas la racine)
```

Pour exposer une donnée avec une clé racine en conflit, encapsulez-la sous un parent.

**Pas de wildcards, ni d'indexation crochet, ni de méthodes** : `cart.items[0]`, `cart.items.0`, `cart.method()` ne sont pas supportés. Voir `context.md` pour les détails sur la résolution.

## Opérateurs

Liste complète, du moins prioritaire au plus prioritaire. Précédences **alignées sur PHP**, à une exception près et assumée : `??` est placé *au-dessus* de AND/OR (en PHP il est en-dessous de `&&`/`||`). Voir la section `??` ci-dessous.

| # | Précédence | Opérateurs | Type | Associativité |
|---|---|---|---|---|
| 0 | ternaire | `?:` | ternaire | droite |
| 1 | OR logique | `OR`, `\|\|` | binaire | gauche |
| 2 | AND logique | `AND`, `&&` | binaire | gauche |
| 3 | null-coalesce | `??` | binaire | droite |
| 4 | comparaison / membership | `=` (`==`), `!=`, `>`, `>=`, `<`, `<=`, `IN`, `NOT IN` | binaire | non-associatif |
| 5 | additif | `+`, `-` | binaire | gauche |
| 6 | multiplicatif | `*`, `/`, `%` | binaire | gauche |
| 7 | NOT | `NOT` | unaire préfixe | droite (récursif) |
| 8 | unaire | `-`, `+` | unaire préfixe | droite (récursif) |

Les mots-clés `AND`, `OR`, `NOT`, `IN` sont **insensibles à la casse** : `and`/`AND`/`And`/`aNd` sont équivalents. Les alternatives symboliques `&&` et `||` sont acceptées pour AND/OR.

### Égalité (`=`, `==`, `!=`)

`=` et `==` sont équivalents (le parser les normalise tous deux en `=`). Égalité **adaptée** :
- Numériques (int et float) : comparaison sur la valeur, sans piège de typage (`5 = 5.0` → `true`)
- Autres types : strict (`'5' = 5` → `TypeErrorException`)
- `null = null` → `true`, `null = <anything>` → `false` — sans exception, **sauf** si l'autre opérande est NaN/INF (rejeté avant le raccourci null, cf. politique générale ci-dessous)
- **Arrays interdits** : `[1,2] = [1,2]` lève (utiliser `IN` pour la membership)
- **NaN/INF** : rejet (cf. politique générale)

### Comparaison d'ordre (`>`, `>=`, `<`, `<=`)

- Numériques entre eux : OK
- Chaînes entre elles : OK (ordre lexicographique). Permet de comparer des dates `Y-m-d` correctement.
- Mélange numérique/chaîne : `TypeErrorException`
- Booléens, null, arrays : `TypeErrorException`

### IN / NOT IN

`x IN list` teste l'appartenance.

**Côté droit** : une liste littérale `[...]`, une variable de type liste, ou une fonction qui en retourne une. **Pas** un scalaire (`x IN 5` lève en parse).

**Liste vide** : `x IN [...]` avec liste vide à droite est interdit syntaxiquement (sur les littéraux `[]`). Sur une variable qui se résout en liste vide à l'évaluation, renvoie `false` — c'est une donnée valide, pas une erreur. Cette distinction est délibérée : une liste vide littérale dans le code est toujours un bug de l'auteur de l'expression, tandis qu'une liste vide en runtime est une donnée légitime (par exemple `tags = []` quand un article n'a aucun tag).

**Côté gauche scalaire** : comparaison élément-par-élément via `looseEqual` (donc même règles que `=`).

**Côté gauche tableau** (sémantique duale) :
1. **Pré-passe** : test si le subject **est** un élément de la liste (comparaison stricte de tableaux). `[1,2] IN [[1,2], 3]` → `true`.
2. **Fallback intersection** : test si **au moins un élément** du subject est aussi dans la liste. `[1,2] IN [1,2,3]` → `true`, `[4,5] IN [1,2,3]` → `false`.

```
'php' IN tags                       # tags contient-il 'php' ?
cart.tags IN ['promo', 'vip']       # le tableau cart.tags est-il l'un des deux ? OU partage-t-il un élément ?
5 NOT IN [1, 2, 3]                  # → true
```

**Politique d'erreurs de type** : si **aucune** comparaison de paire n'a été comparable (toutes les paires lèvent TypeError), l'erreur est re-levée. Si **au moins une** paire a pu être comparée sans match, retourne `false`. Empêche de transformer une erreur silencieuse en `false`.

### Arithmétique (`+`, `-`, `*`, `/`, `%`)

- Opérandes : strictement `int` ou `float`. Tout autre type lève `TypeErrorException` (pas de coercition de string numérique, pas de `null` → `0`).
- **Overflow** : si deux `int` produisent un résultat qui sort de `PHP_INT_MAX` (PHP downcasterait en `float`), levé. Pour accepter la perte de précision, caster explicitement en `float` : `(a + 0.0) + b`.
- **NaN/INF** : rejetés à toute opération arithmétique.
- **Division par zéro** : `1 / 0` lève `TypeErrorException` (message "Division by zero"). `1 % 0` aussi.
- **Modulo** : `int % int` → `int` (modulo PHP standard). Si au moins un `float`, utilise `fmod`.

### `??` (null-coalescing)

`a ?? b` :
- Si `a` est résolu et non-null : renvoie `a`
- Si `a` est manquant (mode strict + mode safe et explain) **ou** `a = null` : renvoie `b`

Précédence intentionnellement **basse** (entre AND et la comparaison). L'alignement sur PHP est **partiel et assumé** :

```
a ?? b == c     →  a ?? (b == c)         (le == est appliqué d'abord — comme en PHP)
NOT a ?? b      →  (NOT a) ?? b          (NOT est très haute précédence — comme en PHP)
1 ?? 2 > 5      →  1 ?? (2 > 5)          →  1 ?? false  →  1
a ?? b AND c    →  (a ?? b) AND c        (⚠ DIVERGE de PHP — voir ci-dessous)
```

**Divergence assumée vs PHP sur AND/OR** : en PHP, `??` est *plus bas* que `&&`/`||` (donc `a ?? b && c` y vaut `a ?? (b && c)`). Ici, `??` est *au-dessus* de AND/OR (donc `a ?? b AND c` vaut `(a ?? b) AND c`). Exemple concret de l'écart :

```
PHP natif :  true ?? false AND false   →  true    (true ?? (false AND false))
php-ruler  :  true ?? false AND false   →  false   ((true ?? false) AND false)
```

La raison : PHP a en réalité **deux étages** d'opérateurs logiques (`&&`/`||` hauts, `and`/`or` tout en bas, sous l'affectation et le ternaire). Cette lib fusionne les formes mot et symbole en un seul étage AND/OR (look SQL-like), elle ne peut donc pas reproduire la position exacte de `??` vis-à-vis des deux étages PHP simultanément. On garde `??` « serré » (au-dessus de AND/OR) pour que `flag ?? defaut` se lise comme une unité autonome dans une règle booléenne plus large.

**Décision figée et délibérée** : pour `??` vs comparaison et `??` vs NOT, on suit PHP. Pour `??` vs AND/OR, on diverge volontairement. Dans tous les cas, qui veut un autre regroupement parenthèse explicitement : `a ?? (b AND c)`, `(a ?? b) == c`.

### Ternaire (`? :`)

Précédence la plus basse. **Right-associative** :

```
a ? b ? c : d : e        →  a ? (b ? c : d) : e
```

La condition doit être strictement `bool`. Pas de coercition truthy/falsy.

```
a ? 'yes' : 'no'        # OK si a est bool
5 ? 'yes' : 'no'        # TypeErrorException
```

Pas de variante "Elvis" (`a ?: b` interdit) : il faut écrire `a ?? b` (avec sémantique null-coalescing) ou `condition ? value : default` explicite.

### NOT

Précédence très haute (juste sous l'unaire `-` / `+`), **alignée sur PHP** `!`. Opérande strictement `bool`.

```
NOT a               →  négation de a
NOT a == b          →  (NOT a) == b
NOT a AND b         →  (NOT a) AND b
NOT a + b           →  (NOT a) + b   (cohérent avec !$a + $b en PHP)
NOT NOT a           →  NOT (NOT a)   (récursif)
```

`NOT IN` (combinaison spéciale) est traitée à part : c'est un opérateur de niveau "comparaison" (4), pas un `NOT` suivi d'un `IN`.

### Unaire `-` / `+`

Plus haute précédence non-fonctionnelle. Récursif :

```
--x   →  -(-x)   (mathématiquement correct)
++x   →  +(+x)   (no-op)
```

`-` exige opérande `int|float`. **Pas** de coercition : `-null`, `-true`, `-'5'` lèvent.

## Appels de fonction

```
fn()
fn(arg)
fn(arg1, arg2)
fn(arg1, fn2(arg2), expr + 1)
```

Les arguments sont évalués **avant** l'appel (eager). L'ordre est gauche à droite.

L'arity et les types attendus dépendent de la fonction. Voir `functions.md` pour le catalogue et les politiques.

**Mauvaise arity** → `TypeErrorException` (la lib valide, contrairement à PHP qui ignore silencieusement les args en trop).

## Parenthèses

`( ... )` pour grouper une sous-expression. Toujours autorisé, jamais nécessaire (la précédence est définie sans ambiguïté).

```
(a + b) * c
NOT (a AND b)            # nécessaire si on veut "pas (a et b)"
(a ?? b) == c            # nécessaire pour court-circuiter la précédence basse de ??
```

## Whitespace

Les espaces, tabs, sauts de ligne sont **ignorés** entre tokens. Le whitespace **dans** un littéral chaîne est préservé.

**NBSP** (U+00A0, non-breaking space) est traité comme un espace ordinaire **hors littéraux quotés**. Dans les littéraux, il est préservé. C'est utile pour les inputs copiés-collés depuis des sources rich-text (Word, web).

Autres whitespaces Unicode (em-space, thin space, etc.) : **non supportés** côté code. C'est un choix délibéré : le NBSP est le seul whitespace Unicode rencontré en pratique dans les inputs copiés-collés. Étendre la liste aurait un coût de maintenance pour un gain quasi nul. Si rencontrés hors littéral, ces caractères tomberont en token "unknown" et lèveront `SyntaxErrorException`.

## Sémantique de typage

### Aucune coercition silencieuse

Le langage est **strict**. Aucun de ces classiques de PHP n'est accepté :

| Expression | Comportement |
|---|---|
| `"false" AND true` | `TypeErrorException` (string n'est pas bool) |
| `5 AND 10` | `TypeErrorException` (int n'est pas bool) |
| `null + 1` | `TypeErrorException` (null n'est pas un nombre) |
| `'5' + 1` | `TypeErrorException` (string n'est pas un nombre) |
| `true = 1` | `TypeErrorException` (bool et int ne sont pas comparables) |

**Exception** : la "loose equality" sur numériques tolère `int` vs `float` (`5 = 5.0` → `true`). C'est utile en pratique : un calcul `int` et un calcul `float` produisent souvent des valeurs égales sémantiquement.

### NaN et INF interdits dans le pipeline

Aucune opération ne peut produire ou propager NaN ou INF silencieusement. Toute **participation à un opérateur** (arithmétique, comparaison, égalité, `-` unaire) lève `TypeErrorException`. En revanche, une valeur NaN/INF venant du contexte ou d'une fonction custom **peut transiter** jusqu'à `is_finite()` tant qu'elle n'entre pas dans un opérateur — c'est précisément ce qui rend `is_finite()` utilisable. C'est la seule façon de les **inspecter**.

### Variables manquantes

En mode strict (`evaluate`), une variable absente lève `UnknownVariableException`. En mode safe et explain, l'absence est collectée. Voir les docs correspondantes.

## Exemples d'expressions

### Règles de panier

```
cart.total > 100
cart.total > 100 AND customer.vip = true
cart.total > 100 OR (customer.loyalty.years > 2 AND cart.items > 0)
cart.country IN ['FR', 'BE', 'CH']
```

### Calculs

```
cart.subtotal * 1.2
round(cart.total / cart.items, 2)
clamp(discount, 0, 100)
pow(base, 2) + 1
```

### Avec dates

```
year(today()) > 2025
dateDiff(today(), cart.created) <= 30
```

### Avec ternaire et coalesce

```
customer.vip ? 'gold' : 'silver'
(discount ?? 0) > 0
customer.score ?? 50
```

### Avec NOT IN

```
customer.country NOT IN ['IR', 'KP']
```

### Avec ?? précédence (attention)

```
# Ce qu'on lit naturellement :  "(a ?? 0) > 100"
# Mais sans parenthèses :        "a ?? (0 > 100)"  →  "a ?? false"  →  a (ou false si a null)

(a ?? 0) > 100      # forme correcte
```

## Décisions de design

### Surface volontairement limitée

Pas d'affectation, pas de fonctions définies dans l'expression, pas d'effets de bord syntaxiques, pas de structures de contrôle. Le langage est **dévalu** : pas un sous-ensemble de PHP, mais un langage d'expressions à part.

### Alignement sur PHP pour la précédence et la sémantique

Quand une décision avait plusieurs options défendables (par exemple `??` au-dessus ou en-dessous des comparaisons), c'est PHP qui a tranché. Justification : la cible utilisateur est un développeur PHP. La surprise minimale prime sur la "lecture naturelle" hypothétique.

**Une exception assumée** : la position de `??` vis-à-vis de AND/OR diverge de PHP (voir la section `??`). Elle découle du choix de fusionner les deux étages logiques de PHP (`&&`/`||` et `and`/`or`) en un seul, incompatible avec la reproduction exacte de la position de `??`.

### Délimiteurs de chaîne : `'` ET `"`

Les deux sont acceptés et **équivalents**. C'est rare dans les langages d'expression mais utile pour quoter une chaîne contenant un des deux : `"L'Oréal"` plutôt que `'L''Oréal'`.

### Mots-clés insensibles à la casse

`AND`, `and`, `And` sont équivalents. Choix stylistique pour un look "SQL-like" qui est familier aux utilisateurs métier.

Conséquence : les identifiants `and`, `or`, `not`, `in` sont inaccessibles en racine (voir Limitations).

### `?:` non supporté (Elvis)

Pas d'opérateur Elvis (`a ?: b` qui prend `a` si truthy, sinon `b`). Justifications :
- Repose sur la coercition truthy/falsy, qui est rejetée partout ailleurs
- Risque de confusion avec `??` (deux opérateurs similaires mais subtilement différents)
- L'usage légitime est couvert par `??` (null-coalescing) ou un ternaire explicite

### Pas de short-circuit dans `??`, AND, OR pour les types

Bien que `??` court-circuite quand la gauche est non-null, la gauche **doit quand même être un type valide**. Idem pour AND/OR : `5 AND b` lève même si la droite aurait pu court-circuiter sémantiquement.

## Limitations connues

Récap des limitations syntaxiques évoquées ci-dessus :

- Mots-clés réservés inaccessibles en racine
- Pas de wildcards, d'indexation crochet, ni d'accès méthode
- Listes : que des littéraux primitifs, pas d'expressions
- Pas d'échappement dans les chaînes hors quote doublée
- Pas d'interpolation
- Pas de date littérale (utilisez une chaîne `'2026-01-15'`)
- Pas de regex
- Pas d'opérateur Elvis
- Pas de `**` (exponentiation), à utiliser `pow()`
- Pas de bitwise (`&`, `|`, `^`, `<<`, `>>`)

## ⚠️ Pièges courants

### Précédence de `??` — plus basse que les comparaisons

`??` a une précédence **inférieure** aux comparaisons (aligné sur PHP). Conséquence :

```
a ?? 0 > 100       →  a ?? (0 > 100)    →  a ?? false     ← PAS (a ?? 0) > 100
(a ?? 0) > 100     →  forme correcte
```

Règle pratique : dès que vous combinez `??` avec un opérateur de comparaison, mettez le `??` entre parenthèses.

### `NOT a ?? b` — NOT a une précédence très haute

```
NOT a ?? b         →  (NOT a) ?? b      ← PAS NOT (a ?? b)
NOT (a ?? b)       →  forme correcte si c'est l'intention
```

Cela déroute souvent parce qu'on lit `NOT a ?? b` comme "NOT (a si non-null sinon b)". Ce n'est pas ce que le parser fait. Parenthéser si l'intention est `NOT (a ?? b)`.