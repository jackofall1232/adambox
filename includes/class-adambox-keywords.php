<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class AdamBox_Keywords {

	/**
	 * Return tiered moderation keywords
	 */
	public static function lists() {
		return array(

			// Tier 1: Critical – always escalate
			'tier_1' => array(
				'kys','kill yourself','commit suicide','end your life',
				'i will kill you','i\'ll kill you','gonna kill you',
				'rape','lynch','nazi','kkk','white power'
			),

			// Tier 2: High-risk harassment
			'tier_2' => array(
				'fuck you','piece of shit','asshole','retard',
				'hope you die','die in a fire',
				'i know where you live','dox you'
			),

			// Tier 3: Low-level hostility
			'tier_3' => array(
				'bitch','idiot','moron','stupid','loser','trash'
			),
		);
	}

	/**
	 * Analyze a message and return severity or false
	 *
	 * @return string|false tier name or false
	 */
	public static function analyze( $ctx, $text ) {

		$txt   = strtolower( $text );
		$lists = self::lists();
		$strict = AdamBox_Settings::moderation_strictness();

		// Tier 1 always triggers
		foreach ( $lists['tier_1'] as $term ) {
			if ( strpos( $txt, $term ) !== false ) {
				return 'tier_1';
			}
		}

		// Tier 2 triggers on medium+
		if ( $strict !== 'low' ) {
			foreach ( $lists['tier_2'] as $term ) {
				if ( strpos( $txt, $term ) !== false ) {
					return 'tier_2';
				}
			}
		}

		// Tier 3 only on high strictness
		if ( $strict === 'high' ) {
			foreach ( $lists['tier_3'] as $term ) {
				if ( strpos( $txt, $term ) !== false ) {
					return 'tier_3';
				}
			}
		}

		// Pattern escalation (repeated hostility)
		$recent = array_slice( $ctx, -3 );
		$hits = 0;

		foreach ( $recent as $m ) {
			if (
				$m['role'] === 'user' &&
				preg_match( '/(bitch|idiot|moron|asshole|loser)/i', $m['content'] )
			) {
				$hits++;
			}
		}

		if ( $hits >= 2 ) {
			return 'tier_2';
		}

		return false;
	}
}
