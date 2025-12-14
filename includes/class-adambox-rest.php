<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class AdamBox_REST {

	const NAMESPACE = 'adambox/v1';

	const MAX_STORE_MESSAGES = 50;
	const MAX_JOIN_MESSAGES  = 10;
	const CONTEXT_TTL        = 1800; // 30 minutes

	// Rate limits
	const MIN_INTERVAL = 3;     // seconds
	const WINDOW_TIME  = 60;    // seconds
	const WINDOW_MAX   = 20;    // messages

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	public function routes() {

		register_rest_route( self::NAMESPACE, '/context', array(
			'methods' => 'GET',
			'callback' => array( $this, 'get_context' ),
			'permission_callback' => array( $this, 'perm' ),
		));

		register_rest_route( self::NAMESPACE, '/message', array(
			'methods' => 'POST',
			'callback' => array( $this, 'post_message' ),
			'permission_callback' => array( $this, 'perm' ),
		));
	}

	public function perm( $req ) {
		return wp_verify_nonce( $req->get_header( 'X-WP-Nonce' ), 'wp_rest' );
	}

	private function ctx_key( $post_id ) {
		return 'adambox_ctx_' . absint( $post_id );
	}

	private function rl_key( $post_id, $sid ) {
		return 'adambox_rl_' . absint( $post_id ) . '_' . substr( hash( 'sha256', $sid ), 0, 16 );
	}

	private function normalize( $str, $len ) {
		$str = trim( wp_strip_all_tags( (string) $str ) );
		$str = preg_replace( '/\s+/', ' ', $str );
		return mb_substr( $str, 0, $len );
	}

	public function get_context( WP_REST_Request $r ) {

		$post_id = absint( $r['post_id'] );
		$full    = get_transient( $this->ctx_key( $post_id ) );

		if ( ! is_array( $full ) ) $full = array();

		return array(
			'context' => array_slice( $full, - self::MAX_JOIN_MESSAGES ),
			'hash'    => md5( wp_json_encode( $full ) )
		);
	}

	private function rate_limit( $post_id, $sid ) {

		$key = $this->rl_key( $post_id, $sid );
		$now = time();
		$rl  = get_transient( $key );

		if ( ! is_array( $rl ) ) {
			$rl = array( 'last' => 0, 'start' => $now, 'count' => 0 );
		}

		if ( $now - $rl['last'] < self::MIN_INTERVAL ) {
			return __( 'Slow down a bit.', 'adambox' );
		}

		if ( $now - $rl['start'] > self::WINDOW_TIME ) {
			$rl['start'] = $now;
			$rl['count'] = 0;
		}

		if ( $rl['count'] >= self::WINDOW_MAX ) {
			return __( 'Rate limit reached. Try again in a moment.', 'adambox' );
		}

		$rl['last']  = $now;
		$rl['count']++;

		set_transient( $key, $rl, self::WINDOW_TIME );

		return '';
	}

	public function post_message( WP_REST_Request $r ) {

		$post_id = absint( $r['post_id'] );
		$sid     = $this->normalize( $r['sid'], 64 );
		$name    = $this->normalize( $r['name'], 20 );
		$text    = $this->normalize( $r['message'], 500 );

		if ( ! $post_id || ! $sid || ! $name || ! $text ) {
			return new WP_Error( 'bad', 'Invalid request', array( 'status' => 400 ) );
		}

		if ( $msg = $this->rate_limit( $post_id, $sid ) ) {
			return new WP_REST_Response( array( 'error' => $msg ), 429 );
		}

		$key = $this->ctx_key( $post_id );
		$ctx = get_transient( $key );
		if ( ! is_array( $ctx ) ) $ctx = array();

		$ctx[] = array(
			'role'    => 'user',
			'name'    => $name,
			'content' => $text,
			'time'    => time(),
		);

		$ctx = array_slice( $ctx, - self::MAX_STORE_MESSAGES );

		set_transient( $key, $ctx, self::CONTEXT_TTL );

		return array(
			'success' => true,
			'context' => $ctx,
			'hash'    => md5( wp_json_encode( $ctx ) )
		);
	}
}
