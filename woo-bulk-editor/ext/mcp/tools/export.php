<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Exporting a selection to CSV.
 *
 * The rows are built by the export extension itself rather than assembled here.
 * That formatting is not trivial - attributes expand into four columns each to
 * match WooCommerce's own product importer, downloads into a variable number,
 * taxonomies and galleries have their own rules - and a second implementation
 * would drift away from what the editor screen produces within a release or
 * two. The user would then have two exports of the same shop that disagree,
 * which is worse than having no export tool at all.
 *
 * The file lands in the extension's own export folder, which is closed to the
 * web. It is handed over through a one time link instead: a random token, good
 * for fifteen minutes, that maps to that one file. The shop's MCP key never
 * appears in a URL - it was deliberately taken out of there - and a leaked
 * download link exposes one export for a quarter of an hour rather than the
 * whole catalogue forever.
 */
final class WOOBE_MCP_TOOL_EXPORT extends WOOBE_MCP_TOOL {

	const TOKEN_PREFIX = 'woobe_mcp_export_';
	const TOKEN_TTL    = 900; // seconds a download link stays valid
	const MAX_ROWS     = 20000;

	public function tools() {

		return array(

			'woobe_export_csv' => array(
				'name'        => 'woobe_export_csv',
				'description' => 'Exports a selection of products to a CSV file and returns a download link. Columns are formatted exactly as the plugin\'s own export screen produces them - attributes and downloads included, ready for WooCommerce\'s product importer. Take a selection with woobe_find_products first. The link works for fifteen minutes and needs no credentials, so give it to the user as it is; do not try to read the file yourself.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'selection_id' => array( 'type' => 'string' ),
						'ids'          => array(
							'type'  => 'array',
							'items' => array( 'type' => 'integer' ),
						),
						'columns'      => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => 'Field keys to export, in the order they should appear. Leave it out to export whatever columns are switched on in the editor. Use the keys from woobe_list_fields, not their titles - if the user names columns in his own words, look the keys up there first.',
						),
						'delimiter'    => array(
							'type'        => 'string',
							'description' => 'Column separator, default a comma. Use a semicolon for a shop whose spreadsheet expects one - that is most of continental Europe.',
						),
						'variations'   => array(
							'type'        => 'boolean',
							'description' => 'Add the variations of any variable product in the selection as their own rows. On by default, because a variable parent exports with no price and no stock.',
						),
					),
				),
				'annotations' => array( 'readOnlyHint' => true ),
			),
		);
	}

	public function call( $name, $args ) {

		if ( 'woobe_export_csv' === $name ) {
			return $this->export_csv( $args );
		}

		return new WP_Error( 'woobe_mcp_unknown_tool', 'Unknown tool: ' . $name );
	}

	// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

	private function export_csv( $args ) {

		global $WOOBE;

		if ( ! isset( $WOOBE->export ) || is_null( $WOOBE->export ) ) {
			return new WP_Error( 'woobe_mcp_no_export', 'The export extension is not available on this shop.' );
		}

		$export = $WOOBE->export;

		foreach ( array( 'get_export_folder', 'get_active_fields', 'get_product_fields', 'get_csv_titles', 'flatten_export_value' ) as $needed ) {
			if ( ! method_exists( $export, $needed ) ) {
				return new WP_Error(
					'woobe_mcp_export_too_old',
					'This build of the export extension does not expose ' . $needed . '(), so an export cannot be produced without duplicating its formatting. Update the plugin.'
				);
			}
		}

		$ids = $this->ids( $args );

		if ( is_wp_error( $ids ) ) {
			return $ids;
		}

		if ( empty( $ids ) ) {
			return new WP_Error( 'woobe_mcp_no_products', 'No products to export.' );
		}

		// variations carry the price and the stock, so a catalogue exported
		// without them is mostly empty cells where a variable product sits
		$with_variations = isset( $args['variations'] ) ? (bool) $args['variations'] : true;

		// the selection as asked for, before variations are added as rows
		$selection_size = count( $ids );

		if ( $with_variations ) {
			$ids = $this->with_children( $ids );
		}

		if ( count( $ids ) > self::MAX_ROWS ) {
			return new WP_Error(
				'woobe_mcp_export_too_big',
				'That selection is ' . count( $ids ) . ' rows. Exports through this connection are capped at ' . self::MAX_ROWS
				. ' - narrow the selection, or use the Export tab on the plugin screen, which streams in batches and has no limit.'
			);
		}

		// A one file override of the editor's column set. Hooked rather than
		// passed as an argument because get_active_fields() is called twice -
		// once for the header row and once per product - and a filter covers
		// both without changing either signature.
		$wanted = isset( $args['columns'] ) && is_array( $args['columns'] ) ? array_map( 'sanitize_text_field', $args['columns'] ) : array();
		$picker = null;

		if ( ! empty( $wanted ) ) {

			$known   = $export->get_active_fields();
			$unknown = array_diff( $wanted, array_keys( $known ) );

			if ( ! empty( $unknown ) ) {
				return new WP_Error(
					'woobe_mcp_export_unknown_columns',
					'These columns are not switched on in the editor, so they cannot be exported: ' . implode( ', ', $unknown )
					. '. An export can only narrow the visible columns, not add hidden ones - switch them on in the plugin settings first, or ask for a different set. Available right now: ' . implode( ', ', array_diff( array_keys( $known ), array( '__checker' ) ) )				);
			}

			$picker = function ( $fields ) use ( $wanted ) {

				$out = array();

				// the caller's order, not the editor's: a price list reads
				// differently from an inventory sheet even with the same columns
				foreach ( $wanted as $key ) {
					if ( isset( $fields[ $key ] ) ) {
						$out[ $key ] = $fields[ $key ];
					}
				}

				return empty( $out ) ? $fields : $out;
			};

			add_filter( 'woobe_export_active_fields', $picker );
		}

		$fields = $export->get_active_fields();

		if ( empty( $fields ) ) {
			$this->drop_picker( $picker );
			return new WP_Error( 'woobe_mcp_no_columns', 'No columns are switched on in the editor, so there is nothing to export. Turn some on in the plugin settings first.' );
		}

		$folder = $export->get_export_folder();

		if ( empty( $folder ) ) {
			$this->drop_picker( $picker );
			return new WP_Error( 'woobe_mcp_no_folder', 'The export folder is missing or not writable on this server.' );
		}

		$delimiter = isset( $args['delimiter'] ) ? substr( (string) $args['delimiter'], 0, 1 ) : ',';
		$delimiter = ( '' === $delimiter ) ? ',' : $delimiter;

		$name = 'woobe_mcp_export_' . gmdate( 'Y-m-d_H-i-s' ) . '_' . wp_generate_password( 6, false, false ) . '.csv';
		$file = $folder . $name;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$fp = fopen( $file, 'w' );

		if ( false === $fp ) {
			$this->drop_picker( $picker );
			return new WP_Error( 'woobe_mcp_export_write', 'Could not create the export file.' );
		}

		// BOM, so the file opens as UTF-8 in Excel rather than as mojibake -
		// the single most common complaint about any CSV export
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fwrite( $fp, "\xEF\xBB\xBF" );

		$titles = $export->get_csv_titles();

		fputcsv( $fp, $titles, $delimiter, '"', '\\' );

		$rows = 0;

		foreach ( $ids as $product_id ) {

			$row = array_map(
				array( $export, 'flatten_export_value' ),
				(array) $export->get_product_fields( intval( $product_id ), $fields )
			);

			fputcsv( $fp, $row, $delimiter, '"', '\\' );
			++$rows;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $fp );

		$this->drop_picker( $picker );

		$token = $this->issue_token( $name );

		return array(
			'file'         => $name,
			'rows'         => $rows,
			'columns'      => count( $titles ),
			// __checker is the editor's row checkbox, never a column in the
			// file; listing it made columns and column_keys disagree by one
			'column_keys'  => array_values( array_diff( array_keys( $fields ), array( '__checker' ) ) ),
			// rows counts variations as rows of their own, so it is larger
			// than the selection whenever variable products are in it
			'products'     => $selection_size,
			'size_kb'      => round( filesize( $file ) / 1024, 1 ),
			'download_url' => rest_url( 'woobe/v1/mcp/export/' . $token ),
			'expires_in'   => self::TOKEN_TTL,
			'note'         => 'Give the user the link as it is - it carries its own one time token and needs no key, so it opens in any browser. It stops working in ' . intval( self::TOKEN_TTL / 60 ) . ' minutes, after which run the export again. Unless specific columns were asked for, these are whichever ones are switched on in the editor right now.',
		);
	}

	/**
	 * Takes the column override back off.
	 *
	 * Called on every exit from export_csv(), not left to the end of the
	 * request: two exports with different columns in one conversation would
	 * otherwise have the second inherit the first one's filter.
	 */
	private function drop_picker( $picker ) {

		if ( $picker ) {
			remove_filter( 'woobe_export_active_fields', $picker );
		}
	}

	/**
	 * Adds the variations of any variable product in the list, keeping the
	 * parents so their own fields still export.
	 */
	private function with_children( $ids ) {

		$out = array();

		foreach ( $ids as $product_id ) {

			$out[]   = intval( $product_id );
			$product = $this->products()->get_product( $product_id );

			if ( $product && $product->is_type( 'variable' ) ) {
				foreach ( $product->get_children() as $child_id ) {
					$out[] = intval( $child_id );
				}
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * A short lived ticket for one file.
	 *
	 * Not the shop's MCP key: that was deliberately kept out of URLs, and a
	 * download has to be clickable in a browser, which cannot send headers.
	 * A per file token that expires is the compromise - losing it exposes one
	 * export for fifteen minutes instead of the whole catalogue forever.
	 */
	private function issue_token( $file_name ) {

		$token = bin2hex( random_bytes( 16 ) );

		set_transient( self::TOKEN_PREFIX . $token, $file_name, self::TOKEN_TTL );

		return $token;
	}
}