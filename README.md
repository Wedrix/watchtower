A wrapper around [graphql-php](https://github.com/webonyx/graphql-php) for serving GraphQL from [Doctrine](https://github.com/doctrine/orm)-based frameworks like Symfony.


# Table of Content

- [Features](#features)
- [Motivation](#motivation)
- [Requirements](#requirements)
- [Installation](#installation)
- [Usage](#usage)
- [Schema](#schema)
- [Plugins](#plugins)
- [Computed Fields](#computed-fields)
- [Filtering](#filtering)
- [Ordering](#ordering)
- [Mutations](#mutations)
- [Subscriptions](#subscriptions)
- [Authorization](#authorization)
- [Security](#security)
- [Known Issues](#known-issues)
- [Versioning](#versioning)
- [Contributing](#contributing)
- [Running the Tests](#running-the-tests)
- [Reporting Vulnerabilities](#reporting-vulnerabilities)
- [License](#license)


# Features

 - SDL first. 
 - Out-of-the-box pagination support.
 - Support for computed fields, filtering, ordering, mutations, subscriptions, authorization, and custom resolvers via user-generated plugins. 
 - Support for all type system features including enums, abstract types (i.e. Unions and Interfaces), custom scalars, and custom directives.
 - Schema generation and updation for queries, based on the project's current Doctrine models.
 - Code generation for plugins and scalar type definitions.


# Motivation

Supporting a GraphQL API usually involves writing a lot of redundant boilerplate code. By abstracting away this boilerplate, you save precious development and maintenance time, allowing you to focus on the more unique aspects of your API.

This library is inspired by similar others created for different platforms:

 - [Lighthouse](https://github.com/nuwave/lighthouse) for Laravel and Eloquent
 - [Mongoose GraphQL Server](https://github.com/DanishSiraj/mongoose-graphql-server) for Express and Mongoose


# Requirements

 - php >= v8.1
 - doctrine/orm >= v2.8
 - graphql-php >= 14.4


# Installation

## Symfony 

This package is currently only compatible with Symfony version 6.1 and higher.  
There is an associated Flex recipe for this package, that generates the initial bootstrap files, allowing you to instantly access a GraphQL API for your service.  
Kindly take note of the following changes made to your project folder before considering the install:

 1. Adds the controller **Watchtower/Controller.php** to the **Controller** directory
 2. Registers the route **/graphql** to point to Watchtower/Controller.php
 3. Adds the following commands to the **Command** directory:
	 - Watchtower/AddPlugin.php 
	 - Watchtower/AddScalarTypeDefinition.php
	 - Watchtower/GenerateSchema.php
	 - Watchtower/ListPlugins.php
	 - Watchtower/ListScalarTypeDefinitions.php
	 - Watchtower/UpdateSchema.php
 4. Adds the following services to the **src** directory:
	 - WatchtowerExecutor.php
	 - WatchtowerConsole.php
 5. Adds the following directories:
	 - config/graphql/plugins/authorizors
	 - config/graphql/plugins/filters
	 - config/graphql/plugins/mutations
	 - config/graphql/plugins/orderings
	 - config/graphql/plugins/resolvers
	 - config/graphql/plugins/selectors
	 - config/graphql/scalar_type_definitions
 6. Generates the schema file in config/graphql/schema.graphql


To install the package for with the Flex recipe enabled, 

For Windows:  

	composer req 'symfony/flex'
	composer config extra.symfony.allow-contrib true
	SET SYMFONY_ENDPOINT=https://raw.githubusercontent.com/symfony/recipes-contrib/flex/pull-1456/index.json
	composer require 'wedrix/watchtower'
	SET SYMFONY_ENDPOINT=

For Unix-like systems (BSD, Linux, and MacOS):  

	composer req 'symfony/flex'
	composer config extra.symfony.allow-contrib true
	export SYMFONY_ENDPOINT=https://raw.githubusercontent.com/symfony/recipes-contrib/flex/pull-1456/index.json
	composer require 'wedrix/watchtower'
	unset SYMFONY_ENDPOINT


This package does not register a bundle like most others do. Instead, it tries to load a few bootstrap files directly into the project folder. This simplifies the library's usage, allowing great flexibility to add custom validation and security rules, [support multiple schemas](#using-multiple-schemas), and to enforce your preferred project structure and choice of configuration. 

We strongly recommend using the flex recipe. However, if for whatever reason you choose to not use it, you may still find the source files instructional. You can access them [here](https://github.com/symfony/recipes-contrib/pull/1456).

After installing the package, your GraphQL API will be available at `/graphql.json` as defined in `App\\Controller\\Watchtower\\Controller.php`.  
Access the CLI commands via `php bin/console watchtower:~`.

## Other Frameworks 

    composer require wedrix/watchtower

## Demo Application  

You can test out this package using the demo application:

1. Install [Symfony CLI](https://symfony.com/doc/current/setup.html)
2. Navigate to an appropriate directory on your filesystem
3. Clone the repository:  

		git clone https://github.com/Wedrix/watchtower-demo.git
4. Navigate to the repository directory:  

		cd watchtower_demo
5. Install the packages:  

		composer install
4. Start the symfony server:  

		symfony server:start
5. Install an appropriate GraphQL client. 
	For example, [ChromeiQL](https://chrome.google.com/webstore/detail/chromeiql/fkkiamalmpiidkljmicmjfbieiclmeij).
6. The API is accessible at http://localhost:8000/en/graphql.json


# Usage

This library has two main components:

 1. The Executor component `Wedrix\Watchtower\Executor`, responsible for auto-resolving queries.
 2. The Console component `Wedrix\Watchtower\Console`, responsible for code generation, schema management, and plugin management.

The Executor component should be used in some controller class or callback  function to power your service's GraphQL endpoint. The example usage below is for a Slim 4 application:

```php
#index.php

<?php
use App\Doctrine\EntityManager;
use GraphQL\Error\DebugFlag;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use Wedrix\Watchtower\Executor;

require __DIR__ . '/vendor/autoload.php';

$app = AppFactory::create();

$app->addRoutingMiddleware();

$errorMiddleware = $app->addErrorMiddleware(true, true, true);

$app->post(
	'/graphql.json', 
	function (Request $request, Response $response, $args) {
		/**
		* Instantiating the executor.
		* Pass the entity manager and other config options using DI or 
		* configuration objects. 
		**/
		$executor = new Executor(
			entityManager: new EntityManager(), // Either as a Singleton or from some DI container
			schemaFileDirectory: __DIR__ . '/resources/graphql/schema.graphql',
			schemaCacheFileDirectory: __DIR__ . '/var/cache/schema.graphql',
			cachesTheSchema: true, // Should be false in dev environment
			pluginsDirectory: __DIR__ . '/resources/graphql/plugins',
			scalarTypeDefinitionsDirectory: __DIR__. '/resources/graphql/scalar_type_definitions'
		);
			
		$response->getBody()
			->write(
				is_string(
					$responseBody = json_encode(
						/**
						* Call executeQuery() on the request to 
						* generate the GraphQL response.
						**/
						$executor->executeQuery(
							source: ($input = (array) $request->getParsedBody())['query'] ?? '',
							rootValue: [],
							contextValue: [
								'request' => $request, 
								'response' => $response, 
								'args' => $args
							],
							variableValues: $input['variables'] ?? null,
							operationName: $input['operationName'] ?? null,
							validationRules: null
						)
						->toArray(
							debug: DebugFlag::INCLUDE_DEBUG_MESSAGE | DebugFlag:INCLUDE_TRACE
						)
					)
				) 
				? $responseBody
				: throw new \Exception("Unable to encode GraphQL result")
			);

		return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
});

$app->run();
```

The Console component on the other hand, should be used in a cli-tool to offer convenience services like code generation during development. Check out the [Symfony Flex recipe](https://github.com/symfony/recipes-contrib/tree/main/wedrix/watchtower/1.0/Command) for an example usage in Symfony.


# Schema

This library relies on a schema file written in the Schema Definition Language (SDL) to describe the service's type system. For a quick primer on the SDL, check out [this](https://wehavefaces.net/graphql-shorthand-notation-cheatsheet-17cd715861b6) article. 

The library supports the complete GraphQL type system through the SDL and is able to auto-resolve Doctrine entities and relations, even collections, out-of-the-box. However, some extra steps are needed for certain features to be fully functional.


## Custom Scalars

In order to support user-defined scalar types (custom scalars), the GraphQL engine must be instructed on how to parse, validate, and serialize values of the said type. These instructions are provided to the engine via Scalar Type Definitions.

### Scalar Type Definitions

Scalar Type Definitions are auto-loaded files containing the respective function definitions: `serialize()`, `parseValue()`, and `parseLiteral()` under a conventional namespace, that instruct the GraphQL engine on how to handle custom scalar values. Since they are auto-loaded, Scalar type Definitions must conform to the following rules:

 1. A Scalar Type Definition must be contained within its own script file.
 2. The script file must follow the following naming format:  
	 {***the scalar type name in snake_case***}_type_definition.php
 3. The script file must be contained within the directory specified for the `scalarTypeDefinitionsDirectory` parameter of both the Executor and Console components.
 4. The respective functions `serialize()`, `parseValue()`, and `parseLiteral()` must have the following function signatures:

```php
/**
 * Serializes an internal value to include in a response.
 */
function serialize(
	mixed $value
): string // You can replace 'mixed' with a more specific type 
{
}

/**
 * Parses an externally provided value (query variable) to use as an input
 */
function parseValue(
	string $value
): mixed // You can replace 'mixed' with a more specific type
{
}

/**
 * Parses an externally provided literal value (hardcoded in GraphQL query) to use as an input.
 * 
 * @param array<string,mixed>|null $variables
 */
function parseLiteral(
	\GraphQL\Language\AST\Node $value, 
	?array $variables = null
): mixed // You can replace 'mixed with a more specific type
{
}
```

5. The respective functions `serialize()`, `parseValue()`, and `parseLiteral()` must be namespaced following this format:
	 `Wedrix\Watchtower\ScalarTypeDefinitions\{the scalar type name in PascalCase}TypeDefinition`

The below code snippet is an example Scalar Type Definition for a custom DateTime scalar type:

```php
#resources/graphql/scalar_type_definitions/date_time_type_definition.php

<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\ScalarTypeDefinitions\DateTimeTypeDefinition;

use GraphQL\Error\Error;
use GraphQL\Language\AST\Node;
use GraphQL\Language\AST\StringValueNode;
use GraphQL\Utils\Utils;

function serialize(
	\DateTime $value
): string
{
    return $value->format(\DateTime::ATOM);
}

function parseValue(
	string $value
): \DateTime
{
    try {
        return new \DateTime($value);
    }
    catch (\Exception $e) {
        throw new Error(
            message: "Cannot represent the following value as DateTime: " . Utils::printSafeJson($value),
            previous: $e
        );
    }
}

/**
 * @param array<string,mixed>|null $variables
 */
function parseLiteral(
	Node $value, 
	?array $variables = null
): \DateTime
{
    if (!$value instanceof StringValueNode) {
        throw new Error(
            message: "Query error: Can only parse strings got: $value->kind",
            nodes: $value
        );
    }

    try {
        return parseValue($value->value);
    }
    catch (\Exception $e) {
        throw new Error(
            message: "Not a valid DateTime Type", 
            nodes: $value,
            previous: $e
        );
    }
}
```

To facilitate speedy development, the Console component offers the convenience method `addScalarTypeDefinition()`, which may be used to auto-generate the necessary boilerplate.


## Querying

### Finding Entities

To find a particular entity by id, you must add the `id` argument to the corresponding field definition in the schema file. For example, for the given schema:

```graphql
type Query {
	product(id: ID!): Product!
}

type Product {
	id: ID!,
	name: String!,
	listings: [Listing!]!
}
```

the query:

```graphql
query {
	product(id: 1) {
		name
	}
}
```

returns the result for the product with id **1**. 

The type of the specified `id` argument must be non-nullable and correspond to the entity's id field. 

### Relations

This library is also able to resolve the relations of your models. For instance, given the following schema definition:

```graphql
type Query {
	product(id: ID!): Product!
}

type Product {
	id: ID!,
	name: String!,
	bestSeller: Listing,
	listings: [Listing!]!
}

type Listing {
	id: ID!,
	sellingPrice: Float!
}
``` 

the query:

```graphql
query {
	product(id: 1) {
		name,
		bestSeller,
		listings
	}
}
```

resolves the product with id **1**, its best seller, and all the corresponding listings as described by the `bestSeller` and `listings` associations of the Product entity. For more details on Doctrine relations check out [the documentation](https://www.doctrine-project.org/projects/doctrine-orm/en/2.13/reference/association-mapping.html).

### Pagination

By default, the complete result-set for a collection relation is returned. To enable pagination for a particular relation, all you have to do is add the `queryParams` input parameter to the corresponding field definition in the schema file. For example:

```graphql
type Query {
	product(id: ID!): Product!
}

type Product {
	id: ID!,
	name: String!,
	bestSeller: Listing,
	listings(queryParams: ListingsQueryParams!): [Listing!]!
}

type Listing {
	id: ID!,
	sellingPrice: Float!
}

input ListingsQueryParams {
	limit: Int, # items per page
	page: Int, # page
}
```

The type specified for the `queryParams` argument does not matter. The only requirement is that it must define the two fields `limit` and `page` as integer types. You may also choose to make them non-nullable to force pagination for the particular query field.

`queryParams` may also be used to paginate the results of a query. For instance, given the following schema:

```graphql
type Query {
	products: [Product!]!,
	paginatedProducts(queryParams: ProductsQueryParams!): [Product!]!
}

type Product {
	id: ID!,
	name: String!,
	bestSeller: Listing
}

input ProductsQueryParams {
	limit: Int!, # items per page
	page: Int!, # page
}
```

the query:

```graphql
query {
	products {
		name
	}
}
```

returns the names of all products, whereas:

```graphql
query {
	paginatedProducts(queryParams: {page: 1, limit: 5}) {
		name
	}
}
```

paginates the results, returning only the first five elements.

You can use any name for your Query fields. This also applies to Mutations and Subscriptions. Fields of other types on the other hand must, either correspond to actual Entity/Embeddable attributes, or have associated plugins that resolve their values.

This package also supports aliases. For instance:

```graphql
query {
	queryAlias: paginatedProducts(queryParams: {page: 1, limit: 3}) {
		nameAlias: name
	}
}
```

returns:

```json
{
  "data": {
    "queryAlias": [
      {
        "nameAlias": "IPhone 6S"
      },
      {
        "nameAlias": "Samsung Galaxy Pro"
      },
      {
        "nameAlias": "MacBook Pro"
      }
    ]
  }
}
```

To facilitate speedy development, the Console component offers convenience methods to [generate](#generating-the-schema) and [update](#updating-the-schema) the schema file based on the project's Doctrine models.

### Distinct Queries

To return distinct results, add the `distinct` parameter to the `queryParams` argument. For example:

```graphql
type Query {
	paginatedProducts(queryParams: ProductsQueryParams!): [Product!]!
}

input ProductsQueryParams {
	distinct: Boolean, # Must be boolean
	limit: Int!,
	page: Int!,
}

type Product {
	id: ID!,
	name: String!,
	bestSeller: Listing
}
```


## Generating the Schema

The console component comes with the helper method `generateSchema()` which may be used to generate the initial schema file based on the project's Doctrine models. 

Kindly take note of the following when using the schema generator:

 1. The generator only creates a schema for Query operation. It does not create any Mutations or Subscriptions. Those must be added manually.
 2. The generator is able to only resolve the following Doctrine types:
	 - All [interger types](https://www.doctrine-project.org/projects/doctrine-dbal/en/current/reference/types.html#integer-types)  - resolve to GraphQL's `Int` type.
	 - All [decimal types](https://www.doctrine-project.org/projects/doctrine-dbal/en/current/reference/types.html#decimal-types) - resolve to GraphQL's `Float` type.
	 - All [string types](https://www.doctrine-project.org/projects/doctrine-dbal/en/current/reference/types.html#string-types) - resolve to GraphQL's `String` type.
	 - All [date and time types](https://www.doctrine-project.org/projects/doctrine-dbal/en/current/reference/types.html#date-and-time-types) - resolve to a custom `Date` type that extends the `String` type.
 3. The generator skips all fields having scalar types different from the above types. You must add those manually, with their corresponding Scalar Type Definitions.
 4. The generator only resolves actual fields that correspond to database columns. All other fields must be added manually, as either computed or resolved fields. 
 5. The generator is not able to properly ascertain the nullability of embedded types and relations, so those must be manually set. Currently, all embedded field types will be nullable by default, and all relations, non-nullable.


## Updating the Schema

The console component comes with the helper method `updateSchema()` which may be used to update queries in the schema file to match the project's Doctrine models. Updates are merged with the original schema and do not overwrite schema definitions for scalars, mutations, subscriptions, directives etc.


## Using Multiple Schemas

Using multiple schemas is as simple as instantiating different objects of the Executor and Console components, with the different schema files' configurations. You can then use them with the appropriate controllers, routes, cli-scripts etc.


# Plugins

Plugins are special auto-loaded functions you define that allow you to add custom logic to the resolver. Since they are auto-loaded, plugins must follow certain conventions for correct package discovery and use:

 1. A plugin must be contained within its own script file. 
 2. The script file name must correspond with the plugin's name.  
 Example: `function apply_listings_ids_filter(...){...}` should correspond with `apply_listings_ids_filter.php`.
 3. The script file must be contained in the directory specified for the `pluginsDirectory` parameter of both the Executor and Console components, under the folder specified for the particular plugin type (see [subsequent sections](#computed-fields) for more details).
 4. The plugin function name must follow the specified naming convention for the particular plugin type (see [subsequent sections](#computed-fields) for more details).
 5. The plugin function signature must follow the specified signature for the particular plugin type (see [subsequent sections](#computed-fields) for more details).
 6. The plugin function must be namespaced based on the specified convention for the particular plugin type (see [subsequent sections](#computed-fields) for more details).

Plugins enable features like filtering, ordering, computed fields, mutations, subscriptions, and authorization. Below is an example filter plugin for filtering listings by the given ids:

```php
# resources/graphql/plugins/filters/apply_listings_ids_filter.php

<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\Plugins\Filters;

use Wedrix\Watchtower\Resolver\Node;
use Wedrix\Watchtower\Resolver\QueryBuilder;

function apply_listings_ids_filter(
    QueryBuilder $queryBuilder,
    Node $node
): void
{
    $entityAlias = $queryBuilder->rootAlias();

    $ids = $node->args()['queryParams']['filters']['ids'];

    $idsValueAlias = $queryBuilder->reconciledAlias('idsValue');

    $queryBuilder->andWhere("{$entityAlias}.id IN (:$idsValueAlias)")
                ->setParameter($idsValueAlias, $ids);
}
```

The Console component offers the following convenience methods for generating plugin files: `addFilterPlugin()`, `addOrderingPlugin()`, `addSelectorPlugin()`, `addResolverPlugin()`, `addAuthorizorPlugin()`, `addMutationPlugin()`, and `addSubscriptionPlugin()`. 


# Computed Fields

Sometimes your API may include fields that do not correspond to actual columns in the database. For instance, you may have a *Product* entity, that persists the *markedPrice* and *discount* fields but computes the *sellingPrice* field on the fly using both of those persisted fields. To resolve such fields, you may either use Selector or Resolver plugins.


## Selector Plugins

Selector plugins allow you to chain select statements onto the query builder. They are useful for fields that are entirely computable by the database. The code snippet below is an example Selector plugin for the computed sellingPrice field:

```php
#resources/graphql/plugins/selectors/apply_product_selling_price_selector.php

<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\Plugins\Selectors;

use Wedrix\Watchtower\Resolver\Node;
use Wedrix\Watchtower\Resolver\QueryBuilder;

function apply_product_selling_price_selector(
    QueryBuilder $queryBuilder,
    Node $node
): void
{
    $entityAlias = $queryBuilder->rootAlias();
    
    $queryBuilder->addSelect("
        $entityAlias.markedPrice - $entityAlias.discount AS sellingPrice
    ");
}
```

### Rules

The rules for Selector plugins are as follows:

 1. The plugin's script file must be contained in the directory specified for the `pluginsDirectory` parameter of both the Executor and Console components, under the `selectors` sub-folder.
 2. The script file's name must follow the following naming format:  
	 apply_{***name of parent type in snake_case***}_{***name of field in snake_case***}_selector.php
 3. Within the script file, the plugin function's name must follow the following naming format:  
	 apply_{***name of parent type in snake_case***}_{***name of field in snake_case***}_selector
 4. The plugin function must have the following signature: 
```php
function function_name(
	\Wedrix\Watchtower\Resolver\QueryBuilder $queryBuilder,
	\Wedrix\Watchtower\Resolver\Node $node
): void;
```
5. The plugin function must be namespaced under `Wedrix\Watchtower\Plugins\Selectors`.


### Helpful Utilities

The first function parameter `$queryBuilder` represents the query builder on which you can chain your own queries to resolve the computed field. It extends the interface for `\Doctrine\ORM\QueryBuilder` with these added functions to help you build the query:
 
 1. Use `$queryBuilder->rootAlias()` to get the query's root entity alias.
 2. Use `$queryBuilder->reconciledAlias(string $alias)` to get an alias that's compatible with the rest of the query aliases. Use it to prevent name collisions.

The second function parameter `$node` represents the particular query node being resolved in the query graph. Use it to determine the appropriate query to chain onto the builder.


## Resolver Plugins

Resolver plugins allow you to resolve fields using other services from the database. Unlike Selector plugins, they allow you to return a result, instead of forcing you to chain a query onto the builder. The code snippet below is an example Resolver plugin for the computed exchangeRate field of a Currency type:

```php
#resources/graphql/plugins/resolvers/resolve_currency_exchange_rate_field.php

<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\Plugins\Resolvers;

use Wedrix\Watchtower\Resolver\Node;
use Wedrix\Watchtower\Resolver\QueryBuilder;

function resolve_currency_exchange_rate_field(
    Node \$node
): mixed
{
	$exchangeRateResolver = $node->context()['exchangeRateResolver']; // Assuming the service was added to $contextValue when Executor::executeQuery() was called

	return $exchangeRateResolver->getExchangeRate(
		currencyCode: $node->root()['isoCode']
	);
}
```

### Rules

The rules for Resolver plugins are as follows:

 1. The plugin's script file must be contained in the directory specified for the `pluginsDirectory` parameter of both the Executor and Console components, under the `resolvers` sub-folder.
 2. The script file's name must follow the following naming format:  
	 resolve_{***name of parent type in snake_case***}_{***name of field in snake_case***}_field.php
 3. Within the script file, the plugin function's name must follow the following naming format:  
	 resolve_{***name of parent type in snake_case***}_{***name of field in snake_case***}_field
 4. The plugin function must have the following signature: 
```php
function function_name(
	\Wedrix\Watchtower\Resolver\Node $node
): mixed;
```
5. The plugin function must be namespaced under `Wedrix\Watchtower\Plugins\Resolvers`.


### Valid Return Types

Kindly note that values returned from a resolver function must be resolvable by the library. This library is able to auto-resolve the following primitive php types: `null`, `int`, `bool`, `float,` `string`, and `array`. Any other return type must have an associated scalar type definition to be resolvable by this library. Values representing user-defined object types must be returned as associative arrays. For collections, return a 0-indexed list.


## Resolving Abstract Types

Use the utility functions `$node->type()`, `$node->isAbstractType()`, `$node->concreteFieldSelection()`, and `$node->abstractFieldSelection()` to determine what type you are resolving: whether it's an abstract type, and the concrete and abstract fields selected, respectively.

When resolving an abstract type, always add a `__typename` field to the result indicating the concrete type being resolved. For example:

```php
function resolve_user(
    Node \$node
): mixed
{
	return [
		'__typename' => 'Customer', // This indicates the concrete type, i.e., Customer
		'name' => 'Sylvester',
		'age' => '20 yrs',
		'total_spent' => '40000' // This probably could only be applicable to the Customer type
	];
}
```

Abstract types may be used with other operation types like Mutations and Subscriptions.


# Filtering

This library allows you to filter queries by chaining where conditions onto the builder. You can filter queries by entity attributes or relations - whatever is permissible by the builder. Filter Plugins are used to implement filters.


## Filter Plugins

Filter plugins allow you to chain where conditions onto the query builder. The code snippet below is an example Filter plugin for filtering listings by the given ids:

```php
<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\Plugins\Filters;

use Wedrix\Watchtower\Resolver\Node;
use Wedrix\Watchtower\Resolver\QueryBuilder;

function apply_listings_ids_filter(
    QueryBuilder $queryBuilder,
    Node $node
): void
{
    $entityAlias = $queryBuilder->rootAlias();

    $ids = $node->args()['queryParams']['filters']['ids'];

    $idsValueAlias = $queryBuilder->reconciledAlias('idsValue');

    $queryBuilder->andWhere("{$entityAlias}.id IN (:$idsValueAlias)")
                ->setParameter($idsValueAlias, $ids);
}
```

### Rules

The rules for Filter plugins are as follows:

 1. The plugin's script file must be contained in the directory specified for the `pluginsDirectory` parameter of both the Executor and Console components, under the `filters` sub-folder.
 2. The script file's name must follow the following naming format:  
	 apply_{***pluralized name of parent type in snake_case***}_{***name of the filter in snake_case***}_filter.php
 3. Within the script file, the plugin function's name must follow the following naming format:  
	 apply_{***pluralized name of parent type in snake_case***}_{***name of the filter in snake_case***}_filter
 4. The plugin function must have the following signature: 
```php
function function_name(
	\Wedrix\Watchtower\Resolver\QueryBuilder $queryBuilder,
	\Wedrix\Watchtower\Resolver\Node $node
): void;
```
5. The plugin function must be namespaced under `Wedrix\Watchtower\Plugins\Filters`.


To use filters add them to the `filters` parameter of the `queryParams` argument. For instance:

```graphql
type Query {
	paginatedProducts(queryParams: ProductsQueryParams!): [Product!]!
}

input ProductsQueryParams {
	filters: ProductsQueryFiltersParam, # Can be any user-defined input type
	limit: Int!,
	page: Int!,
}

input ProductsQueryFiltersParam {
	ids: [String!],
	isStocked: Boolean, # Another filter
}

type Product {
	id: ID!,
	name: String!,
	bestSeller: Listing
}
```

You can then use them in queries like so:

```graphql
query {
	products: paginatedProducts(queryParams:{filters:{ids:[1,2,3]}}) {
		name
		bestSeller
	}
}
```

Kindly refer to the **Helpful Utilities** sections under **Selector Plugins** for helpful methods, using the builder.


# Ordering

This library allows you to order queries by chaining **order by** statements onto the builder. It also supports multiple ordering, where one ordering is applied after another to reorder matching elements. To implement orderings, use Ordering Plugins.


## Ordering Plugins

Ordering plugins allow you to chain **order by** statements onto the query builder. The code snippet below is an example Ordering plugin for ordering listings by the newest:

```php
<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\Plugins\Orderings;

use Wedrix\Watchtower\Resolver\Node;
use Wedrix\Watchtower\Resolver\QueryBuilder;

function apply_listings_newest_ordering(
    QueryBuilder $queryBuilder,
    Node $node
): void
{
    $entityAlias = $queryBuilder->rootAlias();

    $dateCreatedAlias = $queryBuilder->reconciledAlias('dateCreated');

    $queryBuilder->addSelect("$entityAlias.dateCreated AS HIDDEN $dateCreatedAlias")
            ->addOrderBy($dateCreatedAlias, 'DESC');
}
```

### Rules

The rules for Ordering plugins are as follows:

 1. The plugin's script file must be contained in the directory specified for the `pluginsDirectory` parameter of both the Executor and Console components, under the `orderings` sub-folder.
 2. The script file's name must follow the following naming format:  
	 apply_{***pluralized name of parent type in snake_case***}_{***name of the ordering in snake_case***}_ordering.php
 3. Within the script file, the plugin function's name must follow the following naming format:  
	 apply_{***pluralized name of parent type in snake_case***}_{***name of the ordering in snake_case***}_ordering
 4. The plugin function must have the following signature: 
```php
function function_name(
	\Wedrix\Watchtower\Resolver\QueryBuilder $queryBuilder,
	\Wedrix\Watchtower\Resolver\Node $node
): void;
```
5. The plugin function must be namespaced under `Wedrix\Watchtower\Plugins\Orderings`.


To use orderings add them to the `ordering` parameter of the `queryParams` argument. For instance:

```graphql
type Query {
	paginatedProducts(queryParams: ProductsQueryParams!): [Product!]!
}

input ProductsQueryParams {
	ordering: ProductsQueryOrderingParam, # Can be any user-defined input type
	limit: Int!,
	page: Int!,
}

input ProductsQueryOrderingParam {
	closest: ProductsQueryOrderingClosestParam, # Can also be any user-defined input type
	oldest: ProductsQueryOrderingOldestParam, # Another ordering
}

input ProductsQueryOrderingClosestParam {
	rank: Int!, # This parmeter is required for all orderings
	params: ProductsQueryOrderingClosestParamsParam! # This optional for parameterized orderings
}

input ProductsQueryOrderingClosestParamsParam {
	location: Coordinates!
}

input ProductsQueryOrderingOldestParam {
	rank: Int!
}

type Product {
	id: ID!,
	name: String!,
	bestSeller: Listing
}
```

You can then use them in queries like so:

```graphql
query {
	products: paginatedProducts(
		queryParams:{
			ordering:{
				oldest:{
					rank:1
				},
				closest:{
					rank:2,
					params:{
						locaton:"40.74684111541018,-73.98518096794233"
					}
				}
			}
		}
	) {
		name
		bestSeller
	}
}
```

Kindly take note of the `rank` parameter that is required for all orderings. It's used to determine the order in which to apply multiple orderings. The highest ranking ordering is applied first, followed by the next in that order.

You can also pass params to the ordering using the `params` parameter.

Kindly refer to the **Helpful Utilities** sections under **Selector Plugins** for helpful methods, using the builder.


# Mutations

**Mutation** is a different operation type used to reliably change state in your application. Unlike queries, mutations are guaranteed to run in sequence, preventing any potential race conditions. However, just like queries, they can also return a data graph. This library supports mutations through Mutation Plugins.


## Mutation Plugins

Mutation plugins allow you to create mutations to reliably change state in your application. The code snippet below is an example mutation used to log in a user:

```php
<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\Plugins\Mutations;

use App\Server\Sessions\Session;
use Wedrix\Watchtower\Resolver\Node;

function call_log_in_user_mutation(
    Node $node
): mixed
{
    $request = $node->context()['request'] ?? throw new \Exception("Invalid context value! Unset request.");
    $response = $node->context()['response'] ?? throw new \Exception("Invalid context value! Unset response.");

    $session = new Session(
        request: $request,
        response: $response
    );

    $session->login(
        email: $node->args()['email'],
        password: $node->args()['password']
    );

    return $session->toArray();
}
```

### Rules

The rules for Mutation plugins are as follows:

 1. The plugin's script file must be contained in the directory specified for the `pluginsDirectory` parameter of both the Executor and Console components, under the `mutations` sub-folder.
 2. The script file's name must follow the following naming format:  
	 call_{***name of mutation in snake_case***}_mutation.php
 3. Within the script file, the plugin function's name must follow the following naming format:  
	 call_{***name of mutation in snake_case***}_mutation
 4. The plugin function must have the following signature: 
```php
function function_name(
	\Wedrix\Watchtower\Resolver\Node $node
): mixed;
```
5. The plugin function must be namespaced under `Wedrix\Watchtower\Plugins\Mutations`.


### Valid Return Types

Like with Resolver plugin functions, values returned from a mutation function must be resolvable by the library. This library is able to auto-resolve the following primitive php types: `null`, `int`, `bool`, `float,` `string`, and `array`. Any other return type must have an associated scalar type definition to be resolvable by this library. Values representing user-defined object types must be returned as associative arrays. For collections, return a 0-indexed list. 


# Subscriptions

**Subscription** is another GraphQL operation type that is used to subscribe to a stream of events from the server. Unlike queries and mutations, subscriptions send many results over an extended period of time. Thus, they require different plumbing from the normal HTTP request flow. This makes their implementation heavily reliant on architectural choices that are beyond the scope of this library. Nevertheless, the library supports subscriptions through Subscription Plugins that act as connectors to the underlying application's implementation for transport, message brokering, etc.


## Subscription Plugins

Subscription plugins act as connectors to your application's implementation of subscriptions.  The rules for creating Subscription Plugins are as follows:


### Rules

 1. The plugin's script file must be contained in the directory specified for the `pluginsDirectory` parameter of both the Executor and Console components, under the `subscriptions` sub-folder.
 2. The script file's name must follow the following naming format:  
	 call_{***name of subscription in snake_case***}_subscription.php
 3. Within the script file, the plugin function's name must follow the following naming format:  
	 call_{***name of subscription in snake_case***}_subscription
 4. The plugin function must have the following signature: 
```php
function function_name(
	\Wedrix\Watchtower\Resolver\Node $node
): mixed;
```
5. The plugin function must be namespaced under `Wedrix\Watchtower\Plugins\Subscriptions`.


Kindly refer to the [GraphQL spec](https://spec.graphql.org/October2021/#sec-Subscription) for the requirements of a Subscription implementation.


# Authorization

Authorization allows you to you to approve results. This library handles authorization based on user-defined rules for individual node/collection types. These rules apply to all operation type results, including, Queries, Mutations, and Subscriptions. You write an authorization once and can be guaranteed that it will apply to all results. This library supports authorization through Authorization Plugins.


## Authorization Plugins

Authorization plugins allow you to create authorizations for individual node/collection types. The code snippet below is an example authorization applied to User results:

```php
<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\Plugins\Authorizors;

use App\Server\Sessions\Session;
use Wedrix\Watchtower\Resolver\Node;

use function array\any_in_array;

function authorize_customer_node(
    Node $node
): void
{
    $user = new Session(
		request: $node->context()['request'] ?? throw new \Exception("Invalid context value! Unset request."),
		response: $node->context()['response'] ?? throw new \Exception("Invalid context value! Unset response.")
	)
	->user();

    if (
        any_in_array(
            needles: \array_keys($node->concreteFieldsSelection()),
            haystack: $user->hiddenFields()
        )
    ) {
        throw new \Exception("Unauthorized! Hidden field requested.");
    }
}
```

### Rules

The rules for Authorization plugins are as follows:

 1. The plugin's script file must be contained in the directory specified for the `pluginsDirectory` parameter of both the Executor and Console components, under the `authorizors` sub-folder.
 2. The script file's name must follow the following naming format:  
	 authorize_{***name of node (pluralized if for collections) in snake_case***}_node.php
 3. Within the script file, the plugin function's name must follow the following naming format:  
	 authorize_{***name of node (pluralized if for collections) in snake_case***}_node
 4. The plugin function must have the following signature: 
```php
function function_name(
	\Wedrix\Watchtower\Resolver\Node $node
): void;
```
5. The plugin function must be namespaced under `Wedrix\Watchtower\Plugins\Authorizors`.
6. The authorization plugin function must throw an exception when the authorization fails.


# Security

Kindly follow the [graphql-php manual](https://webonyx.github.io/graphql-php/security/) for directions on securing your GraphQL API. Most of the library's security APIs are compatible with this library since they are mostly static, allowing for external configuration.


# Known Issues

This section details some of the known issues relating to this library's usage and their possible workarounds.

## N + 1 Problem

This library is susceptible to the [N + 1 problem](https://techdozo.dev/spring-for-graphql-how-to-solve-the-n1-problem/). However, for most use-cases, this shouldn't pose too much of a problem with current database solutions. You may however start to face performance issues when using Resolver Plugins to make external API calls. For such use-cases, we recommend using an async-capable HTTP client paired with a query batching solution like [Dataloader](https://github.com/overblog/dataloader-php) to mitigate network latency bottlenecks.


## Case Sensitivity & Naming

GraphQL names are case-sensitive as detailed by the [spec](https://spec.graphql.org/October2021/#sec-Names). However, since [PHP names are case-insensitive](https://www.php.net/manual/en/language.namespaces.rationale.php), we cannot follow this spec requirement. Kindly note that using case-sensitive names with this library may lead to erratic undefined behaviour.


## Aliasing Parameterized Fields

There is currently an [open issue](https://github.com/webonyx/graphql-php/issues/1072) in graphql-php that prevents this library from properly resolving a parameterized field passed different arguments. This should probably be fixed in the next major release of graphql-php. Until then, kindly take note of this issue when using aliases.  


# Versioning

This project follows [Semantic Versioning 2.0.0](https://semver.org/spec/v2.0.0.html). 

The intended public API elements are marked with the `@api` PHPDoc tag, and are guaranteed to be stable within minor version changes. All other elements are
not part of this backwards compatibility promise and may change between minor or patch versions.

Check [here](https://github.com/Wedrix/watchtower/releases) for all published releases.


# Contributing

For new features or contributions that propose significant breaking changes, kindly [start a discussion](https://github.com/Wedrix/watchtower/discussions/new) under the **ideas** category for contributors' feedback.

For smaller contributions involving bug fixes and patches:

-   Fork the project.
-   Make your changes.
-   Create a Pull Request.


# Reporting Vulnerabilities

In case you discover a security vulnerability, kindly send an e-mail to the maintainer via [wedamja@gmail.com](mailto:wedamja@gmail.com). Security vulnerabilities will be promptly addressed.


# License

This is free and open-source software distributed under the [MIT LICENSE](LICENSE).
