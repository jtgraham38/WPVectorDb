<?php

namespace jtgraham38\wpvectordb\query;

use jtgraham38\wpvectordb\query\parts\Filter;
use jtgraham38\wpvectordb\query\parts\Sort;
class QueryBuilder{
    public array $filters;
    public array $sorts;
    public string $post_table_sql_id;
    public string $post_meta_table_sql_id;
    public function __construct($post_table_sql_id = 'p', $post_meta_table_sql_id = 'pm'){
        //filters will be an array of arrays of Filter objects
        //each subarray will be an array of Filter objects that are joined by OR
        //the subarrays will be joined by AND
        $this->filters = [];
        $this->post_table_sql_id = $post_table_sql_id;
        $this->post_meta_table_sql_id = $post_meta_table_sql_id;
    }

    /**
     * Add a filter group to the query builder.
     * 
     * @param string $key The key for the filter group.
     */
    public function add_filter_group(string $key){
        $this->filters[$key] = [];
    }

    /**
     * Add a filter to the query builder.
     * 
     * @param string $group_key The key for the filter group.
     * @param array $filter_data The filter data.
     */
    public function add_filter($group_key, array $filter_data){
        //ensure the filter data contains a field_name, operator, and compare_value
        if (!isset($filter_data['field_name']) || !isset($filter_data['operator']) || !isset($filter_data['compare_value'])){
            throw new \Exception('Filter data must contain a field_name, operator, and compare_value');
        }

        //merge the default values with the filter data, with the passed in filter data taking precedence
        $filter_data = array_merge([
            'is_meta_filter' => false
        ], $filter_data);

        $this->filters[$group_key][] = new Filter($filter_data['field_name'], $filter_data['operator'], $filter_data['compare_value'], $filter_data['is_meta_filter']);
    }

    /**
     * Add a sort to the query builder.
     * 
     * @param array $sort_data The sort data.
     */
    public function add_sort(array $sort_data){
        $this->sorts[] = new Sort($sort_data['field_name'], $sort_data['direction'], $sort_data['is_meta_sort'], $sort_data['meta_type']);
    }

    /**
     * Check if the query builder has filters.
     * 
     * @return bool True if the query builder has filters, false otherwise.
     */
    public function has_filters(){
        $has_filter = false;
        foreach ($this->filters as $group_key => $group_filters){
            if (count($group_filters) > 0){
                $has_filter = true;
                break;
            }
        }
        return $has_filter;
    }

    /**
     * Check if the query builder has sorts.
     * 
     * @return bool True if the query builder has sorts, false otherwise.
     */
    public function has_sorts(){
        return count($this->sorts) > 0;
    }

    /**
     * Get the filters from the query builder.
     * 
     * @return array The filters.
     */
    public function get_filters(){
        return $this->filters;
    }

    /**
     * Get the sorts from the query builder.
     * 
     * @return array The sorts.
     */
    public function get_sorts(){
        return $this->sorts;
    }

    /**
     * Get the filters SQL from the query builder.
     * 
     * @return string The filters SQL.
     */
    public function get_filters_sql(){
        $sql = [];
        foreach ($this->filters as $group_key => $group_filters){
            $sql[] = "(" . implode(" OR ", array_map(function($filter){
                return $filter->to_sql($this->post_table_sql_id, $this->post_meta_table_sql_id);
            }, $group_filters)) . ")";
        }
        return implode(" AND ", $sql);
    }

    /**
     * Get the sorts SQL from the query builder.
     * 
     * @return string The sorts SQL.
     */
    public function get_sorts_sql(){
        $sql = [];
        foreach ($this->sorts as $i => $sort){
            $sql[] = $sort->to_sql($this->post_table_sql_id, $this->post_meta_table_sql_id);
        }
        return implode(", ", $sql);
    }
}