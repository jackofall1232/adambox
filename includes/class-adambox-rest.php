<?php
/**
 * REST API handler for AdamBox
 *
 * File: includes/class-adambox-rest.php
 *
 * @package AdamBox
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AdamBox_REST {

	/**
	 * REST namespace
	 */
	const NAMESPACE = 'adambox/v1';

	/**
	 * Routes
	 */
	const ROUTE_MESSAGE = '/message';
	const ROUTE_CONTEXT = '/context';

	/**
	 * Limits
	 */
	const MAX_MESSAGES = 10;
	const TRANSIENT_TTL = 1800; // 30 minutes

	/**
	 * Constructor
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register REST routes
	 */
	public function register_routes() {

		// Fetch current shared context
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE_CONTEXT,
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_context' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'post_id' => array(
						'type'     => 'integer',
						'required' => true,
					),
				),
			)
		);

		// Submit a message
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE_MESSAGE,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_message' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'post_id' => array(
						'type'     => 'integer',
						'required' => true,
					),
					'message' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * Permissions check
	 */
	public function permissions_check( WP_REST_Request $request ) {
		$nonce = $request->get_header( 'X-WP-Nonce' );
		return $nonce && wp_verify_nonce( $nonce, 'wp_rest' );
	}

	/**
	 * Build transient key for a page
	 */
	protected function get_transient_key( $post_id ) {
		return 'adambox_context_post_' . absint( $post_id );
	}

	/**
	 * Fetch shared context
	 */
	public function get_context( WP_REST_Request $request ) {

		$post_id = absint( $request->get_param( 'post_id' ) );
		if ( ! $post_id ) {
			return new WP_REST_Response( array( 'context' => array() ), 200 );
		}

		$context = get_transient( $this->get_transient_key( $post_id ) );
		if ( ! is_array( $context ) ) {
			$context = array();
		}

		return new WP_REST_Response(
			array(
				'context' => array_slice( $context, - self::MAX_MESSAGES ),
			),
			200
		);
	}

	/**
	 * Handle incoming message
	 */
	public function handle_message( WP_REST_Request $request ) {

		$post_id = absint( $request->get_param( 'post_id' ) );
		$message = trim( wp_strip_all_tags( $request->get_param( 'message' ) ) );

		if ( ! $post_id || $message === '' ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'error'   => __( 'Invalid request.', 'adambox' ),
				),
				400
			);
		}

		$key     = $this->get_transient_key( $post_id );
		$context = get_transient( $key );

		if ( ! is_array( $context ) ) {
			$context = array();
		}

		// Append user message
		$context[] = array(
			'role'    => 'user',
			'content' => $message,
		);

		$context = array_slice( $context, - self::MAX_MESSAGES );

		// Determine response
		if ( ! AdamBox_Settings::has_api_key() ) {
			$response_message = __( 'Conversation noted. Please continue respectfully.', 'adambox' );
		} else {
			$response_message = $this->moderate_message(
				$message,
				$context,
				AdamBox_Settings::moderation_strictness(),
				AdamBox_Settings::intervention_level()
			);
		}

		// Append system message
		$context[] = array(
			'role'    => 'system',
			'content' => $response_message,
		);

		$context = array_slice( $context, - self::MAX_MESSAGES );

		// Save shared context
		set_transient( $key, $context, self::TRANSIENT_TTL );

		return new WP_REST_Response(
			array(
				'success' => true,
				'context' => $context,
			),
			200
		);
	}

	/**
	 * Placeholder moderation logic
	 */
	protected function moderate_message( $message, $context, $strictness, $intervention ) {

		switch ( $intervention ) {
			case 'actively_guide':
				return __( 'Let’s keep the conversation on topic and respectful.', 'adambox' );

			case 'summarize_when_needed':
				return __( 'Conversation noted. Please continue respectfully.', 'adambox' );

			case 'intervene_only':
			default:
				return __( 'Message received.', 'adambox' );
		}
	}
}
