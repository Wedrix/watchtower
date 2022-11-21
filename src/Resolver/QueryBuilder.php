<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\Resolver;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Criteria;
use Doctrine\ORM\EntityManagerInterface as DoctrineEntityManager;
use Doctrine\ORM\Query;
use Doctrine\ORM\Query\Expr;
use Doctrine\ORM\Query\Expr\Comparison;
use Doctrine\ORM\Query\Expr\Composite;
use Doctrine\ORM\Query\Expr\OrderBy;
use Doctrine\ORM\Query\Parameter;
use Doctrine\ORM\Query\QueryException;
use Doctrine\ORM\QueryBuilder as DoctrineQueryBuilder;

final class QueryBuilder
{
    private readonly DoctrineQueryBuilder $doctrineQueryBuilder;

    /**
     * @var array<string,int>
     * 
     * The key is the alias and the value is the count
     */
    private array $aliases = [];

    public function __construct(
        private readonly DoctrineEntityManager $doctrineEntityManager
    )
    {
        $this->doctrineQueryBuilder = $doctrineEntityManager->createQueryBuilder();
    }

    public function rootAlias(): string
    {
        return $this->doctrineQueryBuilder
                    ->getRootAliases()[0] ?? throw new \Exception("Invalid Query. The rootAlias is unset.");
    }

    public function reconciledAlias(
        string $alias
    ): string
    {
        if (!isset($this->aliases[$alias])) {
            $this->aliases[$alias] = 1;

            return $alias;
        }

        return $alias.++$this->aliases[$alias];
    }

    public function getEntityManager(): EntityManager
    {
        return new EntityManager(
            doctrineEntityManager: $this->doctrineEntityManager
        );
    }

    /**
     * Gets an ExpressionBuilder used for object-oriented construction of query expressions.
     * This producer method is intended for convenient inline usage. Example:
     *
     * <code>
     *     $qb = $em->createQueryBuilder();
     *     $qb
     *         ->select('u')
     *         ->from('User', 'u')
     *         ->where($qb->expr()->eq('u.id', 1));
     * </code>
     *
     * For more complex expression construction, consider storing the expression
     * builder object in a local variable.
     *
     * @return Expr
     */
    public function expr(): Expr
    {
        return $this->doctrineEntityManager
                    ->getExpressionBuilder();
    }

    /**
     * Gets the complete DQL string formed by the current specifications of this QueryBuilder.
     *
     * <code>
     *     $qb = $em->createQueryBuilder()
     *         ->select('u')
     *         ->from('User', 'u');
     *     echo $qb->getDql(); // SELECT u FROM User u
     * </code>
     *
     * @return string The DQL query string.
     */
    public function getDQL(): string
    {
        return $this->doctrineQueryBuilder
                    ->getDQL();
    }

    /**
     * Constructs a Query instance from the current specifications of the builder.
     *
     * <code>
     *     $qb = $em->createQueryBuilder()
     *         ->select('u')
     *         ->from('User', 'u');
     *     $q = $qb->getQuery();
     *     $results = $q->execute();
     * </code>
     *
     * @return Query
     */
    public function getQuery(): Query
    {
        return $this->doctrineQueryBuilder
                    ->getQuery();
    }

    /**
     * Sets a query parameter for the query being constructed.
     *
     * <code>
     *     $qb = $em->createQueryBuilder()
     *         ->select('u')
     *         ->from('User', 'u')
     *         ->where('u.id = :user_id')
     *         ->setParameter('user_id', 1);
     * </code>
     *
     * @param string|int      $key   The parameter position or name.
     * @param mixed           $value The parameter value.
     * @param string|int|null $type  ParameterType::* or \Doctrine\DBAL\Types\Type::* constant
     *
     * @return $this
     */
    public function setParameter(
        string|int $key, 
        mixed $value, 
        mixed $type = null
    ): static
    {
        $this->doctrineQueryBuilder
            ->setParameter($key, $value, $type);

        return $this;
    }

    /**
     * Sets a collection of query parameters for the query being constructed.
     *
     * <code>
     *     $qb = $em->createQueryBuilder()
     *         ->select('u')
     *         ->from('User', 'u')
     *         ->where('u.id = :user_id1 OR u.id = :user_id2')
     *         ->setParameters(new ArrayCollection(array(
     *             new Parameter('user_id1', 1),
     *             new Parameter('user_id2', 2)
     *        )));
     * </code>
     *
     * @param ArrayCollection<int,Parameter>|mixed[] $parameters The query parameters to set.
     * @psalm-param ArrayCollection<int, Parameter>|mixed[] $parameters
     *
     * @return $this
     */
    public function setParameters(
        ArrayCollection|array $parameters
    ): static
    {
        $this->doctrineQueryBuilder
            ->setParameters($parameters);

        return $this;
    }

    /**
     * Gets all defined query parameters for the query being constructed.
     *
     * @return ArrayCollection The currently defined query parameters.
     * @psalm-return ArrayCollection<int, Parameter>
     */
    public function getParameters(): ArrayCollection
    {
        return $this->doctrineQueryBuilder
                    ->getParameters();
    }

    /**
     * Gets a (previously set) query parameter of the query being constructed.
     *
     * @param string|int $key The key (index or name) of the bound parameter.
     *
     * @return Parameter|null The value of the bound parameter.
     */
    public function getParameter(
        string|int $key
    ): ?Parameter
    {
        return $this->doctrineQueryBuilder
                    ->getParameter($key);
    }

    /**
     * Sets the position of the first result to retrieve (the "offset").
     *
     * @param int|null $firstResult The first result to return.
     *
     * @return $this
     */
    public function setFirstResult(
        ?int $firstResult
    ): static
    {
        $this->doctrineQueryBuilder
            ->setFirstResult($firstResult);

        return $this;
    }

    /**
     * Gets the position of the first result the query object was set to retrieve (the "offset").
     * Returns NULL if {@link setFirstResult} was not applied to this QueryBuilder.
     *
     * @return int|null The position of the first result.
     */
    public function getFirstResult(): ?int
    {
        return $this->doctrineQueryBuilder
                    ->getFirstResult();
    }

    /**
     * Sets the maximum number of results to retrieve (the "limit").
     *
     * @param int|null $maxResults The maximum number of results to retrieve.
     *
     * @return $this
     */
    public function setMaxResults(
        ?int $maxResults
    ): static
    {
        $this->doctrineQueryBuilder
            ->setMaxResults($maxResults);

        return $this;
    }

    /**
     * Gets the maximum number of results the query object was set to retrieve (the "limit").
     * Returns NULL if {@link setMaxResults} was not applied to this query builder.
     *
     * @return int|null Maximum number of results.
     */
    public function getMaxResults(): ?int
    {
        return $this->doctrineQueryBuilder
                    ->getMaxResults();
    }

    /**
     * Specifies an item that is to be returned in the query result.
     * Replaces any previously specified selections, if any.
     *
     * <code>
     *     $qb = $em->createQueryBuilder()
     *         ->select('u', 'p')
     *         ->from('User', 'u')
     *         ->leftJoin('u.Phonenumbers', 'p');
     * </code>
     *
     * @param mixed $select The selection expressions.
     *
     * @return $this
     */
    public function select(
        mixed $select = null
    ): static
    {
        $this->doctrineQueryBuilder
            ->select($select);

        return $this;
    }

    /**
     * Adds a DISTINCT flag to this query.
     *
     * <code>
     *     $qb = $em->createQueryBuilder()
     *         ->select('u')
     *         ->distinct()
     *         ->from('User', 'u');
     * </code>
     *
     * @param bool $flag
     *
     * @return $this
     */
    public function distinct(
        bool $flag = true
    ): static
    {
        $this->doctrineQueryBuilder
            ->distinct($flag);

        return $this;
    }

    /**
     * Adds an item that is to be returned in the query result.
     *
     * <code>
     *     $qb = $em->createQueryBuilder()
     *         ->select('u')
     *         ->addSelect('p')
     *         ->from('User', 'u')
     *         ->leftJoin('u.Phonenumbers', 'p');
     * </code>
     *
     * @param mixed $select The selection expression.
     *
     * @return $this
     */
    public function addSelect(
        mixed $select = null
    ): static
    {
        $this->doctrineQueryBuilder
            ->addSelect($select);

        return $this;
    }

    /**
     * Creates and adds a query root corresponding to the entity identified by the given alias,
     * forming a cartesian product with any existing query roots.
     *
     * <code>
     *     $qb = $em->createQueryBuilder()
     *         ->select('u')
     *         ->from('User', 'u');
     * </code>
     *
     * @param string      $from    The class name.
     * @param string      $alias   The alias of the class.
     * @param string|null $indexBy The index for the from.
     *
     * @return $this
     */
    public function from(
        string $from, 
        string $alias, 
        ?string $indexBy = null
    ): static
    {
        $this->doctrineQueryBuilder
            ->from($from, $alias, $indexBy);

        return $this;
    }

    /**
     * Updates a query root corresponding to an entity setting its index by. This method is intended to be used with
     * EntityRepository->createQueryBuilder(), which creates the initial FROM clause and do not allow you to update it
     * setting an index by.
     *
     * <code>
     *     $qb = $userRepository->createQueryBuilder('u')
     *         ->indexBy('u', 'u.id');
     *
     *     // Is equivalent to...
     *
     *     $qb = $em->createQueryBuilder()
     *         ->select('u')
     *         ->from('User', 'u', 'u.id');
     * </code>
     *
     * @param string $alias   The root alias of the class.
     * @param string $indexBy The index for the from.
     *
     * @return $this
     *
     * @throws QueryException
     */
    public function indexBy(
        string $alias, 
        string $indexBy
    ): static
    {
        $this->doctrineQueryBuilder
            ->indexBy($alias, $indexBy);

        return $this;
    }

    /**
     * Creates and adds a join over an entity association to the query.
     *
     * The entities in the joined association will be fetched as part of the query
     * result if the alias used for the joined association is placed in the select
     * expressions.
     *
     * <code>
     *     $qb = $em->createQueryBuilder()
     *         ->select('u')
     *         ->from('User', 'u')
     *         ->join('u.Phonenumbers', 'p', \Doctrine\ORM\Query\Expr\Join::WITH, 'p.is_primary = 1');
     * </code>
     *
     * @param string                                     $join          The relationship to join.
     * @param string                                     $alias         The alias of the join.
     * @param string|null                                $conditionType The condition type constant. Either ON or WITH.
     * @param string|Comparison|Composite|null $condition     The condition for the join.
     * @param string|null                                $indexBy       The index for the join.
     * @psalm-param \Doctrine\ORM\Query\Expr\Join::ON|\Doctrine\ORM\Query\Expr\Join::WITH|null $conditionType
     *
     * @return $this
     */
    public function join(
        string $join, 
        string $alias, 
        ?string $conditionType = null, 
        string|Comparison|Composite|null $condition = null, 
        ?string $indexBy = null
    ): static
    {
        $this->doctrineQueryBuilder
            ->join($join, $alias, $conditionType, $condition, $indexBy);

        return $this;
    }

    /**
     * Creates and adds a join over an entity association to the query.
     *
     * The entities in the joined association will be fetched as part of the query
     * result if the alias used for the joined association is placed in the select
     * expressions.
     *
     *     [php]
     *     $qb = $em->createQueryBuilder()
     *         ->select('u')
     *         ->from('User', 'u')
     *         ->innerJoin('u.Phonenumbers', 'p', \Doctrine\ORM\Query\Expr\Join::WITH, 'p.is_primary = 1');
     *
     * @param string                                     $join          The relationship to join.
     * @param string                                     $alias         The alias of the join.
     * @param string|null                                $conditionType The condition type constant. Either ON or WITH.
     * @param string|Comparison|Composite|null $condition     The condition for the join.
     * @param string|null                                $indexBy       The index for the join.
     * @psalm-param \Doctrine\ORM\Query\Expr\Join::ON|\Doctrine\ORM\Query\Expr\Join::WITH|null $conditionType
     *
     * @return $this
     */
    public function innerJoin(
        string $join, 
        string $alias, 
        ?string $conditionType = null, 
        string|Comparison|Composite|null $condition = null, 
        ?string $indexBy = null
    ): static
    {
        $this->doctrineQueryBuilder
            ->innerJoin($join, $alias, $conditionType, $condition, $indexBy);

        return $this;
    }

    /**
     * Creates and adds a left join over an entity association to the query.
     *
     * The entities in the joined association will be fetched as part of the query
     * result if the alias used for the joined association is placed in the select
     * expressions.
     *
     * <code>
     *     $qb = $em->createQueryBuilder()
     *         ->select('u')
     *         ->from('User', 'u')
     *         ->leftJoin('u.Phonenumbers', 'p', \Doctrine\ORM\Query\Expr\Join::WITH, 'p.is_primary = 1');
     * </code>
     *
     * @param string                                     $join          The relationship to join.
     * @param string                                     $alias         The alias of the join.
     * @param string|null                                $conditionType The condition type constant. Either ON or WITH.
     * @param string|Comparison|Composite|null $condition     The condition for the join.
     * @param string|null                                $indexBy       The index for the join.
     * @psalm-param \Doctrine\ORM\Query\Expr\Join::ON|\Doctrine\ORM\Query\Expr\Join::WITH|null $conditionType
     *
     * @return $this
     */
    public function leftJoin(
        string $join, 
        string $alias, 
        ?string $conditionType = null, 
        string|Comparison|Composite|null $condition = null, 
        ?string $indexBy = null
    ): static
    {
        $this->doctrineQueryBuilder
            ->leftJoin($join, $alias, $conditionType, $condition, $indexBy);

        return $this;
    }

    /**
     * Specifies one or more restrictions to the query result.
     * Replaces any previously specified restrictions, if any.
     *
     * <code>
     *     $qb = $em->createQueryBuilder()
     *         ->select('u')
     *         ->from('User', 'u')
     *         ->where('u.id = ?');
     *
     *     // You can optionally programmatically build and/or expressions
     *     $qb = $em->createQueryBuilder();
     *
     *     $or = $qb->expr()->orX();
     *     $or->add($qb->expr()->eq('u.id', 1));
     *     $or->add($qb->expr()->eq('u.id', 2));
     *
     *     $qb->update('User', 'u')
     *         ->set('u.password', '?')
     *         ->where($or);
     * </code>
     *
     * @param mixed $predicates The restriction predicates.
     *
     * @return $this
     */
    public function where(
        mixed $predicates
    ): static
    {
        $this->doctrineQueryBuilder
            ->where($predicates);

        return $this;
    }

    /**
     * Adds one or more restrictions to the query results, forming a logical
     * conjunction with any previously specified restrictions.
     *
     * <code>
     *     $qb = $em->createQueryBuilder()
     *         ->select('u')
     *         ->from('User', 'u')
     *         ->where('u.username LIKE ?')
     *         ->andWhere('u.is_active = 1');
     * </code>
     *
     * @see where()
     *
     * @param mixed $where The query restrictions.
     *
     * @return $this
     */
    public function andWhere(
        mixed $where
    ): static
    {
        $this->doctrineQueryBuilder
            ->andWhere($where);

        return $this;
    }

    /**
     * Adds one or more restrictions to the query results, forming a logical
     * disjunction with any previously specified restrictions.
     *
     * <code>
     *     $qb = $em->createQueryBuilder()
     *         ->select('u')
     *         ->from('User', 'u')
     *         ->where('u.id = 1')
     *         ->orWhere('u.id = 2');
     * </code>
     *
     * @see where()
     *
     * @param mixed $where The WHERE statement.
     *
     * @return $this
     */
    public function orWhere(
        mixed $where
    ): static
    {
        $this->doctrineQueryBuilder
            ->orWhere($where);

        return $this;
    }

    /**
     * Specifies a grouping over the results of the query.
     * Replaces any previously specified groupings, if any.
     *
     * <code>
     *     $qb = $em->createQueryBuilder()
     *         ->select('u')
     *         ->from('User', 'u')
     *         ->groupBy('u.id');
     * </code>
     *
     * @param string $groupBy The grouping expression.
     *
     * @return $this
     */
    public function groupBy(
        string $groupBy
    ): static
    {
        $this->doctrineQueryBuilder
            ->groupBy($groupBy);

        return $this;
    }

    /**
     * Adds a grouping expression to the query.
     *
     * <code>
     *     $qb = $em->createQueryBuilder()
     *         ->select('u')
     *         ->from('User', 'u')
     *         ->groupBy('u.lastLogin')
     *         ->addGroupBy('u.createdAt');
     * </code>
     *
     * @param string $groupBy The grouping expression.
     *
     * @return $this
     */
    public function addGroupBy(
        string $groupBy
    ): static
    {
        $this->doctrineQueryBuilder
            ->addGroupBy($groupBy);

        return $this;
    }

    /**
     * Specifies a restriction over the groups of the query.
     * Replaces any previous having restrictions, if any.
     *
     * @param mixed $having The restriction over the groups.
     *
     * @return $this
     */
    public function having(
        mixed $having
    ): static
    {
        $this->doctrineQueryBuilder
            ->having($having);

        return $this;
    }

    /**
     * Adds a restriction over the groups of the query, forming a logical
     * conjunction with any existing having restrictions.
     *
     * @param mixed $having The restriction to append.
     *
     * @return $this
     */
    public function andHaving(
        mixed $having
    ): static
    {
        $this->doctrineQueryBuilder
            ->andHaving($having);

        return $this;
    }

    /**
     * Adds a restriction over the groups of the query, forming a logical
     * disjunction with any existing having restrictions.
     *
     * @param mixed $having The restriction to add.
     *
     * @return $this
     */
    public function orHaving(
        mixed $having
    ): static
    {
        $this->doctrineQueryBuilder
            ->orHaving($having);

        return $this;
    }

    /**
     * Specifies an ordering for the query results.
     * Replaces any previously specified orderings, if any.
     *
     * @param string|OrderBy $sort  The ordering expression.
     * @param string|null         $order The ordering direction.
     *
     * @return $this
     */
    public function orderBy(
        string|OrderBy $sort, 
        ?string $order = null
    ): static
    {
        $this->doctrineQueryBuilder
            ->orderBy($sort, $order);

        return $this;
    }

    /**
     * Adds an ordering to the query results.
     *
     * @param string|OrderBy $sort  The ordering expression.
     * @param string|null         $order The ordering direction.
     *
     * @return $this
     */
    public function addOrderBy(
        string|OrderBy $sort, 
        ?string $order = null
    ): static
    {
        $this->doctrineQueryBuilder
            ->addOrderBy($sort, $order);

        return $this;
    }

    /**
     * Adds criteria to the query.
     *
     * Adds where expressions with AND operator.
     * Adds orderings.
     * Overrides firstResult and maxResults if they're set.
     *
     * @return $this
     *
     * @throws QueryException
     */
    public function addCriteria(
        Criteria $criteria
    ): static
    {
        $this->doctrineQueryBuilder
            ->addCriteria($criteria);

        return $this;
    }
}