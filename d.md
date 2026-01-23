## Exemple pratique complet : Système de commandes avec DDD et Symfony

Basé sur les concepts de l'article SensioLabs, voici un exemple concret d'implémentation d'un agrégat `Order` avec Symfony et Doctrine. [matthiasnoback](https://matthiasnoback.nl/2018/06/doctrine-orm-and-ddd-aggregates/)

### Value Objects : Prix et Email

Les Value Objects représentent des concepts métier immuables: [sensiolabs](https://sensiolabs.com/fr/blog/2025/appliquer-le-domain-driven-design-en-php-et-symfony-un-guide-pratique)

```php
namespace App\Domain\ValueObject;

use Webmozart\Assert\Assert;

final readonly class EmailAddress
{
    public function __construct(public string $value)
    {
        Assert::email($value, 'Email invalide');
    }
    
    public function equals(EmailAddress $other): bool
    {
        return $this->value === $other->value;
    }
}

final readonly class Money
{
    public function __construct(
        public float $amount,
        public string $currency
    ) {
        Assert::greaterThanEq($amount, 0);
        Assert::length($currency, 3);
    }
    
    public function equals(Money $other): bool
    {
        return $this->amount === $other->amount 
            && $this->currency === $other->currency;
    }
    
    public function add(Money $other): self
    {
        Assert::same($this->currency, $other->currency);
        return new self($this->amount + $other->amount, $this->currency);
    }
}
```

### Entité OrderItem (enfant de l'agrégat)

`OrderItem` est une entité qui dépend de l'Aggregate Root: [github](https://github.com/salletti/symfony-ddd-example/blob/master/README.md)

```php
namespace App\Domain\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class OrderItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;
    
    #[ORM\ManyToOne(targetEntity: Order::class, inversedBy: 'items')]
    private Order $order;
    
    #[ORM\Column(type: 'integer')]
    private int $lineNumber;
    
    #[ORM\Column(type: 'string')]
    private string $productId;
    
    #[ORM\Column(type: 'float')]
    private float $quantity;
    
    #[ORM\Column(type: 'float')]
    private float $unitPrice;
    
    public function __construct(
        Order $order,
        int $lineNumber,
        string $productId,
        int $quantity,
        Money $unitPrice
    ) {
        $this->order = $order;
        $this->lineNumber = $lineNumber;
        $this->productId = $productId;
        $this->quantity = $quantity;
        $this->unitPrice = $unitPrice->amount;
    }
    
    public function updateQuantity(int $newQuantity): void
    {
        Assert::greaterThan($newQuantity, 0);
        $this->quantity = $newQuantity;
    }
    
    public function getTotalPrice(): Money
    {
        return new Money($this->unitPrice * $this->quantity, 'EUR');
    }
}
```

### Aggregate Root : Order

L'entité `Order` est le point d'entrée pour toutes modifications de l'agrégat: [matthiasnoback](https://matthiasnoback.nl/2018/06/doctrine-orm-and-ddd-aggregates/)

```php
namespace App\Domain\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class Order
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;
    
    #[ORM\Column(type: 'string')]
    private string $customerEmail;
    
    #[ORM\Column(type: 'string')]
    private string $status;
    
    #[ORM\OneToMany(
        targetEntity: OrderItem::class,
        mappedBy: 'order',
        cascade: ['PERSIST', 'REMOVE']
    )]
    private Collection $items;
    
    private function __construct(EmailAddress $customerEmail)
    {
        $this->customerEmail = $customerEmail->value;
        $this->status = 'pending';
        $this->items = new ArrayCollection();
    }
    
    public static function create(EmailAddress $customerEmail): self
    {
        return new self($customerEmail);
    }
    
    // Méthode pour ajouter une ligne de commande
    public function addItem(
        string $productId,
        int $quantity,
        Money $unitPrice
    ): void {
        // Règle métier : vérifier que le produit n'existe pas déjà
        foreach ($this->items as $item) {
            if ($item->getProductId() === $productId) {
                throw new \DomainException(
                    'Ce produit est déjà dans la commande'
                );
            }
        }
        
        $lineNumber = count($this->items) + 1;
        $this->items[] = new OrderItem(
            $this,
            $lineNumber,
            $productId,
            $quantity,
            $unitPrice
        );
    }
    
    // Toute modification passe par l'Aggregate Root
    public function updateItemQuantity(int $lineNumber, int $newQuantity): void
    {
        foreach ($this->items as $item) {
            if ($item->getLineNumber() === $lineNumber) {
                $item->updateQuantity($newQuantity);
                return;
            }
        }
        
        throw new \InvalidArgumentException('Ligne de commande introuvable');
    }
    
    // Règle métier : calculer le total
    public function getTotalAmount(): Money
    {
        $total = new Money(0, 'EUR');
        
        foreach ($this->items as $item) {
            $total = $total->add($item->getTotalPrice());
        }
        
        return $total;
    }
    
    // Règle métier : valider la commande
    public function validate(): void
    {
        if (count($this->items) === 0) {
            throw new \DomainException(
                'Impossible de valider une commande vide'
            );
        }
        
        $this->status = 'validated';
    }
    
    public function items(): array
    {
        return $this->items->toArray();
    }
}
```

### Repository : abstraction de la persistance

Le Repository cache les détails de l'accès aux données: [sensiolabs](https://sensiolabs.com/fr/blog/2025/appliquer-le-domain-driven-design-en-php-et-symfony-un-guide-pratique)

```php
namespace App\Domain\Repository;

use App\Domain\Entity\Order;

interface OrderRepositoryInterface
{
    public function save(Order $order): void;
    
    public function findById(int $id): ?Order;
    
    public function findByCustomerEmail(string $email): array;
}
```

### Implémentation Doctrine

```php
namespace App\Infrastructure\Repository;

use App\Domain\Entity\Order;
use App\Domain\Repository\OrderRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class OrderRepository extends ServiceEntityRepository implements OrderRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Order::class);
    }
    
    public function save(Order $order): void
    {
        $this->getEntityManager()->persist($order);
        $this->getEntityManager()->flush();
    }
    
    public function findById(int $id): ?Order
    {
        return $this->find($id);
    }
    
    public function findByCustomerEmail(string $email): array
    {
        return $this->createQueryBuilder('o')
            ->where('o.customerEmail = :email')
            ->setParameter('email', $email)
            ->getResult();
    }
}
```

### Domain Service : PaymentService

Certaines opérations impliquent plusieurs entités et ne appartiennent pas à une seule: [sensiolabs](https://sensiolabs.com/fr/blog/2025/appliquer-le-domain-driven-design-en-php-et-symfony-un-guide-pratique)

```php
namespace App\Domain\Service;

use App\Domain\Entity\Order;
use App\Domain\Repository\OrderRepositoryInterface;

class PaymentService
{
    public function __construct(
        private OrderRepositoryInterface $orderRepository,
        private PaymentGatewayInterface $paymentGateway
    ) {}
    
    public function processPayment(Order $order, string $paymentMethod): void
    {
        // Règle métier : vérifier le statut
        if ($order->getStatus() !== 'validated') {
            throw new \DomainException('La commande doit être validée');
        }
        
        $amount = $order->getTotalAmount();
        
        // Appel au système de paiement externe
        $result = $this->paymentGateway->charge(
            $order->getCustomerEmail(),
            $amount,
            $paymentMethod
        );
        
        if ($result->isSuccessful()) {
            $order->markAsPaid();
            $this->orderRepository->save($order);
        }
    }
}
```

### Utilisation dans un Controller

```php
namespace App\Controller;

use App\Domain\Entity\Order;
use App\Domain\ValueObject\EmailAddress;
use App\Domain\ValueObject\Money;
use App\Domain\Repository\OrderRepositoryInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class OrderController extends AbstractController
{
    #[Route('/orders/create', methods: ['POST'])]
    public function create(OrderRepositoryInterface $repository): Response
    {
        // Utilisation du langage ubiquitaire
        $customerEmail = new EmailAddress('client@example.com');
        $order = Order::create($customerEmail);
        
        // Toutes les modifications passent par l'Aggregate Root
        $order->addItem(
            'PROD-123',
            2,
            new Money(29.99, 'EUR')
        );
        
        $order->addItem(
            'PROD-456',
            1,
            new Money(49.99, 'EUR')
        );
        
        // Validation des règles métier
        $order->validate();
        
        $repository->save($order);
        
        return $this->json(['orderId' => $order->getId()]);
    }
}
```
## Arborescence complète de l'exemple DDD Order

Voici l'arborescence détaillée du projet Symfony avec Domain-Driven Design basée sur l'exemple de commandes: [dev](https://dev.to/victoor/ddd-and-hexagonal-architecture-with-symfony-flex-part-2-4ojc)

```
my-symfony-project/
├── app/
│   └── ...
│
├── bin/
│   └── console
│
├── config/
│   ├── packages/
│   ├── routes.yaml
│   └── services.yaml
│
├── src/
│   │
│   ├── Order/                          # Bounded Context "Order"
│   │   │
│   │   ├── Domain/                     # Couche Domaine (logique métier pure)
│   │   │   ├── Entity/
│   │   │   │   ├── Order.php          # Aggregate Root
│   │   │   │   └── OrderItem.php      # Entity enfant
│   │   │   │
│   │   │   ├── ValueObject/
│   │   │   │   ├── Money.php          # Value Object immuable
│   │   │   │   └── EmailAddress.php   # Value Object avec validation
│   │   │   │
│   │   │   ├── Repository/
│   │   │   │   └── OrderRepositoryInterface.php  # Interface du repository
│   │   │   │
│   │   │   ├── Service/
│   │   │   │   └── PaymentService.php # Domain Service
│   │   │   │
│   │   │   ├── Event/
│   │   │   │   ├── OrderCreatedEvent.php
│   │   │   │   └── OrderValidatedEvent.php
│   │   │   │
│   │   │   └── Exception/
│   │   │       ├── OrderNotFoundException.php
│   │   │       └── InvalidOrderStateException.php
│   │   │
│   │   ├── Application/                # Couche Application (use cases)
│   │   │   ├── Command/
│   │   │   │   ├── CreateOrderCommand.php
│   │   │   │   ├── CreateOrderCommandHandler.php
│   │   │   │   ├── AddOrderItemCommand.php
│   │   │   │   └── ValidateOrderCommand.php
│   │   │   │
│   │   │   ├── Query/
│   │   │   │   ├── FindOrderQuery.php
│   │   │   │   ├── FindOrderQueryHandler.php
│   │   │   │   └── ListCustomerOrdersQuery.php
│   │   │   │
│   │   │   └── DTO/
│   │   │       ├── OrderDTO.php
│   │   │       └── OrderItemDTO.php
│   │   │
│   │   ├── Infrastructure/             # Couche Infrastructure (implémentations techniques)
│   │   │   ├── Persistence/
│   │   │   │   ├── Doctrine/
│   │   │   │   │   ├── Repository/
│   │   │   │   │   │   └── OrderRepository.php  # Implémentation Doctrine
│   │   │   │   │   │
│   │   │   │   │   └── Type/
│   │   │   │   │       ├── MoneyType.php        # Custom Doctrine Type
│   │   │   │   │       └── EmailAddressType.php
│   │   │   │   │
│   │   │   │   └── InMemory/
│   │   │   │       └── InMemoryOrderRepository.php  # Pour les tests
│   │   │   │
│   │   │   ├── Gateway/
│   │   │   │   ├── PaymentGatewayInterface.php
│   │   │   │   └── StripePaymentGateway.php
│   │   │   │
│   │   │   └── Resources/
│   │   │       └── config/
│   │   │           ├── doctrine/
│   │   │           │   ├── Order.orm.yaml
│   │   │           │   └── OrderItem.orm.yaml
│   │   │           │
│   │   │           ├── services.yaml
│   │   │           └── routes.yaml
│   │   │
│   │   ├── Presentation/               # Couche Présentation (UI/API)
│   │   │   ├── Controller/
│   │   │   │   └── OrderController.php
│   │   │   │
│   │   │   ├── Form/
│   │   │   │   ├── CreateOrderType.php
│   │   │   │   └── AddOrderItemType.php
│   │   │   │
│   │   │   └── Validator/
│   │   │       └── OrderValidator.php
│   │   │
│   │   └── Tests/                      # Tests du contexte
│   │       ├── Unit/
│   │       │   ├── Domain/
│   │       │   │   ├── OrderTest.php
│   │       │   │   └── MoneyTest.php
│   │       │   │
│   │       │   └── Application/
│   │       │       └── CreateOrderCommandHandlerTest.php
│   │       │
│   │       └── Integration/
│   │           └── Repository/
│   │               └── OrderRepositoryTest.php
│   │
│   ├── Payment/                        # Autre Bounded Context (exemple)
│   │   ├── Domain/
│   │   ├── Application/
│   │   ├── Infrastructure/
│   │   └── Presentation/
│   │
│   ├── Customer/                       # Autre Bounded Context (exemple)
│   │   ├── Domain/
│   │   ├── Application/
│   │   ├── Infrastructure/
│   │   └── Presentation/
│   │
│   └── Shared/                         # Shared Kernel (code partagé)
│       ├── Domain/
│       │   ├── ValueObject/
│       │   │   ├── Uuid.php
│       │   │   └── DateTimeValueObject.php
│       │   │
│       │   ├── Event/
│       │   │   └── DomainEventInterface.php
│       │   │
│       │   └── Aggregate/
│       │       └── AggregateRoot.php
│       │
│       └── Infrastructure/
│           ├── Bus/
│           │   ├── CommandBus.php
│           │   └── EventBus.php
│           │
│           └── Persistence/
│               └── Doctrine/
│                   └── Type/
│                       └── UuidType.php
│
├── tests/                              # Tests globaux
│   ├── Functional/
│   └── E2E/
│
├── var/
│   ├── cache/
│   └── log/
│
├── vendor/
│
├── composer.json
├── composer.lock
├── .env
└── symfony.lock
```

## Explication des couches [fabian-kleiser](https://www.fabian-kleiser.de/blog/domain-driven-design-with-symfony-a-folder-structure/)

### Domain (Domaine)
Contient la logique métier pure, sans dépendances externes: [fabian-kleiser](https://www.fabian-kleiser.de/blog/domain-driven-design-with-symfony-a-folder-structure/)
- **Entities** : objets avec identité (`Order`, `OrderItem`)
- **Value Objects** : objets immuables (`Money`, `EmailAddress`)
- **Repository Interfaces** : contrats pour la persistance
- **Domain Services** : logique métier complexe
- **Events** : événements métier

### Application (Use Cases)
Orchestre les cas d'usage en utilisant le domaine: [dev](https://dev.to/victoor/ddd-and-hexagonal-architecture-with-symfony-flex-part-2-4ojc)
- **Commands** : actions qui modifient l'état
- **Queries** : récupération de données
- **Handlers** : exécutent les commandes/queries
- **DTOs** : transfert de données vers/depuis la présentation

### Infrastructure (Technique)
Implémentations concrètes des interfaces du domaine: [dev](https://dev.to/victoor/ddd-and-hexagonal-architecture-with-symfony-flex-part-2-4ojc)
- **Persistence** : implémentations Doctrine des repositories
- **Gateway** : intégrations avec services externes
- **Resources/config** : configuration Doctrine, services, routes

### Presentation (Interface)
Expose le domaine via API/UI: [fabian-kleiser](https://www.fabian-kleiser.de/blog/domain-driven-design-with-symfony-a-folder-structure/)
- **Controllers** : points d'entrée HTTP
- **Forms** : formulaires Symfony
- **Validators** : validations de formulaires

### Shared (Shared Kernel)
Code partagé entre tous les Bounded Contexts: [github](https://github.com/CodelyTV/php-ddd-example)
- Value Objects communs
- Interfaces de base
- Bus de commandes/événements

## Avantages de cette structure [dev](https://dev.to/victoor/ddd-and-hexagonal-architecture-with-symfony-flex-part-2-4ojc)

- **Séparation claire** des responsabilités par couche
- **Indépendance** du domaine vis-à-vis du framework
- **Testabilité** : chaque couche peut être testée isolément
- **Scalabilité** : ajout facile de nouveaux Bounded Contexts
- **Maintenabilité** : structure cohérente et prévisible

- ## Oui, webmozart/assert est recommandé pour les Value Objects

**Webmozart/assert** est effectivement une bibliothèque **très recommandée** pour valider les Value Objects en PHP et Symfony DDD. [sensiolabs](https://sensiolabs.com/blog/2025/applying-domain-driven-design-in-php-and-symfony-a-hands-on-guide)

### Pourquoi webmozart/assert est populaire

**SensioLabs l'utilise officiellement** dans leurs exemples DDD pour Symfony: [dev](https://dev.to/ludofleury/domain-driven-design-avec-php-symfony-1p2h)
- Validation simple et expressive
- Exceptions claires en cas d'erreur
- API intuitive et légère
- Pas de dépendances lourdes

### Exemple d'utilisation recommandée

```php
use Webmozart\Assert\Assert;

final readonly class EmailAddress
{
    public function __construct(public string $value)
    {
        Assert::email($value, 'Email invalide');
        Assert::stringNotEmpty($value);
    }
}

final readonly class Region
{
    public function __construct(public string $value)
    {
        Assert::stringNotEmpty($value);
        Assert::notWhitespaceOnly($value);
    }
}

final readonly class Price
{
    public function __construct(public float $amount)
    {
        Assert::greaterThanEq($amount, 0, 'Le prix ne peut pas être négatif');
    }
}
```

### Avantages de webmozart/assert [frederickvanbrabant](https://frederickvanbrabant.com/blog/2019-04-03-the-simple-class/)

- **Légèreté** : pas de framework complexe, juste des assertions
- **Performance** : validation immédiate dans le constructeur
- **Lisibilité** : syntaxe claire et autodocumentée (`Assert::email()`, `Assert::greaterThan()`)
- **Exceptions précises** : messages d'erreur personnalisables
- **Type safety** : compatible avec les types stricts PHP 8+

### Alternatives possibles

| Bibliothèque | Usage recommandé | Contexte |
|--------------|------------------|----------|
| **webmozart/assert** | Value Objects (Domain) | Validation légère, assertions rapides  [sensiolabs](https://sensiolabs.com/blog/2025/applying-domain-driven-design-in-php-and-symfony-a-hands-on-guide) |
| **Symfony Validator** | Entities, DTOs, Forms | Validation complexe, règles métier avancées  [stackoverflow](https://stackoverflow.com/questions/52276968/constraint-on-value-object-in-form-symfony) |
| **Respect/Validation** | Objets complexes | Validation en chaîne, règles personnalisées  [reddit](https://www.reddit.com/r/PHP/comments/31sy47/recommended_object_validation_library/) |
| **Manuel (if/throw)** | Cas simples | Validation basique sans dépendance  [stackoverflow](https://stackoverflow.com/questions/37706647/ddd-is-my-valueobject-implementation-correct) |

### Best practice recommandée [dev](https://dev.to/cnastasi/value-object-in-php-8-entities-1jce)

Pour respecter les principes DDD, utilisez **webmozart/assert dans les Value Objects** et **Symfony Validator pour les couches supérieures**: [stackoverflow](https://stackoverflow.com/questions/52276968/constraint-on-value-object-in-form-symfony)

```php
// Domain Layer - Value Object avec webmozart/assert
namespace App\Domain\ValueObject;

use Webmozart\Assert\Assert;

final readonly class Temperature
{
    public function __construct(public float $celsius)
    {
        Assert::range($celsius, -273.15, 1000);
    }
}

// Application/Presentation Layer - DTO avec Symfony Validator
namespace App\Application\DTO;

use Symfony\Component\Validator\Constraints as Assert;

class WeatherRequestDTO
{
    #[Assert\NotBlank]
    #[Assert\Length(min: 2, max: 100)]
    public string $city;
    
    #[Assert\Choice(['metric', 'imperial', 'standard'])]
    public string $unit = 'metric';
}
```

### Installation

```bash
composer require webmozart/assert
```

### Conclusion

**Oui, webmozart/assert est hautement recommandé** pour les Value Objects en DDD car il offre un excellent équilibre entre simplicité, performance et expressivité. C'est la solution utilisée par SensioLabs dans leurs guides officiels sur le DDD avec Symfony. [sensiolabs](https://sensiolabs.com/blog/2025/applying-domain-driven-design-in-php-and-symfony-a-hands-on-guide)
