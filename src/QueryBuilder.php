<?php

namespace Spatie\QueryBuilder;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Spatie\QueryBuilder\Exceptions\InvalidSortQuery;
use Spatie\QueryBuilder\Exceptions\InvalidFieldQuery;
use Spatie\QueryBuilder\Exceptions\InvalidAppendQuery;
use Spatie\QueryBuilder\Exceptions\InvalidFilterQuery;
use Spatie\QueryBuilder\Exceptions\InvalidIncludeQuery;

class QueryBuilder extends Builder
{
    /** @var \Illuminate\Support\Collection */
    protected $allowedFilters;

    /** @var \Illuminate\Support\Collection */
    protected $allowedFields;

    /** @var string|null */
    protected $defaultSort;

    /** @var \Illuminate\Support\Collection */
    protected $allowedSorts;

    /** @var \Illuminate\Support\Collection */
    protected $allowedIncludes;

    /** @var \Illuminate\Support\Collection */
    protected $allowedAppends;

    /** @var \Illuminate\Support\Collection */
    protected $fields;

    /** @var array */
    protected $appends = [];

    /** @var \Illuminate\Http\Request */
    protected $request;

    public function __construct(Builder $builder, ? Request $request = null)
    {
        parent::__construct(clone $builder->getQuery());

        $this->initializeFromBuilder($builder);

        $this->request = $request ?? request();

        if (!empty($this->request->get('fields'))) {
            $this->parseSelectedFields();
        }

        if (!empty($this->request->get('sort'))) {
            $this->allowedSorts('*');
        }
    }

    /**
     * Add the model, scopes, eager loaded relationships, local macro's and onDelete callback
     * from the $builder to this query builder.
     *
     * @param \Illuminate\Database\Eloquent\Builder $builder
     */
    protected function initializeFromBuilder(Builder $builder)
    {
        $this->setModel($builder->getModel())
            ->setEagerLoads($builder->getEagerLoads());

        $builder->macro('getProtected', function (Builder $builder, string $property) {
            return $builder->{$property};
        });

        $this->scopes = $builder->getProtected('scopes');

        $this->localMacros = $builder->getProtected('localMacros');

        $this->onDelete = $builder->getProtected('onDelete');
    }

    /**
     * Create a new QueryBuilder for a request and model.
     *
     * @param string|\Illuminate\Database\Query\Builder $baseQuery Model class or base query builder
     * @param Request $request
     *
     * @return \Spatie\QueryBuilder\QueryBuilder
     */
    public static function for($baseQuery, ? Request $request = null) : self
    {
        if (is_string($baseQuery)) {
            $baseQuery = ($baseQuery)::query();
        }

        return new static($baseQuery, $request ?? request());
    }

    public function allowedFilters($filters) : self
    {
        $filters = is_array($filters) ? $filters : func_get_args();
        $this->allowedFilters = collect($filters)->map(function ($filter) {
            if ($filter instanceof Filter) {
                return $filter;
            }

            return Filter::partial($filter);
        });

        $this->guardAgainstUnknownFilters();

        $this->addFiltersToQuery(new Collection($this->request->get('filter')));

        return $this;
    }

    public function allowedFields($fields) : self
    {
        $fields = is_array($fields) ? $fields : func_get_args();

        $this->allowedFields = collect($fields)
            ->map(function (string $fieldName) {
                if (! Str::contains($fieldName, '.')) {
                    $modelTableName = $this->getModel()->getTable();

                    return "{$modelTableName}.{$fieldName}";
                }

                return $fieldName;
            });

//        if (! $this->allowedFields->contains('*')) {
//            $this->guardAgainstUnknownFields();
//        }

        return $this;
    }

    public function defaultSort($sort) : self
    {
        $this->defaultSort = $sort;

//         $this->addSortsToQuery($this->request->sorts($this->defaultSort));

        return $this;
    }

    public function allowedSorts($sorts) : self
    {
        $sorts = is_array($sorts) ? $sorts : func_get_args();
         if (! $this->request->get('sort')) {
             return $this;
        }

        $this->allowedSorts = collect($sorts);

        if (! $this->allowedSorts->contains('*')) {
            $this->guardAgainstUnknownSorts();
        }

         $this->addSortsToQuery(new Collection($this->request->get('sort') ? explode(',', $this->request->get('sort')) : null));

        return $this;
    }

    public function allowedIncludes($includes) : self
    {
        $includes = is_array($includes) ? $includes : func_get_args();

        $this->allowedIncludes = collect($includes)
            ->flatMap(function ($include) {
                return collect(explode('.', $include))
                    ->reduce(function ($collection, $include) {
                        if ($collection->isEmpty()) {
                            return $collection->push($include);
                        }

                        return $collection->push("{$collection->last()}.{$include}");
                    }, collect());
            });

        $this->guardAgainstUnknownIncludes();

        $this->addIncludesToQuery(new Collection($this->request->get('include') ? explode(',', $this->request->get('include')) : $this->request->get('include')));

        return $this;
    }

    public function allowedAppends($appends) : self
    {
        $appends = is_array($appends) ? $appends : func_get_args();

        $this->allowedAppends = collect($appends);

        $this->guardAgainstUnknownAppends();

        $this->appends = $this->request->appends();

        return $this;
    }

    protected function parseSelectedFields()
    {
        $fields = $this->request->get('fields');


        foreach ($fields as $key => $value) {
            $newFields[$key] = explode(',', $value);
        }
        
        $this->fields = new Collection($newFields);

        $modelTableName = $this->getModel()->getTable();
        $modelFields = $this->fields->get($modelTableName, ['*']);

        $this->select($this->prependFieldsWithTableName($modelFields, $modelTableName));
    }

    protected function prependFieldsWithTableName(array $fields, string $tableName): array
    {
        return array_map(function ($field) use ($tableName) {
            return "{$tableName}.{$field}";
        }, $fields);
    }

    protected function getFieldsForRelatedTable(string $relation): array
    {
        if (! $this->fields) {
            return ['*'];
        }

        return $this->fields->get($relation, []);
    }

    protected function addFiltersToQuery(Collection $filters)
    {
        $filters->each(function ($value, $property) {
            $filter = $this->findFilter($property);

            $filter->filter($this, $value);
        });
    }

    protected function findFilter(string $property) : ? Filter
    {
        return $this->allowedFilters
            ->first(function (Filter $filter) use ($property) {
                return $filter->isForProperty($property);
            });
    }

    protected function addSortsToQuery(Collection $sorts)
    {
        $this->filterDuplicates($sorts)
            ->each(function (string $sort) {
                $descending = $sort[0] === '-';

                $key = ltrim($sort, '-');

                $this->orderBy($key, $descending ? 'desc' : 'asc');
            });
    }

    protected function filterDuplicates(Collection $sorts): Collection
    {
        if (! is_array($orders = $this->getQuery()->orders)) {
            return $sorts;
        }

        return $sorts->reject(function (string $sort) use ($orders) {
            $toSort = [
                'column' => ltrim($sort, '-'),
                'direction' => ($sort[0] === '-') ? 'desc' : 'asc',
            ];
            foreach ($orders as $order) {
                if ($order === $toSort) {
                    return true;
                }
            }
        });
    }

    protected function addIncludesToQuery(Collection $includes)
    {
        $includes
            ->map(function (string $include) {
                return Str::camel($include);
            })
            ->map(function (string $include) {
                return collect(explode('.', $include));
            })
            ->flatMap(function (Collection $relatedTables) {
                return $relatedTables
                    ->mapWithKeys(function ($table, $key) use ($relatedTables) {
                        $fields = $this->getFieldsForRelatedTable(Str::snake($table));
                        $fullRelationName = $relatedTables->slice(0, $key + 1)->implode('.');

                        if (empty($fields)) {
                            return [$fullRelationName];
                        }

                        return [$fullRelationName => function ($query) use ($fields) {
                            $query->select($this->prependFieldsWithTableName($fields, $query->getModel()->getTable()));
                        }];
                    });
            })
            ->pipe(function (Collection $withs) {
                $this->with($withs->all());
            });
    }

    public function setAppendsToResult($result)
    {
        $result->map(function ($item) {
            $item->append($this->appends->toArray());

            return $item;
        });

        return $result;
    }

    protected function guardAgainstUnknownFilters()
    {
        $filterNames = (new Collection($this->request->get('filter')))->keys();

        $allowedFilterNames = $this->allowedFilters->map->getProperty();

        $diff = $filterNames->diff($allowedFilterNames);

        if ($diff->count()) {
            throw InvalidFilterQuery::filtersNotAllowed($diff, $allowedFilterNames);
        }
    }

    protected function guardAgainstUnknownFields()
    {
        $fields = (new Collection($this->request->get('fields')))
            ->map(function ($fields, $model) {
                $tableName = Str::snake(preg_replace('/-/', '_', $model));

                $fields = array_map('snake_case', $fields);

                return $this->prependFieldsWithTableName($fields, $tableName);
            })
            ->flatten()
            ->unique();

        $diff = $fields->diff($this->allowedFields);

        if ($diff->count()) {
            throw InvalidFieldQuery::fieldsNotAllowed($diff, $this->allowedFields);
        }
    }

    protected function guardAgainstUnknownSorts()
    {
        $sorts = (new Collection($this->request->get('sort') ? explode(',', $this->request->get('sort')) : null))->map(function ($sort) {
            return ltrim($sort, '-');
        });

        $diff = $sorts->diff($this->allowedSorts);

        if ($diff->count()) {
            throw InvalidSortQuery::sortsNotAllowed($diff, $this->allowedSorts);
        }
    }

    protected function guardAgainstUnknownIncludes()
    {
        $includes = new Collection($this->request->get('include') ? explode(',', $this->request->get('include')) : null);

        $diff = $includes->diff($this->allowedIncludes);

        if ($diff->count()) {
            throw InvalidIncludeQuery::includesNotAllowed($diff, $this->allowedIncludes);
        }
    }

    protected function guardAgainstUnknownAppends()
    {
        $appends = $this->request->appends();

        $diff = $appends->diff($this->allowedAppends);

        if ($diff->count()) {
            throw InvalidAppendQuery::appendsNotAllowed($diff, $this->allowedAppends);
        }
    }

    public function get($columns = ['*'])
    {
        $result = parent::get($columns);

        if (count($this->appends) > 0) {
            $result = $this->setAppendsToResult($result);
        }

        return $result;
    }
}
