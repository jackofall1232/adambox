<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AdamBox Moderator
 *
 * Handles AI-based moderation responses with
 * safe fallbacks when AI is unavailable.
 *
 * v1.1.0
 */
class AdamBox_Moderator {

	/**
	 * Main entry point
	 *
	 * @param array  $ctx      Conversation context
	 * @param string $severity Severity tier from AdamBox_Keywords
	 * @return string Moderation message or empty string
	 */
	public static function handle( $ctx, $severity ) {

		// Gate: severity check
		if ( ! $severity ) {
			return '';
		}

		// Gate: AI availability
		if ( AdamBox_Settings::has_api_key() ) {
			return self::ai_moderate( $ctx, $severity );
		}

		// Fallback: no AI
		return self::fallback_message( $severity );
	}

	/* =========================
	 * AI MODERATION
	 * ========================= */

	private static function ai_moderate( $ctx, $severity ) {

		$key = AdamBox_Settings::get( 'openai_api_key', '' );
		if ( ! $key ) return '';

		$payload = array(
			'model' => AdamBox_REST::OPENAI_MODEL,

			// Keep it fast + cheap
			'reasoning' => array(
				'effort' => 'low',
			),

			'input' => array(
				array(
					'role' => 'system',
					'content' => array(
						array(
							'type' => 'input_text',
							'text' => self::system_prompt( $severity ),
						),
					),
				),
				array(
					'role' => 'user',
					'content' => array(
						array(
							'type' => 'input_text',
							'text' => self::build_transcript( $ctx, $severity ),
						),
					),
				),
			),

			'max_output_tokens' => AdamBox_REST::OPENAI_MAX_OUT_TOKENS,
		);

		$res = wp_remote_post(
			AdamBox_REST::OPENAI_ENDPOINT,
			array(
				'timeout' => AdamBox_REST::OPENAI_TIMEOUT,
				'headers' => array(
					'Authorization' => 'Bearer ' . $key,
					'Content-Type'  => 'application/json',
				),
				'body' => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $res ) ) {
			return '';
		}

		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $body ) || empty( $body['output'] ) ) {
			return '';
		}

		// Parse GPT-5 Responses API output
		$out = '';

		foreach ( $body['output'] as $item ) {
			if ( isset( $item['type'] ) && $item['type'] === 'message' ) {
				foreach ( $item['content'] as $c ) {
					if ( isset( $c['text'] ) ) {
						$out .= $c['text'] . ' ';
					}
				}
				break;
			}
		}

		$out = trim( preg_replace( '/\s+/', ' ', $out ) );

		if ( strtoupper( $out ) === 'NO_ACTION' || $out === '' ) {
			return '';
		}

		return mb_substr( $out, 0, 380 );
	}

	/* =========================
	 * FALLBACK (NO AI)
	 * ========================= */

	private static function fallback_message( $severity ) {

		switch ( $severity ) {

			case 'tier_1':
				return "Let's pause for a moment and keep the conversation safe and respectful for everyone.";

			case 'tier_2':
				return 'Please keep things civil and avoid personal attacks.';

			case 'tier_3':
				return "Let's keep the discussion respectful.";

			default:
				return '';
		}
	}

	/* =========================
	 * PROMPTS & HELPERS
	 * ========================= */

	private static function system_prompt( $severity ) {

		$base = 'You are Adam, a calm and neutral AI moderator in a shared group chat.';

		switch ( $severity ) {

			case 'tier_1':
				return $base . '
This conversation contains serious or harmful language (self-harm, extreme violence, or hate speech).
Intervene immediately with a firm but respectful message to the group.
Do not shame or threaten. Encourage safety and respect.
Respond with ONE short paragraph (2-3 sentences max).';

			case 'tier_2':
				return $base . '
There is escalating hostility, harassment, or severe insults in this conversation.
Provide a gentle but clear reminder to remain respectful.
Respond with ONE short sentence addressed to the group.';

			case 'tier_3':
				return $base . '
Tone is getting unfriendly or mildly hostile.
Evaluate the context carefully:
- If this is a pattern of repeated hostility, offer a light reminder to keep things constructive.
- If it appears to be isolated, playful banter, or unclear, reply exactly: NO_ACTION
- When in doubt, err toward NO_ACTION rather than over-moderating.
Respond briefly (one sentence) or NO_ACTION.';

			default:
				return $base . '
If no intervention is needed, reply exactly: NO_ACTION';
		}
	}

	private static function build_transcript( $ctx, $severity ) {

		$lines = array();

		// Tell AI what triggered this moderation check
		if ( class_exists( 'AdamBox_Keywords' ) ) {
			$trigger = AdamBox_Keywords::get_tier_description( $severity );
			$lines[] = '[SYSTEM ALERT: ' . $trigger . ']';
			$lines[] = '';
		}

		// Check if this is a pattern escalation
		$is_pattern = self::detect_pattern( $ctx );
		if ( $is_pattern ) {
			$lines[] = '[PATTERN DETECTED: User has sent multiple hostile messages recently]';
			$lines[] = '';
		}

		// Build conversation transcript (last 10 messages)
		foreach ( array_slice( $ctx, -10 ) as $m ) {
			$label = ( $m['role'] === 'user' )
				? ( $m['name'] ?: 'User' )
				: 'Moderator';

			// Mark flagged messages
			$flagged = isset( $m['flagged'] ) && $m['flagged'] ? ' [REMOVED]' : '';

			$lines[] = $label . ': ' . $m['content'] . $flagged;
		}

		return implode( "\n", $lines );
	}

	/**
	 * Detect if user has a pattern of hostile messages
	 * (Used to give AI more context)
	 *
	 * @param array $ctx Context messages
	 * @return bool True if pattern detected
	 */
	private static function detect_pattern( $ctx ) {
		if ( empty( $ctx ) || ! is_array( $ctx ) ) {
			return false;
		}

		// Get last 5 user messages
		$recent = array();
		foreach ( array_reverse( $ctx ) as $m ) {
			if ( isset( $m['role'], $m['content'] ) && $m['role'] === 'user' ) {
				$recent[] = $m['content'];
				if ( count( $recent ) >= 5 ) {
					break;
				}
			}
		}

		// Count hostile terms across recent messages
		$hostile_pattern = '/(fuck|shit|bitch|asshole|idiot|moron|stupid|loser|trash|die|kill)/i';
		$hits = 0;

		foreach ( $recent as $msg ) {
			if ( preg_match( $hostile_pattern, $msg ) ) {
				$hits++;
			}
		}

		// 2+ hostile messages in recent history = pattern
		return ( $hits >= 2 );
	}
}
