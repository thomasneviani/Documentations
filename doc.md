**Non, ce n'est pas le meilleur choix** pour une migration d'un an. Voici pourquoi et quelle stratégie adopter. [symfony](https://symfony.com/doc/current/migration.html)

## Pourquoi ne pas masquer les erreurs globalement dans Symfony

1. **Tu perds les bénéfices de Symfony** : Les erreurs que Symfony détecte sont de vraies bugs qui existent déjà dans ton legacy [sentry](https://sentry.io/answers/notice-undefined-variable-index-array-key-and-offset-using-php/)

2. **Tu crées une dette technique** : Dans un an, tu auras toujours les mêmes problèmes non résolus [symfony](https://symfony.com/doc/current/migration.html)

3. **Tu masques aussi les erreurs du nouveau code Symfony** : Si tu baisses l'`error_reporting` globalement, tu ne verras plus les erreurs dans tes nouveaux contrôleurs Symfony [symfony](https://symfony.com/doc/current/migration.html)

## La bonne stratégie : Isolation par contexte

**Applique l'`error_reporting` legacy UNIQUEMENT dans la LegacyBridge**, pas globalement: [github](https://github.com/opencats/gitbook/blob/main/technical-configuration-options/dev-guide-to-migrate-legacy-to-symfony.md)

```php
// Dans ta LegacyBridge SEULEMENT
public static function handleRequest(Request $request, Response $response, string $publicDirectory): void
{
    $legacyScriptFilename = LegacyBridge::getLegacyScript($request);
    
    $_POST = $request->request->all();
    $_GET = $request->query->all();
    $_FILES = $request->files->all();
    
    // error_reporting legacy SEULEMENT pour le code legacy
    $oldErrorReporting = error_reporting();
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);
    
    ob_start();
    require $legacyScriptFilename;
    $content = ob_get_clean();
    
    // Restaurer immédiatement pour Symfony
    error_reporting($oldErrorReporting);
    
    $response->setContent($content);
}
```

### Avantages de cette approche

1. **Le legacy fonctionne** comme avant pendant la migration [github](https://github.com/opencats/gitbook/blob/main/technical-configuration-options/dev-guide-to-migrate-legacy-to-symfony.md)
2. **Ton nouveau code Symfony** bénéficie du strict error reporting [symfony](https://symfony.com/doc/current/migration.html)
3. **Migration progressive** : À chaque fois que tu migres une route legacy vers Symfony, elle passe automatiquement en mode strict [youtube](https://www.youtube.com/watch?v=uKq9bRMDe7I)
4. **Qualité croissante** : Ton application devient plus robuste au fil de la migration [symfony](https://symfony.com/doc/current/migration.html)

## Stratégie de migration sur 1 an (Strangler Pattern)

### Phase 1 : Stabilisation (Mois 1-2)
- Configure ta LegacyBridge avec l'`error_reporting` legacy [github](https://github.com/jybeul/legacy-bridge-bundle)
- Documente les erreurs connues mais ne les corrige pas encore
- Focus sur la migration des nouvelles features en Symfony pur

### Phase 2 : Migration progressive (Mois 3-10)
- Migre route par route vers des contrôleurs Symfony [youtube](https://www.youtube.com/watch?v=uKq9bRMDe7I)
- **Chaque route migrée = erreurs corrigées** pour cette route
- Les routes non migrées continuent avec le legacy bridge
- Priorise les routes les plus utilisées ou critiques

### Phase 3 : Nettoyage (Mois 11-12)
- Corrige les dernières routes legacy
- Supprime progressivement la LegacyBridge
- Uniformise l'`error_reporting` à `E_ALL` [symfony](https://symfony.com/doc/current/migration.html)

## Exemple concret

```php
// config/routes.yaml

# Routes Symfony (avec error_reporting strict)
app_new_feature:
    path: /new-feature
    controller: App\Controller\NewFeatureController::index

app_migrated_user:
    path: /user/{id}
    controller: App\Controller\UserController::show

# Routes legacy (avec error_reporting legacy dans la bridge)
legacy:
    path: /{path}
    controller: App\Controller\LegacyController::handleLegacy
    requirements:
        path: '.+'
    priority: -1  # Catch-all en dernier
```

## Logging pour le suivi

Ajoute du logging dans ta LegacyBridge pour tracker ce qui doit être migré: [last9](https://last9.io/blog/php-error-logs/)

```php
use Psr\Log\LoggerInterface;

public function __construct(private LoggerInterface $logger)
{
}

public function handleLegacy(Request $request): Response
{
    $script = $this->getLegacyScript($request);
    
    // Log les accès legacy
    $this->logger->info('Legacy route accessed', [
        'path' => $request->getPathInfo(),
        'script' => $script,
    ]);
    
    // Ton code LegacyBridge...
}
```

## Conclusion

**Garde les erreurs silencieuses UNIQUEMENT dans la LegacyBridge**. Ne touche pas à la config Symfony globale. Cela te permet de : [github](https://github.com/jybeul/legacy-bridge-bundle)
- Avoir un legacy fonctionnel immédiatement
- Améliorer progressivement la qualité du code
- Mesurer ta progression (ratio routes Symfony vs legacy)
- Finir la migration avec une application robuste et moderne [youtube](https://www.youtube.com/watch?v=uKq9bRMDe7I)
