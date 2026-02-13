An auto-generation library that eliminates boilerplate by automatically building production-ready GraphQL APIs from [Doctrine ORM](https://github.com/doctrine/orm) entities. Built on [graphql-php](https://github.com/webonyx/graphql-php), it provides out-of-the-box support for queries, mutations, filtering, pagination, and authorization — perfect for [Symfony](https://symfony.com) and other Doctrine-based frameworks.

# Table of Contents

- [Features](#features)
- [Motivation](#motivation)
- [Requirements](#requirements)
- [Installation](#installation)
- [Running Tests](#running-tests)
- [Demo Application](#demo-application)
- [Usage](#usage)
- [Schema](#schema)
- [Querying](#querying)
- [Plugins](#plugins)
- [Computed Fields](#computed-fields)
- [Filtering](#filtering)
- [Ordering](#ordering)
- [Mutations](#mutations)
- [Subscriptions](#subscriptions)
- [Authorizors](#authorizors)
- [Performance Optimization](#performance-optimization)
- [Security](#security)
- [Known Issues](#known-issues)
- [Versioning](#versioning)
- [Contributing](#contributing)
- [Reporting Vulnerabilities](#reporting-vulnerabilities)
- [License](#license)

# Features

- **SDL-First Approach** — Define your GraphQL schema using the Schema Definition Language for clarity and maintainability.
- **Automatic Pagination** — Out-of-the-box pagination support for queries and collections without extra boilerplate.
- **Extensible Plugin System** — Implement computed fields, custom filtering, ordering, mutations, subscriptions, authorization, and custom resolvers through an intuitive plugin architecture.
- **Complete Type System Support** — Full support for enums, abstract types (Unions and Interfaces), custom scalars, custom directives, and more.
- **Schema Auto-Generation** — Automatically generate initial GraphQL schemas from your Doctrine ORM entities and update them as your models evolve.
- **Code Generation Tools** — CLI commands to auto-generate plugin boilerplate and scalar type definitions, speeding up development.
- **Performance-Optimized Batching** — Automatic query batching and support for mutation/resolver batching to eliminate the N+1 problem and reduce database load.

# Motivation

Building GraphQL APIs typically requires writing extensive boilerplate code: schema definitions, resolvers for queries and mutations, pagination logic, filtering, authorization checks, and more. This repetitive work is time-consuming and error-prone, diverting your focus from implementing your API's unique business logic.

Watchtower changes this by **automatically generating your entire GraphQL API from your existing Doctrine ORM models**. Using your existing Doctrine annotations, Watchtower generates a GraphQL SDL schema and provides a complete, production-ready GraphQL API with full querying, filtering, pagination, and authorization capabilities—eliminating months of routine development.

This library draws inspiration from similar code-generation approaches on other platforms:

- **[Lighthouse](https://github.com/nuwave/lighthouse)** for Laravel and Eloquent
- **[Mongoose GraphQL Server](https://github.com/DanishSiraj/mongoose-graphql-server)** for Express and Mongoose

These projects have proven the immense value of automatically generating GraphQL servers. Watchtower brings the same philosophy to the PHP/Doctrine ecosystem.

# Requirements

- php >= v8.0
- doctrine/orm ^2.8 || ^3.0
- webonyx/graphql-php ^15.30.2

# Installation

    composer require wedrix/watchtower

# Running Tests

The project includes an integration-focused test suite for the `Console` and `Executor` entry points.

Run all tests:

    composer test

Run grouped workflows:

    composer test:console
    composer test:executor

Run Doctrine compatibility lanes locally (lowest/latest for each supported major):

    composer test:doctrine2:lowest
    composer test:doctrine2:latest
    composer test:doctrine3:lowest
    composer test:doctrine3:latest

Run the full local matrix:

    composer test:matrix

Run static checks:

    composer lint:check
    composer rector:check
    composer phpstan:check

## Symfony Compatibility

Watchtower has no direct dependency on Symfony. Compatibility is determined by your application's PHP + Doctrine ORM stack.

- Minimum Watchtower baseline: PHP 8.0 + Doctrine ORM 2.8
- Doctrine ORM 3.x requires PHP 8.1+
- In practice, this means Symfony `5.4+` can work when your Symfony/Doctrine bridge versions match those constraints.

If you use Symfony, use a Symfony release that supports your PHP version and Doctrine integration.  
As of February 13, 2026, Symfony's maintained branches are `6.4`, `7.4`, and `8.0`: [https://symfony.com/releases](https://symfony.com/releases).

## Symfony Bundle

The documentation for the Symfony bundle is available [here](https://github.com/Wedrix/watchtower-symfony-bundle). Kindly view it for the appropriate installation steps for Symfony.

## Demo Application  

The demo application, written for Symfony, allows you to test out the various features of this package. The documentation is available [here](https://github.com/Wedrix/watchtower-symfony-demo-application).

# Usage

This library is composed of two main components:

 1. The Executor component `Wedrix\Watchtower\Executor`, responsible for auto-resolving queries.
 2. The Console component `Wedrix\Watchtower\Console`, responsible for code generation, schema management, and plugin management.

The Executor component should be used in some controller class or callback function to power your service's GraphQL endpoint. The example usage below is for a Slim 4 application:

```php
#index.php

<?php
use App\Doctrine\EntityManager;
use GraphQL\Error\DebugFlag;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;

use function Wedrix\Watchtower\Executor;

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
  $executor = Executor(
   entityManager: $entityManager, // Either as a Singleton or from some DI container
   schemaFile: __DIR__ . '/resources/graphql/schema.graphql',
   pluginsDirectory: __DIR__ . '/resources/graphql/plugins',
   scalarTypeDefinitionsDirectory: __DIR__. '/resources/graphql/scalar_type_definitions',
   optimize: true, // Should be false in dev environment
   cacheDirectory: __DIR__ . '/var/cache'
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
       debug: DebugFlag::INCLUDE_DEBUG_MESSAGE | DebugFlag::INCLUDE_TRACE
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

The Console component on the other hand, should be used in a cli tool to offer convenience services like code generation during development. Check out the [Symfony bundle](https://github.com/Wedrix/watchtower-symfony-bundle) for an example usage.

# Schema

This library relies on a schema file written in the Schema Definition Language (SDL) to describe the service's type system. For a quick primer on the SDL, check out [this](https://wehavefaces.net/graphql-shorthand-notation-cheatsheet-17cd715861b6) article by Hafiz Ismail.

The library supports the complete GraphQL type system through the SDL and is able to auto-resolve Doctrine entities and relations, even collections, out-of-the-box. However, some extra steps are needed for certain features to be fully functional.

## Enums, Interfaces, and Unions

Enums, interfaces, and unions are supported through your SDL schema. They are not generated by `generateSchema()` and should be added manually to your schema file.

### Enums

Define enums in SDL and use their enum names as values in resolver/mutation results and variables.

```graphql
enum ContentKind {
  BOOK
  AUTHOR
}

type Query {
  echoContentKind(kind: ContentKind!): ContentKind!
}
```

In resolver or mutation plugins, return enum values as the enum name string:

```php
return 'BOOK';
```

### Interfaces and Unions

For abstract types (interfaces and unions), values returned by resolver or mutation plugins must include `__typename`. The value of `__typename` must exactly match one of the concrete GraphQL type names in your schema.

```graphql
interface SearchResult {
  id: ID!
  label: String!
}

type SearchBook implements SearchResult {
  id: ID!
  label: String!
  pageCount: Int!
}

type SearchAuthor implements SearchResult {
  id: ID!
  label: String!
  nationality: String!
}

union SearchItem = SearchBook | SearchAuthor

type Query {
  searchAsInterface: [SearchResult!]!
  searchAsUnion: [SearchItem!]!
}
```

```php
return [
    [
        '__typename' => 'SearchBook',
        'id' => 'b-1',
        'label' => 'GraphQL Basics',
        'pageCount' => 320,
    ],
    [
        '__typename' => 'SearchAuthor',
        'id' => 'a-1',
        'label' => 'Ada Lovelace',
        'nationality' => 'British',
    ],
];
```

When querying unions/interfaces, use inline fragments for concrete-type fields:

```graphql
query {
  searchAsUnion {
    __typename
    ... on SearchBook {
      pageCount
    }
    ... on SearchAuthor {
      nationality
    }
  }
}
```

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
): mixed // You can replace 'mixed' with a more specific type
{
}
```

5. The respective functions `serialize()`, `parseValue()`, and `parseLiteral()` must be namespaced following this format:
  `Wedrix\Watchtower\{the scalar type name in PascalCase}TypeDefinition`

The below code snippet is an example Scalar Type Definition for a custom DateTime scalar type:

```php
#resources/graphql/scalar_type_definitions/date_time_type_definition.php

<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\DateTimeTypeDefinition;

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

## Generating the Schema

The console component comes with the helper method `generateSchema()` which may be used to generate the initial schema file based on the project's Doctrine models.

### Important Limitations

Kindly take note of the following when using the schema generator:

 1. **Query-Only Generation:** The generator only generates Query operations. It does not generate any Mutation or Subscription operations - those must be added manually.
 2. **Partial Scalar Auto-Generation:** The generator auto-generates scalar type definitions for the custom types: `DateTime`, `Page`, and `Limit` if they do not already exist.
 3. **Limited Type Support:** The generator is able to resolve only the following Doctrine types:

    - All [integer types](https://www.doctrine-project.org/projects/doctrine-dbal/en/current/reference/types.html#integer-types)  - resolve to GraphQL's `Int` type.
    - All [decimal types](https://www.doctrine-project.org/projects/doctrine-dbal/en/current/reference/types.html#decimal-types) - resolve to GraphQL's `Float` type.
    - All [string types](https://www.doctrine-project.org/projects/doctrine-dbal/en/current/reference/types.html#string-types) - resolve to GraphQL's `String` type.
    - All [date and time types](https://www.doctrine-project.org/projects/doctrine-dbal/en/current/reference/types.html#date-and-time-types) - resolve to the custom `DateTime` type (auto-generated if it doesn't already exist).

 4. **Manual Type Additions:** The generator skips all fields having scalar types different from the above-mentioned types. You must manually add those in, with their corresponding Scalar Type Definitions.
 5. **Database Columns Only:** The generator only resolves actual fields that correspond to database columns. All other fields must be added manually, as either Computed or Resolved fields.
 6. **Manual Nullability for Complex Types:** The generator is not able to properly ascertain the nullability of Embedded Types and Relations, so those must be manually set. Currently, all embedded field types will be nullable by default, and all relations, non-nullable.

## Updating the Schema

The console component exposes `updateSchema()`, but schema merge/update behavior is not yet implemented.  
At the moment, the method only invalidates the schema cache file (if present).

## Using Multiple Schemas

Using multiple schemas is as simple as instantiating different objects of the Executor and Console components, with the different schema files' configurations. You can then use them with the appropriate controllers, routes, cli-scripts etc.

# Querying

## Finding Entities

To find a particular entity, you must pass the argument(s) that correspond to any of its unique keys to the corresponding field in the document. For example, for the given schema:

```graphql
type Query {
    product(id: ID!): Product!
}

type Product {
    id: ID!
    name: String!
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

also, for the given schema:

```graphql
type Query {
    productLine(product: ID!, order: ID!): ProductLine!
}

type ProductLine {
    product: Product!
    order: Order!
    quantity: Int!
}

type Product {
 id: ID!
 name: String!
}

type Order {
 id: ID!
}
```

the query:

```graphql
query {
    productLine(product: 1, order: 1) {
        product {
            name
        }
        order {
            id
        }
        quantity
    }
}
```

returns the result for the productLine with product id **1** and order id **1**.

Notice that in the previous example, the unique key for ProductLine is a compound key consisting of the associations `product` and `order`. You may use any combination of fields/associations, that together make a valid unique key, as Find Query parameters.

Note that Find Queries can only be represented by top-level query fields since the resolver auto-relates sub-level fields as relations.

## Relations

This library is also able to resolve the relations of your models. For instance, given the following schema definition:

```graphql
type Query {
    product(id: ID!): Product!
}

type Product {
    id: ID!
    name: String!
    bestSeller: Listing
    listings: [Listing!]!
}

type Listing {
    id: ID!
    sellingPrice: Float!
}
```

the query:

```graphql
query {
    product(id: 1) {
        name
        bestSeller
        listings
    }
}
```

resolves the product with id **1**, its best seller, and all the corresponding listings as described by the `bestSeller` and `listings` associations of the Product entity. For more details on Doctrine relations check out [the documentation](https://www.doctrine-project.org/projects/doctrine-orm/en/2.13/reference/association-mapping.html).

## Pagination

### Collection Pagination

By default, the complete result-set for a collection relation is returned. To enable pagination for a particular collection relation, all you have to do is pass the `queryParams` argument to the corresponding field in the document. For example:

```graphql
type Query {
    product(id: ID!): Product!
}

type Product {
    id: ID!
    name: String!
    bestSeller: Listing
    listings(queryParams: ListingsQueryParams!): [Listing!]!
}

type Listing {
    id: ID!
    sellingPrice: Float!
}

input ListingsQueryParams {
    limit: Int # items per page
    page: Int # page
}
```

The type specified for the `queryParams` argument does not matter. The only requirement is that it must define the two fields `limit` and `page` as integer types. You may also choose to make them non-nullable to force pagination for the particular query field.

### Query Pagination

`queryParams` may also be used to paginate the results of a query. For instance, given the following schema:

```graphql
type Query {
    products: [Product!]!
    paginatedProducts(queryParams: ProductsQueryParams!): [Product!]!
}

type Product {
    id: ID!
    name: String!
    bestSeller: Listing
}

input ProductsQueryParams {
    limit: Int! # items per page
    page: Int! # page
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

## Distinct Queries

To return distinct results, add the `distinct` parameter to the `queryParams` argument. For example:

```graphql
type Query {
    paginatedProducts(queryParams: ProductsQueryParams!): [Product!]!
}

input ProductsQueryParams {
    distinct: Boolean # Must be boolean
    limit: Int!
    page: Int!
}

type Product {
    id: ID!
    name: String!
    bestSeller: Listing
}
```

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

## Plugin Types Reference

Here's a quick reference guide for all plugin types and their naming conventions:

| Plugin Type | Subdirectory | File Naming | Function Naming | Namespace | Purpose |
|---|---|---|---|---|---|
| **Selector** | `selectors/` | `apply_{type}_{field}_selector.php` | `apply_{type}_{field}_selector` | `Wedrix\Watchtower\SelectorPlugin` | Compute fields using database queries |
| **Resolver** | `resolvers/` | `resolve_{type}_{field}_field.php` | `resolve_{type}_{field}_field` | `Wedrix\Watchtower\ResolverPlugin` | Resolve fields using custom logic or services |
| **Filter** | `filters/` | `apply_{type}_{filter}_filter.php` | `apply_{pluralType}_{filter}_filter` | `Wedrix\Watchtower\FilterPlugin` | Filter collections based on parameters |
| **Constraint** | `filters/` | `apply_{type}_constraint.php` | `apply_{type}_constraint` | `Wedrix\Watchtower\ConstraintPlugin` | Always-applied filters |
| **Root Constraint** | `filters/` | `apply_constraint.php` | `apply_constraint` | `Wedrix\Watchtower\ConstraintPlugin` | Global constraints for all queries |
| **Ordering** | `orderings/` | `apply_{type}_{order}_ordering.php` | `apply_{pluralType}_{order}_ordering` | `Wedrix\Watchtower\OrderingPlugin` | Order collection results |
| **Mutation** | `mutations/` | `call_{mutation}_mutation.php` | `call_{mutation}_mutation` | `Wedrix\Watchtower\MutationPlugin` | Perform state-changing operations |
| **Subscription** | `subscriptions/` | `call_{subscription}_subscription.php` | `call_{subscription}_subscription` | `Wedrix\Watchtower\SubscriptionPlugin` | Subscribe to event streams |
| **Authorizor** | `authorizors/` | `authorize_{type}_result.php` | `authorize_{type}_result` | `Wedrix\Watchtower\AuthorizorPlugin` | Authorize individual results |
| **Root Authorizor** | `authorizors/` | `authorize_result.php` | `authorize_result` | `Wedrix\Watchtower\AuthorizorPlugin` | Global authorization rules |

**Key Notes:**

- `{type}` = singular entity type name in snake_case (e.g., `product`)
- `{pluralType}` = pluralized entity type name in snake_case (e.g., `products`)
- `{field}` = field name in snake_case
- `{filter}` / `{order}` / `{mutation}` = operation name in snake_case

Below is an example filter plugin for filtering listings by the given ids:

```php
# resources/graphql/plugins/filters/apply_listings_ids_filter.php

<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\FilterPlugin;

use Wedrix\Watchtower\Resolver\Node;
use Wedrix\Watchtower\Resolver\QueryBuilder;

function apply_listings_ids_filter(
    QueryBuilder $queryBuilder,
    Node $node
): void
{
    $entityAlias = $queryBuilder->rootEntityAlias();

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

namespace Wedrix\Watchtower\SelectorPlugin;

use Wedrix\Watchtower\Resolver\Node;
use Wedrix\Watchtower\Resolver\QueryBuilder;

function apply_product_selling_price_selector(
    QueryBuilder $queryBuilder,
    Node $node
): void
{
    $entityAlias = $queryBuilder->rootEntityAlias();
    
    $queryBuilder->addSelect("
        $entityAlias.markedPrice - $entityAlias.discount AS sellingPrice
    ");
}
```

### Rules

The rules for Selector plugins are as follows:

 1. The plugin's script file must be contained in the directory specified for the `pluginsDirectory` parameter of both the Executor and Console components, under the `selectors` sub-folder.
 2. The script file's name must follow the following naming format:  
  apply_{***node type name in snake_case***}_{***field name in snake_case***}_selector.php
 3. Within the script file, the plugin function's name must follow the following naming format:  
  apply_{***node type name in snake_case***}_{***field name in snake_case***}_selector
 4. The plugin function must have the following signature:

```php
function function_name(
    \Wedrix\Watchtower\Resolver\QueryBuilder $queryBuilder,
    \Wedrix\Watchtower\Resolver\Node $node
): void;
```

5. The plugin function must be namespaced under `Wedrix\Watchtower\SelectorPlugin`.

### Helpful Utilities

The first function parameter `$queryBuilder` represents the query builder on which you can chain your own queries to resolve the computed field. It extends the interface for `\Doctrine\ORM\QueryBuilder` with these added functions to help you build the query:

 1. Use `$queryBuilder->rootEntityAlias()` to get the query's root entity alias.
 2. Use `$queryBuilder->reconciledAlias(string $alias)` to get an alias that's compatible with the rest of the query aliases. Use it to prevent name collisions.

The second function parameter `$node` represents the particular query node being resolved in the query graph. Use it to determine the appropriate query to chain onto the builder.

## Resolver Plugins

Resolver plugins allow you to resolve fields using other services from the database. Unlike Selector plugins, they allow you to return a result, instead of forcing you to chain a query onto the builder. The code snippet below is an example Resolver plugin for the computed exchangeRate field of a Currency type:

```php
#resources/graphql/plugins/resolvers/resolve_currency_exchange_rate_field.php

<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\ResolverPlugin;

use Wedrix\Watchtower\Resolver\Node;
use Wedrix\Watchtower\Resolver\QueryBuilder;

function resolve_currency_exchange_rate_field(
    Node $node
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
  resolve_{***node type name in snake_case***}_{***field name in snake_case***}_field.php
 3. Within the script file, the plugin function's name must follow the following naming format:  
  resolve_{***node type name in snake_case***}_{***field name in snake_case***}_field
 4. The plugin function must have the following signature:

```php
function function_name(
    \Wedrix\Watchtower\Resolver\Node $node
): mixed;
```

5. The plugin function must be namespaced under `Wedrix\Watchtower\ResolverPlugin`.

### Valid Return Types

Kindly note that values returned from a resolver function must be resolvable by the library. This library is able to auto-resolve the following primitive php types: `null`, `int`, `bool`, `float`, `string`, and `array`. Any other return type must have an associated scalar type definition to be resolvable by this library. Values representing user-defined object types must be returned as associative arrays. For collections, return a 0-indexed list.

## Resolving Abstract Types

Use the utility functions `$node->unwrappedType()`, `$node->isAbstract()`, `$node->concreteFieldsSelection()`, and `$node->abstractFieldsSelection()` to determine what type you are resolving: whether it's an abstract type, and the concrete and abstract fields selected, respectively.

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

namespace Wedrix\Watchtower\FilterPlugin;

use Wedrix\Watchtower\Resolver\Node;
use Wedrix\Watchtower\Resolver\QueryBuilder;

function apply_listings_ids_filter(
    QueryBuilder $queryBuilder,
    Node $node
): void
{
    $entityAlias = $queryBuilder->rootEntityAlias();

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
  apply_{***node type name in snake_case***}_{***filter name in snake_case***}_filter.php  
  **Note:** The node type name in the filename should be singular (e.g., `apply_product_ids_filter.php` for a Product type filter), but for the function name, use the format as described below.
 3. Within the script file, the plugin function's name must follow the following naming format:  
  apply_{***pluralized node type name in snake_case***}_{***filter name in snake_case***}_filter  
  **Example:** For filtering products by IDs, the function would be `apply_products_ids_filter()` in the file `apply_product_ids_filter.php`.
 4. The plugin function must have the following signature:

```php
function function_name(
    \Wedrix\Watchtower\Resolver\QueryBuilder $queryBuilder,
    \Wedrix\Watchtower\Resolver\Node $node
): void;
```

 5. The plugin function must be namespaced under `Wedrix\Watchtower\FilterPlugin`.

To use filters add them to the `filters` parameter of the `queryParams` argument. For instance:

```graphql
type Query {
    paginatedProducts(queryParams: ProductsQueryParams!): [Product!]!
}

input ProductsQueryParams {
    filters: ProductsQueryFiltersParam # Can be any user-defined input type
    limit: Int!
    page: Int!
}

input ProductsQueryFiltersParam {
    ids: [String!]
    isStocked: Boolean # Another filter
}

type Product {
    id: ID!
    name: String!
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

Kindly refer to the **Helpful Utilities** section under **Selector Plugins** for helpful methods using the builder.

## Constraint Plugins

Sometimes you may wish to apply a set of filters always regardless of the client's input. Constraint plugins allow you to do exactly that! Unlike filters that rely on the client's input via queryParams, constraints are always applied regardless. The code snippet below is an example Constraint plugin for filtering listings by a closed set of ids:

```php
<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\ConstraintPlugin;

use Wedrix\Watchtower\Resolver\Node;
use Wedrix\Watchtower\Resolver\QueryBuilder;

function apply_listing_constraint(
    QueryBuilder $queryBuilder,
    Node $node
): void
{
    $entityAlias = $queryBuilder->rootEntityAlias();

    $whitelistedListings = ['listing1','listing2','listing3'];

    $idsValueAlias = $queryBuilder->reconciledAlias('idsValue');

    $queryBuilder->andWhere("{$entityAlias}.id IN (:$idsValueAlias)")
                ->setParameter($idsValueAlias, $whitelistedListings);
}
```

Given the above constraint, Listing queries will always resolve to one of the whitelisted listings.

### Rules

The rules for Constraint plugins are as follows:

 1. The plugin's script file must be contained in the directory specified for the `pluginsDirectory` parameter of both the Executor and Console components, under the `filters` sub-folder.
 2. The script file's name must follow the following naming format:  
  apply_{***node type name in snake_case***}_constraint.php
 3. Within the script file, the plugin function's name must follow the following naming format:  
  apply_{***node type name in snake_case***}_constraint
 4. The plugin function must have the following signature:

```php
function function_name(
    \Wedrix\Watchtower\Resolver\QueryBuilder $queryBuilder,
    \Wedrix\Watchtower\Resolver\Node $node
): void;
```

5. The plugin function must be namespaced under `Wedrix\Watchtower\ConstraintPlugin`.

## Root Constraint Plugin

The Root Constraint plugin allows you to create constraint rules that apply for all queries. The code snippet below is an example root constraint that is applied to all queries:

```php
<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\ConstraintPlugin;

use Wedrix\Watchtower\Resolver\Node;
use Wedrix\Watchtower\Resolver\QueryBuilder;

function apply_constraint(
    QueryBuilder $queryBuilder,
    Node $node
): void
{
    $entityAlias = $queryBuilder->rootEntityAlias();

    $queryBuilder->join("{$entityAlias}.app", 'app')
                ->andWhere("app.id = :appId")
                ->setParameter('appId', Config::appId());
}
```

Given the above constraint, all queries will always be filtered by their association to app.

### Rules

The rules for Root Constraint plugins are as follows:

 1. The plugin's script file must be contained in the directory specified for the `pluginsDirectory` parameter of both the Executor and Console components, under the `filters` sub-folder.
 2. The script file's name must follow the following naming format:  
  apply_constraint.php
 3. Within the script file, the plugin function's name must follow the following naming format:  
  apply_constraint
 4. The plugin function must have the following signature:

```php
function function_name(
    \Wedrix\Watchtower\Resolver\QueryBuilder $queryBuilder,
    \Wedrix\Watchtower\Resolver\Node $node
): void;
```

5. The plugin function must be namespaced under `Wedrix\Watchtower\ConstraintPlugin`.

### Usage as Authorizors

Aside filtering, Constraints can also be used to "authorize" nodes, by throwing Exceptions, preventing further query processing/execution. As such, they are preferable to Authorizors for **query** operations since Authorizors only run **after** query execution.

# Ordering

This library allows you to order queries by chaining **order by** statements onto the builder. It also supports multiple ordering, where one ordering is applied after another to reorder matching elements. To implement orderings, use Ordering Plugins.

## Ordering Plugins

Ordering plugins allow you to chain **order by** statements onto the query builder. The code snippet below is an example Ordering plugin for ordering listings by the newest:

```php
<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\OrderingPlugin;

use Wedrix\Watchtower\Resolver\Node;
use Wedrix\Watchtower\Resolver\QueryBuilder;

function apply_listings_newest_ordering(
    QueryBuilder $queryBuilder,
    Node $node
): void
{
    $entityAlias = $queryBuilder->rootEntityAlias();

    $dateCreatedAlias = $queryBuilder->reconciledAlias('dateCreated');

    $queryBuilder->addSelect("$entityAlias.dateCreated AS HIDDEN $dateCreatedAlias")
            ->addOrderBy($dateCreatedAlias, 'DESC');
}
```

### Rules

The rules for Ordering plugins are as follows:

 1. The plugin's script file must be contained in the directory specified for the `pluginsDirectory` parameter of both the Executor and Console components, under the `orderings` sub-folder.
 2. The script file's name must follow the following naming format:  
  apply_{***pluralized node type name in snake_case***}_{***ordering name in snake_case***}_ordering.php
 3. Within the script file, the plugin function's name must follow the following naming format:  
  apply_{***pluralized node type name in snake_case***}_{***ordering name in snake_case***}_ordering
 4. The plugin function must have the following signature:

```php
function function_name(
    \Wedrix\Watchtower\Resolver\QueryBuilder $queryBuilder,
    \Wedrix\Watchtower\Resolver\Node $node
): void;
```

5. The plugin function must be namespaced under `Wedrix\Watchtower\OrderingPlugin`.

To use orderings add them to the `ordering` parameter of the `queryParams` argument. For instance:

```graphql
type Query {
    paginatedProducts(queryParams: ProductsQueryParams!): [Product!]!
}

input ProductsQueryParams {
    ordering: ProductsQueryOrderingParam # Can be any user-defined input type
    limit: Int!
    page: Int!
}

input ProductsQueryOrderingParam {
    closest: ProductsQueryOrderingClosestParam # Can also be any user-defined input type
    oldest: ProductsQueryOrderingOldestParam # Another ordering
}

input ProductsQueryOrderingClosestParam {
    rank: Int! # This parameter is required for all orderings
    params: ProductsQueryOrderingClosestParamsParam! # This is optional for parameterized orderings
}

input ProductsQueryOrderingClosestParamsParam {
    location: Coordinates!
}

input ProductsQueryOrderingOldestParam {
    rank: Int!
}

type Product {
    id: ID!
    name: String!
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
                        location:"40.74684111541018,-73.98518096794233"
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

Kindly refer to the **Helpful Utilities** section under **Selector Plugins** for helpful methods using the builder.

# Mutations

**Mutation** is a different operation type used to reliably change state in your application. Unlike queries, mutations are guaranteed to run in sequence, preventing any potential race conditions. However, just like queries, they can also return a data graph. This library supports mutations through Mutation Plugins.

## Mutation Plugins

Mutation plugins allow you to create mutations to reliably change state in your application. The code snippet below is an example mutation used to log in a user:

```php
<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\MutationPlugin;

use App\Server\Session;
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
  call_{***mutation name in snake_case***}_mutation.php
 3. Within the script file, the plugin function's name must follow the following naming format:  
  call_{***mutation name in snake_case***}_mutation
 4. The plugin function must have the following signature:

```php
function function_name(
    \Wedrix\Watchtower\Resolver\Node $node
): mixed;
```

5. The plugin function must be namespaced under `Wedrix\Watchtower\MutationPlugin`.

### Valid Return Types

Like with Resolver plugin functions, values returned from a mutation function must be resolvable by the library. This library is able to auto-resolve the following primitive php types: `null`, `int`, `bool`, `float`, `string`, and `array`. Any other return type must have an associated scalar type definition to be resolvable by this library. Values representing user-defined object types must be returned as associative arrays. For collections, return a 0-indexed list.

# Subscriptions

**Subscription** is another GraphQL operation type that is used to subscribe to a stream of events from the server. Unlike queries and mutations, subscriptions send many results over an extended period of time. Thus, they require different plumbing from the normal HTTP request flow. This makes their implementation heavily reliant on architectural choices that are beyond the scope of this library. Nevertheless, the library supports subscriptions through Subscription Plugins that act as connectors to the underlying application's implementation for transport, message brokering, etc.

## Subscription Plugins

Subscription plugins act as connectors to your application's implementation of subscriptions. The rules for creating Subscription Plugins are as follows:

### Rules

 1. The plugin's script file must be contained in the directory specified for the `pluginsDirectory` parameter of both the Executor and Console components, under the `subscriptions` sub-folder.
 2. The script file's name must follow the following naming format:  
  call_{***subscription name in snake_case***}_subscription.php
 3. Within the script file, the plugin function's name must follow the following naming format:  
  call_{***subscription name in snake_case***}_subscription
 4. The plugin function must have the following signature:

```php
function function_name(
    \Wedrix\Watchtower\Resolver\Node $node
): mixed;
```

5. The plugin function must be namespaced under `Wedrix\Watchtower\SubscriptionPlugin`.

Kindly refer to the [GraphQL spec](https://spec.graphql.org/October2021/#sec-Subscription) for the requirements of a Subscription implementation.

# Authorizors

Authorizors allow you to approve results based on user-defined rules for individual node/collection types. These rules apply to all operation type results, including Queries, Mutations, and Subscriptions. You write authorizors once and can be guaranteed that they will apply to all results. Authorizors are implemented through Authorizor Plugins.

## Authorizor Plugins

Authorizor plugins allow you to create authorizors for individual node/collection types. The code snippet below is an example authorizor applied to User results:

```php
<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\AuthorizorPlugin;

use App\Server\Session;
use Wedrix\Watchtower\Resolver\Node;
use Wedrix\Watchtower\Resolver\Result;

use function Wedrix\Watchtower\any_in_array;

function authorize_customer_result(
    Result $result,
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

The rules for Authorizor plugins are as follows:

 1. The plugin's script file must be contained in the directory specified for the `pluginsDirectory` parameter of both the Executor and Console components, under the `authorizors` sub-folder.
 2. The script file's name must follow the following naming format:  
  authorize_{***node type name (pluralized if for collections) in snake_case***}_result.php
 3. Within the script file, the plugin function's name must follow the following naming format:  
  authorize_{***node type name (pluralized if for collections) in snake_case***}_result
 4. The plugin function must have the following signature:

```php
function function_name(
    \Wedrix\Watchtower\Resolver\Result $result,
    \Wedrix\Watchtower\Resolver\Node $node
): void;
```

5. The plugin function must be namespaced under `Wedrix\Watchtower\AuthorizorPlugin`.

## Root Authorizor Plugin

The Root Authorizor plugin allows you to create authorizor rules that apply for all node/collection types. The code snippet below is an example root authorizor that is applied on all results:

```php
<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\AuthorizorPlugin;

use App\Server\Session;
use Wedrix\Watchtower\Resolver\Node;
use Wedrix\Watchtower\Resolver\Result;

use function Wedrix\Watchtower\any_in_array;

function authorize_result(
    Result $result,
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

The rules for the Root Authorizor plugin are as follows:

 1. The plugin's script file must be contained in the directory specified for the `pluginsDirectory` parameter of both the Executor and Console components, under the `authorizors` sub-folder.
 2. The script file's name must follow the following naming format:  
  authorize_result.php
 3. Within the script file, the plugin function's name must follow the following naming format:  
  authorize_result
 4. The plugin function must have the following signature:

```php
function function_name(
    \Wedrix\Watchtower\Resolver\Result $result,
    \Wedrix\Watchtower\Resolver\Node $node
): void;
```

5. The plugin function must be namespaced under `Wedrix\Watchtower\AuthorizorPlugin`.

# Performance Optimization

To optimize the executor for production, pass `true` as the argument for the `optimize` parameter of the Executor and generate the cache beforehand using the `Console::generateCache()` method.  
Running in 'optimize' mode, the Executor only relies on the cache as the authoritative source for the Schema file, Plugin files, and the Scalar Type Definition files.  
Note that the cache is never updated at runtime so it must be generated beforehand and kept up to date with changes in the source using Console::generateCache().  

## Batching

This library auto-batches queries, mutations, and resolvers to optimize performance and avoid the N + 1 problem. Batching works by deferring the execution of similar operations and combining them into a single batch operation, which significantly reduces the number of database calls and other I/O operations.

**When is batching beneficial?** Batching is especially useful when:

- Resolving many relations of the same type for different parent entities
- Executing multiple similar mutations with identical or similar parameters
- Calling external services or APIs that support bulk operations

### How Batching Works

The batching mechanism uses two key components:

1. **NodeBuffer**: Collects all nodes that need to be resolved during a single GraphQL operation execution.
2. **ResultBuffer**: Caches batch operation results using a `BatchKey` (derived from the node type, field name, and arguments) to enable result reuse across multiple nodes with identical parameters.

When a batch result is resolved and stored in the `ResultBuffer`, any other node requesting the same result (same parent type, field name, and arguments) can reuse the cached result instead of recomputing it.

### Query Batching (Auto-Applied)

Queries are automatically batched by the library. When multiple nodes with the same parent type and field name are resolved, they are combined into a single database query.

### Mutation Batching

Mutations can also be batched if multiple mutations are called with identical arguments. While mutations execute sequentially per the GraphQL specification, batching allows you to group identical mutations into a single operation:

```php
<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\MutationPlugin;

use Wedrix\Watchtower\Resolver\Node;

use function Wedrix\Watchtower\Resolver\NodeBuffer;
use function Wedrix\Watchtower\Resolver\ResultBuffer;
use function Wedrix\Watchtower\Resolver\BatchKey;

function call_send_notification_mutation(
    Node $node
): mixed
{
    $batchKey = BatchKey(node: $node);
    
    // 1. Check if the batch result is already cached
    if (ResultBuffer()->has($batchKey)) {
        $batchResult = ResultBuffer()->get($batchKey);
        
        // Filter to only those relevant for this node
        return $batchResult[$node->args()['user_id']];
    }
    
    // 2. Collect all matching nodes with the same batch key
    $matchingNodes = [];
    foreach (NodeBuffer() as $bufferedNode) {
        if (BatchKey(node: $bufferedNode)->value() === $batchKey->value()) {
            $matchingNodes[] = $bufferedNode;
        }
    }
    
    // 3. Perform the batch mutation for all matching nodes
    $notificationService = $node->context()['notificationService'];
    $userIds = \array_map(
        static fn (Node $n) => $n->args()['user_id'],
        $matchingNodes
    );
    $batchResult = $notificationService->sendMultiple($userIds, "Hello World!!!");
    
    // 4. Cache the result for subsequent identical mutations
    ResultBuffer()->add(
        batchKey: $batchKey, 
        batchResult: $batchResult
    );
    
    // Filter to only those relevant for this node
    return $batchResult[$node->args()['user_id']];
}
```

### Resolver Batching

You can similarly batch resolvers for external API calls or heavy computations by following the same pattern as mutation batching. This is particularly useful when you have multiple nodes requesting data from the same external service or requiring expensive computations. By batching these requests together, you can significantly reduce the overhead of repeated service calls.

**Note:** While Resolver Batching is supported, detailed examples are limited in this documentation. Refer to the Mutation Batching pattern above for implementation guidance, adapting it to your specific resolver use case.


## Authorizors and Constraints

Be careful running intensive computations and/or I/O (even requests to the Database) in Authorizors and Constraints. Since these usually run for multiple nodes (Root Authorizors run for every node!) they significantly impact performance. In cases where intensive computations and/or I/O are unavoidable, try as much as possible to memoize results to prevent unnecessary re-computation.

# Security

Kindly follow the [graphql-php manual](https://webonyx.github.io/graphql-php/security/) for directions on securing your GraphQL API. Most of the library's security APIs are compatible with this library since they are mostly static, allowing for external configuration.

# Known Issues

This section details some of the known issues relating to this library's usage and their possible workarounds.

## Case Sensitivity & Naming

GraphQL names are case-sensitive as detailed by the [spec](https://spec.graphql.org/October2021/#sec-Names). However, since [PHP names are case-insensitive](https://www.php.net/manual/en/language.namespaces.rationale.php), we cannot follow this spec requirement. Kindly note that using case-sensitive names with this library may lead to erratic undefined behaviour.

## Aliasing Parameterized Fields

Aliasing the same parameterized field with different arguments may still produce unexpected behaviour in some setups. Track upstream context here: [webonyx/graphql-php#1072](https://github.com/webonyx/graphql-php/issues/1072).

## Entity Naming Constraints

**Unique Base Names Required** — All Doctrine entities must have unique base names (the class name without the namespace). Entities with identical base names in different namespaces are not supported. For example, you cannot have both `App\Accounts\Seller` and `App\Dome\Seller` in the same application, as they share the same base name `Seller`. Ensure all entity class names are unique across your entire application.

## Composite Association Keys

**Single-Level Nesting Only** — Composite association keys (compound keys involving associations) are currently supported only to one level of nesting. Attempting to use more deeply nested compound association keys will likely result in errors.

## Reserved Alias Prefixes

When using custom plugins or working with query builders, avoid using the following reserved prefixes in your field aliases or parameter names:

- `__root` — Reserved for root entity aliases
- `__parent` — Reserved for parent entity aliases  
- `__primary` — Reserved for primary key/identifier aliases

Using these prefixes will result in an `InvalidArgumentException`. Choose alternative naming patterns for your custom aliases to avoid conflicts with the internal query building system.  

# Versioning

This project follows [Semantic Versioning 2.0.0](https://semver.org/spec/v2.0.0.html).

The intended public API elements are marked with the `@api` PHPDoc tag, and are guaranteed to be stable within minor version changes. All other elements are
not part of this backwards compatibility promise and may change between minor or patch versions.

Check [here](https://github.com/Wedrix/watchtower/releases) for all published releases.

# Contributing

For new features or contributions that propose significant breaking changes, kindly [start a discussion](https://github.com/Wedrix/watchtower/discussions/new) under the **ideas** category for contributors' feedback.

For smaller contributions involving bug fixes and patches:

- Fork the project.
- Make your changes.
- Create a Pull Request.

# Reporting Vulnerabilities

In case you discover a security vulnerability, kindly send an e-mail to the maintainer via [wedamja@gmail.com](mailto:wedamja@gmail.com). Security vulnerabilities will be promptly addressed.

# License

This is free and open-source software distributed under the [MIT LICENSE](LICENSE).
