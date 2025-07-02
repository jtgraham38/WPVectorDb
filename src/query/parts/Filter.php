<?php

namespace jtgraham38\wpvectordb\query\parts;

class Filter{
    public string $field_name;
    public string $operator;
    public $compare_value;
    public bool $is_meta_filter;

    public function __construct($field_name, $operator, $compare_value, $is_meta_filter=false){
        $this->field_name = $field_name;
        $this->compare_value = $compare_value;
        $this->operator = $operator;
        $this->is_meta_filter = $is_meta_filter;
    }

    /**
     * Convert the filter to a SQL query string.  Paste this clause immediately after the WHERE or AND clause.
     * 
     * @param string $meta_key_query_field_name The field name to use for the meta key query, in case the preceding part of the query renames the meta key field
     * @param string $meta_value_query_field_name The field name to use for the meta value query, in case the preceding part of the query renames the meta value field
     * @return string The SQL query string
     */
    public function to_sql($post_table_sql_id = 'p', $post_meta_table_sql_id = 'pm'){

        //if the filter is an in or not in filter, and the value is empty, return 1=1
        //because in queries with no values are not supported, and I need to maintain valid sql
        if ($this->operator == 'IN' || $this->operator == 'NOT IN'){
            if (!is_array($this->compare_value) || count($this->compare_value) == 0){
                return "1=1";
            }
        }

        //handle a filter based on meta query attributes
        if ($this->is_meta_filter){
            return "$post_meta_table_sql_id.meta_key = '{$this->field_name}' AND $post_meta_table_sql_id.meta_value {$this->operator} {$this->get_compare_value_for_sql()}";
        }

        //handle a filter based on a post table attribute
        return "$post_table_sql_id.{$this->field_name} {$this->operator} {$this->get_compare_value_for_sql()}";
    }

    //get compare value for sql, based on the type of the compare value
    public function get_compare_value_for_sql(){
        $compare_value = $this->compare_value;
        $compare_value_type = gettype($compare_value);
        
        switch ($compare_value_type){
            case 'string':
                return "'{$compare_value}'";
            case 'integer':
                return $compare_value;
            case 'float':
                return $compare_value;
            case 'array':
                return "('" . implode("','", $compare_value) . "')";
            default:
                return $compare_value;
        }
    }
}


