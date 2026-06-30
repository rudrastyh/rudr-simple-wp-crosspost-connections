<?php
/*
 * Plugin name: Simple WP Crossposting – Post Connections
 * Plugin URL: https://rudrastyh.com/support/connect-existing-post-copies
 * Description: This add-on allows to manually establish the connections between post duplicates on different sites.
 * Author: Misha Rudrastyh
 * Author URI: https://rudrastyh.com
 * Version: 1.9
 */

// Add Bulk Actions for Any Existing Post Type
add_action( 'admin_init', function() {

	if( ! class_exists( 'Rudr_Simple_WP_Crosspost' ) ) {
		return;
	}

	$post_types = get_post_types( array( 'public' => true ) );
	$allowed_post_types = ( $allowed_post_types = get_option( 'rudr_sac_post_types' ) ) ? $allowed_post_types : array();
	$allowed_post_types = apply_filters( 'rudr_crosspost_allowed_post_types', $allowed_post_types );

	if( $allowed_post_types && is_array( $allowed_post_types ) ) {
		$post_types = array_intersect( $post_types, $allowed_post_types );
	}


	foreach( $post_types as $post_type ) {
		add_filter( 'bulk_actions-edit-' . $post_type, 'rudr_wp_crosspost_bulk_connections' );
		add_filter( 'handle_bulk_actions-edit-' . $post_type, 'rudr_wp_crosspost_handle_bulk_connections', 10, 3 );
	}

	add_filter( 'bulk_actions-upload', 'rudr_wp_crosspost_bulk_connections' );
	add_filter( 'handle_bulk_actions-upload', 'rudr_wp_crosspost_handle_bulk_connections', 10, 3 );

}, 9999 );


// Add Bulk Options here
function rudr_wp_crosspost_bulk_connections( $bulk_array ) {

	$blogs = Rudr_Simple_WP_Crosspost::get_blogs();

	if( $blogs ) {
		foreach( $blogs as $blog ) {
			$bulk_array[ 'connect_to_'.Rudr_Simple_WP_Crosspost::get_blog_id( $blog ) ] = 'Connect to ' . esc_attr( $blog[ 'name' ] );
		}
	}
	return $bulk_array;

}

// Doing Connect
function rudr_wp_crosspost_handle_bulk_connections( $redirect, $doaction, $object_ids ) {

	$redirect = remove_query_arg(
		array( 'rudr_crosspost_too_much_to_crosspost', 'rudr_crosspost_done', 'rudr_connect_done' ),
		$redirect
	);
	$connected = 0;

	if( 'connect_to_' === substr( $doaction, 0, 11 ) ) {

		// get blog information
		$blog_id = str_replace( 'connect_to_', '', $doaction );
		$blog = Rudr_Simple_WP_Crosspost::get_blog( $blog_id );


		foreach ( $object_ids as $object_id ) {
			// we just need to get an ID of a post by slug or a product by SKU!
			$post_type = get_post_type( $object_id );

			if( false === $post_type ) {
				continue;
			}

			if( 'product' === $post_type && function_exists( 'wc_get_product' ) ) {
				// let's get product object anyway
				$product_site_1 = wc_get_product( $object_id );

				// no needs to initiate the whole product object here
				// $sku = get_post_meta( $object_id, '_sku', true );
				$sku = $product_site_1->get_sku();
				if( ! $sku ) {
					continue;
				}

				$request = wp_remote_get(
					add_query_arg(
						array(
							'sku' => urlencode( $sku ),
						),
						$blog[ 'url' ] . '/wp-json/wc/v3/products'
					),
					array(
						'headers' => array(
							'Authorization' => 'Basic ' . base64_encode( "{$blog[ 'login' ]}:{$blog[ 'pwd' ]}" )
						)
					)
				);

				if( 'OK' === wp_remote_retrieve_response_message( $request ) ) {
					$products = json_decode( wp_remote_retrieve_body( $request ) );
					if( $products ) {
						$product_site_2 = reset( $products );
						Rudr_Simple_WP_Crosspost::add_crossposted_data( $object_id, $product_site_2->id, $blog_id );
						update_post_meta( $object_id, Rudr_Simple_WP_Crosspost::META_KEY . $blog_id, 1 );
						$connected++;

						// amasing, but we have to provide connection to variations!!
						// double check that both products are variable!!!
						if( 'variable' === $product_site_2->type && $product_site_1->is_type( 'variable' ) ) {
							// remove current connections
							$c_v_data = ( $c_v_data = get_post_meta( $object_id, Rudr_Simple_WP_Crosspost::META_KEY . 'variations', true ) ) ? $c_v_data : array();
							$c_v_data[ $blog_id ] = array();
							// at first we get all product variations with rest API
							$request = wp_remote_get(
								add_query_arg( array( 'per_page' => 100 ), $blog[ 'url' ] . '/wp-json/wc/v3/products/' . $product_site_2->id . '/variations' ),
								array(
									'headers' => array(
										'Authorization' => 'Basic ' . base64_encode( "{$blog[ 'login' ]}:{$blog[ 'pwd' ]}" )
									)
								)
							);
							if( 'OK' === wp_remote_retrieve_response_message( $request ) ) {
								// variations from Site 2
								$variations2 = json_decode( wp_remote_retrieve_body( $request ), true );
								$variations1 = $product_site_1->get_available_variations();
								// if
								if( $variations1 && $variations2 ) {
									// some formatting
									$variations2 = wp_list_pluck( $variations2, 'id', 'sku' );
									// loop and connect!
									foreach( $variations1 as $variation1 ) {
										// condition 1
										if( empty( $variation1[ 'sku' ] ) || ! $variation1[ 'sku' ] ) {
											continue;
										}
										// condition 2
										if( empty( $variations2[ $variation1[ 'sku' ] ] ) ) {
											continue;
										}
										// connect now!
										$c_v_data[ $blog_id ][ $variation1[ 'variation_id' ] ] = $variations2[ $variation1[ 'sku' ] ];
									}
								}
							}
							update_post_meta( $object_id, Rudr_Simple_WP_Crosspost::META_KEY . 'variations', $c_v_data );
						} // end variable product checks

					} else {
						// no products found, we need to remove connection
						Rudr_Simple_WP_Crosspost::remove_crossposted_data( $object_id, $blog_id );
						delete_post_meta( $object_id, Rudr_Simple_WP_Crosspost::META_KEY . $blog_id );
						$connected++;
					}
				}
				// we just about to continue the loop

			} else {

				$post = get_post( $object_id );
				$post_type = get_post_type_object( $post->post_type );
				$rest_base = $post_type->rest_base ? $post_type->rest_base : $post->post_type;
				$status = 'attachment' === $post->post_type ? 'inherit' : 'any';

				$request = wp_remote_get(
					add_query_arg(
						array(
							'slug' => $post->post_name,
							'status' => $status,
						),
						"{$blog[ 'url' ]}/wp-json/wp/v2/{$rest_base}"
					),
					array(
						'headers' => array(
							'Authorization' => 'Basic ' . base64_encode( "{$blog[ 'login' ]}:{$blog[ 'pwd' ]}" )
						)
					)
				);

				if( 'OK' === wp_remote_retrieve_response_message( $request ) ) {
					$posts = json_decode( wp_remote_retrieve_body( $request ) );
					if( $posts ) {
						$post = reset( $posts );
						Rudr_Simple_WP_Crosspost::add_crossposted_data( $object_id, $post->id, $blog_id );
						if( 'attachment' !== $post->post_type ) {
							update_post_meta( $object_id, Rudr_Simple_WP_Crosspost::META_KEY . $blog_id, 1 );
						}
						$connected++;
					} else {
						// no products found, we need to remove connection
						Rudr_Simple_WP_Crosspost::remove_crossposted_data( $object_id, $blog_id );
						delete_post_meta( $object_id, Rudr_Simple_WP_Crosspost::META_KEY . $blog_id );
						$connected++;
					}
				}

			}

		}

		$redirect = add_query_arg( 'rudr_connect_done', $connected, $redirect );


	}

	return $redirect;

}

// Doing notices
add_action( 'admin_notices', function() {
	if( ! function_exists( 'get_current_screen' ) ) {
		return;
	}
	
	$screen = get_current_screen();

	$post_type = $screen->post_type;
	$post_type_object = get_post_type_object( $post_type );
	$item_name = 'Media' === $post_type_object->labels->singular_name ? 'media file' : strtolower( $post_type_object->labels->singular_name );

	if( ! empty( $_REQUEST[ 'rudr_connect_done' ] ) && $post_type_object ) {

		printf(
			'<div id="message" class="updated notice is-dismissible"><p>' . _n( '%s %s has been successfully connected.', '%s %ss have been successfully connected.', absint( $_REQUEST[ 'rudr_connect_done' ] ) ) . '</p></div>',
			absint( $_REQUEST[ 'rudr_connect_done' ] ),
			$item_name
		);

	}

} );
