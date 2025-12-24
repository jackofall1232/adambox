=== AdamBox ===
Contributors: jackofall1232, askadam
Donate link:
Tags: ai moderation, chat moderation, content moderation, ai safety, community tools
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.1.1
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Lightweight AI-powered moderation box for WordPress that provides calm, neutral AI oversight without tracking users or storing data.

== Description ==

Lightweight AI-powered moderation for WordPress conversations with calm, neutral oversight and no user tracking.  
It is an **AI moderator**, not a participant.

### Core Principles

- Clean and lean
- WordPress-standards compliant
- No user tracking
- No persistent message storage
- No database tables
- No external JavaScript frameworks
- Community-first and fully open source

### What AdamBox Does

- Renders a lightweight moderation-enabled conversation box via shortcode
- Supports multiple visitors chatting together on the same page
- Uses session-based display names (no accounts required)
- Observes recent messages temporarily for moderation context only
- Intervenes calmly when moderation is needed
- Uses WordPress REST API and vanilla JavaScript only

### What AdamBox Does *Not* Do

- No long-term memory
- No learning from conversations
- No user profiling or identity tracking
- No chat transcripts saved to the database
- No analytics or tracking scripts
- No feature gating in the free version

AdamBox is built for site owners who want **responsible AI oversight** without sacrificing privacy or control.

== Installation ==

1. Upload the `adambox` folder to the `/wp-content/plugins/` directory  
2. Activate the plugin through the **Plugins** menu in WordPress  
3. Go to **Settings → AdamBox** to configure options  
4. Add the shortcode `[adambox]` to any page or post  

== Usage ==

Insert the shortcode anywhere you want the moderation box to appear:

`[adambox]`

Each page maintains its own conversation context.  
Messages are shared live between visitors on the same page.

== Settings ==

AdamBox adds a settings page at:

**Settings → AdamBox**

Available options include:

### API Configuration
- Optional OpenAI API key
- Stored securely using WordPress options

### Moderation Controls
- Moderation strictness:
  - Low
  - Medium (default)
  - High
- AI intervention level:
  - Intervene only (default)
  - Summarize when needed
  - Actively guide

These controls affect **how and when** the AI moderator intervenes — not what users are allowed to say.

== Privacy ==

AdamBox is privacy-first by design.

- No persistent user data
- No chat logs stored in the database
- No cookies beyond session state (or localStorage fallback)
- No analytics or tracking scripts
- No third-party JavaScript frameworks

Messages exist **only temporarily** for moderation context and are discarded automatically.

== External Services ==

AdamBox optionally connects to the OpenAI API to provide AI-powered moderation.

This service is used only when a site administrator supplies their own OpenAI API key
in the plugin settings.

Data sent:
- User-submitted chat messages (text only)
- Sent only at the moment moderation is triggered

No data is stored by AdamBox after processing.

Service provider:
OpenAI, Inc.

Terms of Service:
https://openai.com/policies/terms-of-use

Privacy Policy:
https://openai.com/policies/privacy-policy

== Frequently Asked Questions ==

= Is AdamBox a chatbot? =

No.  
AdamBox is an **AI moderator**, not a conversational agent.

= Does AdamBox store conversations? =

No.  
Messages are stored ephemerally only to support live moderation and are never written to the database.

= Does AdamBox track users? =

No.  
Display names are session-based only and are not linked to identities.

= Can I use AdamBox without an API key? =

Yes.  
The API key is optional. Without it, AdamBox continues to function using non-AI moderation logic.

= Is AdamBox free? =

Yes.  
AdamBox is fully functional as a free, open-source plugin licensed under GPL v3.

== Screenshots ==

1. AdamBox moderation interface embedded on a page  
2. AdamBox settings panel in WordPress admin  

== Changelog ==

= 1.1.1 =
* Documentation updates for WordPress.org compliance
* External service disclosure added
* Distribution cleanup (no functional changes)

= 1.1.0 =
* Added tier-based moderation strictness with meaningful behavioral differences
* Implemented automatic cooldowns for severe (Tier 1) violations
* Added placeholder replacement for removed messages to prevent escalation
* Improved intent-based handling for low-level (Tier 3) hostility
* Enhanced AI prompts with better context awareness and pattern detection
* Strengthened abuse prevention while preserving low-friction conversation flow
* Internal moderation logic refactored for future Pro feature expansion

= 1.0.0 =
* Initial stable release
* Hardened moderation pipeline with keyword tiers and optional AI oversight
* Privacy-first architecture validated for WordPress.org compliance
* REST API rate limiting and abuse protection
* Mobile stability and caching fixes finalized
* Plugin Checker warnings resolved

= 0.3.2 =
* Fixed aggressive mobile browser caching preventing live updates
* Improved polling reliability across Chrome Mobile and Safari
* Fixed scroll jumping and message flashing during live updates
* Added session-locked display names for clearer multi-user conversations
* Improved mobile behavior when switching tabs or returning to the page

= 0.2.0 =
* Added per-page shared conversation context
* Messages now visible to all visitors on the same page
* Context stored ephemerally using WordPress transients
* No user tracking or persistent storage

= 0.1.0 =
* Initial release
* Plugin bootstrap and architecture
* Shortcode rendering
* Admin settings foundation
* REST API scaffolding
* WordPress.org compliance baseline

== Upgrade Notice ==

= 1.1.1 =
Documentation and compliance update only.  
No configuration changes are required.

== License ==

This plugin is licensed under the GNU General Public License v3.0 or later.

You are free to use, modify, and distribute this software under the terms of the GPL.
