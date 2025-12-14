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
	 * REST route
	 */
	const ROUTE = '/message';

	/**
	 * Max messages kept in session window
	 */
	const MAX_MESSAGES = 10;

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

		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_message' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'message' => array(
						'type'     => 'string',
						'required' => true,
					),
					'context' => array(
						'type'     => 'array',
						'required' => false,
					),
				),
			)
		);
	}

	/**
	 * Permissions check for REST requests
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool
	 */
	public function permissions_check( $request ) {

		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( ! $nonce ) {
			return false;
		}

		return wp_verify_nonce( $nonce, 'wp_rest' );
	}

	/**
	 * Handle incoming message
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function handle_message( WP_REST_Request $request ) {

		$message = trim( wp_strip_all_tags( $request->get_param( 'message' ) ) );
		if ( $message === '' ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'error'   => __( 'Empty message.', 'adambox' ),
				),
				400
			);
		}

		$context = $request->get_param( 'context' );
		if ( ! is_array( $context ) ) {
			$context = array();
		}

		// Enforce message window size
		$context = array_slice( $context, - self::MAX_MESSAGES );

		// Append current user message
		$context[] = array(
			'role'    => 'user',
			'content' => $message,
		);

		// Fetch settings
		$strictness  = AdamBox_Settings::moderation_strictness();
		$intervene   = AdamBox_Settings::intervention_level();
		$has_api_key = AdamBox_Settings::has_api_key();

		/**
		 * If no API key is set, degrade gracefully.
		 * We return a neutral system message instead of failing.
		 */
		if ( ! $has_api_key ) {

			$response_message = __( 'Message received. Moderation is currently passive.', 'adambox' );

			$context[] = array(
				'role'    => 'system',
				'content' => $response_message,
			);

			return new WP_REST_Response(
				array(
					'success' => true,
					'message' => $response_message,
					'context' => array_slice( $context, - self::MAX_MESSAGES ),
				),
				200
			);
		}

		/**
		 * Placeholder moderation logic
		 * (AI call will be injected here later)
		 */
		$response_message = $this->moderate_message(
			$message,
			$context,
			$strictness,
			$intervene
		);

		$context[] = array(
			'role'    => 'system',
			'content' => $response_message,
		);

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => $response_message,
				'context' => array_slice( $context, - self::MAX_MESSAGES ),
			),
			200
		);
	}

	/**
	 * Moderation logic stub (safe default behavior)
	 *
	 * @param string $message User message.
	 * @param array  $context Conversation context.
	 * @param string $strictness Moderation strictness.
	 * @param string $intervention AI intervention level.
	 * @return string
	 */
	protected function moderate_message( $message, $context, $strictness, $intervention ) {

		// This is intentionally conservative.
		// Real AI logic will replace this later.

		switch ( $intervention ) {
			case 'actively_guide':
				return __( 'Let’s keep the conversation respectful and on topic.', 'adambox' );

			case 'summarize_when_needed':
				return __( 'Conversation noted. Please continue respectfully.', 'adambox' );

			case 'intervene_only':
			default:
				return __( 'Message received.', 'adambox' );
		}
	}
}
