<?php

/*
 * This file is part of the API Platform project.
 *
 * (c) Kévin Dunglas <dunglas@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace ApiPlatform\Core\Bridge\Doctrine\Orm\Util;

use Doctrine\Common\Persistence\ManagerRegistry;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;

/**
 * Utility functions for working with Doctrine ORM query.
 *
 * @author Teoh Han Hui <teohhanhui@gmail.com>
 * @author Vincent Chalamon <vincentchalamon@gmail.com>
 *
 * @internal
 */
final class QueryChecker
{
    private function __construct()
    {
    }

    /**
     * Determines whether the QueryBuilder uses a HAVING clause.
     */
    public static function hasHavingClause(QueryBuilder $queryBuilder): bool
    {
        return null !== $queryBuilder->getDQLPart('having');
    }

    /**
     * Determines whether the QueryBuilder has any root entity with foreign key identifier.
     */
    public static function hasRootEntityWithForeignKeyIdentifier(QueryBuilder $queryBuilder, ManagerRegistry $managerRegistry): bool
    {
        foreach ($queryBuilder->getRootEntities() as $rootEntity) {
            /** @var ClassMetadata $rootMetadata */
            $rootMetadata = $managerRegistry
                ->getManagerForClass($rootEntity)
                ->getClassMetadata($rootEntity);

            if ($rootMetadata->containsForeignIdentifier) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determines whether the QueryBuilder has any composite identifier.
     */
    public static function hasRootEntityWithCompositeIdentifier(QueryBuilder $queryBuilder, ManagerRegistry $managerRegistry): bool
    {
        foreach ($queryBuilder->getRootEntities() as $rootEntity) {
            /** @var ClassMetadata $rootMetadata */
            $rootMetadata = $managerRegistry
                ->getManagerForClass($rootEntity)
                ->getClassMetadata($rootEntity);

            if ($rootMetadata->isIdentifierComposite) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determines whether the QueryBuilder has a limit on the maximum number of results.
     */
    public static function hasMaxResults(QueryBuilder $queryBuilder): bool
    {
        return null !== $queryBuilder->getMaxResults();
    }

    /**
     * Determines whether the QueryBuilder has ORDER BY on a column from a fetch joined to-many association.
     */
    public static function hasOrderByOnFetchJoinedToManyAssociation(QueryBuilder $queryBuilder, ManagerRegistry $managerRegistry): bool
    {
        if (
            empty($orderByParts = $queryBuilder->getDQLPart('orderBy')) ||
            empty($joinParts = $queryBuilder->getDQLPart('join'))
        ) {
            return false;
        }

        $orderByAliases = [];
        foreach ($orderByParts as $orderBy) {
            $parts = $orderBy->getParts();

            foreach ($parts as $part) {
                $pos = strpos($part, '.');
                if (false !== $pos) {
                    $orderByAliases[substr($part, 0, $pos)] = true;
                }
            }
        }

        if (!$orderByAliases) {
            return false;
        }

        foreach ($joinParts as $joins) {
            foreach ($joins as $join) {
                $alias = $join->getAlias();

                if (!isset($orderByAliases[$alias])) {
                    continue;
                }
                $relationship = $join->getJoin();

                if (false !== strpos($relationship, '.')) {
                    /*
                     * We select the parent alias because it may differ from the origin alias given above
                     * @see https://github.com/api-platform/core/issues/1313
                     */
                    [$relationAlias, $association] = explode('.', $relationship);
                    $metadata = QueryJoinParser::getClassMetadataFromJoinAlias($relationAlias, $queryBuilder, $managerRegistry);
                    if ($metadata->isCollectionValuedAssociation($association)) {
                        return true;
                    }
                } else {
                    $parentMetadata = $managerRegistry->getManagerForClass($relationship)->getClassMetadata($relationship);

                    foreach ($queryBuilder->getRootEntities() as $rootEntity) {
                        $rootMetadata = $managerRegistry
                            ->getManagerForClass($rootEntity)
                            ->getClassMetadata($rootEntity);

                        if (!$rootMetadata instanceof ClassMetadata) {
                            continue;
                        }

                        foreach ($rootMetadata->getAssociationsByTargetClass($relationship) as $association => $mapping) {
                            if ($parentMetadata->isCollectionValuedAssociation($association)) {
                                return true;
                            }
                        }
                    }
                }
            }
        }

        return false;
    }

    /**
     * Determines whether the QueryBuilder has ORDER BY on a column from a fetch joined to-many association.
     *
     * @deprecated
     */
    public static function hasOrderByOnToManyJoin(QueryBuilder $queryBuilder, ManagerRegistry $managerRegistry): bool
    {
        @trigger_error(sprintf('The use of "%s::hasOrderByOnToManyJoin()" is deprecated since 2.4 and will be removed in 3.0. Use "%1$s::hasOrderByOnFetchJoinedToManyAssociation()" instead.', __CLASS__), E_USER_DEPRECATED);

        return self::hasOrderByOnFetchJoinedToManyAssociation($queryBuilder, $managerRegistry);
    }

    /**
     * Determines whether the QueryBuilder already has a left join.
     */
    public static function hasLeftJoin(QueryBuilder $queryBuilder): bool
    {
        foreach ($queryBuilder->getDQLPart('join') as $joins) {
            foreach ($joins as $join) {
                if (Join::LEFT_JOIN === $join->getJoinType()) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Determines whether the QueryBuilder has a joined to-many association.
     */
    public static function hasJoinedToManyAssociation(QueryBuilder $queryBuilder, ManagerRegistry $managerRegistry): bool
    {
        if (
            0 === \count($queryBuilder->getDQLPart('join'))
        ) {
            return false;
        }

        $joinAliases = array_diff($queryBuilder->getAllAliases(), $queryBuilder->getRootAliases());
        if (0 === \count($joinAliases)) {
            return false;
        }

        foreach ($joinAliases as $joinAlias) {
            foreach (QueryBuilderHelper::traverseJoins($joinAlias, $queryBuilder, $managerRegistry) as $alias => [$metadata, $association]) {
                if (null !== $association && $metadata->isCollectionValuedAssociation($association)) {
                    return true;
                }
            }
        }

        return false;
    }
}
