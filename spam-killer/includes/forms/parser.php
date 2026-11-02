<?php
namespace SpamKiller\Forms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Reads saved data without executing widgets, shortcodes, or form actions. */
final class Parser {
	public function parse( $data, string $content = '' ): array {
		$result = array( 'forms' => array(), 'references' => array(), 'invalid' => false );
		if ( is_string( $data ) && '' !== $data ) {
			$data = json_decode( $data, true );
			$result['invalid'] = JSON_ERROR_NONE !== json_last_error() || ! is_array( $data );
		}
		if ( is_array( $data ) ) {
			// Also accept the documented template export wrapper.
			$this->walk( isset( $data['content'] ) && is_array( $data['content'] ) ? $data['content'] : $data, $result );
		}
		$result['references'] = array_values( array_unique( array_merge( $result['references'], $this->shortcode_references( $content ) ) ) );
		$result['forms'] = array_merge( $result['forms'], $this->cf7_forms( $content, '' ) );
		return $result;
	}

	private function walk( array $elements, array &$result, string $path = '', int $depth = 0 ): void {
		if ( $depth > 100 ) {
			$result['invalid'] = true;
			return;
		}
		foreach ( $elements as $index => $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			$position = $path . '/' . $index;
			$settings = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array();
			$type = self::text( $element['widgetType'] ?? '' );
			if ( 'widget' === ( $element['elType'] ?? '' ) ) {
				if ( 'form' === $type ) {
					$fields = array();
					foreach ( (array) ( $settings['form_fields'] ?? array() ) as $field ) {
						if ( is_array( $field ) ) {
							$fields[] = array(
								'label' => self::text( $field['field_label'] ?? $field['custom_id'] ?? '' ),
								'type' => self::text( $field['field_type'] ?? 'text' ),
							);
						}
					}
					$actions = array_filter( array_map( array( self::class, 'text' ), (array) ( $settings['submit_actions'] ?? array() ) ) );
					$result['forms'][] = array(
						'plugin' => 'elementor',
						'widget_id' => self::text( $element['id'] ?? '' ),
						'path' => $position,
						'name' => self::text( $settings['form_name'] ?? '' ),
						'fields' => $fields,
						'actions' => array_values( $actions ),
					);
				}
				if ( in_array( $type, array( 'template', 'global' ), true ) ) {
					$id = self::reference_id( 'global' === $type ? ( $element['templateID'] ?? 0 ) : ( $settings['template_id'] ?? 0 ) );
					if ( $id ) {
						$result['references'][] = $id;
					}
				}
				// Only inspect controls whose content Elementor actually renders.
				$control = array( 'shortcode' => 'shortcode', 'text-editor' => 'editor' )[ $type ] ?? '';
				if ( $control ) {
					$result['references'] = array_merge( $result['references'], $this->shortcode_references( self::text( $settings[ $control ] ?? '' ) ) );
					$result['forms'] = array_merge( $result['forms'], $this->cf7_forms( self::text( $settings[ $control ] ?? '' ), $position ) );
				}
			}
			if ( isset( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$this->walk( $element['elements'], $result, $position, $depth + 1 );
			}
		}
	}

	/** [contact-form-7 id="…"] embeds. id is a post ID or (CF7 5.8+) a hash prefix. Also covers the CF7 block, which saves the shortcode. */
	private function cf7_forms( string $content, string $path ): array {
		$forms = array();
		if ( false === strpos( $content, '[contact-form-7' ) ) {
			return $forms;
		}
		preg_match_all( '/' . get_shortcode_regex( array( 'contact-form-7' ) ) . '/s', $content, $matches, PREG_SET_ORDER );
		foreach ( $matches as $match ) {
			if ( '[' === $match[1] && ']' === $match[6] ) {
				continue;
			}
			$attributes = shortcode_parse_atts( $match[3] );
			$ref = is_array( $attributes ) ? self::text( $attributes['id'] ?? '' ) : '';
			if ( '' !== $ref ) {
				$forms[] = array( 'plugin' => 'cf7', 'ref' => $ref, 'path' => $path, 'name' => self::text( $attributes['title'] ?? '' ) );
			}
		}
		return $forms;
	}

	private function shortcode_references( string $content ): array {
		$references = array();
		if ( false === strpos( $content, '[elementor-template' ) ) {
			return $references;
		}
		preg_match_all( '/' . get_shortcode_regex( array( 'elementor-template' ) ) . '/s', $content, $matches, PREG_SET_ORDER );
		foreach ( $matches as $match ) {
			// WordPress treats [[elementor-template ...]] as literal text.
			if ( '[' === $match[1] && ']' === $match[6] ) {
				continue;
			}
			$attributes = shortcode_parse_atts( $match[3] );
			$id = self::reference_id( is_array( $attributes ) ? ( $attributes['id'] ?? 0 ) : 0 );
			if ( $id ) {
				$references[] = $id;
			}
		}
		return $references;
	}

	private static function reference_id( $value ): int {
		return ( is_int( $value ) || is_string( $value ) ) && ctype_digit( (string) $value ) && (int) $value > 0 ? (int) $value : 0;
	}

	public static function text( $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}
}
