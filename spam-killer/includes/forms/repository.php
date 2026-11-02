<?php
namespace SpamKiller\Forms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Repository {
	const CACHE_KEY = 'spam_killer_forms_v1';

	public function register_hooks(): void {
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
			add_action( $hook, array( $this, 'meta_changed' ), 10, 4 );
		}
		foreach ( array( 'save_post', 'deleted_post', 'trashed_post', 'untrashed_post' ) as $hook ) {
			add_action( $hook, array( self::class, 'invalidate' ) );
		}
	}

	public function meta_changed( $meta_id, $post_id, $key, $value ): void {
		if ( in_array( $key, array( '_elementor_data', '_elementor_template_type', '_elementor_conditions' ), true ) ) {
			self::invalidate();
		}
	}

	public static function invalidate(): void {
		delete_transient( self::CACHE_KEY );
	}

	public function get(): array {
		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) && isset( $cached['forms'], $cached['documents'] ) ) {
			return $cached;
		}
		$inventory = ( new Inventory() )->build( $this->documents() );
		$inventory['scanned_at'] = time();
		set_transient( self::CACHE_KEY, $inventory, 5 * MINUTE_IN_SECONDS );
		return $inventory;
	}

	private function documents(): array {
		global $wpdb;
		$types = array_keys( get_post_types( array( 'show_ui' => true ) ) );
		$types[] = 'elementor_library';
		$types = array_values( array_diff( array_unique( $types ), array( 'attachment', 'revision' ) ) );
		$statuses = array_values( array_diff( get_post_stati(), array( 'trash', 'auto-draft', 'inherit' ) ) );
		$type_placeholders = implode( ', ', array_fill( 0, count( $types ), '%s' ) );
		$status_placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
		$documents = array();
		$last_id = 0;
		$parser = new Parser();
		do {
			// Keyset pagination bounds each database read and avoids loading revisions.
			$sql = "SELECT p.* FROM {$wpdb->posts} p
				WHERE p.ID > %d AND p.post_type IN ($type_placeholders)
				AND p.post_status IN ($status_placeholders)
				AND (EXISTS (SELECT 1 FROM {$wpdb->postmeta} pm WHERE pm.post_id = p.ID AND pm.meta_key = %s)
				OR p.post_content LIKE %s OR p.post_content LIKE %s)
				ORDER BY p.ID ASC LIMIT 200";
			$args = array_merge( array( $last_id ), $types, $statuses, array( '_elementor_data', '%' . $wpdb->esc_like( '[elementor-template' ) . '%', '%' . $wpdb->esc_like( '[contact-form-7' ) . '%' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Dynamic placeholders; all values are prepared.
			$posts = $wpdb->get_results( $wpdb->prepare( $sql, $args ) );
			if ( ! is_array( $posts ) ) {
				throw new \RuntimeException( 'Could not read Elementor documents.' );
			}
			update_meta_cache( 'post', array_column( $posts, 'ID' ) );
			foreach ( $posts as $post ) {
				$id = (int) $post->ID;
				$parsed = $parser->parse( get_post_meta( $id, '_elementor_data', true ), $post->post_content );
				$documents[ $id ] = array_merge( $parsed, array(
					'id' => $id,
					'title' => $post->post_title,
					'post_type' => $post->post_type,
					'status' => $post->post_status,
					'template_type' => Parser::text( get_post_meta( $id, '_elementor_template_type', true ) ),
				) );
				$last_id = $id;
			}
		} while ( 200 === count( $posts ) );
		return $documents;
	}
}
