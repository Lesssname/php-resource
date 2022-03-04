<?php
declare(strict_types=1);

namespace LessResource\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Query\QueryBuilder;
use JsonException;
use LessDatabase\Query\Builder\Applier\PaginateApplier;
use LessHydrator\Hydrator;
use LessResource\Model\ResourceModel;
use LessResource\Service\Dbal\Applier\ResourceApplier;
use LessResource\Service\Exception\AbstractNoResourceWithId;
use LessResource\Service\Exception\NoResourceFromBuilder;
use LessResource\Set\ArrayResourceSet;
use LessResource\Set\ResourceSet;
use LessValueObject\Composite\Paginate;
use LessValueObject\String\Format\Resource\Identifier;
use RuntimeException;

/**
 * @implements ResourceService<T>
 *
 * @template T of \LessResource\Model\ResourceModel
 */
abstract class AbstractDbalResourceService implements ResourceService
{
    abstract protected function getIdColumn(): string;

    abstract protected function getResourceApplier(): ResourceApplier;

    /**
     * @return class-string<T>
     */
    abstract protected function getResourceModelClass(): string;

    abstract protected function makeNoResourceWithIdException(Identifier $id): AbstractNoResourceWithId;

    public function __construct(
        protected Connection $connection,
        protected Hydrator $hydrator
    ) {}

    /**
     * @throws Exception
     */
    public function exists(Identifier $id): bool
    {
        $builder = $this->createBaseBuilder();
        $builder->select('count(*)');
        $this->applyWhereId($builder, $id);

        return $builder->fetchOne() > 0;
    }

    /**
     * @throws AbstractNoResourceWithId
     * @throws Exception
     */
    public function getWithId(Identifier $id): ResourceModel
    {
        $builder = $this->createResourceBuilder();
        $this->applyWhereId($builder, $id);

        try {
            return $this->getResourceFromBuilder($builder);
        } catch (NoResourceFromBuilder) {
            throw $this->makeNoResourceWithIdException($id);
        }
    }

    /**
     * @throws Exception
     */
    public function getByLastActivity(Paginate $paginate): ResourceSet
    {
        $builder = $this->connection->createQueryBuilder();

        $applier = $this->getResourceApplier();
        $applier->apply($builder);

        (new PaginateApplier($paginate))->apply($builder);

        $builder->addOrderBy("{$applier->getTableAlias()}.`activity_last`", 'desc');

        return $this->getResourceSetFromBuilder($builder);
    }

    /**
     * @throws AbstractNoResourceWithId
     * @throws Exception
     */
    public function getCurrentVersion(Identifier $id): int
    {
        $builder = $this->connection->createQueryBuilder();
        $builder->select('version');
        $this->applyWhereId($builder, $id);

        $applier = $this->getResourceApplier();
        $builder->from("`{$applier->getTableName()}`", $applier->getTableAlias());

        $result = $builder->fetchOne();
        assert(is_string($result) || $result === false);

        if ($result === false) {
            throw $this->makeNoResourceWithIdException($id);
        }

        return (int)$result;
    }

    /**
     * @return T
     *
     * @throws NoResourceFromBuilder
     * @throws Exception
     */
    protected function getResourceFromBuilder(QueryBuilder $builder): ResourceModel
    {
        $associative = $builder->fetchAssociative();

        if ($associative === false) {
            throw new NoResourceFromBuilder();
        }

        return $this->hydrateResource($associative);
    }

    /**
     * @return array<int, T>
     *
     * @throws Exception
     */
    protected function getResourcesFromBuilder(QueryBuilder $builder): array
    {
        return array_map(
            fn (array $associative) => $this->hydrateResource($associative),
            $builder->fetchAllAssociative(),
        );
    }

    /**
     * @return ResourceSet<T>
     *
     * @throws Exception
     */
    protected function getResourceSetFromBuilder(QueryBuilder $builder): ResourceSet
    {
        return new ArrayResourceSet(
            $this->getResourcesFromBuilder($builder),
            $this->getCountFromResultsBuilder($builder),
        );
    }

    /**
     * @throws Exception
     */
    protected function getCountFromResultsBuilder(QueryBuilder $builder): int
    {
        $countBuilder = clone $builder;

        $countBuilder->select("count(distinct {$this->getIdColumn()})");

        $countBuilder->resetQueryPart('orderBy');
        $countBuilder->resetQueryPart('distinct');
        $countBuilder->resetQueryPart('groupBy');
        $countBuilder->resetQueryPart('having');

        // Resets limit/offset
        $countBuilder->setMaxResults(1);
        $countBuilder->setFirstResult(0);

        return (int)$countBuilder->fetchOne();
    }

    protected function createResourceBuilder(): QueryBuilder
    {
        $builder = $this->connection->createQueryBuilder();
        $this->getResourceApplier()->apply($builder);

        return $builder;
    }

    protected function createBaseBuilder(): QueryBuilder
    {
        $builder = $this->connection->createQueryBuilder();

        $applier = $this->getResourceApplier();
        $builder->from("`{$applier->getTableName()}`", $applier->getTableAlias());

        return $builder;
    }

    /**
     * @param array<string, mixed> $array
     *
     * @return T
     *
     * @throws JsonException
     */
    protected function hydrateResource(array $array): ResourceModel
    {
        return $this->hydrator->hydrate(
            $this->getResourceModelClass(),
            $this->decode($array),
        );
    }

    /**
     * @param array<string, mixed> $array
     *
     * @return array<string, mixed>
     *
     * @throws JsonException
     *
     * @psalm-suppress MixedAssignment
     */
    protected function decode(array $array): array
    {
        foreach ($this->getJsonFields() as $field) {
            if (isset($array[$field]) && is_string($array[$field])) {
                $array[$field] = json_decode($array[$field], flags: JSON_THROW_ON_ERROR);
            }
        }

        return $this->unflatten($array);
    }

    /**
     * @return iterable<string>
     */
    protected function getJsonFields(): iterable
    {
        return [];
    }

    /**
     * @param array<string, mixed> $array
     *
     * @return array<string, mixed>
     *
     * @psalm-suppress MixedAssignment
     */
    protected function unflatten(array $array): array
    {
        $output = [];

        foreach ($array as $key => $value) {
            $keyParts = explode('.', $key);
            $keyCount = count($keyParts);
            $paste = &$output;

            foreach ($keyParts as $i => $keyPart) {
                if ($keyCount === $i + 1) {
                    if (array_key_exists($keyPart, $paste)) {
                        throw new RuntimeException();
                    }

                    $paste[$keyPart] = $value;
                } else {
                    if (!array_key_exists($keyPart, $paste)) {
                        $paste[$keyPart] = [];
                    } elseif (!is_array($paste[$keyPart])) {
                        throw new RuntimeException();
                    }

                    $paste = &$paste[$keyPart];
                }
            }
        }

        return $output;
    }

    protected function applyWhereId(QueryBuilder $builder, Identifier $id): void
    {
        $builder->andWhere($this->getIdColumn() . ' = :id');
        $builder->setParameter('id', $id);
    }
}