<?php

namespace jtgraham38\wpvectordb\query\parts;

class Filter{
    public string $field_name;
    public string $operator;
    public $compare_value;
    public bool $is_meta_filter;

    public function __construct($field_name, $operator, $compare_value, $is_meta_filter=false){
        $this->field_name = esc_sql($field_name);
        //escape the operator
        switch ($operator){
            case '=':
                $this->operator = '=';
                break;
            case '!=':
                $this->operator = '!=';
                break;
            case '>':
                $this->operator = '>';
                break;
            case '<':
                $this->operator = '<';
                break;
            case '>=':
                $this->operator = '>=';
                break;
            case '<=':
                $this->operator = '<=';
                break;
            case 'IN':
                $this->operator = 'IN';
                break;
            case 'NOT IN':
                $this->operator = 'NOT IN';
                break;
            case 'LIKE':
                $this->operator = 'LIKE';
                break;
            case 'NOT LIKE':
                $this->operator = 'NOT LIKE';
                break;
            default:
                throw new \Exception('Invalid filter operator: ' . $operator);
        }

        //escape the compare value
        switch (gettype($compare_value)){
            case 'string':
                $this->compare_value = $compare_value;
                break;
            case 'integer':
                $this->compare_value = $compare_value;
                break;
            case 'float':
                $this->compare_value = $compare_value;
                break;
            case 'object':
                if (get_class($compare_value) == 'DateTime'){    
                    $this->compare_value = $compare_value;
                }
                else{
                    throw new \Exception('Invalid filter compare value class: ' . get_class($compare_value));
                }
                break;
            case 'array':
                $this->compare_value = array_map('esc_sql', $compare_value);
                break;
            default:
                throw new \Exception('Invalid filter compare value type: ' . gettype($compare_value));
        }
        $this->is_meta_filter = $is_meta_filter ? true : false;

    }

    /**
     * Convert the filter to a SQL query string.  Paste this clause immediately after the WHERE or AND clause.
     * 
     * @param string $meta_key_query_field_name The field name to use for the meta key query, in case the preceding part of the query renames the meta key field
     * @param string $meta_value_query_field_name The field name to use for the meta value query, in case the preceding part of the query renames the meta value field
     * @return string The SQL query string
     */
    public function to_sql($post_table_alias = 'p', $post_meta_table_alias = 'pm'){

        //if the filter is an in or not in filter, and the value is empty, return 1=1
        //because in queries with no values are not supported, and I need to maintain valid sql
        if ($this->operator == 'IN' || $this->operator == 'NOT IN'){
            if (!is_array($this->compare_value) || count($this->compare_value) == 0){
                return "1=1";
            }
        }

        //handle a filter based on meta query attributes
        if ($this->is_meta_filter){
            $sql = "$post_meta_table_alias.meta_key = '{$this->field_name}' AND $post_meta_table_alias.meta_value {$this->operator} {$this->get_compare_value_for_sql()}";
        } else{
            $sql = "$post_table_alias.{$this->field_name} {$this->operator} {$this->get_compare_value_for_sql()}";
        }

        //handle a filter based on a post table attribute
        return $sql;
    }

    public function get_compare_value_for_sql(){
        switch (gettype($this->compare_value)){
            case 'string':
                //add %% if the operator is like or not like
                if ($this->operator == 'LIKE' || $this->operator == 'NOT LIKE'){
                    return "'%" . esc_sql("{$this->compare_value}") . "%'";
                }
                else{
                    return "'" . esc_sql($this->compare_value) . "'";
                }
            case 'integer':
                return $this->compare_value;
            case 'float':
                return $this->compare_value;
            case 'object':
                if (get_class($this->compare_value) == 'DateTime'){    
                    return "CAST('{$this->compare_value->format('Y-m-d H:i:s')}' AS DATETIME)";
                }
                else{
                    throw new \Exception('Invalid filter compare value class: ' . get_class($this->compare_value));
                }
            case 'array':
                return "('" . implode("','", array_map('esc_sql', $this->compare_value)) . "')";
            default:
                throw new \Exception('Invalid filter compare value type: ' . gettype($this->compare_value));
        }
    }
}


