<?php

namespace Zus1\LaravelBaseRepository\Controllers;

use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Zus1\LaravelBaseRepository\Repository\LaravelBaseRepository;

class BaseCollectionController
{
    protected array $collectionRelations = [];

    public  function __construct(
        private LaravelBaseRepository $repository,
    ){
    }

    protected function retrieveCollection(Request $request): LengthAwarePaginator
    {
        if($this->collectionRelations !== []) {
            $this->repository->setCollectionRelations($this->collectionRelations);
        }

        return $this->repository->getCollection(
            $request->query('filters',[]),
            $request->query('per_page', config('laravel-base-repository.pagination.default_per_page')),
            $request->query('order_by', config('laravel-base-repository.order_by.default_field')),
            $request->query('order_direction', config('laravel-base-repository.order_by.default_direction')),
        );
    }

    protected function addCollectionRelation(array $relation): void
    {
        $this->collectionRelations[] = $relation;
    }
}
