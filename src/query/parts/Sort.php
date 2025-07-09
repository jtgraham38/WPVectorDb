<?php

namespace jtgraham38\wpvectordb\query\parts;

class Sort{
    public string $field_name;
    public string $direction;
    public bool $is_meta_sort;
    public string $meta_cast;

    public function __construct($field_name, $direction, $is_meta_sort=false, $meta_cast='text'){
        $this->field_name = esc_sql($field_name);
        switch ($direction){
            case 'ASC':
                $this->direction = 'ASC';
                break;
            case 'DESC':
                $this->direction = 'DESC';
                break;
            default:
                throw new \Exception('Invalid sort direction: ' . $direction);
        }
        $this->is_meta_sort = $is_meta_sort ? true : false;
        if ($is_meta_sort){
            $this->meta_cast = $meta_cast;
        }
    }

    /**
     * Convert the sort to a SQL query string.  Paste this clause immediately after the ORDER BY clause.
     * 
     * In order to sort by meta, you need to convert meta attributes to a column of the output.  That means you cannot use the 
     * $post_meta_table_sql_id.meta_value column.  Instead, make sure you rename the column in tehe external query to meta_key.
     * 
     * @param string $post_table_sql_id The SQL ID for the post table
     * @param string $post_meta_table_sql_id The SQL ID for the post meta table
     * @return string The SQL query string
     */
    public function to_sql($post_table_alias = 'p', $post_meta_table_alias = 'pm'){
        //if the sort is a meta sort, cast the field to the correct type
        if ($this->is_meta_sort){
            switch ($this->meta_cast){
                case 'number':
                    return "CAST($this->field_name AS DECIMAL) {$this->direction}";
                case 'date':
                    return "CAST($this->field_name AS DATE) {$this->direction}";
                case 'text':
                    return "$this->field_name {$this->direction}";
            }
        }

        return "$post_table_alias.{$this->field_name} {$this->direction}";
    }
}