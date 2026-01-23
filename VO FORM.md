**Non, vous ne devez PAS mettre de Value Objects dans le DTO/Command utilisé pour les formulaires**.[1][2][3]

## Pourquoi éviter les VO dans les DTOs de formulaire ?

Les formulaires Symfony travaillent avec des **types scalaires** (string, int, float, bool) car ils reçoivent des données brutes de l'utilisateur. Les Value Objects doivent être créés **après validation** du formulaire, dans le CommandHandler.[2][3]

## ❌ Mauvaise approche : VO dans le DTO

```php
class CreateOrderCommand
{
    // ❌ Problématique : le formulaire ne peut pas instancier directement un VO
    public EmailAddress $customerEmail;  
    public Money $totalAmount;
}
```

**Problèmes** :
- Le formulaire reçoit une string `"john@example.com"` mais le DTO attend un objet `EmailAddress`
- Vous devrez créer un DataMapper complexe pour chaque VO
- La validation échoue avant même que le VO soit créé

## ✅ Bonne approche : Scalaires dans le DTO, VO dans le Handler

### 1. DTO avec types scalaires

```php
namespace App\Application\Command;

use Symfony\Component\Validator\Constraints as Assert;

class CreateOrderCommand
{
    #[Assert\NotBlank]
    #[Assert\Email]
    public string $customerEmail;  // ← String, pas EmailAddress VO
    
    #[Assert\NotBlank]
    #[Assert\Length(min: 2, max: 100)]
    public string $customerName;
    
    #[Assert\NotBlank]
    #[Assert\Positive]
    public float $amount;  // ← Float, pas Money VO
    
    public string $currency = 'EUR';
}
```

### 2. FormType simple (pas de DataMapper nécessaire)

```php
namespace App\Presentation\Form;

use App\Application\Command\CreateOrderCommand;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;

class CreateOrderType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('customerEmail', EmailType::class, [
                'label' => 'Email',
            ])
            ->add('customerName', TextType::class, [
                'label' => 'Nom',
            ])
            ->add('amount', NumberType::class, [
                'label' => 'Montant',
                'scale' => 2,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CreateOrderCommand::class,
        ]);
    }
}
```

### 3. Conversion en VO dans le CommandHandler

```php
namespace App\Application\CommandHandler;

use App\Application\Command\CreateOrderCommand;
use App\Domain\Entity\Order;
use App\Domain\ValueObject\EmailAddress;
use App\Domain\ValueObject\Money;
use App\Domain\Repository\OrderRepositoryInterface;

class CreateOrderCommandHandler
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository
    ) {}
    
    public function __invoke(CreateOrderCommand $command): Order
    {
        // ✅ Conversion des scalaires en Value Objects ICI
        $email = new EmailAddress($command->customerEmail);
        $amount = new Money($command->amount, $command->currency);
        
        // Création de l'entité avec les VOs
        $order = Order::create($email);
        $order->setTotalAmount($amount);
        
        $this->orderRepository->save($order);
        
        return $order;
    }
}
```

## Cas particulier : Formulaires avec VO (approche avancée)

Si vous voulez **vraiment** utiliser des VO dans les formulaires (pour des raisons spécifiques), vous devez créer un **custom FormType avec DataMapper**  :[3][2]

### FormType pour EmailAddress VO

```php
namespace App\Presentation\Form\Type;

use App\Domain\ValueObject\EmailAddress;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\DataMapperInterface;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class EmailAddressType extends AbstractType implements DataMapperInterface
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class)
            ->setDataMapper($this);
    }

    public function mapDataToForms($viewData, \Traversable $forms): void
    {
        $forms = iterator_to_array($forms);
        
        // EmailAddress VO → String pour le formulaire
        $forms['email']->setData(
            $viewData instanceof EmailAddress ? $viewData->value : null
        );
    }

    public function mapFormsToData(\Traversable $forms, &$viewData): void
    {
        $forms = iterator_to_array($forms);
        $email = $forms['email']->getData();
        
        // String → EmailAddress VO
        if ($email !== null) {
            try {
                $viewData = new EmailAddress($email);
            } catch (\InvalidArgumentException $e) {
                // La validation du formulaire gèrera l'erreur
                $viewData = null;
            }
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => EmailAddress::class,
            'empty_data' => null,
        ]);
    }
}
```

### Utilisation dans le Command

```php
class CreateOrderCommand
{
    // Maintenant c'est un VO
    public ?EmailAddress $customerEmail = null;
}

class CreateOrderType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('customerEmail', EmailAddressType::class)  // Custom type
            ->add('customerName', TextType::class);
    }
}
```

**Mais attention** : Cette approche est plus complexe et rarement nécessaire.[2][3]

## Recommandation finale

| Approche | Avantages | Inconvénients |
|----------|-----------|---------------|
| **Scalaires dans DTO** [3] | Simple, standard Symfony | Conversion manuelle dans Handler |
| **VO avec DataMapper** [2] | Type-safety, validation dans VO | Complexe, plus de code |

**Ma recommandation** : **Gardez les scalaires dans le DTO** et convertissez en VO dans le CommandHandler. C'est :[1][3]
- Plus simple à maintenir
- Plus facile à tester
- La pratique standard Symfony
- Respecte la séparation des responsabilités (Présentation → scalaires, Domaine → VO)

## Résumé

```
Formulaire (Présentation)
    ↓ (string, float, int)
CreateOrderCommand (DTO)
    ↓ (scalaires validés)
CreateOrderCommandHandler (Application)
    ↓ (conversion en VO)
Order (Domaine)
    ↓ (EmailAddress, Money VOs)
```

**Conversion DTO → VO = responsabilité du CommandHandler**  ! 🎯[3][1][2]

Sources
[1] Symfony2 DTO, Entity conversion - php https://stackoverflow.com/questions/23953288/symfony2-dto-entity-conversion
[2] Using a Command as DTO to populate Symfony Form #5 https://github.com/webdevilopers/php-ddd/issues/5
[3] Form Model Classes (DTOs) - Symfony 4 https://symfonycasts.com/screencast/symfony-forms/form-dto
