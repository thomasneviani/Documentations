**Oui, c'est une excellente pratique et même la recommandation officielle** pour une migration progressive avec un legacy route loader. [symfony](https://symfony.com/doc/current/migration.html)

## Pourquoi c'est recommandé

### 1. **Principe d'isolation des contextes**

Tu crées une **frontière claire** entre le code legacy et le code Symfony: [github](https://github.com/jybeul/legacy-bridge-bundle)
- **Contexte legacy** : error_reporting legacy, superglobales, working directory, etc.
- **Contexte Symfony** : error_reporting strict, objets Request/Response, DI, etc.

### 2. **Migration sans régression**

C'est la base du **Strangler Pattern** recommandé par Symfony: [youtube](https://www.youtube.com/watch?v=uKq9bRMDe7I)
- Le legacy continue de fonctionner **exactement comme avant**
- Aucune régression pendant la migration
- Tu migres route par route à ton rythme [github](https://github.com/opencats/gitbook/blob/main/technical-configuration-options/dev-guide-to-migrate-legacy-to-symfony.md)

### 3. **Évite la correction massive**

Sans cette isolation, tu dois: [symfony](https://symfony.com/doc/current/migration.html)
- Corriger **toutes** les erreurs du legacy avant de démarrer
- Tester **toute** l'application legacy
- Bloquer la migration pendant des semaines/mois

Avec l'isolation: [github](https://github.com/jybeul/legacy-bridge-bundle)
- Le legacy fonctionne immédiatement
- Tu corriges **uniquement** les routes que tu migres
- Migration progressive et sûre

## Configuration recommandée dans ta LegacyBridge

```php
namespace App\Legacy;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class LegacyBridge
{
    public static function handleRequest(Request $request, Response $response, string $publicDirectory): void
    {
        $legacyScriptFilename = self::getLegacyScript($request);
        
        // 1. Sauvegarder le contexte Symfony
        $oldErrorReporting = error_reporting();
        $oldWorkingDir = getcwd();
        
        // 2. Restaurer le contexte legacy
        self::restoreLegacyEnvironment($request);
        
        // 3. Exécuter le script legacy
        ob_start();
        require $legacyScriptFilename;
        $content = ob_get_clean();
        
        // 4. Restaurer le contexte Symfony
        error_reporting($oldErrorReporting);
        chdir($oldWorkingDir);
        
        $response->setContent($content);
    }
    
    private static function restoreLegacyEnvironment(Request $request): void
    {
        // Superglobales
        $_POST = $request->request->all();
        $_GET = $request->query->all();
        $_FILES = $request->files->all();
        $_COOKIE = $request->cookies->all();
        $_SERVER = array_merge($_SERVER, $request->server->all());
        
        // Error reporting legacy
        error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);
        
        // Working directory (si nécessaire)
        // chdir($publicDirectory);
    }
}
```

## Ce que dit la documentation Symfony

La documentation officielle recommande explicitement cette approche: [symfony](https://symfony.com/doc/current/migration.html)

> "The idea is to leave the existing application as-is and start building the new application around it"

> "Use output buffering to capture the legacy application output and convert it into a Symfony Response"

## Exemples de projets open source

Des projets comme **OpenCATS** et **Drupal** utilisent exactement cette stratégie: [drupal](https://www.drupal.org/node/2150267)

```php
// Exemple de migration Drupal
// Ils isolent complètement le legacy avec ses propres règles
public function handle(Request $request) 
{
    // Save Symfony state
    $oldErrorReporting = error_reporting();
    
    // Apply legacy settings
    error_reporting(E_ALL & ~E_NOTICE);
    
    // Run legacy
    $response = $this->runLegacy($request);
    
    // Restore Symfony state
    error_reporting($oldErrorReporting);
    
    return $response;
}
```

## Quand retirer cette isolation ?

Au fur et à mesure de la migration: [youtube](https://www.youtube.com/watch?v=uKq9bRMDe7I)

```php
// Mois 1-2 : 100% legacy
legacy:
    path: /{path}
    controller: App\Controller\LegacyController::handleLegacy

// Mois 6 : 50% legacy / 50% Symfony
app_users:
    path: /users/{id}
    controller: App\Controller\UserController::show  # ✅ Symfony strict

legacy:
    path: /{path}  # ⚠️ Reste du legacy avec isolation
    controller: App\Controller\LegacyController::handleLegacy
    priority: -1

// Mois 12 : 100% Symfony
# Plus de legacy route loader
# Plus besoin de l'isolation error_reporting
```

## Best practices complémentaires

### 1. **Documente les différences**

```php
/**
 * LegacyBridge - Isolates legacy PHP code execution
 * 
 * Environment differences:
 * - error_reporting: E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING (legacy)
 *   vs E_ALL (Symfony)
 * - Uses superglobals ($_POST, $_GET) instead of Request object
 * - Output buffering for response capture
 * 
 * @todo Remove this bridge when migration is complete
 */
class LegacyBridge
```

### 2. **Ajoute du monitoring**

```php
use Psr\Log\LoggerInterface;

public function __construct(private LoggerInterface $logger)
{
}

public function handleLegacy(Request $request): Response
{
    $this->logger->info('Legacy route accessed', [
        'path' => $request->getPathInfo(),
        'referer' => $request->headers->get('referer'),
    ]);
    
    // Ton code bridge...
}
```

### 3. **Teste progressivement**

```php
// tests/Legacy/LegacyBridgeTest.php
class LegacyBridgeTest extends WebTestCase
{
    public function testLegacyErrorReportingIsIsolated(): void
    {
        $client = static::createClient();
        
        // Accès à une route legacy
        $client->request('GET', '/legacy-page.php');
        
        // Vérifie que Symfony n'est pas affecté
        $this->assertEquals(E_ALL, error_reporting());
    }
}
```

## Conclusion

**Oui, c'est non seulement recommandé mais essentiel**. Cette isolation permet : [github](https://github.com/opencats/gitbook/blob/main/technical-configuration-options/dev-guide-to-migrate-legacy-to-symfony.md)
- ✅ Migration sans régression
- ✅ Progression mesurable (ratio Symfony/legacy)
- ✅ Qualité croissante au fil du temps
- ✅ Équipe productive pendant toute la migration

C'est la stratégie standard pour les migrations legacy → Symfony sur 1 an. [youtube](https://www.youtube.com/watch?v=uKq9bRMDe7I)
