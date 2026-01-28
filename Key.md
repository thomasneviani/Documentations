Voici la version optimisée suivant les principes DDD, SOLID et DRY :

## Event Subscriber refactorisé

```php
// src/EventSubscriber/ApiKeyExpirationSubscriber.php

namespace App\EventSubscriber;

use App\Domain\Authentication\Service\ApiKeyRefreshService;
use App\Domain\Authentication\ValueObject\ApiKeyExpiration;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final readonly class ApiKeyExpirationSubscriber implements EventSubscriberInterface
{
    private const EXCLUDED_ROUTES = ['storepicker'];

    public function __construct(
        private ApiKeyRefreshService $apiKeyRefreshService,
        private Security $security,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 10],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$this->shouldProcess($event)) {
            return;
        }

        $session = $event->getRequest()->getSession();
        $expiration = ApiKeyExpiration::fromSession($session);

        if ($expiration->isExpired()) {
            $this->apiKeyRefreshService->refresh(
                $session,
                $this->security->getUser()->getUserIdentifier(),
                $session->get('store_id')
            );
        }
    }

    private function shouldProcess(RequestEvent $event): bool
    {
        if (!$event->isMainRequest()) {
            return false;
        }

        $request = $event->getRequest();

        if (in_array($request->attributes->get('_route'), self::EXCLUDED_ROUTES, true)) {
            return false;
        }

        if (!$this->security->getUser()) {
            return false;
        }

        return $request->hasSession();
    }
}
```

## Value Object pour l'expiration

```php
// src/Domain/Authentication/ValueObject/ApiKeyExpiration.php

namespace App\Domain\Authentication\ValueObject;

use Symfony\Component\HttpFoundation\Session\SessionInterface;

final readonly class ApiKeyExpiration
{
    private function __construct(
        private ?\DateTimeInterface $expirationDate
    ) {}

    public static function fromSession(SessionInterface $session): self
    {
        $nextlogws = $session->get('nextlogws');
        
        if (!$nextlogws) {
            return new self(null);
        }

        try {
            return new self(new \DateTimeImmutable($nextlogws));
        } catch (\Exception) {
            return new self(null);
        }
    }

    public function isExpired(): bool
    {
        if (!$this->expirationDate) {
            return true;
        }

        return new \DateTimeImmutable() >= $this->expirationDate;
    }
}
```

## Service de rafraîchissement

```php
// src/Domain/Authentication/Service/ApiKeyRefreshService.php

namespace App\Domain\Authentication\Service;

use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class ApiKeyRefreshService
{
    private const LOCK_TTL = 30;
    private const LOCK_KEY_PATTERN = 'api_key_refresh_user_%s_store_%s';

    public function __construct(
        private LockFactory $lockFactory,
        private HttpClientInterface $httpClient,
        private string $externalApiUrl,
    ) {}

    public function refresh(SessionInterface $session, string $userId, ?string $storeId): void
    {
        $lock = $this->createLock($userId, $storeId);

        try {
            if (!$lock->acquire(true)) {
                return;
            }

            if (!$this->shouldRefresh($session)) {
                return;
            }

            $this->performRefresh($session, $storeId);
        } finally {
            $lock->release();
        }
    }

    private function createLock(string $userId, ?string $storeId): \Symfony\Component\Lock\LockInterface
    {
        $lockKey = sprintf(self::LOCK_KEY_PATTERN, $userId, $storeId ?? 'default');
        return $this->lockFactory->createLock($lockKey, self::LOCK_TTL);
    }

    private function shouldRefresh(SessionInterface $session): bool
    {
        $expiration = ApiKeyExpiration::fromSession($session);
        return $expiration->isExpired();
    }

    private function performRefresh(SessionInterface $session, ?string $storeId): void
    {
        $response = $this->httpClient->request('POST', $this->externalApiUrl, [
            'json' => [
                'username' => $_ENV['EXTERNAL_API_USERNAME'],
                'password' => $_ENV['EXTERNAL_API_PASSWORD'],
                'store_id' => $storeId,
            ],
            'timeout' => 10,
        ]);

        $data = $response->toArray();

        if (isset($data['keylog'])) {
            $session->set('keylog', $data['keylog']);
        }

        if (isset($data['nextlogws'])) {
            $session->set('nextlogws', $data['nextlogws']);
        }
    }
}
```

## Configuration

```yaml
# config/services.yaml

services:
    App\Domain\Authentication\Service\ApiKeyRefreshService:
        arguments:
            $lockFactory: '@lock.redis_api_refresh.factory'
            $externalApiUrl: '%env(EXTERNAL_API_URL)%'

    App\EventSubscriber\ApiKeyExpirationSubscriber:
        tags:
            - { name: 'kernel.event_subscriber' }
```

## Principes appliqués

**Single Responsibility Principle (SRP)** : Le subscriber gère uniquement l'écoute des événements, le Value Object encapsule la logique d'expiration, et le service orchestre le rafraîchissement.[1][2]

**Open/Closed Principle (OCP)** : Les routes exclues sont dans une constante facilement extensible sans modifier le code.[3]

**Dependency Inversion Principle (DIP)** : Le subscriber dépend d'abstractions (`ApiKeyRefreshService`, `Security`) plutôt que d'implémentations concrètes.[2]

**DRY** : La logique de vérification d'expiration est centralisée dans `ApiKeyExpiration`, utilisée à la fois dans le subscriber et le service pour éviter la duplication.[4]

**Value Object (DDD)** : `ApiKeyExpiration` est un Value Object immuable qui encapsule la logique métier de l'expiration.[2][4]

Sources
[1] Events and Event Listeners (Symfony Docs) https://symfony.com/doc/current/event_dispatcher.html
[2] Symfony: check user authorization inside event listener https://stackoverflow.com/questions/43947102/symfony-check-user-authorization-inside-event-listener
[3] How to get current route in Symfony 2? https://stackoverflow.com/questions/7096546/how-to-get-current-route-in-symfony-2
[4] Using a Redis backed lock to address concurrency issues https://labs.madisoft.it/using-a-redis-backed-lock-to-address-concurrency-issues/
