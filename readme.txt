=== AdamBox ===
Contributors: jackofall1232, Ask Adam
Donate link: 
Tags: ai moderation, chat moderation, content moderation, ai safety, community tools
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Lightweight AI-powered moderation box for WordPress. AdamBox provides calm, neutral AI oversight for conversations without tracking users or storing data.

== Description ==

AdamBox is a **lightweight AI moderation box** for WordPress.

It is designed to provide **calm, neutral, non-dominant AI moderation** for conversations embedded on a page — without acting as a chatbot, without storing user data, and without tracking identities.

AdamBox is **not** a chat replacement.
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

- Renders a lightweight chat box via shortcode
- Observes recent messages (session-based only)
- Intervenes when moderation is needed
- Optionally summarizes or guides conversations based on admin settings
- Uses WordPress REST API and vanilla JavaScript only

### What AdamBox Does *Not* Do

- No long-term memory
- No learning from conversations
- No user profiling or identity tracking
- No chat transcripts saved to the database
- No feature gating in the free version

AdamBox is built for site owners who want **responsible AI oversight** without sacrificing privacy or control.

== Installation ==

1. Upload the `adambox` folder to the `/wp-content/plugins/` directory  
2. Activate the plugin through the **Plugins** menu in WordPress  
3. Go to **Settings → AdamBox** to configure options  
4. Add the shortcode `[adambox]` to any page or post  

== Usage ==

Insert the shortcode anywhere you want the moderation box to appear:
[adambox]

AdamBox will render a lightweight chat interface and operate entirely within the visitor’s session.

No messages are saved to the database.

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

These controls adjust **how and when** the AI moderator intervenes — not what users are allowed to say.

== Privacy ==

AdamBox is privacy-first by design.

- No persistent user data
- No chat logs stored
- No cookies beyond session state (or localStorage fallback)
- No analytics or tracking scripts
- No third-party JavaScript frameworks

Messages exist **only temporarily** for moderation context and are discarded automatically.

== Frequently Asked Questions ==

= Is AdamBox a chatbot? =

No.  
AdamBox is an **AI moderator**, not a conversational agent.

= Does AdamBox store conversations? =

No.  
Messages are session-based only and never written to the database.

= Does AdamBox track users? =

No.  
There is no user profiling, fingerprinting, or analytics.

= Can I use AdamBox without an API key? =

Yes.  
The API key is optional. Without it, AdamBox can still render the interface and degrade gracefully.

= Is there a Pro version? =

AdamBox is fully functional as a free plugin.  
The architecture allows for future extensions, but no features are gated or hidden in the free version.

== Screenshots ==

1. AdamBox moderation interface embedded on a page  
2. AdamBox settings panel in WordPress admin  

== Changelog ==

= 0.1.0 =
* Initial release
* Plugin bootstrap and architecture
* Shortcode rendering
* Admin settings foundation
* REST API scaffolding
* WordPress.org compliance baseline

== Upgrade Notice ==

= 0.1.0 =
Initial public release.

== License ==

This plugin is licensed under the GNU General Public License v3.0 or later.

You are free to use, modify, and distribute this software under the terms of the GPL.
