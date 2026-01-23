// src/EventSubscriber/SessionValidationSubscriber.php
namespace App\EventSubscriber;

use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
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

        $request = $event->getRequest();
        $route = $request->attributes->get('_route');
        
        // Gère le flash message sur la page de login
        if ($route === 'app_login') {
            $this->handleLoginPageFlash($request);
            return;
        }

        // Ignore les autres routes exclues
        if (in_array($route, self::EXCLUDED_ROUTES, true)) {
            return;
        }

        // Récupère l'utilisateur
        $user = $this->security->getUser();
        
        // Ignore si l'utilisateur n'est pas connecté
        if (!$user) {
            return;
        }

        $session = $request->getSession();

        // Vérifie si la session est valide
        if ($this->isSessionValid($session)) {
            return;
        }

        // Gère la session invalide
        $this->handleInvalidSession($event, $session, $user);
    }

    /**
     * Ajoute le flash message sur la page de login si marqueur présent
     */
    private function handleLoginPageFlash(Request $request): void
    {
        $session = $request->getSession();
        
        // Vérifie si un marqueur de session invalide est présent
        if ($session->has('_logout_reason') && $session->get('_logout_reason') === 'invalid_session') {
            $session->getFlashBag()->add(
                'error',
                'Votre session est invalide. Vous avez été déconnecté. Veuillez vous reconnecter.'
            );
            
            // Supprime le marqueur après usage
            $session->remove('_logout_reason');
            
            $this->logger->info('Flash message added on login page', [
                'reason' => 'invalid_session',
            ]);
        }
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
    private function handleInvalidSession(RequestEvent $event, SessionInterface $session, mixed $user): void
    {
        $missingKeys = $this->getMissingKeys($session);

        // Log l'événement
        $this->logger->warning('Invalid session detected, redirecting to logout', [
            'user' => $user->getUserIdentifier(),
            'missing_keys' => $missingKeys,
        ]);

        // Marque la raison du logout dans la session
        // Ce marqueur survivra à l'invalidation et sera lu sur la page login
        $session->set('_logout_reason', 'invalid_session');

        // Redirige vers logout
        $response = new RedirectResponse(
            $this->urlGenerator->generate('app_logout')
        );
        
        $event->setResponse($response);
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Priorité 7 : APRÈS FirewallListener (priorité 8) mais AVANT les contrôleurs
            KernelEvents::REQUEST => [['onKernelRequest', 7]],
        ];
    }
}
