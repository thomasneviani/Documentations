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

        // Ignore les routes exclues
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

        // Ajoute le flash MAINTENANT avant la redirection vers logout
        $session->getFlashBag()->add(
            'error',
            'Votre session est invalide. Vous avez été déconnecté. Veuillez vous reconnecter.'
        );

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
