=== AdamBox ===
Contributors: jackofall1232, Ask Adam
Donate link:
Tags: ai moderation, chat moderation, content moderation, ai safety, community tools
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: trunk
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Lightweight AI-powered moderation box for WordPress. AdamBox provides calm, neutral AI oversight for conversations without tracking users or storing data.

== Description ==

AdamBox is a **lightweight AI moderation box** for WordPress.

It is designed to provide **calm, neutral, non-dominant AI moderation** for conversations embedded on a page — without acting as a chatbot, without storing user data, and without tracking identities.

AdamBox is **not** a chat replacement.  
It is an **AI moderator**, not a participant.

**Note:** Current releases are **alpha versions** intended for testing and development use.

### Core Principles

- Clean and lean
- WordPress-standards compliant
- No user tracking
- No persistent message storage
- No database tables
- No external JavaScript frameworks
- Community-first and fully open source

### What AdamBox Does

- Renders a lightweight chat interface via shortcode
- Provides shared, per-page conversation visibility
- Displays recent messages to participants for clarity
- Applies rate limiting to prevent spam or flooding
- Uses ephemeral display names for conversation clarity
- Intervenes only when moderation is needed
- Uses WordPress REST API and vanilla JavaScript only

### What AdamBox Does *Not* Do

- No long-term memory
- No learning from conversations
- No user profiling or real identity tracking
- No chat transcripts saved to the database
- No analytics or third-party scripts
- No feature gating in the free version

AdamBox is built for site owners who want **responsible AI oversight** without sacrificing privacy or control.

== Installation ==

1. Upload the `adambox` folder to the `/wp-content/plugins/` directory  
2. Activate the plugin through the **Plugins** menu in WordPress  
3. Go to **Settings → AdamBox** to configure options  
4. Add the shortcode `[adambox]` to any page or post  

== Usage ==

Insert the shortcode anywhere you want the moderation box to appear:
