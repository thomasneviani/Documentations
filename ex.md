Voici des exemples complets pour la couche Présentation dans une architecture DDD avec Symfony :

## OrderController.php

Le contrôleur est mince et délègue à la couche Application  : [github](https://github.com/salletti/symfony-ddd-example/blob/master/README.md)

```php
<?php

namespace App\Presentation\Controller;

use App\Application\Command\CreateOrderCommand;
use App\Application\Command\AddOrderItemCommand;
use App\Application\Query\FindOrderQuery;
use App\Presentation\Form\CreateOrderType;
use App\Presentation\Form\AddOrderItemType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/order', name: 'order_')]
class OrderController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $commandBus,
        private readonly MessageBusInterface $queryBus
    ) {}

    #[Route('/create', name: 'create', methods: ['GET', 'POST'])]
    public function create(Request $request): Response
    {
        // Utilisation d'un DTO pour le formulaire
        $command = new CreateOrderCommand();
        $form = $this->createForm(CreateOrderType::class, $command);
        
        $form->handleRequest($request);
        
        if ($form->isSubmitted() && $form->isValid()) {
            // Dispatch vers le CommandHandler
            $envelope = $this->commandBus->dispatch($command);
            $order = $envelope->last(HandledStamp::class)->getResult();
            
            $this->addFlash('success', 'Commande créée avec succès');
            
            return $this->redirectToRoute('order_show', [
                'id' => $order->getId()
            ]);
        }
        
        return $this->render('order/create.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'show', methods: ['GET'])]
    public function show(int $id): Response
    {
        // Utilisation d'une Query pour la lecture
        $query = new FindOrderQuery($id);
        $envelope = $this->queryBus->dispatch($query);
        $order = $envelope->last(HandledStamp::class)->getResult();
        
        if (!$order) {
            throw $this->createNotFoundException('Commande introuvable');
        }
        
        return $this->render('order/show.html.twig', [
            'order' => $order,
        ]);
    }

    #[Route('/{id}/add-item', name: 'add_item', methods: ['POST'])]
    public function addItem(int $id, Request $request): Response
    {
        $command = new AddOrderItemCommand();
        $command->orderId = $id;
        
        $form = $this->createForm(AddOrderItemType::class, $command);
        $form->handleRequest($request);
        
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->commandBus->dispatch($command);
                $this->addFlash('success', 'Article ajouté à la commande');
            } catch (\DomainException $e) {
                $this->addFlash('error', $e->getMessage());
            }
            
            return $this->redirectToRoute('order_show', ['id' => $id]);
        }
        
        return $this->render('order/add_item.html.twig', [
            'form' => $form,
            'orderId' => $id,
        ]);
    }
}
```

## CreateOrderType.php

Le FormType utilise un DTO (Command) comme modèle de données  : [stackoverflow](https://stackoverflow.com/questions/23953288/symfony2-dto-entity-conversion)

```php
<?php

namespace App\Presentation\Form;

use App\Application\Command\CreateOrderCommand;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class CreateOrderType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('customerEmail', EmailType::class, [
                'label' => 'Email du client',
                'constraints' => [
                    new Assert\NotBlank(message: 'L\'email est requis'),
                    new Assert\Email(message: 'Email invalide'),
                ],
            ])
            ->add('customerName', TextType::class, [
                'label' => 'Nom du client',
                'constraints' => [
                    new Assert\NotBlank(message: 'Le nom est requis'),
                    new Assert\Length(
                        min: 2,
                        max: 100,
                        minMessage: 'Le nom doit contenir au moins {{ limit }} caractères'
                    ),
                ],
            ])
            ->add('shippingAddress', TextType::class, [
                'label' => 'Adresse de livraison',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CreateOrderCommand::class,
            'csrf_protection' => true,
        ]);
    }
}
```

## AddOrderItemType.php

FormType avec DataMapper pour gérer des Value Objects  : [github](https://github.com/webdevilopers/php-ddd/issues/5)

```php
<?php

namespace App\Presentation\Form;

use App\Application\Command\AddOrderItemCommand;
use App\Domain\ValueObject\Money;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\DataMapperInterface;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class AddOrderItemType extends AbstractType implements DataMapperInterface
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('productId', TextType::class, [
                'label' => 'ID Produit',
                'constraints' => [
                    new Assert\NotBlank(),
                ],
            ])
            ->add('quantity', IntegerType::class, [
                'label' => 'Quantité',
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Positive(),
                ],
            ])
            ->add('unitPrice', NumberType::class, [
                'label' => 'Prix unitaire (EUR)',
                'scale' => 2,
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\PositiveOrZero(),
                ],
            ])
            ->setDataMapper($this);
    }

    public function mapDataToForms(mixed $viewData, \Traversable $forms): void
    {
        if (null === $viewData) {
            return;
        }

        /** @var FormInterface[] $forms */
        $forms = iterator_to_array($forms);
        
        // Map du Command vers les champs du formulaire
        $forms['productId']->setData($viewData->productId);
        $forms['quantity']->setData($viewData->quantity);
        
        // Extraction du montant depuis le VO Money
        if ($viewData->unitPrice instanceof Money) {
            $forms['unitPrice']->setData($viewData->unitPrice->amount);
        }
    }

    public function mapFormsToData(\Traversable $forms, mixed &$viewData): void
    {
        /** @var FormInterface[] $forms */
        $forms = iterator_to_array($forms);
        
        if (!$viewData instanceof AddOrderItemCommand) {
            $viewData = new AddOrderItemCommand();
        }
        
        // Map des champs vers le Command
        $viewData->productId = $forms['productId']->getData();
        $viewData->quantity = $forms['quantity']->getData();
        
        // Création du VO Money depuis les données du formulaire
        $amount = $forms['unitPrice']->getData();
        if ($amount !== null) {
            $viewData->unitPrice = new Money((float) $amount, 'EUR');
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => AddOrderItemCommand::class,
            'empty_data' => null,
        ]);
    }
}
```

## OrderValidator.php

Validateur personnalisé pour les règles métier spécifiques  : [dev](https://dev.to/mykola_vantukh/ddd-in-symfony-7-clean-architecture-and-deptrac-enforced-boundaries-120a)

```php
<?php

namespace App\Presentation\Validator;

use App\Application\Command\CreateOrderCommand;
use App\Domain\Repository\OrderRepositoryInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

#[\Attribute]
class UniqueActiveOrder extends Constraint
{
    public string $message = 'Le client {{ email }} a déjà une commande active en cours.';
    
    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}

class UniqueActiveOrderValidator extends ConstraintValidator
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository
    ) {}

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof UniqueActiveOrder) {
            throw new UnexpectedTypeException($constraint, UniqueActiveOrder::class);
        }

        if (!$value instanceof CreateOrderCommand) {
            return;
        }

        // Vérification métier : un client ne peut avoir qu'une seule commande active
        $existingOrder = $this->orderRepository->findActiveByEmail(
            $value->customerEmail
        );

        if ($existingOrder !== null) {
            $this->context
                ->buildViolation($constraint->message)
                ->setParameter('{{ email }}', $value->customerEmail)
                ->addViolation();
        }
    }
}
```

## Points clés de cette architecture

- **Contrôleur mince** : Ne contient que de l'orchestration HTTP, pas de logique métier [github](https://github.com/salletti/symfony-ddd-example/blob/master/README.md)
- **DTOs/Commands** : Séparation claire entre les données du formulaire et les entités du domaine [symfonycasts](https://symfonycasts.com/screencast/symfony-forms/form-dto)
- **DataMapper** : Gestion propre de la conversion entre formulaires et Value Objects [github](https://github.com/webdevilopers/php-ddd/issues/5)
- **Message Bus** : Communication asynchrone avec la couche Application via CQRS [dev](https://dev.to/mykola_vantukh/ddd-in-symfony-7-clean-architecture-and-deptrac-enforced-boundaries-120a)
- **Validation en couches** : Validation de formulaire (syntaxe) + validation métier (dans le CommandHandler) [dev](https://dev.to/mykola_vantukh/ddd-in-symfony-7-clean-architecture-and-deptrac-enforced-boundaries-120a)

Cette structure garantit que la couche Présentation reste découplée du domaine et peut évoluer indépendamment. [williamdurand](https://williamdurand.fr/2013/08/07/ddd-with-symfony2-folder-structure-and-code-first/)

**Non, ce n'est pas la même chose** : le **Command Bus** et le **Command Handler** sont deux composants différents mais complémentaires du pattern Command. [arnaudlanglade](https://www.arnaudlanglade.com/fr/command-bus-design-pattern/)

## Différences entre Command Bus et Command Handler

### Command Handler
Le **Command Handler** est la classe responsable de l'exécution de la logique métier pour une commande spécifique  : [arnaudlanglade](https://www.arnaudlanglade.com/fr/command-handler-patterns/)

```php
namespace App\Application\CommandHandler;

use App\Application\Command\CreateOrderCommand;
use App\Domain\Entity\Order;
use App\Domain\Repository\OrderRepositoryInterface;
use App\Domain\ValueObject\EmailAddress;

class CreateOrderCommandHandler
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository
    ) {}
    
    // Ce handler traite UNE seule commande
    public function __invoke(CreateOrderCommand $command): Order
    {
        // Logique métier pour créer la commande
        $email = new EmailAddress($command->customerEmail);
        $order = Order::create($email);
        
        $this->orderRepository->save($order);
        
        return $order;
    }
}
```

**Règle importante** : Une commande est traitée par **un seul handler** car il n'y a qu'une seule façon de traiter un cas d'utilisation spécifique. [arnaudlanglade](https://www.arnaudlanglade.com/fr/command-bus-design-pattern/)

### Command Bus
Le **Command Bus** est un routeur/médiateur qui identifie et exécute le bon handler pour une commande donnée  : [dev](https://dev.to/er1cak/monoliths-that-scale-architecting-with-command-and-event-buses-2mp)

```php
// Dans le contrôleur, vous utilisez le Command Bus
$this->commandBus->dispatch($command);

// Le Command Bus fait automatiquement :
// 1. Identifie le bon handler (CreateOrderCommandHandler)
// 2. Exécute les middlewares (validation, transaction, etc.)
// 3. Appelle le handler
// 4. Retourne le résultat
```

## Comment ça fonctionne ensemble

```
┌─────────────────┐
│  Controller     │
│                 │
│  dispatch(cmd)  │
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│  Command Bus    │  ← Routeur/médiateur
│                 │
│  • Validation   │  ← Middleware 1
│  • Transaction  │  ← Middleware 2
│  • Find Handler │  ← Middleware 3
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│ Command Handler │  ← Exécute la logique
│                 │
│ __invoke(cmd)   │
└─────────────────┘
```

## Avantages du Command Bus

Le Command Bus apporte plusieurs avantages par rapport à l'appel direct du handler  : [jdecool](https://www.jdecool.fr/blog/2022/02/25/le-pattern-commande.html)

### 1. Découplage
Vous n'avez pas besoin de connaître le handler dans le contrôleur  : [jdecool](https://www.jdecool.fr/blog/2022/02/25/le-pattern-commande.html)

```php
// ❌ Sans Command Bus : couplage fort
$handler = new CreateOrderCommandHandler($this->orderRepository);
$handler->__invoke($command);

// ✅ Avec Command Bus : découplage
$this->commandBus->dispatch($command);
```

### 2. Middlewares
Le bus permet d'ajouter des comportements transversaux via des middlewares  : [arnaudlanglade](https://www.arnaudlanglade.com/fr/command-handler-patterns/)

```php
// Symfony Messenger configuration
framework:
    messenger:
        buses:
            command.bus:
                middleware:
                    - validation      # Valide la commande
                    - doctrine_transaction  # Gère les transactions SQL
                    - handler_locator      # Trouve et exécute le handler
```

**Middleware de validation**  : [arnaudlanglade](https://www.arnaudlanglade.com/fr/command-handler-patterns/)
```php
class ValidationMiddleware implements MiddlewareInterface
{
    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $command = $envelope->getMessage();
        $violations = $this->validator->validate($command);
        
        if (count($violations)) {
            throw new ValidationFailedException($command, $violations);
        }
        
        return $stack->next()->handle($envelope, $stack);
    }
}
```

### 3. Traitement asynchrone
Le bus facilite l'envoi de commandes dans une file d'attente  : [arnaudlanglade](https://www.arnaudlanglade.com/fr/command-handler-patterns/)

```php
// Traitement synchrone
$this->commandBus->dispatch($command);

// Traitement asynchrone via RabbitMQ
$this->commandBus->dispatch(new SendEmailCommand($email));
```

### 4. Gestion automatique du mapping
Le bus associe automatiquement les commandes aux handlers via autowiring  : [arnaudlanglade](https://www.arnaudlanglade.com/fr/command-bus-design-pattern/)

```yaml
# config/services.yaml
App\Application\CommandHandler\:
    resource: '../src/Application/CommandHandler'
    tags: [messenger.message_handler]
```

## Relations entre les composants

| Composant | Rôle | Nombre par Command |
|-----------|------|-------------------|
| **Command** | DTO portant les données et l'intention  [arnaudlanglade](https://www.arnaudlanglade.com/fr/command-handler-patterns/) | 1 |
| **Command Handler** | Exécute la logique métier  [arnaudlanglade](https://www.arnaudlanglade.com/fr/command-bus-design-pattern/) | 1 (règle stricte) |
| **Command Bus** | Route et orchestre l'exécution  [arnaudlanglade](https://www.arnaudlanglade.com/fr/command-bus-design-pattern/) | 1 (instance partagée) |

## En résumé

- **Command** : DTO qui exprime l'intention ("créer une commande") [jdecool](https://www.jdecool.fr/blog/2022/02/25/le-pattern-commande.html)
- **Command Handler** : Service qui exécute la logique métier pour cette intention [stackoverflow](https://stackoverflow.com/questions/24474859/what-is-the-difference-between-command-commandhandler-and-service)
- **Command Bus** : Infrastructure qui route la commande vers son handler et applique des comportements transversaux (validation, transactions, etc.) [arnaudlanglade](https://www.arnaudlanglade.com/fr/command-bus-design-pattern/)

Le `$this->commandBus->dispatch($command)` **utilise** le pattern Command Handler en trouvant et appelant automatiquement le bon handler, tout en ajoutant des fonctionnalités comme la validation et les transactions. [arnaudlanglade](https://www.arnaudlanglade.com/fr/command-bus-design-pattern/)
