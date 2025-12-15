<?php
/**
 * Enhanced Moderation System for AdamBox
 * 
 * Drop-in replacement for the should_moderate() and run_moderation() methods
 * in class-adambox-rest.php
 */

/* =========================
 * ENHANCED KEYWORD LIST
 * Replace the simple hard_terms array
 * ========================= */

private function get_moderation_keywords() {
	return array(
		// TIER 1: Critical - Always moderate immediately
		'tier_1' => array(
			// Self-harm
			'kill yourself', 'kys', 'end your life', 'commit suicide',
			'hang yourself', 'shoot yourself', 'slit your wrists',
			
			// Direct violence threats
			'i will kill you', 'i\'ll kill you', 'gonna kill you',
			'i will murder', 'gonna murder you', 'watch your back',
			
			// Extreme hate speech
			'nazi', 'heil hitler', 'kkk', 'white power', 'lynch',
			
			// Sexual violence
			'rape you', 'sexually assault', 'molest',
		),
		
		// TIER 2: High Priority - Context matters but likely harmful
		'tier_2' => array(
			// Slurs (abbreviated for example - expand as needed)
			'nigger', 'n1gger', 'faggot', 'f4ggot', 'tranny',
			'chink', 'spic', 'wetback', 'towelhead',
			
			// Death wishes
			'hope you die', 'wish you were dead', 'die in a fire',
			
			// Doxxing threats
			'i know where you live', 'gonna find you', 'post your address',
			'dox you', 'doxx you',
		),
		
		// TIER 3: Moderate - Aggressive but common
		'tier_3' => array(
			'fuck you', 'screw you', 'go to hell', 'piece of shit',
			'dumb fuck', 'fucking idiot', 'retard', 'kill urself',
		),
	);
}

/**
 * Enhanced should_moderate with tiered keyword system
 */
private function should_moderate( $ctx, $text ) {

	if ( ! AdamBox_Settings::has_api_key() ) return false;

	$strictness   = AdamBox_Settings::moderation_strictness();
	$intervention = AdamBox_Settings::intervention_level();
	
	$txt = strtolower( $text );
	$keywords = $this->get_moderation_keywords();
	
	// TIER 1: Always moderate regardless of strictness
	foreach ( $keywords['tier_1'] as $term ) {
		if ( strpos( $txt, $term ) !== false ) {
			return true;
		}
	}
	
	// TIER 2: Moderate on medium+ strictness
	if ( $strictness !== 'low' ) {
		foreach ( $keywords['tier_2'] as $term ) {
			if ( strpos( $txt, $term ) !== false ) {
				return true;
			}
		}
	}
	
	// TIER 3: Only on high strictness
	if ( $strictness === 'high' ) {
		foreach ( $keywords['tier_3'] as $term ) {
			if ( strpos( $txt, $term ) !== false ) {
				return true;
			}
		}
	}
	
	// Pattern detection (applies to medium+ strictness)
	if ( $strictness !== 'low' ) {
		// Excessive punctuation
		if ( preg_match( '/[!?]{5,}/', $text ) ) {
			return true;
		}
		
		// Excessive caps (20+ characters)
		if ( preg_match( '/[A-Z\s]{20,}/', $text ) ) {
			return true;
		}
		
		// Repeated characters (spam pattern)
		if ( preg_match( '/(.)\1{10,}/', $text ) ) {
			return true;
		}
	}
	
	// URL/Email spam detection (high strictness only)
	if ( $strictness === 'high' ) {
		$url_count = preg_match_all( '/https?:\/\/[^\s]+/', $text );
		if ( $url_count > 2 ) {
			return true;
		}
		
		$email_count = preg_match_all( '/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/i', $text );
		if ( $email_count > 1 ) {
			return true;
		}
	}
	
	// Periodic intervention (summary/guidance)
	if ( $intervention !== 'intervene_only' ) {
		$count = 0;
		foreach ( $ctx as $m ) {
			if ( $m['role'] === 'user' ) $count++;
		}
		if ( $count > 0 && $count % 8 === 0 ) {
			return true;
		}
	}
	
	return false;
}

/**
 * CORRECTED run_moderation() with proper GPT-5 Responses API format
 */
private function run_moderation( $ctx ) {

	$key = AdamBox_Settings::get( 'openai_api_key', '' );
	if ( ! $key ) return '';

	$res = wp_remote_post( self::OPENAI_ENDPOINT, array(
		'timeout' => self::OPENAI_TIMEOUT,
		'headers' => array(
			'Authorization' => 'Bearer ' . $key,
			'Content-Type'  => 'application/json',
		),
		'body' => wp_json_encode( array(
			'model' => self::OPENAI_MODEL,
			'input' => array(
				array(
					'role' => 'developer',
					'content' => 'You are Adam, a neutral AI moderator for a shared chat. Keep conversations civil and helpful. If no intervention is needed, reply exactly: NO_ACTION

If intervention is needed, provide a brief, kind moderation message (max 100 words). Be firm but friendly.'
				),
				array(
					'role' => 'user',
					'content' => $this->build_transcript( $ctx )
				)
			),
			'reasoning' => array(
				'effort' => 'none'  // Fast responses for real-time moderation
			),
			'temperature' => 0.3,
			'max_tokens' => self::OPENAI_MAX_OUT_TOKENS,
		) ),
	) );

	if ( is_wp_error( $res ) ) return '';

	$status = wp_remote_retrieve_response_code( $res );
	if ( $status !== 200 ) return '';

	$body = json_decode( wp_remote_retrieve_body( $res ), true );
	
	// CORRECTED: GPT-5 Responses API returns in choices[0].message.content
	$out = trim( $body['choices'][0]['message']['content'] ?? '' );

	if ( strtoupper( $out ) === 'NO_ACTION' ) return '';

	return mb_substr( preg_replace( '/\s+/', ' ', $out ), 0, 380 );
}

/* =========================
 * OPTIONAL: Add logging for debugging
 * ========================= */

private function log_moderation( $message, $data = array() ) {
	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		$log = '[AdamBox] ' . $message;
		if ( ! empty( $data ) ) {
			$log .= ' | ' . wp_json_encode( $data );
		}
		error_log( $log );
	}
}

// Then in should_moderate(), add logging:
// $this->log_moderation( 'Tier 1 keyword detected', array( 'term' => $term ) );

// And in run_moderation():
// $this->log_moderation( 'API request sent' );
// $this->log_moderation( 'API response', array( 'status' => $status, 'has_content' => !empty($out) ) );
