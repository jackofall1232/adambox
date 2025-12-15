<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class AdamBox_REST {

	const NAMESPACE = 'adambox/v1';

	/* =========================
	 * Context limits
	 * ========================= */
	const MAX_STORE_MESSAGES = 50;
	const MAX_JOIN_MESSAGES  = 10;
	const CONTEXT_TTL        = 1800;

	/* =========================
	 * Rate limits
	 * ========================= */
	const MIN_INTERVAL = 3;
	const WINDOW_TIME  = 60;
	const WINDOW_MAX   = 20;

	/* =========================
	 * OpenAI
	 * ========================= */
	const OPENAI_ENDPOINT       = 'https://api.openai.com/v1/responses';
	const OPENAI_MODEL          = 'gpt-5-mini';
	const OPENAI_TIMEOUT        = 12;
	const OPENAI_MAX_OUT_TOKENS = 620;

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	public function routes() {

		register_rest_route( self::NAMESPACE, '/context', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_context' ),
			'permission_callback' => array( $this, 'perm' ),
		) );

		register_rest_route( self::NAMESPACE, '/message', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'post_message' ),
			'permission_callback' => array( $this, 'perm' ),
		) );
	}

	public function perm( $req ) {
		return wp_verify_nonce( $req->get_header( 'X-WP-Nonce' ), 'wp_rest' );
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

	private function ip_hash() {
		$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
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
		if ( ! is_array( $full ) ) $full = array();

		return $this->no_cache_response( array(
			'context' => array_slice( $full, - self::MAX_JOIN_MESSAGES ),
			'hash'    => md5( wp_json_encode( $full ) ),
		) );
	}

	/* =========================
	 * Moderation (FORCED ON)
	 * ========================= */

	private function should_moderate( $ctx, $text ) {
		return true; // 🔴 FOR DEBUG – always moderate
	}

	private function build_transcript( $ctx ) {
		$lines = array();
		foreach ( array_slice( $ctx, -10 ) as $m ) {
			$label = ( $m['role'] === 'user' ) ? ( $m['name'] ?: 'User' ) : 'Moderator';
			$lines[] = $label . ': ' . $m['content'];
		}
		return implode( "\n", $lines );
	}

	private function run_moderation( $ctx ) {

		$key = AdamBox_Settings::get( 'openai_api_key', '' );
		if ( ! $key ) {
			return 'Moderator unavailable (API key missing).';
		}

		$payload = array(
			'model' => self::OPENAI_MODEL,
			'reasoning' => array( 'effort' => 'low' ),
			'input' => array(
				array(
					'role' => 'system',
					'content' => array(
						array(
							'type' => 'input_text',
							'text' => 'You are Adam, a calm and neutral AI moderator. Always respond with ONE short sentence to the group.'
						)
					)
				),
				array(
					'role' => 'user',
					'content' => array(
						array(
							'type' => 'input_text',
							'text' => $this->build_transcript( $ctx )
						)
					)
				)
			),
			'max_output_tokens' => self::OPENAI_MAX_OUT_TOKENS,
		);

		$res = wp_remote_post(
			self::OPENAI_ENDPOINT,
			array(
				'timeout' => self::OPENAI_TIMEOUT,
				'headers' => array(
					'Authorization' => 'Bearer ' . $key,
					'Content-Type'  => 'application/json',
				),
				'body' => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $res ) ) {
			return 'Moderator temporarily unavailable.';
		}

		$body = json_decode( wp_remote_retrieve_body( $res ), true );

		if ( ! is_array( $body ) || empty( $body['output'] ) ) {
			return 'Moderator is reviewing the conversation.';
		}

		foreach ( $body['output'] as $item ) {
			if ( isset( $item['type'] ) && $item['type'] === 'message' ) {
				foreach ( $item['content'] as $c ) {
					if ( isset( $c['text'] ) ) {
						return mb_substr(
							trim( preg_replace( '/\s+/', ' ', $c['text'] ) ),
							0,
							380
						);
					}
				}
			}
		}

		return 'Moderator is monitoring the discussion.';
	}

	/* =========================
	 * Message Handling
	 * ========================= */

	public function post_message( WP_REST_Request $r ) {

		$post_id = absint( $r['post_id'] );
		$sid     = $this->normalize( $r['sid'], 64 );
		$name    = $this->normalize( $r['name'], 20 );
		$text    = $this->normalize( $r['message'], 500 );

		if ( ! $post_id || ! $sid || ! $name || ! $text ) {
			return new WP_Error( 'bad', 'Invalid request', array( 'status' => 400 ) );
		}

		$key = $this->ctx_key( $post_id );
		$ctx = get_transient( $key ) ?: array();

		$ctx[] = array(
			'role'    => 'user',
			'name'    => $name,
			'content' => $text,
			'time'    => time(),
		);

		if ( $this->should_moderate( $ctx, $text ) ) {
			$ctx[] = array(
				'role'    => 'system',
				'content' => $this->run_moderation( $ctx ),
				'time'    => time(),
			);
		}

		$ctx = array_slice( $ctx, - self::MAX_STORE_MESSAGES );
		set_transient( $key, $ctx, self::CONTEXT_TTL );

		return $this->no_cache_response( array(
			'success' => true,
			'context' => array_slice( $ctx, - self::MAX_JOIN_MESSAGES ),
			'hash'    => md5( wp_json_encode( $ctx ) ),
		) );
	}
}
