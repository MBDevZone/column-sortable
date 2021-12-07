<?php

namespace Kyslik\ColumnSortable;

use BadMethodCallException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Schema;
use Kyslik\ColumnSortable\Exceptions\ColumnSortableException;

/**
 * Sortable trait.
 */
trait Sortable
{
    /**
     * The table name of this sorting
     *
     * @var string
     */
    private $sortTableName;

    /**
     * @param \Illuminate\Database\Query\Builder $query
     * @param array|null                         $defaultParameters
     * @param string|null                        $tableName
     *
     * @return \Illuminate\Database\Query\Builder
     * @throws \Kyslik\ColumnSortable\Exceptions\ColumnSortableException
     */
    public function scopeSortable($query, $defaultParameters = null, $tableName = null)
    {
        if ($tableName !== null)
            $this->sortTableName = $tableName;

        if (request()->allFilled(['sort', 'direction'])) { // allFilled() is macro
            return $this->queryOrderBuilder($query, request()->only(['sort', 'direction', 'table']));
        }

        if (is_null($defaultParameters)) {
            $defaultParameters = $this->getDefaultSortable();
        }

        if ( ! is_null($defaultParameters)) {
            $defaultSortArray = $this->formatToParameters($defaultParameters);

            if (config('columnsortable.allow_request_modification', true) && ! empty($defaultSortArray)) {
                request()->merge($defaultSortArray);
            }

            return $this->queryOrderBuilder($query, $defaultSortArray);
        }

        return $query;
    }


    /**
     * Returns the first element of defined sortable columns from the Model
     *
     * @return array|null
     */
    private function getDefaultSortable()
    {
        if (config('columnsortable.default_first_column', false)) {
            $sortBy = Arr::first($this->sortable);
            if ( ! is_null($sortBy)) {
                return [$sortBy => config('columnsortable.default_direction', 'asc')];
            }
        }

        return null;
    }


    /**
     * @param \Illuminate\Database\Query\Builder $query
     * @param array                              $sortParameters
     *
     * @return \Illuminate\Database\Query\Builder
     *
     * @throws ColumnSortableException
     */
    private function queryOrderBuilder($query, array $sortParameters)
    {
        $model = $this;

        list($column, $direction, $table) = $this->parseParameters($sortParameters);

        if ($table !== null && $table !== $this->sortTableName) {
            return $query;
        }

        if (is_null($column)) {
            return $query;
        }

        $explodeResult = SortableLink::explodeSortParameter($column);
        if ( ! empty($explodeResult)) {
            $relationName = $explodeResult[0];
            $column       = $explodeResult[1];

            try {
                $relation = $query->getRelation($relationName);
                $query    = $this->queryJoinBuilder($query, $relation);
            } catch (BadMethodCallException $e) {
                throw new ColumnSortableException($relationName, 1, $e);
            } catch (\Exception $e) {
                throw new ColumnSortableException($relationName, 2, $e);
            }

            $model = $relation->getRelated();
        }

        if (method_exists($model, Str::camel($column).'Sortable')) {
            return call_user_func_array([$model, Str::camel($column).'Sortable'], [$query, $direction]);
        }

        if (isset($model->sortableAs) && in_array($column, $model->sortableAs)) {
            $query = $query->orderBy($column, $direction);
        } elseif ($this->columnExists($model, $column)) {
            $column = $model->getTable().'.'.$column;
            $query  = $query->orderBy($column, $direction);
        }

        return $query;
    }


    /**
     * @param array $parameters
     *
     * @return array
     */
    private function parseParameters(array $parameters)
    {
        $column = Arr::get($parameters, 'sort');
        if (empty($column)) {
            return [null, null, null];
        }

        $direction = Arr::get($parameters, 'direction', []);
        if ( ! in_array(strtolower($direction), ['asc', 'desc'])) {
            $direction = config('columnsortable.default_direction', 'asc');
        }

        $table = Arr::get($parameters, 'table', null);
        if ($table !== null && (!is_string($table) || strlen($table) > 30))
            $table = null;

        return [$column, $direction, $table];
    }


    /**
     * @param \Illuminate\Database\Query\Builder $query
     * @param \Illuminate\Database\Eloquent\Relations\BelongsTo|\Illuminate\Database\Eloquent\Relations\HasOne $relation
     *
     * @return \Illuminate\Database\Query\Builder
     *
     * @throws \Exception
     */
    private function queryJoinBuilder($query, $relation)
    {
        $relatedTable = $relation->getRelated()->getTable();
        $parentTable  = $relation->getParent()->getTable();

        if ($parentTable === $relatedTable) {
            $query       = $query->from($parentTable.' as parent_'.$parentTable);
            $parentTable = 'parent_'.$parentTable;
            $relation->getParent()->setTable($parentTable);
        }

        if ($relation instanceof HasOne) {
            $relatedPrimaryKey = $relation->getQualifiedForeignKeyName();
            $parentPrimaryKey  = $relation->getQualifiedParentKeyName();
        } elseif ($relation instanceof BelongsTo) {
            $relatedPrimaryKey = $relation->getQualifiedOwnerKeyName();
            $parentPrimaryKey  = $relation->getQualifiedForeignKeyName();
        } else {
            throw new \Exception();
        }

        return $this->formJoin($query, $parentTable, $relatedTable, $parentPrimaryKey, $relatedPrimaryKey);
    }


    /**
     * @param $model
     * @param $column
     *
     * @return bool
     */
    private function columnExists($model, $column)
    {
        return (isset($model->sortable)) ? in_array($column, $model->sortable) :
            Schema::connection($model->getConnectionName())->hasColumn($model->getTable(), $column);
    }


    /**
     * @param array|string $array
     *
     * @return array
     */
    private function formatToParameters($array)
    {
        if (empty($array)) {
            return [];
        }

        $defaultDirection = config('columnsortable.default_direction', 'asc');

        if (is_string($array)) {
            return ['sort' => $array, 'direction' => $defaultDirection];
        }

        return ['sort' => $array['sort'], 'direction' => $array['direction']];
        /*return (key($array) === 0) ? ['sort' => $array[0], 'direction' => $defaultDirection] : [
            'sort'      => key($array),
            'direction' => reset($array),
        ];*/
    }


    /**
     * @param $query
     * @param $parentTable
     * @param $relatedTable
     * @param $parentPrimaryKey
     * @param $relatedPrimaryKey
     *
     * @return mixed
     */
    private function formJoin($query, $parentTable, $relatedTable, $parentPrimaryKey, $relatedPrimaryKey)
    {
        $joinType = config('columnsortable.join_type', 'leftJoin');

        return $query->select($parentTable.'.*')->{$joinType}($relatedTable, $parentPrimaryKey, '=', $relatedPrimaryKey);
    }
}
