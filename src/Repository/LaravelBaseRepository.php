<?php

namespace Zus1\LaravelBaseRepository\Repository;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class LaravelBaseRepository
{
    protected const MODEL = '';

    protected array $collectionRelations = [];

    public function setCollectionRelations(array $collectionRelations): self
    {
        $this->collectionRelations = $collectionRelations;

        return $this;
    }

    public function getCollection(
        string $query,
        array $filters,
        int $recodesPerPage,
        string $orderBy,
        string $orderDirection,
    ): LengthAwarePaginator {
        $builder = $this->getBuilder();

        if($this->collectionRelations !== []) {
            foreach ($this->collectionRelations as $relation)
            $builder->orWhereRelation(
                $relation['relation'],
                $relation['field'],
                $relation['value']
            );
        }

        $this->applySearch($builder, $query);
        $this->applyFilters($builder, $filters);
        $this->applyOrderBy($builder, $orderBy, $orderDirection);

        return $builder->paginate($recodesPerPage);
    }

    public function saveResource(Model $model, string $resourceName, string $attribute): Model
    {
        $model->setAttribute($attribute, $resourceName);

        $model->save();

        return $model;
    }

    public function findBy(array $params): Collection
    {
        $builder = $this->getBuilder();

        foreach($params as $attribute => $value) {
            $builder->where($attribute, $value);
        }

        return $builder->get();
    }

    public function findByOr404(array $params): Collection
    {
        $collection = $this->findBy($params);

        if($collection->isEmpty() === true) {
            throw new HttpException(404, 'Models not found');
        }

        return $collection;
    }

    public function findOneBy(array $params): ?Model
    {
        $results = $this->findBy($params);

        return $results->first();
    }

    public function findOneByOr404(array $params): Model
    {
        $model = $this->findOneBy($params);

        if($model === null) {
            throw new HttpException(404, 'Model not found');
        }

        return $model;
    }

    public function findAll(): Collection
    {
        $builder = $this->getBuilder();

        return $builder->get();
    }

    private function applySearch(Builder $builder, string $query): void
    {
        $model = $this->getModelInstance();

        if($query === '' || !method_exists($model, 'searchableFields')) {
            return;
        }

        $searchableFields = $model->searchableFields();

        [$modelFields, $relationFields] = $this->assignFields($builder, array_combine($searchableFields, $searchableFields));

        $this->applyModelSearch($builder, $modelFields, $query);
        $this->applyRelationSearch($builder, $relationFields, $query);
    }

    private function applyModelSearch(Builder $builder, array $modelFields, string $query)
    {
        foreach ($modelFields as $searchableField) {
            $builder->orWhere($searchableField, 'like', '%'.$query.'%');
        }
    }

    private function applyRelationSearch(Builder $builder, array $relationFields, string $query)
    {
        foreach ($relationFields as $searchableField) {
            [$relationship, $field] = explode('.', $searchableField);

            $builder->orWhereRelation($relationship, $field, 'like', '%'.$query.'%');
        }
    }


    private function applyFilters(Builder $builder, array $filters): void
    {
        if($filters === []) {
            return;
        }

        $this->sanitizeFilters($filters);

        [$singleFilters, $rangeFilters] = $this->assignFilters($filters);
        [$modelFilters, $relationFilters] = $this->assignFields($builder, $singleFilters);
        [$modelRangeFilters, $relationRangeFilters] = $this->assignFields($builder, $rangeFilters);

        $this->applyModelFilters($builder, $modelFilters, $modelRangeFilters);

        $this->applyRelationshipFilters($builder, $relationFilters, $relationRangeFilters);
    }

    private function sanitizeFilters(array &$filters): void
    {
        $filters = array_map(function ($filter) {
            if(in_array($filter, ['true', 'false'])) {
                return $filter === 'true';
            }

            return $filter;
        }, $filters);
    }

    private function assignFields(Builder $builder, array $filters): array
    {
        $model = $builder->getModel();
        $modelFilterKeys = array_intersect(array_keys($filters), $model->getConnection()->getSchemaBuilder()->getColumnListing($model->getTable()));

        $modelFilters = array_filter($filters, function ($key) use($modelFilterKeys) {
            return in_array($key, $modelFilterKeys);
        }, ARRAY_FILTER_USE_KEY);
        $relationFilters = array_diff_key($filters, $modelFilters);

        return [$modelFilters, $relationFilters];
    }

    private function assignFilters(array $filters): array
    {
        $singleFilters = [];
        $rangeFilters = [];

        array_walk($filters, function ($filter, $key) use (&$singleFilters, &$rangeFilters) {
            if(str_starts_with($key, 'range.')) {
                $rangeFilters[substr($key, strlen('range.'))] = $filter;
            } else {
                $singleFilters[$key] = $filter;
            }
        });

        return [$singleFilters, $rangeFilters];
    }

    private function applyModelFilters(Builder $builder, array $modelFilters, array $rangeModelFilters): void
    {
        foreach ($modelFilters as $attribute => $value) {
            $builder->where($attribute, $value);
        }
        foreach ($rangeModelFilters as $attribute => $value) {
            $builder->whereBetween($attribute, explode('$', $value));
        }
    }

    private function applyRelationshipFilters(Builder $builder, array $relationFilters, $rangeRelationFilters): void
    {
        if($relationFilters === [] && $rangeRelationFilters === []) {
            return;
        }

        $relationship = $relationFilters !== [] ?  $this->extractRelationshipFromRelationFilters($relationFilters) :
            $this->extractRelationshipFromRelationFilters($rangeRelationFilters);

        $builder->whereHas($relationship, function (Builder $builder) use($relationFilters, $rangeRelationFilters) {
            foreach ($relationFilters as $key => $value) {
                $attribute = explode('.', $key)[1];

                $builder->where($attribute, $value);
            }
            foreach ($rangeRelationFilters as $key => $value) {
                $attribute = explode('.', $key)[1];

                $builder->whereBetween($attribute, explode('$', $value));
            }
        });
    }

    private function extractRelationshipFromRelationFilters(array $relationFilters): string
    {
        return explode('.', array_keys($relationFilters)[0])[0];
    }

    private function applyOrderBy(Builder $builder, string $orderBy, string $orderDirection): void
    {
        $orderByArr = explode('.', $orderBy);

        if(count($orderByArr) === 1) {
            $builder->orderBy($orderBy, $orderDirection);

            return;
        }

        $wheres = $builder->getQuery()->wheres;
        $relationship = $this->isRelationshipSet($wheres);
        if($relationship === false) {
            $wheres = $this->mockWheres($builder, $orderByArr);
        }


        $this->applyJoin($builder, $wheres, $orderByArr, $orderDirection);
    }

    private function isRelationshipSet(array $wheres): bool
    {
        $relationship = false;
        array_walk($wheres, function ($value) use(&$relationship) {
            if($value['type'] === 'Exists') {
                $relationship = true;
            }
        });

        return $relationship;
    }

    private function mockWheres(Builder $builder, array $orderByArr): array
    {
        $cloneBuilder = $builder->clone();
        $cloneBuilder->whereHas($orderByArr[0]);

        return $cloneBuilder->getQuery()->wheres;
    }

    private function applyJoin(Builder $builder, array $wheres, array $orderByArr, string $orderDirection): void
    {
        /** @var \Illuminate\Database\Query\Builder $relationBuilder */
        $relationBuilder = array_values(array_filter($wheres, function (array $value) {
            return $value['type'] === 'Exists';
        }))[0]['query'];

        $relationAttributes = array_values(array_filter($relationBuilder->wheres, function (array $value) {
            return $value['type'] === 'Column';
        }))[0];

        $builder->join($relationBuilder->from, $relationAttributes['first'], $relationAttributes['second'])
            ->select(sprintf('%s.*', $this->getModelInstance()->getTable()))
            ->orderBy(sprintf('%s.%s', Str::plural($orderByArr[0]), $orderByArr[1]), $orderDirection);
    }

    protected function getModelInstance(): Model
    {
        /** @var Model $model $model */
        $model = new ($this::MODEL);

        return $model;
    }

    protected function getBuilder(): Builder
    {
        $model = $this->getModelInstance();
        return $model->newModelQuery();
    }
}
