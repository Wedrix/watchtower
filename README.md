A wrapper around [graphql-php](https://github.com/webonyx/graphql-php) for serving GraphQL from [Doctrine](https://github.com/doctrine/orm)-based frameworks like Symfony.


# Table of contents

- [Features](#features)
- [Motivation](#motivation)
- [Requirements](#requirements)
- [Installation](#installation)
  - [Note for Symfony Users](#note-for-symfony-users)
- [Usage](#usage)
- [Schema](#schema)
  - [Custom Scalars](#custom-scalars)
    - [Scalar Type Definitions](#scalar-type-definitions)
  - [Querying](#querying)
    - [Finding Entities](#finding-entities)
    - [Relations](#relations)
    - [Pagination](#pagination)
- [Plugins](#plugins)
- [Computed Fields](#computed-fields)
  - [Selector Plugins](#selector-plugins)
    - [Rules](#rules)
    - [Helpful Utilities](#helpful-utilities)
  - [Resolver Plugins](#resolver-plugins)
    - [Rules](#rules)
  - [Resolving Abstract Types](#resolving-abstract-types)
- [Filtering](#filtering)
- [Ordering](#ordering)
- [Mutations](#mutations)
- [Subscriptions](#subscriptions)
- [Authorization](#authorization)
- [Using Multiple Schemas](#using-multiple-schemas)
- [Versioning](#versioning)
- [Contributing](#contributing)
- [Running the Tests](#running-the-tests)
- [Security](#security)
- [License](#license)

# Features

 - SDL first. 
 - Out-of-the-box pagination support.
 - Support for computed fields, filtering, ordering, mutations, subscriptions, authorization, and custom resolvers via user-generated plugins. 
 - Support for all type system features including enums, abstract types (i.e. Unions and Interfaces), custom scalars, and custom directives.
 - Schema generation and updating for queries, based on the project's Doctrine models.
 - Code generation for plugins and scalar type definitions.


# Motivation

Supporting a GraphQL API usually involves writing a lot of redundant boilerplate code. Abstracting most of it could save you precious development and maintenance time, allowing you to focus on the more unique aspects of your API.

This library is inspired by similar others for different platforms:

 - [Lighthouse](https://github.com/nuwave/lighthouse) for Laravel and Eloquent
 - [Mongoose GraphQL Server](https://github.com/DanishSiraj/mongoose-graphql-server) for Express and Mongoose


# Requirements

 - php >= v8.1
 - doctrine/orm >= v2.8
 - graphql-php >= 14.4


# Installation

Via composer:

    composer require wedrix/watchtower


## Note for Symfony Users

There is an associated Flex recipe for this package, that generates initial bootstrap files that allow you to instantly access a GraphQL API for your service. Kindly take note of the following changes made to your project before considering the install:

 1. List item

This package does not register a bundle like most others do. Instead, it tries to register the bootstrap files directly into the project. Considering the integral nature of GraphQL for most projects, we believe this to be the most appropriate approach, as it allows you the most flexibility. This simplifies adding custom validation or security rules, [supporting multiple schemas](#using-multiple-schemas), and conforming with your project's structure. 

If you choose to not run the recipe during installation, the source may still be instructional for your custom integration. It can be accessed here.


# Usage

This library has two main components:

 1. The Executor component (`Wedrix\Watchtower\Executor`), responsible for auto-resolving queries.
 2. The Console component (`Wedrix\Watchtower\Console`), responsible for code generation, schema management, and plugin management.

The Executor component should be used in some form of controller class or callback  function to power your service's GraphQL endpoint. The example below is for a simple Slim 4 application:

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

$app->post('/graphql.json', function (Request $request, Response $response, $args) {
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
								source: ($input = (array) $request->getParsedBody())['query'] ?? null,
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

The Console component on the other hand, should be used in a cli-tool, offering convenience services like code generation during development. For example:

```php

```


# Schema

This library relies on a schema file written in the Schema Definition Language (SDL) to describe the service's type system. For a quick primer on the SDL, check out [this](https://wehavefaces.net/graphql-shorthand-notation-cheatsheet-17cd715861b6) article. 

The library supports the complete GraphQL type system through the SDL and is able to auto-resolve Doctrine entities and relations, even collections, out-of-the-box. However, some extra steps are needed for certain features to be fully functional.


## Custom Scalars

In order to support user-defined scalar types (custom scalars), the GraphQL engine must be instructed on how to parse, validate, and serialize values of the said type. We provide these instructions to the engine using Scalar Type Definitions.

### Scalar Type Definitions

Scalar Type Definitions are auto-loaded files containing the respective functions: `serialize()`, `parseValue()`, and `parseLiteral()` under a conventional namespace, that instruct the GraphQL engine on how to handle custom scalar values. Since they are auto-loaded, Scalar type Definitions must conform to the following rules:

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

To facilitate speedy development, the Console component offers the convenience method `addScalarTypeDefinition()`, which may be used to auto-generate the necessary boilerplate for a Scalar Type Definition.


## Querying

### Finding Entities

To find a particular entity by id, you must add the `id` input parameter to the corresponding field definition in the schema file. For example, for the given schema:

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

returns the result for the product with id '1'. 

The type of the specified `id` parameter does not matter. The only requirement is that it must be non-nullable and correspond to the entity's id field. 

### Relations

This library is able to resolve the relations of your models. For instance, given the following schema definition:

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

resolves the product with id '1', its best seller, and all its corresponding listings as described by the `bestSeller` and `listings` association of the Product entity. For more details on Doctrine relations check out [the documentation](https://www.doctrine-project.org/projects/doctrine-orm/en/2.13/reference/association-mapping.html).

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
	limit: Int!, # items per page
	page: Int!, # page
}
```

The type of the specified `queryParams` parameter does not matter. The only requirement is that it must define the two fields `limit` and `page` as non-nullable integer types.

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

Note that query names are irrelevant. In other words, you can use any name for your Query fields. This also applies to the Mutation and Subscription types. Fields on other types must however, either correspond to actual Entity/Embeddable attributes, or have associated plugins to resolve their values.

This package also supports aliases:

```graphql
query {
	queryAlias: paginatedProducts(queryParams: {page: 1, limit: 5}) {
		nameAlias: name
	}
}
```

To facilitate speedy development, the Console component offers the convenience methods `generateSchema()` and `updateSchema()`, which may be used to auto-generate and update queries in the schema file, respectively. The generated schema will be based on the current definitions of the project's Doctrine models.


# Plugins

Plugins are special auto-loaded functions you define that allow you to add custom logic to the resolver. Since they are auto-loaded, plugins must follow certain conventions for correct package discovery and use:

 1. A plugin must be contained within its own script file. 
 2. The script file name must correspond with the plugin's name. Example: `function apply_listings_ids_filter(...){...}` should correspond with `apply_listings_ids_filter.php`.
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

/**
 * @param array<string,mixed> $context
 */
function apply_listings_ids_filter(
    QueryBuilder $queryBuilder,
    Node $node,
    array $context
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

/**
 * @param array<string,mixed> $context
 */
function apply_product_selling_price_selector(
    QueryBuilder $queryBuilder,
    Node $node,
    array $context
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
	 apply_{***name of type in snake_case***}_{***name of field in snake_case***}_selector.php
 3. Within the script file, the plugin function's name must follow the following naming format: 
	 apply_{***name of type in snake_case***}_{***name of field in snake_case***}_selector
 4. The plugin function must have the following signature: 
```php
/**
 * @param array<string,mixed> $context
 */
function name_of_plugin_function(
	\Wedrix\Watchtower\Resolver\QueryBuilder $queryBuilder,
	\Wedrix\Watchtower\Resolver\Node $node,
	array $context
): void;
```
5. The plugin function must be namespaced under `Wedrix\Watchtower\Plugins\Selectors`.


### Helpful Utilities

The first function parameter `$queryBuilder` represents the query builder on which you can chain your own queries to resolve the computed field. It extends the interface for `\Doctrine\ORM\QueryBuilder` with these added functions to help you build the query:
 
 1. Use `$queryBuilder->rootAlias()` to get the query's root entity alias.
 2. Use `$queryBuilder->reconciledAlias(string $alias)` to get an alias that's compatible with the rest of the query aliases. Use it to prevent name collisions.

The second function parameter `$node` represents the particular query node being resolved in the query graph. Use it to determine the appropriate query to chain onto the builder.

The third function parameter `$context` represents the context value passed to the GraphQL executor. It contains whatever values you set.


## Resolver Plugins

Resolver plugins allow you to resolve fields using other services from the database. Unlike Selector plugins, they allow you to return a result, instead of forcing you to chain a query onto the builder. The code snippet below is an example Resolver plugin for the computed exchangeRate field of a Currency type:

```php
#resources/graphql/plugins/resolvers/resolve_currency_exchange_rate_field.php

<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\Plugins\Resolvers;

use Wedrix\Watchtower\Resolver\Node;
use Wedrix\Watchtower\Resolver\QueryBuilder;

/**
 * @param array<string,mixed> $context
 */
function resolve_currency_exchange_rate_field(
    Node \$node,
    array \$context
): string|int|float|bool|null|array
{
	$exchangeRateResolver = $context['exchangeRateResolver']; // Assuming we added the service to the $context

	return $exchangeRateResolver->getExchangeRate(
		currencyCode: $node->root()['isoCode']
	);
}
```

### Rules

The rules for Resolver plugins are as follows:

 1. The plugin's script file must be contained in the directory specified for the `pluginsDirectory` parameter of both the Executor and Console components, under the `resolvers` sub-folder.
 2. The script file's name must follow the following naming format:
	 resolve_{***name of type in snake_case***}_{***name of field in snake_case***}_field.php
 3. Within the script file, the plugin function's name must follow the following naming format: 
	 resolve_{***name of type in snake_case***}_{***name of field in snake_case***}_field
 4. The plugin function must have the following signature: 
```php
/**
 * @param array<string,mixed> $context
 */
function name_of_plugin_function(
	\Wedrix\Watchtower\Resolver\Node $node,
	array $context
): string|int|float|bool|null|array;
```
5. The plugin function must be namespaced under `Wedrix\Watchtower\Plugins\Resolvers`.


## Resolving Abstract Types

Use the utility functions `$node->type()`, `$node->isAbstractType()`, `$node->concreteFieldSelection()`, and `$node->abstractFieldSelection()` to determine what type you are resolving, whether it's an abstract type, the concrete fields selected, and the abstract fields selected, respectively.

When resolving an abstract type, always add a `__typename` field to the result indicating the concrete type being resolved. For example:

```php
/**
 * @param array<string,mixed> $context
 */
function resolve_user_field(
    Node \$node,
    array \$context
): string|int|float|bool|null|array
{
	return [
		'__typename' => 'Customer', // This indicates the concrete type, i.e., Customer
		'name' => 'Sylvester',
		'age' => '20 yrs',
		'total_spent' => '40000' // This probably could only be applicable to the Customer type
	];
}
```

You can use abstract types in other operations like mutations and subscriptions.


# Filtering


# Ordering


# Mutations


# Subscriptions


# Authorization


# Using Multiple Schemas

Using multiple schemas is as simple as instantiating different objects of the Executor and Console components, with the different schema files' configurations. You can then use them with the appropriate controllers, routes, cli-scripts etc.


# Versioning

This project follows [Semantic Versioning 2.0.0](https://semver.org/spec/v2.0.0.html). 

The intended public API elements are marked with the `@api` PHPDoc tag, and are guaranteed to be stable within minor version changes. All other elements are
not part of this backwards compatibility promise and may change between minor or patch versions.

Check [releases](/releases) for all published releases.


# Contributing

For new features or contributions that propose significant breaking changes, kindly [start a discussion](/discussions/new) under the **ideas** category for contributors' feedback.

For smaller contributions involving bug fixes and patches:

-   Fork the project.
-   Make your changes.
-   Add tests **(strongly encouraged)**.
-   Run the tests ([see how](#running-the-tests)).
-   Create a Pull Request.


# Running the Tests

To run the tests, run `composer check`.


# Security

In case you discover a security vulnerability, kindly send an e-mail to the maintainer via [wedamja@gmail.com](mailto:wedamja@gmail.com). Security vulnerabilities will be promptly addressed.


# License

This is free and open-source software distributed under the [MIT LICENSE](LICENSE).
