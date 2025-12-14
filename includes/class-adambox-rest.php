<?php
/**
 * REST API handler for AdamBox (0.3.0 ALPHA)
 *
 * File: includes/class-adambox-rest.php
 *
 * Features:
 * - Per-page shared context (transients)
 * - Required display names (ephemeral)
 * - Rate limiting (per page + per session)
 * - No DB tables, no tracking, no persistent identity
 *
 * @package AdamBox
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AdamBox_REST {

	const NAMESPACE     = 'adambox/v1';
	const ROUTE_MESSAGE = '/message';
	const ROUTE_CONTEXT = '/context';

	const MAX_MESSAGES  = 10;
	const TRANSIENT_TTL = 1800; // 30 minutes

	// Rate limit rules
	const MIN_INTERVAL_SECONDS = 3;    // minimum seconds between messages per session
	const WINDOW_SECONDS       = 300;  // 5 minutes
	const WINDOW_MAX_MESSAGES  = 20;   // max messages per session per page per window

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {

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
					'name' => array(
						'type'     => 'string',
						'required' => true,
					),
					'sid' => array(
						'type'     => 'string',
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

	public function permissions_check( WP_REST_Request $request ) {
		$nonce = $request->get_header( 'X-WP-Nonce' );
		return (bool) ( $nonce && wp_verify_nonce( $nonce, 'wp_rest' ) );
	}

	protected function get_context_key( $post_id ) {
		return 'adambox_context_post_' . absint( $post_id );
	}

	protected function get_rate_key( $post_id, $sid_hash ) {
		return 'adambox_rl_' . absint( $post_id ) . '_' . $sid_hash;
	}

	protected function normalize_name( $name ) {
		$name = trim( (string) $name );
		// Strip tags + normalize whitespace
		$name = wp_strip_all_tags( $name );
		$name = preg_replace( '/\s+/', ' ', $name );
		// Limit length
		$name = mb_substr( $name, 0, 20 );
		return $name;
	}

	protected function normalize_message( $message ) {
		$message = trim( (string) $message );
		$message = wp_strip_all_tags( $message );
		$message = preg_replace( '/\s+/', ' ', $message );
		$message = mb_substr( $message, 0, 500 );
		return $message;
	}

	protected function sid_hash( $sid ) {
		$sid = trim( (string) $sid );
		$sid = wp_strip_all_tags( $sid );
		$sid = mb_substr( $sid, 0, 64 );

		if ( $sid === '' ) {
			return '';
		}

		// Hash to avoid storing raw session ID
		return substr( hash( 'sha256', $sid . '|' . wp_salt( 'nonce' ) ), 0, 16 );
	}

	public function get_context( WP_REST_Request $request ) {

		$post_id = absint( $request->get_param( 'post_id' ) );
		if ( ! $post_id ) {
			return new WP_REST_Response( array( 'context' => array() ), 200 );
		}

		$context = get_transient( $this->get_context_key( $post_id ) );
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

	protected function is_name_in_use( $context, $name ) {
		if ( ! is_array( $context ) ) {
			return false;
		}

		$target = mb_strtolower( $name );

		foreach ( $context as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			if ( ( $item['role'] ?? '' ) !== 'user' ) {
				continue;
			}
			$item_name = $item['name'] ?? '';
			$item_name = $this->normalize_name( $item_name );
			if ( $item_name === '' ) {
				continue;
			}
			if ( mb_strtolower( $item_name ) === $target ) {
				return true;
			}
		}

		return false;
	}

	protected function rate_limit_check( $post_id, $sid_hash ) {
		$key  = $this->get_rate_key( $post_id, $sid_hash );
		$data = get_transient( $key );

		if ( ! is_array( $data ) ) {
			$data = array(
				'last'  => 0,
				'start' => time(),
				'count' => 0,
			);
		}

		$now = time();

		// Min interval
		if ( ( $now - (int) $data['last'] ) < self::MIN_INTERVAL_SECONDS ) {
			return array(
				'ok'    => false,
				'error' => __( 'Please slow down a bit.', 'adambox' ),
			);
		}

		// Sliding window reset
		if ( ( $now - (int) $data['start'] ) >= self::WINDOW_SECONDS ) {
			$data['start'] = $now;
			$data['count'] = 0;
		}

		// Max messages in window
		if ( (int) $data['count'] >= self::WINDOW_MAX_MESSAGES ) {
			return array(
				'ok'    => false,
				'error' => __( 'Rate limit reached. Try again in a moment.', 'adambox' ),
			);
		}

		// Update counters
		$data['last']  = $now;
		$data['count'] = (int) $data['count'] + 1;

		// Keep throttle state alive for the window duration
		set_transient( $key, $data, self::WINDOW_SECONDS );

		return array( 'ok' => true );
	}

	public function handle_message( WP_REST_Request $request ) {

		$post_id = absint( $request->get_param( 'post_id' ) );
		$name    = $this->normalize_name( $request->get_param( 'name' ) );
		$sid     = (string) $request->get_param( 'sid' );
		$message = $this->normalize_message( $request->get_param( 'message' ) );

		if ( ! $post_id || $name === '' || $message === '' ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'error'   => __( 'Invalid request.', 'adambox' ),
				),
				400
			);
		}

		$sid_hash = $this->sid_hash( $sid );
		if ( $sid_hash === '' ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'error'   => __( 'Session error. Please refresh and join again.', 'adambox' ),
				),
				400
			);
		}

		// Rate limiting (per page + per session)
		$rl = $this->rate_limit_check( $post_id, $sid_hash );
		if ( empty( $rl['ok'] ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'error'   => $rl['error'],
				),
				429
			);
		}

		// Load context
		$key     = $this->get_context_key( $post_id );
		$context = get_transient( $key );
		if ( ! is_array( $context ) ) {
			$context = array();
		}

		// Soft uniqueness enforcement (server-side) to avoid races
		if ( $this->is_name_in_use( $context, $name ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'error'   => __( 'That display name is currently in use. Please choose another.', 'adambox' ),
				),
				409
			);
		}

		// Append user message (with display name)
		$context[] = array(
			'role'    => 'user',
			'name'    => $name,
			'content' => $message,
		);

		$context = array_slice( $context, - self::MAX_MESSAGES );

		// Moderation (minimal and non-dominant for alpha)
		// If API key not set, we stay silent unless strictness suggests otherwise.
		$moderation_text = $this->moderate_if_needed(
			$message,
			$context,
			AdamBox_Settings::moderation_strictness(),
			AdamBox_Settings::intervention_level()
		);

		if ( $moderation_text !== '' ) {
			$context[] = array(
				'role'    => 'system',
				'content' => $moderation_text,
			);
		}

		$context = array_slice( $context, - self::MAX_MESSAGES );

		// Save context
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
	 * Minimal moderation logic (alpha-safe).
	 * - If API key exists: placeholder hook for real AI moderation (we'll add next).
	 * - For now: basic guardrails + intervene only when necessary.
	 */
	protected function moderate_if_needed( $message, $context, $strictness, $intervention ) {

		// Basic "always-on" guardrail examples (no AI yet):
		// If strictness is high and message contains aggressive profanity patterns, intervene.
		// Keep it lightweight and non-judgmental.
		$lower = mb_strtolower( $message );

		$flag_words = array(
			// Very mild starter list; replace with AI moderation soon.
			'hate you',
			'kill yourself',
			'go die',
		);

		$hit = false;
		foreach ( $flag_words as $w ) {
			if ( strpos( $lower, $w ) !== false ) {
				$hit = true;
				break;
			}
		}

		if ( $hit ) {
			return __( 'Let’s keep things respectful. Personal attacks or self-harm statements aren’t allowed here.', 'adambox' );
		}

		// If no API key, stay silent unless intervention is set to actively guide (then give a gentle reminder occasionally).
		if ( ! AdamBox_Settings::has_api_key() ) {
			if ( $intervention === 'actively_guide' ) {
				// Very light touch
				return __( 'Quick reminder: keep it respectful and on topic.', 'adambox' );
			}
			return '';
		}

		// API key exists: still keep alpha quiet by default.
		// We'll wire real OpenAI moderation next step; for now do a neutral, non-dominant reminder ONLY when intervention wants it.
		if ( $intervention === 'actively_guide' ) {
			return __( 'Let’s keep the conversation on topic and respectful.', 'adambox' );
		}

		if ( $intervention === 'summarize_when_needed' ) {
			return __( 'Please continue respectfully.', 'adambox' );
		}

		// intervene_only => silent unless flagged
		return '';
	}
}
