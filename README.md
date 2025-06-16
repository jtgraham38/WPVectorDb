# WP Vector Table

A WordPress plugin for efficient storage and retrieval of vector embeddings in WordPress posts. This plugin provides a robust solution for implementing vector similarity search in WordPress, with optimized storage and retrieval mechanisms.

## Features

- Efficient storage of vector embeddings in WordPress database
- Two-phase vector similarity search:
  1. Fast candidate generation using Hamming distance
  2. Precise reranking using cosine similarity
- Support for multiple vectors per post
- Binary code optimization for faster similarity search
- Automatic vector normalization
- WordPress post type integration
- Queue system for batch processing of vectors

## Requirements

- WordPress 5.0 or higher
- PHP 7.4 or higher
- MySQL 5.7 or higher

## Installation

1. Download the plugin files
2. Upload the plugin directory to your WordPress plugins directory (`wp-content/plugins/`)
3. Activate the plugin through the WordPress admin interface
4. Configure the plugin settings:
   - Select post types to enable vector embeddings for
   - Set vector length (default: 1024)
   - Configure any additional settings

## Usage

### Basic Usage

```php
// Initialize the vector table
$vector_table = new \jtgraham38\wpvectordb\VectorTable('your_plugin_prefix');

// Store a vector for a post
$vector = [/* your vector data */];
$vector_table->upsert($post_id, 0, json_encode($vector), 'embedding_type');

// Search for similar vectors
$query_vector = [/* your query vector */];
$similar_posts = $vector_table->search($query_vector, 5); // Get 5 most similar posts
```

### Vector Search

The plugin implements a two-phase search process for optimal performance:

1. **Candidate Generation**: Uses Hamming distance on binary codes to quickly filter potential matches
2. **Reranking**: Uses cosine similarity on full vectors to get precise results

This approach provides a good balance between search speed and accuracy.

### Vector Storage

Vectors are stored with the following optimizations:

- Binary codes for fast similarity comparison
- Normalized vectors for accurate similarity calculations
- Magnitude pre-computation for faster cosine similarity
- Efficient database schema with proper indexing

### Queue System

The plugin includes a queue system for batch processing of vectors:

```php
// Add posts to the queue
$queue = new \jtgraham38\wpvectordb\VectorTableQueue('your_plugin_prefix');
$queue->add_posts([$post_id1, $post_id2]);

// Process the queue
$queue->process_queue();
```

## Database Schema

The plugin creates a custom table with the following structure:

- `id`: Auto-incrementing primary key
- `post_id`: WordPress post ID
- `sequence_no`: Index of the vector within the post
- `vector`: JSON-encoded vector data
- `normalized_vector`: JSON-encoded normalized vector
- `vector_type`: Type identifier for the vector
- `binary_code`: Binary representation for fast comparison
- `magnitude`: Pre-computed vector magnitude
- `created_at`: Timestamp of creation
- `updated_at`: Timestamp of last update

## Performance Considerations

- The plugin uses binary codes to optimize storage and search performance
- Vectors are normalized to unit length for consistent similarity calculations
- The search process uses a two-phase approach to balance speed and accuracy
- Database queries are optimized with proper indexing
- Batch processing is available through the queue system

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## License

This project is licensed under the MIT License - see the LICENSE file for details.

## Support

For support, please open an issue in the GitHub repository or contact the maintainers.

## Credits

Developed by [jtgraham38](https://github.com/jtgraham38)