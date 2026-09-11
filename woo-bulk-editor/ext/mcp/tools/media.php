<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Product images: the featured one and the gallery.
 *
 * What this can and cannot do is worth being plain about, because the obvious
 * expectation is the one thing that does not work.
 *
 * An image pasted into a chat cannot be put on the shop. The assistant sees a
 * picture, not a file, and has no way to hand the bytes to a server. What it
 * can do is fetch an image from a URL, and attach images already in the media
 * library.
 *
 * It also cannot show the user his own images. The sandbox that renders replies
 * will not load files from the shop's domain, so a gallery cannot be displayed
 * for picking. woobe_media_list therefore returns names, dates, sizes and a
 * link per image: the owner opens what he needs in another tab and says which
 * ones he wants by name or number. Clumsier than thumbnails, and honest.
 */
final class WOOBE_MCP_TOOL_MEDIA extends WOOBE_MCP_TOOL {

	public function tools() {

		return array(

			'woobe_media_list' => array(
				'name'        => 'woobe_media_list',
				'description' => 'Images in the media library, newest first, with id, file name, upload date, size and a link. Use it when the user refers to images he uploaded rather than giving ids - "the photos I put up yesterday" is answered by listing that day and reading the names back. The images themselves cannot be displayed in the conversation, so let him open the links and tell you which ones he means.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'search'     => array(
							'type'        => 'string',
							'description' => 'Part of a file name or title.',
						),
						'uploaded_after' => array(
							'type'        => 'string',
							'description' => 'Anything strtotime reads - "yesterday", "-7 days", "2026-09-01".',
						),
						'unattached' => array(
							'type'        => 'boolean',
							'description' => 'Only images not attached to any product yet. Useful right after a batch upload.',
						),
						'limit'      => array( 'type' => 'integer' ),
					),
				),
				'annotations' => array( 'readOnlyHint' => true ),
			),

			'woobe_product_images' => array(
				'name'        => 'woobe_product_images',
				'description' => 'What images a product currently has: the featured one and the gallery, in order. Read this before changing anything so the user hears what is there now - people usually mean "add to" rather than "replace".',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'product_id' => array( 'type' => 'integer' ),
					),
					'required'   => array( 'product_id' ),
				),
				'annotations' => array( 'readOnlyHint' => true ),
			),

			'woobe_set_images' => array(
				'name'        => 'woobe_set_images',
				'description' => 'Sets the featured image and the gallery of a product from images already in the media library. Gallery order is the order of the ids given. Say what will change before calling it, especially when replacing: the old gallery is not kept anywhere and putting it back means naming every image again.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'product_id' => array( 'type' => 'integer' ),
						'featured'   => array(
							'type'        => 'integer',
							'description' => 'Attachment id for the main product image. Pass 0 to remove it.',
						),
						'gallery'    => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'integer' ),
							'description' => 'Attachment ids for the gallery, in the order they should appear.',
						),
						'mode'       => array(
							'type'        => 'string',
							'enum'        => array( 'replace', 'add', 'remove' ),
							'description' => 'What to do with gallery. replace swaps the whole gallery, add appends what is not there yet, remove takes those out. Defaults to add, because that is what people usually mean.',
						),
					),
					'required'   => array( 'product_id' ),
				),
				'annotations' => array( 'readOnlyHint' => false ),
			),

			'woobe_upload_link' => array(
				'name'        => 'woobe_upload_link',
				'description' => 'Gives the user a link to a small upload page for this shop, where he drags photos from his computer straight into the media library. This is the way to get images off somebody\'s desktop: a picture pasted into a chat cannot be forwarded to a server, and asking him to put it on a file sharing service first is a detour nobody enjoys. The link lasts fifteen minutes and needs no login. Offer it the moment the user says he has photos to add.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'product_id' => array(
							'type'        => 'integer',
							'description' => 'Optional. Whatever he uploads is attached to this product automatically.',
						),
						'as'         => array(
							'type'        => 'string',
							'enum'        => array( 'featured', 'gallery' ),
							'description' => 'Where to put them on that product. Defaults to featured for the first image when the product has none, gallery for the rest.',
						),
					),
				),
				'annotations' => array( 'readOnlyHint' => false ),
			),

			'woobe_upload_status' => array(
				'name'        => 'woobe_upload_status',
				'description' => 'What has arrived through an upload link so far. Call it after the user says he has dropped his files - it tells you the ids and names without hunting through the library, and whether they were attached to a product.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'token' => array( 'type' => 'string' ),
					),
					'required'   => array( 'token' ),
				),
				'annotations' => array( 'readOnlyHint' => true ),
			),

			'woobe_upload_image' => array(
				'name'        => 'woobe_upload_image',
				'description' => 'Puts an image into the media library from base64 data. For agents that hold the file themselves - a coding assistant with disk access, a script. In a chat this is the wrong tool: encoding a photograph as text costs more than the whole conversation around it, so offer woobe_upload_link instead and let the user drop the file in a browser. Refused above one megabyte for the same reason.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'filename'   => array(
							'type'        => 'string',
							'description' => 'File name with its extension, which decides the type.',
						),
						'data'       => array(
							'type'        => 'string',
							'description' => 'The file, base64 encoded. A data: prefix is accepted and stripped.',
						),
						'product_id' => array( 'type' => 'integer' ),
						'as'         => array(
							'type' => 'string',
							'enum' => array( 'featured', 'gallery' ),
						),
						'alt'        => array( 'type' => 'string' ),
					),
					'required'   => array( 'filename', 'data' ),
				),
				'annotations' => array( 'readOnlyHint' => false ),
			),

			'woobe_import_image' => array(
				'name'        => 'woobe_import_image',
				'description' => 'Downloads an image from a URL into the media library and optionally attaches it to a product. Use it when the picture is already online - a supplier\'s page, a CDN link. A picture pasted into the chat cannot be forwarded, because the assistant sees an image rather than a file: when the photo is on the user\'s computer, give him woobe_upload_link instead and let him drop the file in a browser.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'url'        => array(
							'type'        => 'string',
							'description' => 'Direct https link to the image file itself, not to a page containing it.',
						),
						'product_id' => array(
							'type'        => 'integer',
							'description' => 'Optional. Attach it to this product once downloaded.',
						),
						'as'         => array(
							'type'        => 'string',
							'enum'        => array( 'featured', 'gallery' ),
							'description' => 'Where to put it on that product. Defaults to featured when the product has no image, gallery otherwise.',
						),
						'title'      => array( 'type' => 'string' ),
						'alt'        => array(
							'type'        => 'string',
							'description' => 'Alt text. Worth setting: it is what a search engine and a screen reader read.',
						),
					),
					'required'   => array( 'url' ),
				),
				'annotations' => array( 'readOnlyHint' => false ),
			),
		);
	}

	public function call( $name, $args ) {

		switch ( $name ) {
			case 'woobe_media_list':
				return $this->media_list( $args );
			case 'woobe_product_images':
				return $this->product_images( $args );
			case 'woobe_set_images':
				return $this->set_images( $args );
			case 'woobe_import_image':
				return $this->import_image( $args );
			case 'woobe_upload_link':
				return $this->upload_link( $args );
			case 'woobe_upload_status':
				return $this->upload_status( $args );
			case 'woobe_upload_image':
				return $this->upload_image( $args );
		}

		return new WP_Error( 'woobe_mcp_unknown_tool', 'Unknown tool: ' . $name );
	}

	// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

	private function media_list( $args ) {

		$query = array(
			'post_type'      => 'attachment',
			'post_mime_type' => 'image',
			'post_status'    => 'inherit',
			'posts_per_page' => isset( $args['limit'] ) ? min( 100, max( 1, intval( $args['limit'] ) ) ) : 30,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		if ( ! empty( $args['search'] ) ) {
			$query['s'] = sanitize_text_field( $args['search'] );
		}

		if ( ! empty( $args['uploaded_after'] ) ) {

			$after = strtotime( (string) $args['uploaded_after'] );

			if ( $after ) {
				$query['date_query'] = array( array( 'after' => gmdate( 'Y-m-d H:i:s', $after ) ) );
			}
		}

		// nothing attached yet: what a batch upload leaves behind
		if ( ! empty( $args['unattached'] ) ) {
			$query['post_parent'] = 0;
		}

		$posts = get_posts( $query );
		$rows  = array();

		foreach ( $posts as $post ) {

			$meta = wp_get_attachment_metadata( $post->ID );
			$file = get_post_meta( $post->ID, '_wp_attached_file', true );

			$rows[] = array(
				'id'        => $post->ID,
				'title'     => $post->post_title,
				'file'      => $file ? basename( $file ) : '',
				'uploaded'  => $post->post_date,
				'size'      => ( isset( $meta['width'], $meta['height'] ) ) ? $meta['width'] . 'x' . $meta['height'] : '',
				'url'       => wp_get_attachment_url( $post->ID ),
				'used_by'   => $this->used_by( $post->ID ),
			);
		}

		return array(
			'count' => count( $rows ),
			'rows'  => $rows,
			'note'  => 'The images cannot be shown in the conversation - this connection can read the library but the reply cannot display files from the shop. Read the names and dates back to the user and let him open the links to check; then he tells you which ids he wants. used_by names the products already using each image, which is often enough for him to recognise it without opening anything.',
		);
	}

	/**
	 * Which products already use this image. Recognising "the one on the blue
	 * hoodie" is easier than recognising IMG_4471.jpg.
	 */
	private function used_by( $attachment_id ) {

		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta}
				  WHERE ( meta_key = '_thumbnail_id' AND meta_value = %s )
					 OR ( meta_key = '_product_image_gallery' AND ( meta_value = %s OR meta_value LIKE %s OR meta_value LIKE %s OR meta_value LIKE %s ) )
				  LIMIT 5",
				$attachment_id,
				$attachment_id,
				$wpdb->esc_like( $attachment_id . ',' ) . '%',
				'%' . $wpdb->esc_like( ',' . $attachment_id . ',' ) . '%',
				'%' . $wpdb->esc_like( ',' . $attachment_id )
			)
		);

		$out = array();

		foreach ( (array) $ids as $product_id ) {

			if ( 'product' === get_post_type( $product_id ) || 'product_variation' === get_post_type( $product_id ) ) {
				$out[] = $this->product_label( $product_id );
			}
		}

		return $out;
	}

	private function product_images( $args ) {

		$product_id = isset( $args['product_id'] ) ? intval( $args['product_id'] ) : 0;
		$product    = $this->products()->get_product( $product_id );

		if ( ! $product ) {
			return new WP_Error( 'woobe_mcp_no_product', 'No such product: ' . $product_id );
		}

		$featured_id = $product->get_image_id();
		$gallery_ids = $product->get_gallery_image_ids();

		return array(
			'product_id' => $product_id,
			'title'      => $this->product_label( $product_id ),
			'featured'   => $featured_id ? $this->describe( $featured_id ) : null,
			'gallery'    => array_map( array( $this, 'describe' ), $gallery_ids ),
			'note'       => 'Gallery order is the order shown here, and that is the order a customer sees. When the user asks to add an image, add rather than replace unless he said replace - the old gallery is not stored anywhere once it is overwritten.',
		);
	}

	private function describe( $attachment_id ) {

		$meta = wp_get_attachment_metadata( $attachment_id );
		$file = get_post_meta( $attachment_id, '_wp_attached_file', true );

		return array(
			'id'    => intval( $attachment_id ),
			'title' => get_the_title( $attachment_id ),
			'file'  => $file ? basename( $file ) : '',
			'size'  => ( isset( $meta['width'], $meta['height'] ) ) ? $meta['width'] . 'x' . $meta['height'] : '',
			'url'   => wp_get_attachment_url( $attachment_id ),
			'alt'   => get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
		);
	}

	private function set_images( $args ) {

		$product_id = isset( $args['product_id'] ) ? intval( $args['product_id'] ) : 0;
		$product    = $this->products()->get_product( $product_id );

		if ( ! $product ) {
			return new WP_Error( 'woobe_mcp_no_product', 'No such product: ' . $product_id );
		}

		$before  = array(
			'featured' => $product->get_image_id(),
			'gallery'  => $product->get_gallery_image_ids(),
		);
		$changed = array();

		if ( isset( $args['featured'] ) ) {

			$featured = intval( $args['featured'] );

			if ( $featured && ! $this->is_image( $featured ) ) {
				return new WP_Error( 'woobe_mcp_not_an_image', 'Attachment ' . $featured . ' is not an image in this library.' );
			}

			$product->set_image_id( $featured ? $featured : '' );
			$changed[] = 'featured';
		}

		if ( isset( $args['gallery'] ) && is_array( $args['gallery'] ) ) {

			$asked = array_map( 'intval', $args['gallery'] );

			foreach ( $asked as $id ) {
				if ( ! $this->is_image( $id ) ) {
					return new WP_Error( 'woobe_mcp_not_an_image', 'Attachment ' . $id . ' is not an image in this library. Nothing was changed.' );
				}
			}

			$mode    = isset( $args['mode'] ) ? sanitize_key( $args['mode'] ) : 'add';
			$current = $before['gallery'];

			switch ( $mode ) {
				case 'replace':
					$gallery = $asked;
					break;
				case 'remove':
					$gallery = array_values( array_diff( $current, $asked ) );
					break;
				default:
					$gallery = array_values( array_unique( array_merge( $current, $asked ) ) );
			}

			$product->set_gallery_image_ids( $gallery );
			$changed[] = 'gallery';
		}

		if ( empty( $changed ) ) {
			return new WP_Error( 'woobe_mcp_nothing_to_set', 'Neither featured nor gallery was given, so there was nothing to change.' );
		}

		$product->save();

		$this->mcp->clear_caches_for( array( $product_id ) );

		$after = $this->products()->get_product( $product_id );

		return array(
			'product_id' => $product_id,
			'title'      => $this->product_label( $product_id ),
			'changed'    => $changed,
			'featured'   => $after->get_image_id() ? $this->describe( $after->get_image_id() ) : null,
			'gallery'    => array_map( array( $this, 'describe' ), $after->get_gallery_image_ids() ),
			'note'       => 'Say what the product has now rather than what was passed in - the two differ when adding, and the user wants to hear the result.',
		);
	}

	private function is_image( $attachment_id ) {

		$attachment_id = intval( $attachment_id );

		return $attachment_id
			&& 'attachment' === get_post_type( $attachment_id )
			&& 0 === strpos( (string) get_post_mime_type( $attachment_id ), 'image/' );
	}

	/**
	 * Refuses addresses that point back inside the network.
	 *
	 * Fetching a URL on request is a door into wherever the server can reach,
	 * and a server can usually reach far more than the internet can: the
	 * metadata service of a cloud host, a database admin panel on localhost, a
	 * router on the office LAN. The caller holds a valid key, so this is not
	 * the first line of defence - but a key is one secret with no expiry, and
	 * a copied one should not come with the shop's private network attached.
	 *
	 * Returns an explanation when the address is refused, or an empty string
	 * when it is fine.
	 */
	private static function blocked_host( $url ) {

		$host = wp_parse_url( $url, PHP_URL_HOST );

		if ( ! $host ) {
			return 'That address has no host in it.';
		}

		// a literal address is checked as it stands; a name has to be resolved
		// first, or "internal.example.com" pointing at 10.0.0.5 walks straight
		// past a check on the text
		$ips = filter_var( $host, FILTER_VALIDATE_IP ) ? array( $host ) : array();

		if ( empty( $ips ) ) {

			$resolved = gethostbynamel( $host );
			$ips      = is_array( $resolved ) ? $resolved : array();

			// IPv6 is not covered by gethostbynamel, and an address that only
			// resolves over v6 cannot be checked here at all
			if ( empty( $ips ) ) {
				return 'That host could not be resolved.';
			}
		}

		foreach ( $ips as $ip ) {

			// FILTER_FLAG_NO_PRIV_RANGE and NO_RES_RANGE together cover
			// 10.x, 172.16-31.x, 192.168.x, 127.x, 169.254.x, ::1 and the
			// reserved blocks - which is every way back into the network
			if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
				return 'That address points inside the server\'s own network (' . $ip . '), which this tool will not fetch from. Give a public link to the image, or use the upload page instead - woobe_upload_link.';
			}
		}

		return '';
	}

	private function import_image( $args ) {

		$url = isset( $args['url'] ) ? esc_url_raw( trim( (string) $args['url'] ) ) : '';

		if ( '' === $url || ! in_array( wp_parse_url( $url, PHP_URL_SCHEME ), array( 'http', 'https' ), true ) ) {
			return new WP_Error( 'woobe_mcp_bad_url', 'That is not an http or https address.' );
		}

		$blocked = self::blocked_host( $url );

		if ( $blocked ) {
			return new WP_Error( 'woobe_mcp_blocked_host', $blocked );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$product_id = isset( $args['product_id'] ) ? intval( $args['product_id'] ) : 0;

		if ( $product_id && ! $this->products()->get_product( $product_id ) ) {
			return new WP_Error( 'woobe_mcp_no_product', 'No such product: ' . $product_id );
		}

		// download_url follows redirects, so a public address can hand the
		// request straight to a private one. Five is WordPress's own default
		// and the filter is scoped to this request only.
		$limit_redirects = function () {
			return 0;
		};

		add_filter( 'http_request_redirection_count', $limit_redirects, 99 );

		$tmp = download_url( $url, 30 );

		remove_filter( 'http_request_redirection_count', $limit_redirects, 99 );

		if ( is_wp_error( $tmp ) ) {
			return new WP_Error( 'woobe_mcp_download_failed', 'Could not fetch that address: ' . $tmp->get_error_message() );
		}

		$file = array(
			'name'     => basename( wp_parse_url( $url, PHP_URL_PATH ) ),
			'tmp_name' => $tmp,
		);

		// media_handle_sideload checks the file type itself and refuses
		// anything that is not really an image, whatever the URL claimed
		$attachment_id = media_handle_sideload(
			$file,
			$product_id,
			isset( $args['title'] ) ? sanitize_text_field( $args['title'] ) : null
		);

		if ( is_wp_error( $attachment_id ) ) {

			wp_delete_file( $tmp );

			return new WP_Error( 'woobe_mcp_sideload_failed', 'The file was fetched but not accepted: ' . $attachment_id->get_error_message() );
		}

		if ( ! $this->is_image( $attachment_id ) ) {
			wp_delete_attachment( $attachment_id, true );
			return new WP_Error( 'woobe_mcp_not_an_image', 'That address did not lead to an image.' );
		}

		if ( ! empty( $args['alt'] ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $args['alt'] ) );
		}

		$attached_as = null;

		if ( $product_id ) {

			$product = $this->products()->get_product( $product_id );
			$as      = isset( $args['as'] ) ? sanitize_key( $args['as'] ) : '';

			// no image yet means this one is almost certainly the main one
			if ( '' === $as ) {
				$as = $product->get_image_id() ? 'gallery' : 'featured';
			}

			if ( 'featured' === $as ) {
				$product->set_image_id( $attachment_id );
			} else {
				$gallery   = $product->get_gallery_image_ids();
				$gallery[] = $attachment_id;
				$product->set_gallery_image_ids( array_values( array_unique( $gallery ) ) );
			}

			$product->save();
			$this->mcp->clear_caches_for( array( $product_id ) );

			$attached_as = $as;
		}

		return array(
			'attachment' => $this->describe( $attachment_id ),
			'product_id' => $product_id ? $product_id : null,
			'attached_as' => $attached_as,
			'note'        => $attached_as
				? 'Downloaded into the library and set as the ' . $attached_as . ' image. Give the user the file link so he can check it arrived intact - a fetched image is occasionally the wrong size or a placeholder the other site serves to strangers.'
				: 'Downloaded into the library but not attached to anything. Its id is above; woobe_set_images puts it on a product.',
		);
	}

	// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
	// getting images off somebody's computer

	const UPLOAD_PREFIX = 'woobe_mcp_upload_';
	const UPLOAD_TTL    = 900;

	/**
	 * A short lived page the user can drop files onto.
	 *
	 * The gap this closes: a photograph lives on the owner's desktop, and
	 * neither a chat nor an API can reach it. Encoding it as text is possible
	 * and absurd - a single product photo costs more than the conversation
	 * around it. So the shop opens a door for fifteen minutes instead, and the
	 * browser does what browsers are good at.
	 *
	 * The token is the credential: single purpose, expiring, and tied to one
	 * product if a product was named. Losing it exposes the ability to add an
	 * image for a quarter of an hour, not the shop.
	 */
	private function upload_link( $args ) {

		$product_id = isset( $args['product_id'] ) ? intval( $args['product_id'] ) : 0;

		if ( $product_id && ! $this->products()->get_product( $product_id ) ) {
			return new WP_Error( 'woobe_mcp_no_product', 'No such product: ' . $product_id );
		}

		$token = bin2hex( random_bytes( 16 ) );

		set_transient(
			self::UPLOAD_PREFIX . $token,
			array(
				'product_id' => $product_id,
				'as'         => isset( $args['as'] ) ? sanitize_key( $args['as'] ) : '',
				'uploaded'   => array(),
			),
			self::UPLOAD_TTL
		);

		return array(
			'upload_url' => rest_url( 'woobe/v1/mcp/upload/' . $token ),
			'token'      => $token,
			'product_id' => $product_id ? $product_id : null,
			'product'    => $product_id ? $this->product_label( $product_id ) : null,
			'expires_in' => self::UPLOAD_TTL,
			'note'       => 'Give the user the link as it is - it opens a page where he drags his photos in, and it needs no login. Tell him it works for fifteen minutes. When he says he is done, call woobe_upload_status with the token to see what arrived; do not guess, and do not go looking through the whole media library.',
		);
	}

	private function upload_status( $args ) {

		$token = isset( $args['token'] ) ? preg_replace( '/[^a-f0-9]/', '', (string) $args['token'] ) : '';
		$data  = $token ? get_transient( self::UPLOAD_PREFIX . $token ) : false;

		if ( ! is_array( $data ) ) {
			return new WP_Error(
				'woobe_mcp_upload_expired',
				'That upload link has expired or was never issued. Ask woobe_upload_link for a new one - anything already uploaded is safe in the library and woobe_media_list finds it.'
			);
		}

		$rows = array();

		foreach ( $data['uploaded'] as $attachment_id ) {
			$rows[] = $this->describe( $attachment_id );
		}

		return array(
			'count'      => count( $rows ),
			'images'     => $rows,
			'product_id' => $data['product_id'] ? $data['product_id'] : null,
			'note'       => empty( $rows )
				? 'Nothing has arrived yet. The user may still be choosing files - ask before assuming something went wrong, and do not issue a second link, the first one still works.'
				: 'These are in the library now' . ( $data['product_id'] ? ' and on the product' : '' ) . '. Read the file names back so the user can confirm they are the ones he meant.',
		);
	}

	/**
	 * The same thing for an agent that already holds the file.
	 *
	 * Sensible from a script or a coding assistant, wasteful from a chat: the
	 * ceiling is there to make the wrong caller notice before it has spent the
	 * conversation on one photograph.
	 */
	private function upload_image( $args ) {

		$filename = isset( $args['filename'] ) ? sanitize_file_name( $args['filename'] ) : '';
		$data     = isset( $args['data'] ) ? (string) $args['data'] : '';

		if ( '' === $filename || '' === $data ) {
			return new WP_Error( 'woobe_mcp_no_file', 'Both filename and data are needed.' );
		}

		// a data: URL is what most callers have to hand
		if ( 0 === strpos( $data, 'data:' ) ) {
			$comma = strpos( $data, ',' );
			$data  = false === $comma ? '' : substr( $data, $comma + 1 );
		}

		$bytes = base64_decode( $data, true );

		if ( false === $bytes || '' === $bytes ) {
			return new WP_Error( 'woobe_mcp_bad_base64', 'That is not valid base64 data.' );
		}

		if ( strlen( $bytes ) > MB_IN_BYTES ) {
			return new WP_Error(
				'woobe_mcp_upload_too_big',
				'That file is ' . size_format( strlen( $bytes ) ) . ', and this route is capped at one megabyte because the data travels as text. Use woobe_upload_link instead: the user drops the file in a browser and it goes straight into the library at any size.'
			);
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = wp_tempnam( $filename );

		if ( ! $tmp ) {
			return new WP_Error( 'woobe_mcp_tmp_failed', 'Could not create a temporary file.' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $tmp, $bytes );

		return $this->sideload(
			array(
				'name'     => $filename,
				'tmp_name' => $tmp,
			),
			isset( $args['product_id'] ) ? intval( $args['product_id'] ) : 0,
			isset( $args['as'] ) ? sanitize_key( $args['as'] ) : '',
			isset( $args['alt'] ) ? sanitize_text_field( $args['alt'] ) : ''
		);
	}

	/**
	 * One path into the library for every caller: the upload page, the base64
	 * route and the URL import all end here, so an image is validated and
	 * attached the same way whichever door it came through.
	 */
	public function sideload( $file, $product_id = 0, $as = '', $alt = '' ) {

		if ( $product_id && ! $this->products()->get_product( $product_id ) ) {
			return new WP_Error( 'woobe_mcp_no_product', 'No such product: ' . $product_id );
		}

		$attachment_id = media_handle_sideload( $file, $product_id );

		if ( is_wp_error( $attachment_id ) ) {

			wp_delete_file( $file['tmp_name'] );

			return new WP_Error( 'woobe_mcp_sideload_failed', 'The file was not accepted: ' . $attachment_id->get_error_message() );
		}

		if ( ! $this->is_image( $attachment_id ) ) {
			wp_delete_attachment( $attachment_id, true );
			return new WP_Error( 'woobe_mcp_not_an_image', 'That file is not an image.' );
		}

		if ( '' !== $alt ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt );
		}

		$attached_as = null;

		if ( $product_id ) {

			$product = $this->products()->get_product( $product_id );

			if ( '' === $as ) {
				$as = $product->get_image_id() ? 'gallery' : 'featured';
			}

			if ( 'featured' === $as ) {
				$product->set_image_id( $attachment_id );
			} else {
				$gallery   = $product->get_gallery_image_ids();
				$gallery[] = $attachment_id;
				$product->set_gallery_image_ids( array_values( array_unique( $gallery ) ) );
			}

			$product->save();
			$this->mcp->clear_caches_for( array( $product_id ) );

			$attached_as = $as;
		}

		return array(
			'attachment'  => $this->describe( $attachment_id ),
			'product_id'  => $product_id ? $product_id : null,
			'attached_as' => $attached_as,
			'note'        => $attached_as
				? 'In the library and set as the ' . $attached_as . ' image.'
				: 'In the library, not attached to anything yet. woobe_set_images puts it on a product.',
		);
	}
}