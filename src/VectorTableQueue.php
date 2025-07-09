<?php

namespace jtgraham38\wpvectordb;

//class that manages the vector table queue
class VectorTableQueue {
    private $table_name;
    private $db_version;
    private $plugin_prefix;

    /**
     * Constructor for VectorTableQueue
     * 
     * @param string $plugin_prefix Prefix to use for database table and options
     */
    public function __construct($plugin_prefix){
        global $wpdb;

        $this->plugin_prefix = $plugin_prefix;
        $this->table_name = $wpdb->prefix . $plugin_prefix . 'post_embed_queue';
        $this->db_version = '1.0';

        //call initialize function
        $this->init();
    }

    /**
     * Initialize the table
     * 
     * @return void
     */
    public function init(): void{
        //if the table does not exist, create it
        if ($this->table_exists() == false){
            $this->create_table();
        }
    }

    /**
     * Get the table name
     * 
     * @return string Table name
     */
    public function get_table_name(): string{
        return $this->table_name;
    }

    /**
     * Delete the table
     * 
     * @return void
     */
    public function drop_table(): void{
        global $wpdb;
        $wpdb->query("DROP TABLE IF EXISTS $this->table_name");
    }

    /**
     * Check if the table exists
     * 
     * @return bool True if the table exists, false otherwise
     */
    public function table_exists(): bool{
        global $wpdb;
        return $wpdb->get_var("SHOW TABLES LIKE '$this->table_name'") == $this->table_name;
    }

    /**
     * Create the table
     * 
     * @return void
     */
    public function create_table(): void{
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $this->table_name (
            job_id SERIAL PRIMARY KEY,
            post_id INTEGER NOT NULL,
            chunk_count INTEGER DEFAULT 0,
            status VARCHAR(20) NOT NULL CHECK (status IN ('pending', 'processing', 'completed', 'failed')),
            queued_time TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            start_time TIMESTAMP,
            end_time TIMESTAMP,
            error_count INTEGER DEFAULT 0,
            error_message TEXT DEFAULT NULL
        );";

        //execute the query
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
     * Add a post to the queue
     * 
     * @param int $post_id The post ID to add
     * @param int $chunk_count The number of chunks to add
     * @return bool True on success, false on failure
     */
    public function add_post($post_id, $chunk_count = 0) {
        global $wpdb;

        // Check if post already exists in queue
        if ($this->post_exists($post_id)) {
            throw new \Exception('Post already exists in queue');

            //check if the status is either failed or completed
            $status = $wpdb->get_var("SELECT status FROM $this->table_name WHERE post_id = $post_id");
            if ($status == 'failed' || $status == 'completed'){
                //reset the post
                $this->delete_post($post_id);
            }
        }


        $result = $wpdb->insert(
            $this->table_name,
            array(
                'post_id' => $post_id,
                'chunk_count' => $chunk_count,
                'status' => 'pending',
                'queued_time' => current_time('mysql'),
                'error_count' => 0
            ),
            array('%d', '%d', '%s', '%s', '%d')
        );

        if ($result === false) {
            throw new \Exception('Failed to add post to queue: ' . $wpdb->last_error);
        }

        return true;
    }

    /**
     * Add multiple posts to the queue in a single query
     * 
     * @param array $post_ids Array of post IDs to add
     * @return array Array of results for each post
     */
    public function add_posts($post_ids) {
        global $wpdb;
        $results = array();

        //return if array is empty
        if (empty($post_ids)) {
            return array();
        }

        //create the first sql clause
        $sql = "INSERT INTO {$this->table_name} (post_id, chunk_count, status, queued_time, error_count) VALUES ";

        //create the values clause for each post id
        $values = array();
        $placeholders = array();
        foreach ($post_ids as $post_id) {
            //add to placeholders
            $placeholders[] = "(%d, 0, 'pending', NOW(), 0)";
            //add to values
            $values[] = $post_id;
        }

        //add the values to the sql query
        $sql .= implode(',', $placeholders);

        //add the posts to the queue
        $num_added = $wpdb->query(
            $wpdb->prepare(
                $sql,
                $values
            )
        );

        return $num_added;
    }

    /**
     * Get the next batch of posts to process
     * 
     * @param int $batch_size Maximum number of chunks to process
     * @return array Array of post IDs to process
     */
    public function get_next_batch($batch_size = 25) {
        global $wpdb;

        // Get posts that are pending and haven't exceeded error count
        //NOTE: including failed results here is temporary, get first pending posts, then get failed posts that have not exceeded error count
        $posts = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT post_id, chunk_count 
                FROM {$this->table_name} 
                WHERE status = 'pending'
                OR (status = 'failed' AND error_count < 3)
                ORDER BY 
                    CASE 
                        WHEN status = 'pending' THEN 0 
                        WHEN status = 'failed' AND error_count < 3 THEN 1 
                        ELSE 2 
                    END,
                    queued_time ASC 
                LIMIT %d",
                $batch_size
            ),
            ARRAY_A
        );

        //if there are no posts to process, return an empty array
        if (empty($posts)) {
            return array();
        }

        // Update status to processing
        $post_ids = array_column($posts, 'post_id');
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$this->table_name} 
                SET status = 'processing', 
                start_time = %s 
                WHERE post_id IN (" . implode(',', array_fill(0, count($post_ids), '%d')) . ")",
                array_merge(array(current_time('mysql')), $post_ids)
            )
        );

        return $post_ids;
    }

    /**
     * Update the status of posts in the queue
     * 
     * @param array $post_ids Array of post IDs to update
     * @param string $status New status (completed, failed)
     * @param string $error_message Optional error message
     * @return bool True on success, false on failure
     */
    public function update_status($post_ids, $status, $error_message = '') {
        global $wpdb;

        if (!in_array($status, array('completed', 'failed'))) {
            return false;
        }

        $updates = array(
            'status' => $status,
            'end_time' => current_time('mysql'),
            'error_message' => $error_message
        );

        //todo: make this a single query
        foreach ($post_ids as $post_id) {
            if ($status === 'failed') {
                $updates['error_count'] = $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT error_count + 1 FROM {$this->table_name} WHERE post_id = %d",
                        $post_id
                    )
                );
            }

            $wpdb->update(
                $this->table_name,                  //table name
                $updates,                          //updates
                array('post_id' => $post_id),  //where clause
                array('%s', '%s', '%s', '%d'),     //format for status, end_time, error_message, error_count
                array('%d')                         //where format
            );
        }
    }

    /**
     * Check if a post exists in the queue
     * 
     * @param int $post_id The post ID to check
     * @return bool True if post exists, false otherwise
     */
    public function post_exists($post_id) {
        global $wpdb;
        return (bool) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table_name} WHERE post_id = %d",
                $post_id
            )
        );


    }

    /**
     * Get queue statistics
     * 
     * @return array Array of queue statistics
     */
    public function get_stats() {
        global $wpdb;

        return array(
            'total' => $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}"),
            'pending' => $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name} WHERE status = 'pending'"),
            'processing' => $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name} WHERE status = 'processing'"),
            'completed' => $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name} WHERE status = 'completed'"),
            'failed' => $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name} WHERE status = 'failed'")
        );
    }

    /**
     * Clean up completed records
     * 
     * @return int Number of records cleaned up
     */
    public function cleanup() {
        global $wpdb;

        //if a record has been started more than 15 minutes ago and its status is processing, set it as a failure
        $wpdb->query(
            "UPDATE {$this->table_name} 
            SET status = 'failed', 
            error_count = error_count + 1,
            end_time = NOW(),
            error_message = 'Processing time exceeded 15 minutes.'
            WHERE status = 'processing' 
            AND start_time < NOW() - INTERVAL 15 MINUTE
            AND end_time IS NULL
            "
        );

        //delete the records that are older than 7 days and complete or failed more than 3 times
        return $wpdb->query(
            "DELETE FROM {$this->table_name} 
            WHERE (status = 'completed' AND end_time IS NOT NULL AND end_time < NOW() - INTERVAL 3 DAY) 
            OR (status = 'failed' AND error_count > 3)"
        );
    }

    /**
     * Get posts that need to be retried
     * 
     * @return array Array of post IDs that need retrying
     */
    public function get_posts_to_retry() {
        global $wpdb;

        return $wpdb->get_col(
            "SELECT post_id 
            FROM {$this->table_name} 
            WHERE status = 'failed' 
            AND error_count < 3
            LIMIT 25000
            "
        );
    }

    /**
     * Reset a post's status to pending
     * 
     * @param int $post_id The post ID to reset
     * @return bool True on success, false on failure
     */
    public function reset_post($post_id) {
        global $wpdb;

        return $wpdb->update(
            $this->table_name,
            array(
                'status' => 'pending',
                'start_time' => null,
                'end_time' => null,
            ),
            array('post_id' => $post_id),
            array('%s', '%s', '%s'),
            array('%d')
        ) !== false;
    }

    /**
     * Delete a post from the queue
     * 
     * @param int $post_id The post ID to delete
     * @return bool True on success, false on failure
     */
    public function delete_post($post_id) {
        global $wpdb;
        return $wpdb->delete($this->table_name, array('post_id' => $post_id), array('%d'));
    }

    /**
     * Delete a record by ID
     * 
     * @param int $id The ID of the record to delete
     * @return bool True on success, false on failure
     */
    public function delete_record($id) {
        global $wpdb;
        return $wpdb->delete($this->table_name, array('id' => $id), array('%d'));
    }

    /**
     * Get a page of records from the queue
     * 
     * @param int $page The page number to get
     * @param int $per_page The number of records per page
     * @return array Array of records
     */
    public function get_page_of_records($page = 1, $per_page = 25) {
        global $wpdb;
        $posts_table = esc_sql($wpdb->posts);

        //calculate the offset
        $offset = ($page - 1) * $per_page;

        //prepare the sql query to get the records from the queue, along with the post title and post type
        //order them by processing, pending, failed, completed
        $statuses = ['pending', 'processing', 'failed', 'completed'];
        $sql = $wpdb->prepare(
            "SELECT * FROM {$this->table_name} 
            LEFT JOIN {$posts_table} ON {$this->table_name}.post_id = {$posts_table}.ID 
            WHERE status IN ('%s', '%s', '%s', '%s')
            ORDER BY status DESC,
                CASE 
                    WHEN status = 'pending' THEN 0 
                    WHEN status = 'completed' THEN 1
                    WHEN status = 'failed' THEN 2
                    WHEN status = 'processing' THEN 3 
                    ELSE 4
                END,
            queued_time ASC
            LIMIT %d OFFSET %d",
            array_merge(
                $statuses,
                array($per_page, $offset)
            )
        );

        //get all the data we need
        $results = $wpdb->get_results($sql);

        return $results;
    }

    /**
     * Get the total number of records in the queue
     * 
     * @return int Total number of records
     */
    public function get_total_records() {
        global $wpdb;
        return $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}");
    }
} 