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
	const MIN_INTERVAL = 3;
	const WINDOW_TIME  = 60;
	const WINDOW_MAX   = 20;

	/* =========================
	 * Tier-1 cooldown
	 * ========================= */
	const TIER1_COOLDOWN = 60; // seconds

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

	private function cooldown_key( $post_id, $sid ) {
		return 'adambox_cd_' . absint( $post_id ) . '_' . substr( hash( 'sha256', $sid ), 0, 16 );
	}

	private function normalize( $str, $len ) {
		$str = trim( wp_strip_all_tags( (string) $str ) );
		$str = preg_replace( '/\s+/', ' ', $str );
		return mb_substr( $str, 0, $len );
	}

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
	 * Severity normalization
	 * High mode: All tiers escalate to tier_1 (zero tolerance)
	 * Medium mode: tier_2→tier_1, tier_3→tier_2
	 * Low mode: unchanged
	 * ========================= */

	private function normalize_severity( $severity ) {

		$strict = AdamBox_Settings::moderation_strictness();

		if ( $strict === 'high' ) {
			// Zero tolerance - everything is tier_1
			return 'tier_1';
		}

		if ( $strict === 'medium' ) {
			if ( $severity === 'tier_2' ) return 'tier_1';
			if ( $severity === 'tier_3' ) return 'tier_2';
		}

		return $severity; // low = unchanged
	}

	/* =========================
	 * Check for 3 tier-3 messages in a row
	 * (from any users - conversation tone deteriorating)
	 * ========================= */

	private function has_tier3_pattern( $ctx ) {
		if ( empty( $ctx ) || ! is_array( $ctx ) ) {
			return false;
		}

		// Get last 3 user messages
		$recent = array();
		foreach ( array_reverse( $ctx ) as $m ) {
			if ( isset( $m['role'] ) && $m['role'] === 'user' && ! empty( $m['content'] ) ) {
				$recent[] = $m['content'];
				if ( count( $recent ) >= 3 ) {
					break;
				}
			}
		}

		// Need at least 3 messages to check
		if ( count( $recent ) < 3 ) {
			return false;
		}

		// Check if all 3 contain tier_3 keywords
		// Use empty context to prevent pattern escalation - we only want literal tier_3 detection
		$tier3_count = 0;

		if ( class_exists( 'AdamBox_Keywords' ) ) {
			foreach ( $recent as $msg ) {
				$detected = AdamBox_Keywords::analyze( array(), $msg );
				// Only count explicit tier_3 returns (no escalation)
				if ( $detected === 'tier_3' ) {
					$tier3_count++;
				}
			}
		}

		// 3 tier_3 messages in a row = pattern
		return ( $tier3_count >= 3 );
	}

	/* =========================
	 * Tier-1 placeholder
	 * ========================= */

	private function tier1_placeholder() {

		$messages = array(
			"🚨 Message removed — let's keep this respectful.",
			"🧼 That crossed the line and was removed.",
			"🤖 AdamBox stepped in on that one.",
			"🚧 Content blocked for community safety.",
			"😬 Let's rewind and try again.",
			"🛑 That message wasn't allowed here.",
		);

		return $messages[ array_rand( $messages ) ];
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

		$cd_key = $this->cooldown_key( $post_id, $sid );
		if ( get_transient( $cd_key ) ) {
			return $this->no_cache_response(
				array( 'error' => 'Please wait a moment before posting again.' ),
				429
			);
		}

		$key = $this->ctx_key( $post_id );
		$ctx = get_transient( $key );
		if ( ! is_array( $ctx ) ) {
			$ctx = array();
		}

		if ( $msg = $this->rate_limit( $post_id, $sid, $name ) ) {
			return $this->no_cache_response(
				array(
					'error'   => $msg,
					'context' => array_slice( $ctx, - self::MAX_JOIN_MESSAGES ),
					'hash'    => md5( wp_json_encode( $ctx ) ),
				),
				429
			);
		}

		/* =========================
		 * Moderation decision
		 * ========================= */

		$severity = false;
		if ( class_exists( 'AdamBox_Keywords' ) ) {
			$severity = AdamBox_Keywords::analyze( $ctx, $text );
		}

		// Normalize severity based on strictness (high mode escalates everything)
		if ( $severity ) {
			$severity = $this->normalize_severity( $severity );
		}

		// Tier 1: Auto-block + cooldown
		if ( $severity === 'tier_1' ) {

			$ctx[] = array(
				'role'    => 'user',
				'name'    => $name,
				'content' => $this->tier1_placeholder(),
				'time'    => time(),
				'flagged' => true,
			);

			set_transient(
				$cd_key,
				time() + self::TIER1_COOLDOWN,
				self::TIER1_COOLDOWN + 5
			);

		} else {

			// Add message to context
			$ctx[] = array(
				'role'    => 'user',
				'name'    => $name,
				'content' => $text,
				'time'    => time(),
			);
		}

		/* =========================
		 * Tier 3 Intent-Based Handling
		 * 
		 * Low: Only moderate if 3 tier-3 messages in a row (conversation deteriorating)
		 * Medium: Always send tier-3 to AI for intent judgment
		 * High: Already escalated to tier_1 above (zero tolerance)
		 * ========================= */

		$should_moderate = false;

		if ( $severity === 'tier_3' ) {
			$strict = AdamBox_Settings::moderation_strictness();

			if ( $strict === 'low' ) {
				// Low: Only if 3 tier-3 messages in a row (any users)
				$should_moderate = $this->has_tier3_pattern( $ctx );

			} elseif ( $strict === 'medium' ) {
				// Medium: Always send to AI for intent check
				$should_moderate = true;

			} else {
				// High: Already handled above (escalated to tier_1)
				$should_moderate = false;
			}

		} elseif ( $severity === 'tier_2' ) {
			// Tier 2: Always moderate
			$should_moderate = true;
		}

		// Send to AI moderator if appropriate
		if ( $severity && $should_moderate && class_exists( 'AdamBox_Moderator' ) ) {

			$mod = AdamBox_Moderator::handle( $ctx, $severity );

			if ( $mod ) {
				$ctx[] = array(
					'role'    => 'system',
					'content' => $mod,
					'time'    => time(),
				);
			}
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
