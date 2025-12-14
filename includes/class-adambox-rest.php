<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class AdamBox_REST {

	const NAMESPACE = 'adambox/v1';

	// Context limits
	const MAX_STORE_MESSAGES = 50;
	const MAX_JOIN_MESSAGES  = 10;
	const CONTEXT_TTL        = 1800; // 30 minutes

	// Rate limits (per user, per page)
	const MIN_INTERVAL = 3;   // seconds between messages
	const WINDOW_TIME  = 60;  // rolling window (seconds)
	const WINDOW_MAX   = 20;  // max messages per window

	// OpenAI (Moderation) - Step 2
	const OPENAI_ENDPOINT       = 'https://api.openai.com/v1/responses';
	const OPENAI_MODEL          = 'gpt-5-nano';
	const OPENAI_TIMEOUT        = 12;    // seconds
	const OPENAI_MAX_OUT_TOKENS = 120;

	// Options (adjust here if your settings keys differ)
	const OPT_OPENAI_KEY   = 'adambox_openai_api_key';
	const OPT_STRICTNESS   = 'adambox_moderation_strictness';     // low|medium|high
	const OPT_INTERVENTION = 'adambox_ai_intervention_level';     // intervene_only|summarize_when_needed|actively_guide

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

	private function rl_key( $post_id, $sid ) {
		return 'adambox_rl_' . absint( $post_id ) . '_' . substr( hash( 'sha256', $sid ), 0, 16 );
	}

	private function normalize( $str, $len ) {
		$str = trim( wp_strip_all_tags( (string) $str ) );
		$str = preg_replace( '/\s+/', ' ', $str );
		return mb_substr( $str, 0, $len );
	}

	private function get_opt( $key, $default = '' ) {
		$val = get_option( $key, $default );
		if ( is_string( $val ) ) return trim( $val );
		return $default;
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

		// Prevent proxy/browser caching oddities on mobile
		if ( function_exists( 'nocache_headers' ) ) {
			nocache_headers();
		}

		return array(
			'context' => array_slice( $full, - self::MAX_JOIN_MESSAGES ),
			'hash'    => md5( wp_json_encode( $full ) ),
		);
	}

	/* =========================
	 * Rate Limiting (per sid)
	 * ========================= */

	private function rate_limit( $post_id, $sid ) {

		$key = $this->rl_key( $post_id, $sid );
		$now = time();
		$rl  = get_transient( $key );

		if ( ! is_array( $rl ) ) {
			$rl = array( 'last' => 0, 'start' => $now, 'count' => 0 );
		}

		// Minimum spacing between messages
		if ( $now - (int) $rl['last'] < self::MIN_INTERVAL ) {
			return __( 'Slow down a bit.', 'adambox' );
		}

		// Reset rolling window
		if ( $now - (int) $rl['start'] > self::WINDOW_TIME ) {
			$rl['start'] = $now;
			$rl['count'] = 0;
		}

		if ( (int) $rl['count'] >= self::WINDOW_MAX ) {
			return __( 'Rate limit reached. Try again in a moment.', 'adambox' );
		}

		$rl['last']  = $now;
		$rl['count'] = (int) $rl['count'] + 1;

		set_transient( $key, $rl, self::WINDOW_TIME + 30 );

		return '';
	}

	/* =========================
	 * Moderation (GPT-5 Nano)
	 * ========================= */

	private function should_moderate( $ctx, $latest_text ) {
		$api_key = $this->get_opt( self::OPT_OPENAI_KEY, '' );
		if ( empty( $api_key ) ) return false;

		$strictness   = strtolower( $this->get_opt( self::OPT_STRICTNESS, 'medium' ) );
		$intervention = strtolower( $this->get_opt( self::OPT_INTERVENTION, 'intervene_only' ) );

		$txt = strtolower( (string) $latest_text );

		// Lightweight, local triggers to avoid calling the model constantly.
		$hard_terms = array(
			'kys', 'kill yourself', 'suicide', 'die', 'i will kill', 'i\'ll kill',
			'rape', 'nazi', 'kkk'
		);

		$toxic_terms = array(
			'fuck you', 'fucking', 'bitch', 'cunt', 'retard', 'idiot', 'moron',
			'stupid', 'trash', 'garbage', 'piece of shit', 'shut up'
		);

		// Always trigger on "hard" terms.
		foreach ( $hard_terms as $t ) {
			if ( strpos( $txt, $t ) !== false ) return true;
		}

		if ( $strictness === 'high' ) {
			foreach ( $toxic_terms as $t ) {
				if ( strpos( $txt, $t ) !== false ) return true;
			}

			// Shouting / escalation
			if ( preg_match( '/[A-Z]{10,}/', (string) $latest_text ) ) return true;
			if ( preg_match( '/[!?]{4,}/', (string) $latest_text ) ) return true;
		}

		if ( $strictness === 'medium' ) {
			// Medium: only catch direct insults (subset) + escalation punctuation
			$medium_terms = array( 'fuck you', 'bitch', 'idiot', 'moron', 'shut up' );
			foreach ( $medium_terms as $t ) {
				if ( strpos( $txt, $t ) !== false ) return true;
			}
			if ( preg_match( '/[!?]{5,}/', (string) $latest_text ) ) return true;
		}

		// Optional: periodic summarization/guidance modes
		if ( $intervention !== 'intervene_only' ) {
			// Every ~8 user messages, allow a gentle "state of the convo" check.
			$user_count = 0;
			if ( is_array( $ctx ) ) {
				foreach ( $ctx as $m ) {
					if ( is_array( $m ) && isset( $m['role'] ) && $m['role'] === 'user' ) {
						$user_count++;
					}
				}
			}
			if ( $user_count > 0 && ( $user_count % 8 ) === 0 ) {
				return true;
			}
		}

		return false;
	}

	private function build_transcript( $ctx ) {
		// Send only the last ~10 messages to keep costs/latency down.
		$window = array_slice( (array) $ctx, - 10 );

		$lines = array();
		foreach ( $window as $m ) {
			if ( ! is_array( $m ) ) continue;
			$role = isset( $m['role'] ) ? (string) $m['role'] : 'system';
			$name = isset( $m['name'] ) ? (string) $m['name'] : '';
			$text = isset( $m['content'] ) ? (string) $m['content'] : '';

			$text = trim( preg_replace( '/\s+/', ' ', $text ) );
			if ( $text === '' ) continue;

			if ( $role === 'user' ) {
				$label = $name !== '' ? $name : 'User';
				$lines[] = $label . ': ' . $text;
			} else {
				$lines[] = 'Moderator: ' . $text;
			}
		}

		return implode( "\n", $lines );
	}

	private function moderation_instructions() {
		return "You are Adam, a neutral AI moderator for a live public conversation.\n\n"
			. "You are NOT a participant.\n"
			. "You do NOT take sides.\n"
			. "You do NOT continue the conversation.\n\n"
			. "Your role:\n"
			. "- Calm escalation\n"
			. "- Gentle redirection\n"
			. "- Clarification when confusion arises\n"
			. "- De-escalation when tone becomes hostile\n"
			. "- Brief summarization only if explicitly needed\n\n"
			. "Rules:\n"
			. "- Be concise (1–3 sentences max)\n"
			. "- Do not address individuals directly unless necessary\n"
			. "- Do not invent facts\n"
			. "- Do not ask questions\n"
			. "- Do not repeat user messages\n"
			. "- Never adopt a persona or opinion\n\n"
			. "If moderation is not required, respond with exactly:\n"
			. "NO_ACTION\n\n"
			. "If intervention is required, respond with a short neutral message suitable for a public chat.";
	}

	private function extract_output_text( $body ) {
		if ( is_array( $body ) && isset( $body['output_text'] ) && is_string( $body['output_text'] ) ) {
			return trim( $body['output_text'] );
		}

		// Fallback: walk output[] -> content[] -> text
		if ( ! is_array( $body ) || ! isset( $body['output'] ) || ! is_array( $body['output'] ) ) {
			return '';
		}

		$text = '';
		foreach ( $body['output'] as $out ) {
			if ( ! is_array( $out ) || empty( $out['content'] ) || ! is_array( $out['content'] ) ) continue;
			foreach ( $out['content'] as $c ) {
				if ( ! is_array( $c ) ) continue;
				if ( isset( $c['type'] ) && $c['type'] === 'output_text' && isset( $c['text'] ) && is_string( $c['text'] ) ) {
					$text .= $c['text'];
				} elseif ( isset( $c['text'] ) && is_string( $c['text'] ) ) {
					$text .= $c['text'];
				}
			}
		}

		return trim( $text );
	}

	private function run_moderation( $ctx ) {
		$api_key = $this->get_opt( self::OPT_OPENAI_KEY, '' );
		if ( empty( $api_key ) ) return '';

		$transcript = $this->build_transcript( $ctx );
		if ( $transcript === '' ) return '';

		$payload = array(
			'model'             => self::OPENAI_MODEL,
			'instructions'      => $this->moderation_instructions(),
			'input'             => "Conversation so far:\n" . $transcript,
			'max_output_tokens' => self::OPENAI_MAX_OUT_TOKENS,
			// keep it steady and non-chatty
			'temperature'       => 0.2,
		);

		$res = wp_remote_post( self::OPENAI_ENDPOINT, array(
			'timeout' => self::OPENAI_TIMEOUT,
			'headers' => array(
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $api_key,
			),
			'body'    => wp_json_encode( $payload ),
		) );

		if ( is_wp_error( $res ) ) return '';

		$code = (int) wp_remote_retrieve_response_code( $res );
		$raw  = wp_remote_retrieve_body( $res );
		$body = json_decode( $raw, true );

		if ( $code < 200 || $code >= 300 ) {
			return '';
		}

		$out = $this->extract_output_text( $body );
		if ( $out === '' ) return '';

		// Normalize
		$out = trim( preg_replace( '/\s+/', ' ', $out ) );

		if ( strtoupper( $out ) === 'NO_ACTION' ) return '';

		// Hard cap for safety (and to keep Adam from rambling)
		if ( mb_strlen( $out ) > 380 ) {
			$out = mb_substr( $out, 0, 380 );
			$out = rtrim( $out, " \t\n\r\0\x0B" ) . '…';
		}

		return $out;
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

		// Per-user rate limit
		if ( $msg = $this->rate_limit( $post_id, $sid ) ) {
			return new WP_REST_Response( array( 'error' => $msg ), 429 );
		}

		$key = $this->ctx_key( $post_id );
		$ctx = get_transient( $key );
		if ( ! is_array( $ctx ) ) $ctx = array();

		// Append user message
		$ctx[] = array(
			'role'    => 'user',
			'name'    => $name,
			'content' => $text,
			'time'    => time(),
		);

		$ctx = array_slice( $ctx, - self::MAX_STORE_MESSAGES );

		// GPT Moderation (triggered only when needed)
		if ( $this->should_moderate( $ctx, $text ) ) {
			$moderation = $this->run_moderation( $ctx );
			if ( $moderation ) {
				$ctx[] = array(
					'role'    => 'system',
					'content' => $moderation,
					'time'    => time(),
				);
				$ctx = array_slice( $ctx, - self::MAX_STORE_MESSAGES );
			}
		}

		set_transient( $key, $ctx, self::CONTEXT_TTL );

		// Prevent caching oddities on mobile
		if ( function_exists( 'nocache_headers' ) ) {
			nocache_headers();
		}

		return array(
			'success' => true,
			'context' => $ctx,
			'hash'    => md5( wp_json_encode( $ctx ) )
		);
	}
}
