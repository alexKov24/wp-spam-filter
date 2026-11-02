<?php
namespace SpamKiller\Forms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Resolves saved template references backwards to their containing pages. */
final class Inventory {
	public function build( array $documents ): array {
		$parents = array();
		foreach ( $documents as $id => $document ) {
			foreach ( $document['references'] as $target ) {
				// Elementor template embeds target library documents, not arbitrary posts.
				if ( isset( $documents[ $target ] ) && 'elementor_library' === $documents[ $target ]['post_type'] ) {
					$parents[ $target ][] = $id;
				}
			}
		}
		$forms = array();
		foreach ( $documents as $id => $document ) {
			if ( ! $document['forms'] ) {
				continue;
			}
			$queue = array( $id );
			$seen = array();
			$pages = array();
			$templates = array();
			for ( $i = 0; $i < count( $queue ); ++$i ) {
				$ancestor = $queue[ $i ];
				if ( isset( $seen[ $ancestor ] ) ) {
					continue;
				}
				$seen[ $ancestor ] = true;
				if ( 'elementor_library' === $documents[ $ancestor ]['post_type'] ) {
					$templates[] = $ancestor;
				} else {
					$pages[] = $ancestor;
				}
				foreach ( $parents[ $ancestor ] ?? array() as $parent ) {
					$queue[] = $parent;
				}
			}
			foreach ( $document['forms'] as $form ) {
				$form['source_id'] = $id;
				$form['pages'] = $pages;
				$form['templates'] = $templates;
				$forms[] = $form;
			}
		}
		return array( 'documents' => $documents, 'forms' => $forms );
	}
}
