<?php

namespace jtgraham38\wpvectordb\query;

use jtgraham38\wpvectordb\query\parts\Filter;

class QueryBuilder{
    public array $filters;
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

    public function add_filter_group(string $key){
        $this->filters[$key] = [];
    }

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
    public function get_filters(){
        return $this->filters;
    }

    public function to_sql(){
        $sql = [];
        foreach ($this->filters as $group_key => $group_filters){
            $sql[] = "(" . implode(" OR ", array_map(function($filter){
                return $filter->to_sql($this->post_table_sql_id, $this->post_meta_table_sql_id);
            }, $group_filters)) . ")";
        }
        return implode(" AND ", $sql);
    }
}