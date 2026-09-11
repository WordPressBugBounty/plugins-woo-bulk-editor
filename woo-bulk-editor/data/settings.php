<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

function woobe_get_total_settings( $data ) {
	return array(
		'per_page'                    => array(
			'title' => esc_html__( 'Default products count per page', 'woo-bulk-editor' ),
			
			'desc'  => sprintf(
				// translators: %s: link to documentation.
				esc_html__( 'How many rows of products show per page in tab Products. Max possible value is 100! To set more - read %s please', 'woo-bulk-editor' ),
				WOOBE_HELPER::draw_link(
					array(
						'href'   => 'https://bulk-editor.com/howto/set-more-rows-of-products-per-page/',
						'title'  => esc_html__( 'this article', 'woo-bulk-editor' ),
						'target' => '_blank',
					)
				)
			),
			'value' => '',
			'type'  => 'number',
		),
		'default_sort_by'             => array(
			'title'          => esc_html__( 'Default sort by', 'woo-bulk-editor' ),
			'desc'           => esc_html__( 'Select column by which products sorting is going after plugin page loaded', 'woo-bulk-editor' ),
			'value'          => '',
			'type'           => 'select',
			'select_options' => $data['default_sort_by'],
		),
		'default_sort'                => array(
			'title'          => esc_html__( 'Default sort', 'woo-bulk-editor' ),
			'desc'           => esc_html__( 'Select sort direction for Default sort by column above', 'woo-bulk-editor' ),
			'value'          => '',
			'type'           => 'select',
			'select_options' => array(
				'desc' => array( 'title' => 'DESC' ),
				'asc'  => array( 'title' => 'ASC' ),
			),
		),
		'show_admin_bar_menu_btn'     => array(
			'title'          => esc_html__( 'Show button in admin bar', 'woo-bulk-editor' ),
			'desc'           => esc_html__( 'Show Bulk Editor button in admin bar for quick access to the products editor', 'woo-bulk-editor' ),
			'value'          => '',
			'type'           => 'select',
			'select_options' => array(
				1 => array( 'title' => esc_html__( 'Yes', 'woo-bulk-editor' ) ),
				0 => array( 'title' => esc_html__( 'No', 'woo-bulk-editor' ) ),
			),
		),
		'show_thumbnail_preview'      => array(
			'title'          => esc_html__( 'Show thumbnail preview', 'woo-bulk-editor' ),
			'desc'           => esc_html__( 'Show bigger thumbnail preview on mouse over', 'woo-bulk-editor' ),
			'value'          => '',
			'type'           => 'select',
			'select_options' => array(
				1 => array( 'title' => esc_html__( 'Yes', 'woo-bulk-editor' ) ),
				0 => array( 'title' => esc_html__( 'No', 'woo-bulk-editor' ) ),
			),
		),
		'load_switchers'              => array(
			'title'          => esc_html__( 'Load beauty switchers', 'woo-bulk-editor' ),
			'desc'           => esc_html__( 'Load beauty switchers instead of checkboxes in the products table.', 'woo-bulk-editor' ),
			'value'          => '',
			'type'           => 'select',
			'select_options' => array(
				1 => array( 'title' => esc_html__( 'Yes', 'woo-bulk-editor' ) ),
				0 => array( 'title' => esc_html__( 'No', 'woo-bulk-editor' ) ),
			),
		),
		'number_posts_processed'      => array(
			'title'          => esc_html__( 'Number of posts processed at a time', 'woo-bulk-editor' ),
			'desc'           => esc_html__( 'This option is beneficial if your server has limited memory; by decreasing the speed of the operation, you can enhance stability. The more powerful your server, the higher value you can set here', 'woo-bulk-editor' ),
			'value'          => '',
			'type'           => 'select',
			'select_options' => array(
				10  => array( 'title' => esc_html__( '10 (for weak shared hosting)', 'woo-bulk-editor' ) ),
				20  => array( 'title' => esc_html__( '20', 'woo-bulk-editor' ) ),
				30  => array( 'title' => esc_html__( '30 (optimal)', 'woo-bulk-editor' ) ),
				40  => array( 'title' => esc_html__( '40', 'woo-bulk-editor' ) ),
				50  => array( 'title' => esc_html__( '50', 'woo-bulk-editor' ) ),
				60  => array( 'title' => esc_html__( '60', 'woo-bulk-editor' ) ),
				70  => array( 'title' => esc_html__( '70', 'woo-bulk-editor' ) ),
				80  => array( 'title' => esc_html__( '80', 'woo-bulk-editor' ) ),
				90  => array( 'title' => esc_html__( '90 (for very strong servers only)', 'woo-bulk-editor' ) ),
				100 => array( 'title' => esc_html__( '100', 'woo-bulk-editor' ) ),
			),
		),
		'autocomplete_max_elem_count' => array(
			'title' => esc_html__( 'Autocomplete max count', 'woo-bulk-editor' ),
			'desc'  => esc_html__( 'How many products display in the autocomplete drop-downs. Uses in up-sells, cross-sells and grouped popups.', 'woo-bulk-editor' ),
			'value' => '',
			'type'  => 'number',
		),
		'quick_search_fieds'          => array(
			'title' => esc_html__( 'Add fields to the quick search', 'woo-bulk-editor' ),
			'desc'  => esc_html__( 'Adds more fields to quick search fields drop-down on the tools panel. Works only for text fields. Syntax: post_name:Product slug,post_content: Content', 'woo-bulk-editor' ),
			'value' => '',
			'type'  => 'text',
		),
		'vendor_roles'                => array(
			'title' => esc_html__( 'Vendor roles', 'woo-bulk-editor' ),
			'desc'  => esc_html__( 'Add a vendor role slug and all vendors will only be able to see and edit their own product', 'woo-bulk-editor' ),
			'value' => '',
			'type'  => 'text',
		),
		'override_switcher_fieds'     => array(
			'title' => esc_html__( 'Override meta checkbox value', 'woo-bulk-editor' ),
			'desc'  => esc_html__( 'By default, the checkbox works with 1 and 0. If you need to redefine these values for example to "yes" or "true". Syntax: meta_key1:yes^no,meta_key2:true', 'woo-bulk-editor' ),
			'value' => '',
			'type'  => 'text',
		),
		'sync_profiles'               => array(
			'title'          => esc_html__( 'Synchronize managers profiles', 'woo-bulk-editor' ),
			'desc'           => esc_html__( 'The profiles will be the same for all managers. Except the administrator.', 'woo-bulk-editor' ),
			'value'          => '',
			'type'           => 'select',
			'select_options' => array(
				1 => array( 'title' => esc_html__( 'Yes', 'woo-bulk-editor' ) ),
				0 => array( 'title' => esc_html__( 'No', 'woo-bulk-editor' ) ),
			),
		),
		'autocomplet_txt_search'      => array(
			'title'          => esc_html__( 'Text search type', 'woo-bulk-editor' ),
			'desc'           => esc_html__( 'What fields to search for Cross-sells and Upsells', 'woo-bulk-editor' ),
			'value'          => '',
			'type'           => 'select',
			'select_options' => array(
				0 => array( 'title' => esc_html__( 'Search by title', 'woo-bulk-editor' ) ),
				1 => array( 'title' => esc_html__( 'Search by SKU, Title, ID.', 'woo-bulk-editor' ) ),
			),
		),
		'show_text_editor'            => array(
			'title'          => esc_html__( 'Show content and excerpt text partly', 'woo-bulk-editor' ),
			'desc'           => esc_html__( 'Show part of the text in the buttons of the content and excerpt', 'woo-bulk-editor' ),
			'value'          => '',
			'type'           => 'select',
			'select_options' => array(
				1 => array( 'title' => esc_html__( 'Yes', 'woo-bulk-editor' ) ),
				0 => array( 'title' => esc_html__( 'No', 'woo-bulk-editor' ) ),
			),
		),
		'storage_type'                => array(
			'title'          => esc_html__( 'Plugin memory storage', 'woo-bulk-editor' ),
			'desc'           => esc_html__( 'Where the plugin keeps the current filter and bulk data between requests.', 'woo-bulk-editor' ),
			'value'          => '',
			'type'           => 'select',
			'select_options' => array(
				'option'    => array( 'title' => esc_html__( 'Option (recommended)', 'woo-bulk-editor' ) ),
				'transient' => array( 'title' => esc_html__( 'Transient', 'woo-bulk-editor' ) ),
				//'session'   => array( 'title' => esc_html__( 'Session', 'woo-bulk-editor' ) ),
				//'cookie'    => array( 'title' => esc_html__( 'Cookie', 'woo-bulk-editor' ) ),
			),
		),
		'mcp_enabled'                 => array(
			'title'          => esc_html__( 'Enable the MCP server', 'woo-bulk-editor' ),
			'desc'           => esc_html__( 'Off by default. The MCP server lets an AI assistant read and edit this shop over a REST endpoint. While it is off, the endpoint is not registered at all and nothing can reach it. Turn it on only when you are about to connect an assistant, and turn it back off if you stop using one.', 'woo-bulk-editor' ),
			'value'          => '',
			'type'           => 'select',
			'select_options' => array(
				0 => array( 'title' => esc_html__( 'No', 'woo-bulk-editor' ) ),
				1 => array( 'title' => esc_html__( 'Yes', 'woo-bulk-editor' ) ),
			),
		),
		'mcp_key'                     => array(
			'title'          => esc_html__( 'MCP secret key', 'woo-bulk-editor' ),
			'desc'           => esc_html__( 'Lets an AI assistant work with this shop: read products, run reports and make bulk edits, all through a chat. Give the assistant the address below and this key as a request header named Authorization, with the value "Bearer" followed by a space and the key. Some clients call it an API key header instead - X-WOOBE-KEY works the same way. The key is never accepted in the address itself, because anything written into a URL ends up in server logs, browser history and every proxy along the way. Clear this field and press Save to issue a new key: the old one stops working at once and every connected assistant has to be reconnected.', 'woo-bulk-editor' ),
			'underfield'     => sprintf(
				/* translators: 1: the MCP endpoint URL, 2: documentation link */
				esc_html__( 'Address for the assistant: %1$s %2$s', 'woo-bulk-editor' ),
				'<code style="user-select: all;">' . esc_url( rest_url( 'woobe/v1/mcp' ) ) . '</code>',
				'<br /><a href="https://bulk-editor.com/document/mcp-server/" target="_blank" class="button button-primary" style="margin-top: 6px;"><span class="icon-book"></span>&nbsp;' . esc_html__( 'How to connect an AI assistant', 'woo-bulk-editor' ) . '</a>'
			),
			'value'          => '',
			'type'           => 'text',
		),
		'mcp_2fa'                     => array(
			'title'          => esc_html__( 'MCP two-factor connection', 'woo-bulk-editor' ),
			'desc'           => sprintf(
				/* translators: %s: how long a connection may stay idle */
				esc_html__( 'Off by default, recommended. With it on, the MCP key alone is not enough: every assistant session starts by showing you a token, you paste it below and confirm, and only then can the assistant work. The connection ends by itself after %s without activity, so a leaked key is useless without you. One assistant is connected at a time.', 'woo-bulk-editor' ),
				class_exists( 'WOOBE_MCP_BOOT' ) ? WOOBE_MCP_BOOT::idle_text() : '3600 seconds'
			),
			'value'          => '',
			'type'           => 'select',
			'select_options' => array(
				0 => array( 'title' => esc_html__( 'No', 'woo-bulk-editor' ) ),
				1 => array( 'title' => esc_html__( 'Yes', 'woo-bulk-editor' ) ),
			),
		),
	);
}
