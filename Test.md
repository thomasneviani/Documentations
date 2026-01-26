Voici un exemple complet de test unitaire avec Symfony et Foundry, utilisant le pattern Story.[1][2]

## Installation

D'abord, installez Foundry et DoctrineFixturesBundle  :[2]

```bash
composer require --dev foundry orm-fixtures
```

## Création des Factories

Générez une factory pour votre entité avec la commande  :[1]

```bash
bin/console make:factory 'App\Entity\Product'
```

Exemple de factory générée  :[1]

```php
// src/Factory/ProductFactory.php
namespace App\Factory;

use App\Entity\Product;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<Product>
 */
final class ProductFactory extends PersistentObjectFactory
{
    protected function defaults(): array
    {
        return [
            'name' => self::faker()->productName(),
            'price' => self::faker()->numberBetween(1000, 50000),
            'description' => self::faker()->text(),
            'createdAt' => \DateTimeImmutable::createFromMutable(self::faker()->dateTime()),
        ];
    }

    protected static function class(): string
    {
        return Product::class;
    }
}
```

## Création d'une Story

Générez une Story pour définir des scénarios réutilisables  :[2][1]

```bash
bin/console make:story 'DefaultProducts'
```

Exemple de Story  :[1]

```php
// src/Story/DefaultProductsStory.php
namespace App\Story;

use App\Factory\ProductFactory;
use Zenstruck\Foundry\Story;

final class DefaultProductsStory extends Story
{
    public function build(): void
    {
        // Créer un produit spécifique pour les tests
        ProductFactory::createOne([
            'name' => 'Product Test Premium',
            'price' => 9999,
            'description' => 'Un produit de test premium',
        ]);

        // Créer 50 produits aléatoires
        ProductFactory::createMany(50);
    }
}
```

## Test Unitaire avec Story

Voici un exemple de test utilisant la Story  :[2]

```php
// tests/Entity/ProductTest.php
namespace App\Tests\Entity;

use App\Entity\Product;
use App\Factory\ProductFactory;
use App\Story\DefaultProductsStory;
use PHPUnit\Framework\TestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class ProductTest extends TestCase
{
    use ResetDatabase, Factories;

    public function testProductCreation(): void
    {
        // Arrange - Créer un produit avec des valeurs spécifiques
        $product = ProductFactory::createOne([
            'name' => 'Test Product',
            'price' => 1500,
        ]);

        // Assert
        $this->assertEquals('Test Product', $product->getName());
        $this->assertEquals(1500, $product->getPrice());
    }

    public function testProductPriceCalculation(): void
    {
        // Arrange
        $product = ProductFactory::createOne(['price' => 10000]);

        // Act - Supposons qu'on a une méthode getPriceWithTax()
        $priceWithTax = $product->getPriceWithTax(0.20);

        // Assert
        $this->assertEquals(12000, $priceWithTax);
    }

    public function testMultipleProductsWithStory(): void
    {
        // Arrange - Charger la Story
        DefaultProductsStory::load();

        // Act - Créer des produits supplémentaires
        ProductFactory::createMany(10);

        // Assert - Vérifier le nombre total (50 + 1 + 10)
        $this->assertCount(61, ProductFactory::repository()->findAll());
    }
}
```

## Test Fonctionnel avec API Platform

Pour les tests fonctionnels, voici un exemple plus complet  :[2]

```php
// tests/Api/ProductsTest.php
namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\Product;
use App\Factory\ProductFactory;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class ProductsTest extends ApiTestCase
{
    use ResetDatabase, Factories;

    public function testGetCollection(): void
    {
        // Arrange
        ProductFactory::createMany(100);

        // Act
        $response = static::createClient()->request('GET', '/api/products');

        // Assert
        $this->assertResponseIsSuccessful();
        $this->assertJsonContains([
            '@context' => '/api/contexts/Product',
            '@type' => 'Collection',
            'totalItems' => 100,
        ]);
    }

    public function testCreateProduct(): void
    {
        // Act
        $response = static::createClient()->request('POST', '/api/products', [
            'json' => [
                'name' => 'Nouveau Produit',
                'price' => 5999,
                'description' => 'Description du produit',
            ]
        ]);

        // Assert
        $this->assertResponseStatusCodeSame(201);
        $this->assertJsonContains([
            '@type' => 'Product',
            'name' => 'Nouveau Produit',
            'price' => 5999,
        ]);
    }
}
```

## Lancer les Tests

Exécutez vos tests avec PHPUnit  :[2]

```bash
php bin/phpunit
# Ou pour un fichier spécifique
php bin/phpunit tests/Entity/ProductTest.php
```

Le trait `ResetDatabase` de Foundry s'occupe automatiquement de purger la base de données et de gérer les transactions pour chaque test, garantissant l'isolation des tests.[2]

Sources
[1] Jour 8 : Les tests unitaires https://symfony.com/legacy/doc/jobeet/1_4/fr/08?orm=Doctrine
[2] Symfony - Comment faire des tests unitaires - Gary Houbre https://blog.gary-houbre.fr/developpement/tests/symfony-comment-faire-des-tests-unitaires
[3] Atelier d'introduction aux tests unitaires (en PHP) https://github.com/eckinox/introduction-tests-unitaires
[4] Test unitaire Symfony https://webtech.fr/glossaire/tests-symfony/
[5] The ultimate guide to testing your code like a pro https://www.youtube.com/watch?v=VdKuEclVXG0
[6] Symfony Foundry Story Generator | Claude Code Skill https://mcpmarket.com/zh/tools/skills/symfony-foundry-story-generator
[7] Testing (Symfony Docs) https://symfony.com/doc/current/testing.html
[8] Testing the API with Symfony https://api-platform.com/docs/symfony/testing/
[9] Supercharging Symfony Testing with Zenstruck Foundry https://sensiolabs.com/blog/2025/supercharging-symfony-testing-with-zenstruck-foundry
[10] Sortie de Foundry 2 : nouveautés et migration https://les-tilleuls.coop/blog/sortie-de-foundry-2-nouveautes-et-migration
