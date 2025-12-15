<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AdamBox_REST {

	const NAMESPACE = 'adambox/v1';

	/* =========================
	 * Context limits
	 * ========================= */
	const MAX_STORE_MESSAGES = 50;
	const MAX_JOIN_MESSAGES  = 10;
	const CONTEXT_TTL        = 1800; // 30 minutes

	/* =========================
	 * Rate limits
	 * ========================= */
	const MIN_INTERVAL = 3;   // seconds between messages
	const WINDOW_TIME  = 60;  // rolling window (seconds)
	const WINDOW_MAX   = 20;  // max messages per window

	/* =========================
	 * OpenAI (used by Moderator)
	 * ========================= */
	const OPENAI_ENDPOINT       = 'https://api.openai.com/v1/responses';
	const OPENAI_MODEL          = 'gpt-5-mini';
	const OPENAI_TIMEOUT        = 12;
	const OPENAI_MAX_OUT_TOKENS = 620;

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	/* =========================
	 * Routes
	 * ========================= */

	public function routes() {

		register_rest_route( self::NAMESPACE, '/context', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_context' ),
			'permission_callback' => '__return_true',
		) );

		register_rest_route( self::NAMESPACE, '/message', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'post_message' ),
			'permission_callback' => '__return_true',
		) );
	}

	/* =========================
	 * Helpers
	 * ========================= */

	private function ctx_key( $post_id ) {
		return 'adambox_ctx_' . absint( $post_id );
	}

	private function normalize( $str, $len ) {
		$str = trim( wp_strip_all_tags( (string) $str ) );
		$str = preg_replace( '/\s+/', ' ', $str );
		return mb_substr( $str, 0, $len );
	}

	/**
	 * Hash client IP for rate limiting
	 * Uses filter_input() to satisfy WP Plugin Checker
	 */
	private function ip_hash() {

		$ip = filter_input( INPUT_SERVER, 'REMOTE_ADDR', FILTER_SANITIZE_STRING );

		if ( empty( $ip ) ) {
			$ip = '0.0.0.0';
		}

		return substr( hash( 'sha256', $ip ), 0, 16 );
	}

	private function rl_key( $post_id, $sid, $name ) {
		return 'adambox_rl_' . absint( $post_id ) . '_'
			. substr( hash( 'sha256', $sid ), 0, 8 ) . '_'
			. substr( hash( 'sha256', $name ), 0, 8 ) . '_'
			. $this->ip_hash();
	}

	private function no_cache_response( $data, $status = 200 ) {
		$resp = rest_ensure_response( $data );
		$resp->set_status( $status );
		$resp->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
		$resp->header( 'Pragma', 'no-cache' );
		$resp->header( 'Expires', '0' );
		return $resp;
	}

	/* =========================
	 * Context
	 * ========================= */

	public function get_context( WP_REST_Request $r ) {

		$post_id = absint( $r['post_id'] );
		if ( ! $post_id ) {
			return new WP_Error( 'bad', 'Invalid post.', array( 'status' => 400 ) );
		}

		$full = get_transient( $this->ctx_key( $post_id ) );
		if ( ! is_array( $full ) ) {
			$full = array();
		}

		return $this->no_cache_response( array(
			'context' => array_slice( $full, - self::MAX_JOIN_MESSAGES ),
			'hash'    => md5( wp_json_encode( $full ) ),
		) );
	}

	/* =========================
	 * Rate limiting
	 * ========================= */

	private function rate_limit( $post_id, $sid, $name ) {

		$key = $this->rl_key( $post_id, $sid, $name );
		$now = time();
		$rl  = get_transient( $key );

		if ( ! is_array( $rl ) ) {
			$rl = array(
				'last'  => 0,
				'start' => $now,
				'count' => 0,
			);
		}

		if ( $now - $rl['last'] < self::MIN_INTERVAL ) {
			return 'Slow down a bit.';
		}

		if ( $now - $rl['start'] > self::WINDOW_TIME ) {
			$rl['start'] = $now;
			$rl['count'] = 0;
		}

		if ( $rl['count'] >= self::WINDOW_MAX ) {
			return 'Rate limit reached. Try again shortly.';
		}

		$rl['last']  = $now;
		$rl['count']++;

		set_transient( $key, $rl, self::WINDOW_TIME + 30 );

		return '';
	}

	/* =========================
	 * Message handling
	 * ========================= */

	public function post_message( WP_REST_Request $r ) {

		$post_id = absint( $r['post_id'] );
		$sid     = $this->normalize( $r['sid'], 64 );
		$name    = $this->normalize( $r['name'], 20 );
		$text    = $this->normalize( $r['message'], 500 );

		if ( ! $post_id || ! $sid || ! $name || ! $text ) {
			return new WP_Error( 'bad', 'Invalid request.', array( 'status' => 400 ) );
		}

		$key = $this->ctx_key( $post_id );
		$ctx = get_transient( $key );
		if ( ! is_array( $ctx ) ) {
			$ctx = array();
		}

		// Rate limit first
		if ( $msg = $this->rate_limit( $post_id, $sid, $name ) ) {
			return $this->no_cache_response( array(
				'error'   => $msg,
				'context' => array_slice( $ctx, - self::MAX_JOIN_MESSAGES ),
				'hash'    => md5( wp_json_encode( $ctx ) ),
			), 429 );
		}

		// Store user message
		$ctx[] = array(
			'role'    => 'user',
			'name'    => $name,
			'content' => $text,
			'time'    => time(),
		);

		/* =========================
		 * Moderation pipeline
		 * ========================= */

		if ( class_exists( 'AdamBox_Keywords' ) && class_exists( 'AdamBox_Moderator' ) ) {

			$severity = AdamBox_Keywords::analyze( $ctx, $text );

			if ( $severity ) {
				$mod = AdamBox_Moderator::handle( $ctx, $severity );

				if ( $mod ) {
					$ctx[] = array(
						'role'    => 'system',
						'content' => $mod,
						'time'    => time(),
					);
				}
			}
		}

		// Save context
		$ctx = array_slice( $ctx, - self::MAX_STORE_MESSAGES );
		set_transient( $key, $ctx, self::CONTEXT_TTL );

		return $this->no_cache_response( array(
			'success' => true,
			'context' => array_slice( $ctx, - self::MAX_JOIN_MESSAGES ),
			'hash'    => md5( wp_json_encode( $ctx ) ),
		) );
	}
}
