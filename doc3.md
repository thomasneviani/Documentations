Yep, c’est **normal** — et tu as très bien identifié le problème 👀
👉 **Symfony ne crée pas les erreurs**, il **les rend visibles**.

Ton legacy vivait dans un monde où :

* `error_reporting` était faible (ou à 0)
* les *notices / warnings* étaient ignorées
* PHP “laissait passer” des trucs sales

Avec Symfony :

* `error_reporting(E_ALL)`
* erreurs converties en exceptions (en dev)
* affichage via Whoops / ErrorHandler

Résultat :
💥 *“Undefined array offset”*, *“Undefined index”*, etc.

---

## 1️⃣ Pourquoi ça arrive **spécifiquement** avec le Route Loader

Parce que :

* Symfony **bootstrape PHP avant** ton legacy
* `ErrorHandler` est déjà actif
* ton `require legacy.php` hérite de cette config stricte

Avant :

```php
require 'legacy.php'; // PHP en mode cowboy 🤠
```

Maintenant :

```php
require 'legacy.php'; // PHP sous surveillance 👮
```

---

## 2️⃣ Solution rapide (reproduire EXACTEMENT le comportement legacy)

### 🔥 Option A – Rétablir le silence (recommandé au début)

Dans ton `LegacyController` :

```php
public function handle(Request $request): Response
{
    $previousLevel = error_reporting(0);
    $previousDisplay = ini_get('display_errors');

    ini_set('display_errors', '0');
    error_reporting(0);

    ob_start();

    $_GET = $request->query->all();
    $_POST = $request->request->all();
    $_REQUEST = array_merge($_GET, $_POST);

    require __DIR__ . '/../../legacy/legacy.php';

    $content = ob_get_clean();

    // restore
    error_reporting($previousLevel);
    ini_set('display_errors', $previousDisplay);

    return new Response($content);
}
```

✅ Tu retrouves **exactement** le comportement d’avant
❌ Tu masques la dette technique (mais c’est voulu au début)

---

## 3️⃣ Option B – Filtrer uniquement les notices / warnings

Plus propre si tu veux garder les erreurs fatales :

```php
error_reporting(E_ERROR | E_PARSE);
```

Ou :

```php
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING & ~E_DEPRECATED);
```

💡 Très utile pendant une migration progressive.

---

## 4️⃣ Cas concret de tes erreurs

### ❌ `Undefined array offset`

Legacy typique :

```php
$value = $data[3];
```

Symfony dit :

> *Undefined array offset 3*

Solution legacy-safe :

```php
$value = $data[3] ?? null;
```

---

### ❌ `Undefined $post['add']`

Souvent un mélange entre `$_POST` et `$post`

Avant ça passait parce que :

* `register_globals` (ancien PHP)
* notices ignorées

Fix minimal :

```php
$post = $_POST ?? [];

if (!empty($post['add'])) {
    ...
}
```

Ou dans ton loader (hack temporaire assumé 😅) :

```php
$post = $_POST;
$get = $_GET;
```

---

## 5️⃣ Très important : désactiver l’ErrorHandler Symfony **localement**

Symfony convertit les warnings en exceptions.

👉 Tu peux le désactiver JUSTE pour le legacy :

```php
use Symfony\Component\ErrorHandler\ErrorHandler;

ErrorHandler::register(null, false);
```

⚠️ À faire **avant** le `require legacy.php`.

Exemple complet :

```php
use Symfony\Component\ErrorHandler\ErrorHandler;

ErrorHandler::register(null, false);
error_reporting(0);
```

---

## 6️⃣ Approche pro (celle que je recommande)

### Phase 1 – Compatibilité

* silence des erreurs
* comportement identique
* prod stable

### Phase 2 – Observation

* log des notices dans un fichier
* pas d’affichage

```php
ini_set('log_errors', '1');
ini_set('error_log', '/tmp/legacy.log');
```

### Phase 3 – Nettoyage progressif

* corriger fichier par fichier
* migrer vers des controllers Symfony

---

## 7️⃣ Règle d’or migration legacy 💀➡️✨

> **Ne corrige jamais le legacy en même temps que l’intégration Symfony.**
> Sinon tu ne sais plus ce qui casse quoi.

---

Si tu veux, je peux te donner :

* un **LegacyKernel isolé**
* un **wrapper de compatibilité PHP 5 → 8**
* ou un **script pour scanner toutes les notices legacy**

Dis-moi ce que tu veux attaquer en premier 🔧
