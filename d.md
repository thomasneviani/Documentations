Voici le code complet dans un seul fichier, bien structuré et commenté  : [symfony](https://symfony.com/doc/current/security.html)

```php
// src/EventSubscriber/SessionValidationSubscriber.php
namespace App\EventSubscriber;

use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class SessionValidationSubscriber implements EventSubscriberInterface
{
    // Clés de session obligatoires
    private const REQUIRED_SESSION_KEYS = ['keylog'];
    
    // Routes exclues de la validation
    private const EXCLUDED_ROUTES = ['app_logout', 'app_login', 'store_picker'];

    public function __construct(
        private Security $security,
        private UrlGeneratorInterface $urlGenerator,
        private LoggerInterface $logger,
    ) {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        // Ignore si ce n'est pas une requête principale
        if (!$event->isMainRequest()) {
            return;
        }

        // Ignore si l'utilisateur n'est pas connecté
        if (!$this->security->getUser()) {
            return;
        }

        // Ignore les routes exclues
        $route = $event->getRequest()->attributes->get('_route');
        if (in_array($route, self::EXCLUDED_ROUTES, true)) {
            return;
        }

        $session = $event->getRequest()->getSession();

        // Vérifie si la session est valide
        if ($this->isSessionValid($session)) {
            return;
        }

        // Gère la session invalide
        $this->handleInvalidSession($event, $session);
    }

    /**
     * Vérifie si toutes les clés requises sont présentes en session
     */
    private function isSessionValid(SessionInterface $session): bool
    {
        foreach (self::REQUIRED_SESSION_KEYS as $key) {
            if (!$session->has($key)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Récupère les clés manquantes en session
     */
    private function getMissingKeys(SessionInterface $session): array
    {
        return array_filter(
            self::REQUIRED_SESSION_KEYS,
            fn(string $key) => !$session->has($key)
        );
    }

    /**
     * Gère le cas d'une session invalide
     */
    private function handleInvalidSession(RequestEvent $event, SessionInterface $session): void
    {
        $missingKeys = $this->getMissingKeys($session);

        // Log l'événement
        $this->logger->warning('Invalid session detected, redirecting to logout', [
            'user' => $this->security->getUser()?->getUserIdentifier(),
            'missing_keys' => $missingKeys,
        ]);

        // Ajoute le message flash (sera préservé lors du logout)
        $this->addFlashMessage($session);

        // Redirige vers la route de logout
        $response = new RedirectResponse(
            $this->urlGenerator->generate('app_logout')
        );
        
        $event->setResponse($response);
    }

    /**
     * Ajoute un message flash d'erreur
     */
    private function addFlashMessage(SessionInterface $session): void
    {
        $session->getFlashBag()->add(
            'error',
            'Votre session est invalide. Vous avez été déconnecté. Veuillez vous reconnecter.'
        );
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Priorité 9 : après authentification, avant contrôleurs
            KernelEvents::REQUEST => [['onKernelRequest', 9]],
        ];
    }
}
```

## Configuration

```yaml
# config/services.yaml
services:
    App\EventSubscriber\SessionValidationSubscriber:
        arguments:
            $logger: '@monolog.logger.security'
```

## Template pour afficher le message

```twig
{# templates/security/login.html.twig #}
{% for message in app.flashes('error') %}
    <div class="alert alert-danger">
        {{ message }}
    </div>
{% endfor %}
```

Le code est maintenant concentré dans un seul fichier, bien organisé avec des méthodes privées courtes et une responsabilité claire par fonction. [stackoverflow](https://stackoverflow.com/questions/72033675/symfony-6-0-force-logout-user-in-controller)
