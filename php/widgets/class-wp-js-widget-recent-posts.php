<?php
/**
 * Class WP_JS_Widget_Recent_Posts.
 *
 * @package JSWidgets
 */

/**
 * Class WP_JS_Widget_Recent_Posts
 *
 * @package JSWidgets
 */
class WP_JS_Widget_Recent_Posts extends WP_JS_Widget {

	/**
	 * Version of widget.
	 *
	 * @var string
	 */
	public $version = '0.1';

	/**
	 * Proxied widget.
	 *
	 * @var WP_Widget_Recent_Posts
	 */
	public $proxied_widget;

	/**
	 * Widget constructor.
	 *
	 * @throws Exception If the `$proxied_widget` is not the expected class.
	 *
	 * @param WP_Widget $proxied_widget Proxied widget.
	 */
	public function __construct( WP_Widget $proxied_widget ) {
		if ( $proxied_widget instanceof WP_JS_Widget ) {
			throw new Exception( 'Do not proxy WP_Customize_Widget instances.' );
		}
		$this->proxied_widget = $proxied_widget;
		parent::__construct( $proxied_widget->id_base, $proxied_widget->name, $proxied_widget->widget_options, $proxied_widget->control_options );
	}

	/**
	 * Register scripts.
	 *
	 * @param WP_Scripts $wp_scripts Scripts.
	 */
	public function register_scripts( $wp_scripts ) {
		$suffix = ( SCRIPT_DEBUG ? '' : '.min' ) . '.js';
		$plugin_dir_url = plugin_dir_url( dirname( dirname( __FILE__ ) ) );

		$handle = 'recent-posts-widget-form-react-component';
		$src = $plugin_dir_url . 'js/widgets/recent-posts-widget-form-react-component-browserified' . $suffix;
		$deps = array( 'react', 'react-dom' );
		$wp_scripts->add( $handle, $src, $deps, $this->version );

		$handle = 'recent-posts-widget-frontend-react-component';
		$src = $plugin_dir_url . 'js/widgets/recent-posts-widget-frontend-react-component-browserified' . $suffix;
		$deps = array( 'react', 'react-dom' );
		$wp_scripts->add( $handle, $src, $deps, $this->version );

		$handle = 'customize-widget-recent-posts';
		$src = $plugin_dir_url . 'js/widgets/customize-widget-recent-posts' . $suffix;
		$deps = array( 'customize-js-widgets', 'redux', 'recent-posts-widget-form-react-component' );
		$wp_scripts->add( $handle, $src, $deps, $this->version );
	}

	/**
	 * Enqueue scripts needed for the control.s
	 */
	public function enqueue_control_scripts() {
		wp_enqueue_script( 'customize-widget-recent-posts' );
	}

	/**
	 * Get instance schema properties.
	 *
	 * @return array Schema.
	 */
	public function get_item_schema() {
		$schema = array(
			'title' => array(
				'description' => __( 'The title for the widget.', 'js-widgets' ),
				'type' => 'object',
				'context' => array( 'view', 'edit', 'embed' ),
				'properties' => array(
					'raw' => array(
						'description' => __( 'Title for the widget, as it exists in the database.', 'js-widgets' ),
						'type' => 'string',
						'context' => array( 'edit' ),
						'default' => '',
						'arg_options' => array(
							'validate_callback' => array( $this, 'validate_title_field' ),
						),
					),
					'rendered' => array(
						'description' => __( 'HTML title for the widget, transformed for display.', 'js-widgets' ),
						'type' => 'string',
						'context' => array( 'view', 'edit', 'embed' ),
						'default' => __( 'Recent Posts', 'js-widgets' ),
						'readonly' => true,
					),
				),
			),
			'number' => array(
				'description' => __( 'The number of posts to display.', 'js-widgets' ),
				'type' => 'integer',
				'context' => array( 'view', 'edit', 'embed' ),
				'default' => 5,
				'minimum' => 1,
				'arg_options' => array(
					'validate_callback' => 'rest_validate_request_arg',
				),
			),
			'show_date' => array(
				'description' => __( 'Whether the date should be shown.', 'js-widgets' ),
				'type' => 'boolean',
				'default' => false,
				'context' => array( 'view', 'edit', 'embed' ),
				'arg_options' => array(
					'validate_callback' => 'rest_validate_request_arg',
				),
			),
			'posts' => array(
				'description' => __( 'The IDs for the recent posts.', 'js-widgets' ),
				'type' => 'array',
				'items' => array(
					'type' => 'integer',
				),
				'context' => array( 'view', 'edit', 'embed' ),
				'readonly' => true,
				'default' => array(),
			),
		);
		return $schema;
	}

	/**
	 * Render a widget instance for a REST API response.
	 *
	 * Map the instance data to the REST resource fields and add rendered fields.
	 * The Text widget stores the `content` field in `text` and `auto_paragraph` in `filter`.
	 *
	 * @inheritdoc
	 *
	 * @param array           $instance Raw database instance.
	 * @param WP_REST_Request $request  REST request.
	 * @return array Widget item.
	 */
	public function prepare_item_for_response( $instance, $request ) {
		$schema = $this->get_item_schema();
		$instance = array_merge( $this->get_default_instance(), $instance );

		$title_rendered = $instance['title'] ? $instance['title'] : $schema['title']['properties']['rendered']['default'];
		/** This filter is documented in src/wp-includes/widgets/class-wp-widget-pages.php */
		$title_rendered = apply_filters( 'widget_title', $title_rendered, $instance, $this->id_base );

		$number = max( intval( $instance['number'] ), $schema['number']['minimum'] );

		/** This filter is documented in src/wp-includes/widgets/class-wp-widget-recent-posts.php */
		$query = new WP_Query( apply_filters( 'widget_posts_args', array(
			'posts_per_page' => $number,
			'no_found_rows' => true,
			'post_status' => 'publish',
			'ignore_sticky_posts' => true,
			'update_post_meta_cache' => false,
			'update_term_meta_cache' => false,
		) ) );

		$item = array(
			'title' => array(
				'raw' => $instance['title'],
				'rendered' => $title_rendered,
			),
			'number' => $number,
			'show_date' => boolval( $instance['number'] ),
			'posts' => wp_list_pluck( $query->posts, 'ID' ),
		);

		return $item;
	}

	/**
	 * Prepare links for the response.
	 *
	 * @param WP_REST_Response           $response   Response.
	 * @param WP_REST_Request            $request    Request.
	 * @param JS_Widgets_REST_Controller $controller Controller.
	 * @return array Links for the given post.
	 */
	public function get_rest_response_links( $response, $request, $controller ) {
		$links = array();

		$links['wp:post'] = array();
		foreach ( $response->data['posts'] as $post_id ) {
			$post = get_post( $post_id );
			if ( empty( $post ) ) {
				continue;
			}
			$obj = get_post_type_object( $post->post_type );
			if ( empty( $obj ) ) {
				continue;
			}

			$rest_base = ! empty( $obj->rest_base ) ? $obj->rest_base : $obj->name;
			$base = sprintf( '/wp/v2/%s', $rest_base );

			$links['wp:post'][] = array(
				'href'       => rest_url( trailingslashit( $base ) . $post_id ),
				'embeddable' => true,
				'post_type'  => $post->post_type,
			);
		}
		return $links;
	}

	/**
	 * Validate a title request argument based on details registered to the route.
	 *
	 * @param  mixed           $value   Value.
	 * @param  WP_REST_Request $request Request.
	 * @param  string          $param   Param.
	 * @return WP_Error|boolean
	 */
	public function validate_title_field( $value, $request, $param ) {
		$valid = rest_validate_request_arg( $value, $request, $param );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		if ( $this->should_validate_strictly( $request ) ) {
			if ( preg_match( '#</?\w+.*?>#', $value ) ) {
				return new WP_Error( 'rest_invalid_param', sprintf( __( '%s cannot contain markup', 'js-widgets' ), $param ) );
			}
			if ( trim( $value ) !== $value ) {
				return new WP_Error( 'rest_invalid_param', sprintf( __( '%s contains whitespace padding', 'js-widgets' ), $param ) );
			}
			if ( preg_match( '/%[a-f0-9]{2}/i', $value ) ) {
				return new WP_Error( 'rest_invalid_param', sprintf( __( '%s contains illegal characters (octets)', 'js-widgets' ), $param ) );
			}
		}
		return true;
	}

	/**
	 * Sanitize instance data.
	 *
	 * @inheritdoc
	 *
	 * @param array $new_instance  New instance.
	 * @param array $old_instance  Old instance.
	 * @return array|null|WP_Error Array instance if sanitization (and validation) passed. Returns `WP_Error` or `null` on failure.
	 */
	public function sanitize( $new_instance, $old_instance ) {
		$default_instance = $this->get_default_instance();
		$new_instance = array_merge( $default_instance, $new_instance );
		$old_instance = array_merge( $default_instance, $old_instance );
		$instance = $this->proxied_widget->update( $new_instance, $old_instance );
		return $instance;
	}

	/**
	 * Render widget.
	 *
	 * @param array $args     Widget args.
	 * @param array $instance Widget instance.
	 * @return void
	 */
	public function render( $args, $instance ) {
		$this->proxied_widget->widget( $args, $instance );
	}

	/**
	 * Get configuration data for the form.
	 *
	 * @return array
	 */
	public function get_form_args() {
		$item_schema = $this->get_item_schema();
		return array(
			'minimum_number' => $item_schema['number']['minimum'],
			'l10n' => array(
				'title_tags_invalid' => __( 'Tags will be stripped from the title.', 'js-widgets' ),
				'label_title' => __( 'Title:', 'js-widgets' ),
				'placeholder_title' => $item_schema['title']['properties']['rendered']['default'],
				'label_number' => __( 'Number:', 'js-widgets' ),
				'label_show_date' => __( 'Show date', 'js-widgets' ),
			),
		);
	}
}
