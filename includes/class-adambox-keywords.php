<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class AdamBox_Keywords {

	/**
	 * Tiered keyword lists (literal matches only)
	 */
	public static function lists() {
		return array(

			/* =========================
			 * Tier 1: Critical – always escalate
			 * ========================= */
			'tier_1' => array(
				// Self-harm
				'kys','kill yourself','commit suicide','end your life',

				// Direct violence
				'i will kill you','i\'ll kill you','gonna kill you',

				// Extreme hate / violence
				'rape','lynch','nazi','kkk','white power',
			),

			/* =========================
			 * Tier 2: High-risk harassment
			 * ========================= */
			'tier_2' => array(
				// Direct harassment
				'fuck you','piece of shit','asshole','retard',

				// Death wishes
				'hope you die','die in a fire',

				// Doxxing threats
				'i know where you live','dox you',
			),

			/* =========================
			 * Tier 3: Low-level hostility
			 * ========================= */
			'tier_3' => array(
				'bitch','idiot','moron','stupid','loser','trash',
			),
		);
	}

	/**
	 * Analyze a message and return severity tier or false
	 *
	 * @return string|false
	 */
	public static function analyze( $ctx, $text ) {

		$txt    = strtolower( $text );
		$lists  = self::lists();
		$strict = AdamBox_Settings::moderation_strictness();

		/* =========================
		 * Tier 1: Always escalate
		 * ========================= */
		foreach ( $lists['tier_1'] as $term ) {
			if ( strpos( $txt, $term ) !== false ) {
				return 'tier_1';
			}
		}

		/* =========================
		 * Tier 2: Medium+ strictness
		 * ========================= */
		if ( $strict !== 'low' ) {

			// Literal Tier 2
			foreach ( $lists['tier_2'] as $term ) {
				if ( strpos( $txt, $term ) !== false ) {
					return 'tier_2';
				}
			}

			// Regex: compound profanity (fuck off, fuckface, fuck head, etc.)
			if ( preg_match(
				'/\bfuck(\s+(off|you|him|her|them)|[\s\-]?(face|head|wad|hole|bag))\b/i',
				$text
			) ) {
				return 'tier_2';
			}

			// Regex: shit-based insults
			if ( preg_match(
				'/\bshit(\s+(head|face|bag)|[\s\-]?(head|face|bag))\b/i',
				$text
			) ) {
				return 'tier_2';
			}
		}

		/* =========================
		 * Tier 3: High strictness only
		 * ========================= */
		if ( $strict === 'high' ) {

			foreach ( $lists['tier_3'] as $term ) {
				if ( strpos( $txt, $term ) !== false ) {
					return 'tier_3';
				}
			}

			// Mild directives (go to hell, screw you)
			if ( preg_match(
				'/\b(go to hell|screw you|piss off)\b/i',
				$text
			) ) {
				return 'tier_3';
			}
		}

		/* =========================
		 * Pattern escalation (repeated hostility)
		 * ========================= */
		$recent = array_slice( $ctx, -3 );
		$hits   = 0;

		foreach ( $recent as $m ) {
			if (
				$m['role'] === 'user' &&
				preg_match(
					'/(bitch|idiot|moron|asshole|loser|fuck)/i',
					$m['content']
				)
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
