# Résolution d'alias

## Vue d'ensemble

Le résolveur d'alias est un composant **indépendant** qui traduit entre une représentation "humaine" et une représentation "technique" des expressions.

Cas d'usage type : un backoffice où un utilisateur métier édite des règles en langage naturel (`"customer group = 'vip' AND cart amount > 100"`) mais où les données du contexte sont structurées techniquement (`customer.group`, `cart.total`). L'AliasResolver fait le pont dans les deux sens :

- **Saisie utilisateur** → `humanToExpression()` → expression technique → évaluation
- **Expression technique stockée** → `expressionToHuman()` → affichage utilisateur

L'AliasResolver est **autonome** : il ne fait que de la substitution textuelle ciblée. Il n'évalue pas, ne parse pas, ne valide pas la sémantique. Il ne nécessite pas d'`ExpressionEvaluator`.

## API

```php
namespace Ols\PhpRuler;

final class AliasResolver
{
    public function add(string $path, string $alias): self;
    public function remove(string $path): self;
    public function clear(): self;
    public function all(): array;

    public function humanToExpression(string $human): string;
    public function expressionToHuman(string $expression): string;
}
```

## Comportements

### `add(string $path, string $alias): self`

Enregistre une correspondance bidirectionnelle entre un chemin technique et un alias humain.

```php
$resolver = new AliasResolver();
$resolver
    ->add('customer.group', 'customer group')
    ->add('cart.total',     'cart amount')
    ->add('order.shipping', 'shipping cost');
```

**Règles de validation de l'alias** :

| Règle | Si violée |
|---|---|
| Pas de guillemets (`'` ou `"`) | `InvalidArgumentException` |
| Pas vide ni whitespace-seulement | `InvalidArgumentException` |
| Pas d'espaces de tête ni de queue | `InvalidArgumentException` (suggère trim) |
| Caractères autorisés : lettres ASCII, chiffres, underscore, whitespace interne, Unicode (`\x{0080}-\x{FFFF}`) | `InvalidArgumentException` |
| Ne doit pas correspondre (insensible à la casse) à un mot-clé : `and`, `or`, `not`, `in`, `true`, `false`, `null` | `InvalidArgumentException` |
| Doit être unique : un même alias ne peut pas pointer vers deux chemins différents | `InvalidArgumentException` |

**Caractères interdits explicitement** (au-delà du whitelist) :
- `.` (pointerait collision avec les chemins dot-notation)
- `-` (ambigu avec l'opérateur de soustraction)
- Toute ponctuation, opérateur, ou métacaractère de regex (produirait une expression mal formée après substitution)

```php
$resolver->add('cart.total', 'cart-total');    // InvalidArgumentException — tiret interdit
$resolver->add('cart.total', 'and');           // InvalidArgumentException — mot-clé
$resolver->add('cart.total', "cart's total");  // InvalidArgumentException — apostrophe
$resolver->add('cart.total', 'AND');           // InvalidArgumentException — mot-clé insensible à la casse
```

### Asymétrie sur la ré-inscription

**Un alias** ne peut pointer que vers **un seul** chemin. Tenter de réutiliser un alias pour un chemin différent lève.

**Un chemin** peut être ré-inscrit avec un **nouvel alias**. Dans ce cas, l'alias précédent est **silencieusement supprimé** (last-write-wins) :

```php
$resolver->add('cart.total', 'cart amount');
$resolver->add('cart.total', 'cart total');     // OK, 'cart amount' est supprimé
// 'cart.total' ↔ 'cart total' désormais

$resolver->add('order.total', 'cart total');    // InvalidArgumentException
// L'alias 'cart total' est déjà utilisé par 'cart.total'
```

Justification : les chemins sont l'identifiant canonique. La lib est conçue pour être configurée une fois au bootstrap, donc la ré-inscription d'un chemin est une décision explicite du développeur — pas besoin de protection runtime supplémentaire.

### `remove(string $path): self`

Supprime l'alias associé au chemin. Silencieux si le chemin n'a pas d'alias enregistré.

### `clear(): self`

Vide toutes les correspondances.

### `all(): array`

Retourne le tableau interne `path => alias`.

```php
$resolver->all();
// ['customer.group' => 'customer group', 'cart.total' => 'cart amount']
```

### `humanToExpression(string $human): string`

Traduit une expression "humaine" en expression "technique" en remplaçant chaque alias trouvé par son chemin associé.

```php
$resolver->humanToExpression("customer group = 'vip' AND cart amount > 100");
// → "customer.group = 'vip' AND cart.total > 100"
```

### `expressionToHuman(string $expression): string`

Traduction inverse — chaque chemin connu est remplacé par son alias.

```php
$resolver->expressionToHuman("customer.group = 'vip' AND cart.total > 100");
// → "customer group = 'vip' AND cart amount > 100"
```

### Garanties de substitution

Les deux méthodes opèrent par **substitution textuelle**, mais avec plusieurs gardes pour éviter les pièges classiques :

#### 1. Pas de remplacement dans les littéraux quotés

L'expression est découpée en segments alternés "hors-quote" / "dans-quote". Seuls les segments hors-quote sont traités.

```php
$resolver->add('cart.total', 'cart amount');
$resolver->humanToExpression("customer.group = 'cart amount'");
// → "customer.group = 'cart amount'"  ← littéral préservé, PAS remplacé
```

#### 2. Word boundary stricte

Les substitutions ne matchent que des occurrences délimitées par autre chose qu'une lettre, chiffre, underscore, point, ou caractère Unicode `\x{0080}-\x{FFFF}` :

```php
$resolver->add('cart.total', 'total');
$resolver->expressionToHuman('cart.total = subtotal');
// → 'total = subtotal'  ← "total" remplacé, "subtotal" préservé

// Et de l'autre côté :
$resolver->add('sum', 'total');
$resolver->humanToExpression('total(x)');
// → 'total(x)'  ← PAS remplacé : un alias ne peut pas devenir un nom de fonction
```

**Le `(` à droite est explicitement exclu** : un alias représente une variable, pas une fonction. Un alias suivi d'une parenthèse n'est pas substitué.

#### 3. Conscience UTF-8

Les regex utilisent le mode `/u` et incluent la plage Unicode dans la définition de "frontière de mot". Cela évite les corruptions du type :

```php
$resolver->add('menu', 'menu');  // (hypothétique)
// Sans /u et plage Unicode :  'menü' aurait été corrompu en 'menupath' (le 'ü' n'étant pas vu comme une frontière)
// Avec :                       'menü' est préservé intact
```

#### 4. Longest match first

Si plusieurs alias se chevauchent, le plus long est essayé en premier :

```php
$resolver
    ->add('a.b.c', 'customer group name')
    ->add('a.b',   'customer group');

$resolver->humanToExpression('customer group name = "x" AND customer group = "y"');
// → 'a.b.c = "x" AND a.b = "y"'  ← 'customer group name' matché avant 'customer group'
```

#### 5. Casse exacte

Les substitutions sont **sensibles à la casse**. Un alias enregistré comme `'Cart Total'` ne match pas `'cart total'` ni `'CART TOTAL'`.

Justification : les alias sont gérés par les développeurs, pas par les utilisateurs finaux. Une match approximative cacherait des fautes de frappe. Sur l'expression résultante, c'est aussi le Lexer qui rejettera proprement.

#### 6. Gestion d'erreur UTF-8 invalide

Si l'expression contient des séquences UTF-8 invalides, les méthodes de traduction lèvent `InvalidArgumentException`. Pas de tentative de réparation silencieuse.

## Décisions de design

### Découplage total de l'évaluateur

`AliasResolver` est **indépendant** d'`ExpressionEvaluator`. Pas de dépendance, pas d'instance partagée. Vous pouvez utiliser l'un sans l'autre :

```php
// Évaluation sans alias
$eval->evaluate('cart.total > 100', $ctx);

// Aliasage sans évaluation (par exemple pour stocker une règle "humanisée" en base)
$resolver->expressionToHuman($ruleTechnical);
```

C'est délibéré : ce sont deux préoccupations distinctes.

### Substitution textuelle, pas parsing

Le résolveur ne parse pas. Il fait du remplacement regex avec des gardes. Avantages :
- Très rapide
- N'impose pas que l'expression soit syntaxiquement valide pour aliaser/désaliaser (utile pendant la frappe utilisateur dans un éditeur)
- Symétrique : `humanToExpression(expressionToHuman($x)) === $x` si tous les alias sont connus

Limites :
- Pas de validation que les chemins/aliasés existent dans le contexte
- Pas de garantie que le résultat est une expression valide (mais le Lexer/Parser le détectera)

### Stratégie de découpage cohérente entre composants

La détection de littéraux quotés (pour préserver leur contenu) utilise la même regex que :
- `Lexer::normalizeNbspOutsideStrings()`
- `ExpressionEvaluator::canonicalizeForCache()`

Les trois composants sont synchronisés. Si la grammaire des littéraux évolue (par exemple ajout de backticks), il faut mettre à jour les trois sites.

### Caractères autorisés volontairement restreints

Le whitelist n'autorise pas les opérateurs ni la ponctuation. Conséquence : un alias ne peut pas accidentellement contenir un caractère qui produirait une expression invalide après substitution.

C'est ce qui rend le résolveur fiable malgré sa simplicité : la classe des alias acceptés est suffisamment réduite pour qu'aucune ambiguïté ne puisse apparaître à l'usage.

### Tolérance Unicode dans les alias

Les caractères Unicode `\x{0080}-\x{FFFF}` sont acceptés dans les alias. Cela couvre l'essentiel des langues européennes (accents, ñ, ü...) et asiatiques (CJK basique).

⚠️ Cette plage **n'inclut pas** :
- Les caractères au-delà du BMP (Basic Multilingual Plane) — émojis, certains caractères asiatiques rares...
- La normalisation Unicode (NFC vs NFD) — `'café'` en NFC et `'café'` en NFD ne matchent pas, même s'ils s'affichent identiquement

Pour l'usage backoffice francophone/anglophone visé, c'est largement suffisant. À étendre si besoin pour d'autres alphabets.

## Limitations connues

### Pas de matching sémantique

Le résolveur ignore le contexte syntaxique. Conséquence : si un alias et un nom de variable légitime collisionnent, le résolveur substitue. Exemple pathologique :

```php
$resolver->add('a.b', 'foo');
$resolver->humanToExpression('foo > 5');  // → 'a.b > 5'

// Mais si l'utilisateur avait écrit 'foo' en pensant à une autre variable foo :
// pas de moyen de distinguer
```

En pratique, ce risque est mitigé par :
- La validation stricte des alias (pas d'opérateur, pas de caractère ambigu)
- La convention "les alias sont des mots ou phrases descriptives", très différents des chemins techniques

### Pas d'alias hiérarchiques partiels

Si vous aliasez `cart.total` en `'cart total'`, le résolveur ne sait rien de l'alias pour `cart` lui-même (sauf si vous l'enregistrez séparément). Pas de propagation automatique.

### Aliasage côté backoffice uniquement

Le résolveur est conçu pour la couche présentation. L'évaluation ne connaît rien des alias. Si vous voulez qu'`evaluate()` accepte directement les alias, traduisez avant.

```php
$human = $userInput;
$technical = $resolver->humanToExpression($human);
$result = $eval->evaluate($technical, $context);
```

### Pas de cache de traduction

Chaque appel à `humanToExpression()` / `expressionToHuman()` re-construit le pattern regex. Pour des traductions massives répétées sur les mêmes alias, un cache externe peut être pertinent — mais en pratique, l'usage typique (une traduction par requête) ne pose pas de problème.