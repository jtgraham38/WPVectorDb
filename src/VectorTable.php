<?php 

namespace jtgraham38\wpvectordb;

// Use statements for namespaced classes
//custom heap class, used in candidate generation
class HammingDistMinHeap extends \SplMinHeap{
    protected function compare($a, $b): int{
        return $b['hamming_distance'] <=> $a['hamming_distance'];
    }
}

// another custom heap class, for getting most similar vectors
class CosimMaxHeap extends \SplMaxHeap{
    protected function compare($a, $b): int{
        return $b['cosine_similarity'] <=> $a['cosine_similarity'];
    }
}

//class that manages the vector table
class VectorTable{
    private $table_name;
    private $db_version;
    private $plugin_prefix;
    private $vector_length;

    /**
     * Constructor for VectorTable
     * 
     * @param string $plugin_prefix Prefix to use for database table and options
     * @param int $vector_length Length of vectors to be stored (default: 1024)
     */
    public function __construct($plugin_prefix, int $vector_length=1024){
        global $wpdb;

        $this->plugin_prefix = $plugin_prefix;
        $this->table_name = $wpdb->prefix . $plugin_prefix . 'embeddings';
        $this->db_version = '1.0';
        $this->vector_length = $vector_length;

        //call initialize function
        $this->init();
    }

    /**
     * Initialize the vector table, creating it if it doesn't exist
     * 
     * @return void
     */
    public function init(): void{
        //if the table does not exist, create it
        if ($this->table_exists() == false){
            $this->create_table();
        }

    }

    //  \\  //  \\  //  \\ TABLE CRUD //  \\  //  \\  //  \\

    /**
     * Search for the n most similar vectors to a given vector
     * 
     * @param array|string $vector The query vector to search for (can be array or JSON string)
     * @param int $n Number of most similar vectors to return (default: 5)
     * @return array Array of post IDs of the most similar vectors
     */
    public function search($vector, int $n=5): array{
        global $wpdb;

        //convert from array to string
        if (is_array($vector)){
            $vector = json_encode($vector);
        }

        //get the binary code
        $binary_code = $this->hex_to_binary( $this->get_binary_code($vector) );

        //  \\  //  \\  CANDIDATE GENERATION //  \\  //  \\  //
        //ensure only published posts and the selected post types are used
        $post_types = get_option($this->plugin_prefix . 'post_types');
        $post_types = array_map(function($post_type){
            return "'$post_type'";
        }, $post_types);
        $post_types = implode(',', $post_types);
        //some versions of mysql don't support limits in subqueries, so we do not include a limit here
        $candidate_posts_query = "SELECT ID from $wpdb->posts WHERE post_type IN ($post_types) AND post_status = 'publish'";

        //get all the vectors for the candidate posts
        $candidates_query = "select id, binary_code from $this->table_name WHERE post_id IN ($candidate_posts_query) LIMIT 1000000";

        $embeddings = $wpdb->get_results($candidates_query);

        //get the n vectors with the smallest hamming distance
        $closest_candidates = new HammingDistMinHeap();
        //add each vector to my minheap
        foreach ($embeddings as $embedding){
            //compute the hamming distance between the embedding and the user query vector
            $embedding_binary_code = $this->hex_to_binary( $embedding->binary_code);
            $hamming_distance = 0;
            for ($i = 0; $i < $this->vector_length; $i++){
                if ($binary_code[$i] != $embedding_binary_code[$i]){
                    $hamming_distance++;
                }
            }

            //get id and binary code
            $closest_candidates->insert([
                'id' => $embedding->id,
                'hamming_distance' => floatval($hamming_distance)
            ]);
        }
        
        //get the 4n closest candidates out of the heap
        $candidates = [];
        for ($i = 0; $i < 4*$n; $i++){
            if ($closest_candidates->count() < 1) break;
            $candidates[] = $closest_candidates->extract();
        }

        //get the ids of the candidates
        $candidate_ids = array_map(function($candidate){ return $candidate['id']; }, $candidates);
        $candidates_str = implode(',', $candidate_ids);

        //  \\  //  \\  RERANKING //  \\  //  \\  //
        //find the candidates with the lowest cosine distance to the query vector using php
        $reranked_candidates = new CosimMaxHeap();
        
        //get all the candidates
        //100000 should be more than enough to cover the 4n candidates
        $sql = "SELECT id, magnitude, vector FROM $this->table_name WHERE id IN ($candidates_str) LIMIT 100000";
        $candidates = $wpdb->get_results($sql);

        //parse the vector
        $vector = json_decode($vector, true);

        //compute the cosine similarity of each candidate with the user query vector
        foreach ($candidates as $candidate){
            //decode the vector
            $candidate_vector = json_decode($candidate->vector, true);

            //calculate the cosine similarity
            $cosine_similarity = 0;
            for ($i = 0; $i < count( $candidate_vector ); $i++){
                $cosine_similarity += floatval( $vector[$i] ) * floatval( $candidate_vector[$i] );
            }
            $cosine_similarity /= ($candidate->magnitude * $this->magnitude($vector)) + 0.000000000001;

            //put the candidate in the reranked max heap
            $reranked_candidates->insert([
                'id' => $candidate->id,
                'cosine_similarity' => $cosine_similarity
            ]);

        }

        //get the n most similar candidates
        $reranked_candidates_arr = [];
        for ($i = 0; $i < $n; $i++){
            if ($reranked_candidates->count() < 1) break;
            $reranked_candidates_arr[] = $reranked_candidates->extract();
        }

        //get post titles with candidates
        // foreach ($reranked_candidates_arr as &$candidate){
        //     $candidate['post_title'] = get_the_title($candidate['id']);
        // }
        // echo json_encode($reranked_candidates_arr);
        

        //return the ids of the reranked candidates
        $reranked_ids = array_map(function($candidate){ return $candidate['id']; }, $reranked_candidates_arr);

        return $reranked_ids;
    }

    /**
     * Get a vector by its database ID
     * 
     * @param int $id Database ID of the vector
     * @return object|null Vector data object or null if not found
     */
    public function id(int $id): object | null{
        global $wpdb;

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $this->table_name WHERE id = %d",
            $id
        ));
    }

    /**
     * Get multiple vectors by their database IDs
     * 
     * @param array $ids Array of database IDs
     * @return array Array of vector data objects
     */
    public function ids(array $ids): array{
        global $wpdb;

        $ids_str = implode(',', $ids);

        if (empty($ids_str)){
            return [];
        }

        return $wpdb->get_results(
            "SELECT * FROM $this->table_name WHERE id IN ($ids_str)"
        );

    }


    /**
     * Get a vector by post ID and sequence number
     * 
     * @param int $post_id WordPress post ID
     * @param int $sequence_no Sequence number of the vector within the post
     * @return object|null Vector data object or null if not found
     */
    public function get(int $post_id, int $sequence_no): object | null{
        global $wpdb;

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $this->table_name WHERE post_id = %d AND sequence_no = %d",
            $post_id,
            $sequence_no
        ));
    }

    /**
     * Get all vectors associated with a post
     * 
     * @param int $post_id WordPress post ID
     * @return array Array of vector data objects
     */
    public function get_all_for_post(int $post_id): array{
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $this->table_name WHERE post_id = %d",
            $post_id
        ));
    }
    

    /**
     * Get the most recently updated vector for a post
     * 
     * @param int $post_id WordPress post ID
     * @return object|null Most recent vector data object or null if not found
     */
    public function get_latest_updated(int $post_id): object | null{
        global $wpdb;

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $this->table_name WHERE post_id = %d ORDER BY updated_at DESC LIMIT 1",
            $post_id
        ));
    }

    /**
     * Get all vectors in the database
     * 
     * @return array Array of all vector data objects (limited to 100,000)
     */
    public function get_all(): array{
        global $wpdb;

        //TODO: I need to paginate this eventually
        return $wpdb->get_results(
            "SELECT * FROM $this->table_name LIMIT 100000"
        );
    }

    /**
     * Insert or update a vector in the database
     * 
     * @param int $post_id WordPress post ID
     * @param int $sequence_no Sequence number of the vector within the post
     * @param string $vector Vector data as JSON string
     * @param string $vector_type Type identifier for the vector
     * @return int Database ID of the inserted/updated vector
     */
    public function upsert(int $post_id, int $sequence_no, string $vector, string $vector_type){
        global $wpdb;

        //check if the vector exists
        $vector_exists = $this->get($post_id, $sequence_no);

        //get the binary code
        $binary_code = $this->get_binary_code($vector);

        //get the normalized vector
        $normalized_vector = json_encode($this->normalize(json_decode($vector, true)));

        //if the vector exists, update it with a sql statement (to use the UNHEX function)
        if ($vector_exists > 0){
            $wpdb->query($wpdb->prepare(
                "UPDATE $this->table_name SET vector = %s, normalized_vector = %s, vector_type = %s, binary_code = %s WHERE post_id = %d AND sequence_no = %d",
                $vector,
                $normalized_vector,
                $vector_type,
                $binary_code,
                $post_id,
                $sequence_no
            ));

            $ret_id = $vector_exists->id;
        }
        //if the vector does not exist, insert it
        else{
            //insert with a sql statement (to use the UNHEX function)
             $wpdb->query($wpdb->prepare(
                "INSERT INTO $this->table_name (post_id, sequence_no, vector, normalized_vector, vector_type, binary_code, magnitude) VALUES (%d, %d, %s, %s, %s, %s , %f)",
                $post_id,
                $sequence_no,
                $vector,
                $normalized_vector,
                $vector_type,
                $binary_code,
                $this->magnitude(json_decode($vector, true))
            ));

            //return the id of the inserted vector
            $ret_id = $wpdb->insert_id;
        }

        //return the id of the inserted/updated vector
        return $ret_id;
    }

    /**
     * Insert or update all vectors for a post
     * 
     * @param int $post_id WordPress post ID
     * @param array $vectors Array of vector data, indexed by sequence number
     * @return array Array of database IDs for the inserted vectors
     */
    public function insert_all(int $post_id, array $vectors): array{
        global $wpdb;

        //delete all existing vectors for the post
        $wpdb->delete(
            $this->table_name,
            array(
                'post_id' => $post_id
            ),
            array(
                '%d'
            )
        );

        //track inserted ids
        $inserted_ids = [];

        //insert the new vectors
        //TODO: make this a single query enventually, should be find for small arrays of vectors though
        foreach ($vectors as $sequence_no => $vector){
            $inserted_ids[] = $this->upsert($post_id, $sequence_no, $vector['vector'], $vector['vector_type']);
        }

        return $inserted_ids;
    }

    /**
     * Delete a vector by its database ID
     * 
     * @param int $id Database ID of the vector to delete
     * @return void
     */
    public function delete(int $id): void{
        global $wpdb;

        $wpdb->delete(
            $this->table_name,
            array(
                'id' => $id
            ),
            array(
                '%d'
            )
        );
    }

    /**
     * Get the total number of vectors in the database
     * 
     * @return int Number of vectors
     */
    public function get_vector_count(): int{
        global $wpdb;

        return $wpdb->get_var("SELECT COUNT(*) FROM $this->table_name");
    }

    //  \\  //  \\  //  \\ MANAGE SQL TABLES/FUNCS //  \\  //  \\  //  \\
    /**
     * Create the vector table in the database
     * 
     * @return void
     */
    public function create_table(): void{
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        //NOTE: sequence_no is the index of the vector in the document
        $sql = sprintf("CREATE TABLE $this->table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            post_id mediumint(9) NOT NULL,
            sequence_no mediumint(9) NOT NULL,
            vector JSON NOT NULL,
            normalized_vector JSON NOT NULL,
            vector_type varchar(255) NOT NULL,
            binary_code BLOB NOT NULL,
            magnitude float NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id)
        ) $charset_collate;"/*, $this->vector_length/8 * 2*/);
        //^ binary code is the binary representation of the vector, length is vector_length/8 for 8 bits per byte
        //it is divided by 8 because 1 byte = 8 bits,
        //and each character in the binary code is a hexadecimal character representing 4 bits
        //each hexadecimal character represents the signs of 4 values in the vector
        // 4 bits/char * 2 chars/byte = 8 bits/byte
        //so divide the length of the binary code in bits by 8, and multiply by 2 to get the length in bytes

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        add_option($this->prefix . 'db_version', $this->db_version);
    }

    /**
     * Drop the vector table from the database
     * 
     * @return void
     */
    public function drop_table(): void{
        global $wpdb;
        $wpdb->query("DROP TABLE IF EXISTS $this->table_name");
    }

    /**
     * Check if the vector table exists in the database
     * 
     * @return bool True if table exists, false otherwise
     */
    public function table_exists(): bool{
        global $wpdb;
        return $wpdb->get_var("SHOW TABLES LIKE '$this->table_name'") == $this->table_name;
    }

    //  \\  //  \\  //  \\  BINARY CODES //  \\  //  \\  //  \\
    /**
     * Get the binary code representation of a vector
     * 
     * @param array|string $vector Vector data (can be array or JSON string)
     * @return string Hexadecimal string representing the binary code
     */
    public function get_binary_code( $vector ): string{    //hexadecimal string
        //convert the vecor to an array, if it is not one
        if (!is_array($vector)){
            $vector = json_decode($vector, true);
        }

        return $this->vector_to_hex($vector);
    }

    /**
     * Convert a vector array to hexadecimal binary code
     * 
     * @param array $vector_arr Vector data as array
     * @return string Hexadecimal string representing the binary code
     */
    public function vector_to_hex(array $vector_arr): string{    //hexadecimal string
        $binary_code = '';

        //1 if value is greater than 0, 0 otherwise
        foreach ($vector_arr as $value){
            $binary_code .= $value > 0 ? '1' : '0';
        }

        //convert binary to bytes
        return $this->binary_to_hex($binary_code);
    }

    /**
     * Convert a hexadecimal string to binary string
     * 
     * @param string $hex Hexadecimal string
     * @return string Binary string
     */
    public function hex_to_binary(string $hex): string{
        $binary_code = '';
        foreach (str_split($hex) as $char){
            $binary_code .= str_pad(decbin(hexdec($char)), 4, '0', STR_PAD_LEFT);
        }
        return $binary_code;
    }

    /**
     * Convert a binary string to hexadecimal string
     * 
     * @param string $binary Binary string
     * @return string Hexadecimal string
     */
    public function binary_to_hex(string $binary): string{
        $hex_code = '';
        foreach (str_split($binary, 4) as $halfByte){
            $hex_code .= strtoupper(dechex(bindec($halfByte)));
        }
        return $hex_code;
    }

    //  \\  //  \\  //  \\ UTILS //  \\  //  \\  //  \\
    /**
     * Normalize a vector to unit length
     * 
     * @param array $vector Vector data as array
     * @return array Normalized vector array
     */
    public function normalize($vector): array{
        $mag = $this->magnitude($vector);
        $magnitude = $mag == 0 ? 1e-10 : $mag;
        return array_map(function($value) use ($magnitude){
            return $value / $magnitude;
        }, $vector);
    }


    /**
     * Get the database table name
     * 
     * @return string Table name
     */
    public function get_table_name(): string{
        return $this->table_name;
    }

    /**
     * Get the database version
     * 
     * @return string Database version
     */
    public function get_db_version(): string{
        return $this->db_version;
    }

    /**
     * Get the plugin prefix
     * 
     * @return string Plugin prefix
     */
    public function get_prefix(): string{
        return $this->prefix;
    }

    /**
     * Calculate the magnitude (L2 norm) of a vector
     * 
     * @param array $vector Vector data as array
     * @return float Vector magnitude
     */
    public function magnitude($vector): float{
        $magnitude = 0;
        foreach ($vector as $value){
            $magnitude += $value * $value;
        }
        return sqrt($magnitude);
    }
}