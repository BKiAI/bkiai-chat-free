=== BKiAI KI Chatbot ===
Contributors: BusinessKiai
Tags: ai chat, chatbot, website chat, voice chat, ai assistant
Requires at least: 6.4
Tested up to: 6.9.4
Requires PHP: 7.4
Stable tag: 3.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Add an AI chat to your WordPress site with one configurable bot, voice recording, design settings, and optional knowledge-file support.

== Description ==

BKiAI Chat adds a configurable AI chat to your WordPress website. The free edition focuses on a lean, usable core setup with one active bot, design controls, and optional knowledge-file support for Bot 1.

= Included in BKiAI Chat =

* AI chat integration for WordPress
* One configurable bot
* Voice recording in supported browsers
* Design and layout settings for the chat window
* Popup mode for Bot 1
* One uploaded knowledge file for Bot 1
* Two selectable OpenAI chat models in the free edition: GPT-4o mini and GPT-4.1 mini

= Not included in the free edition =

* Local chat logs in the WordPress backend
* Live AI voice conversation
* Image generation
* PDF generation
* Web search
* Website-content knowledge sources
* Additional bots beyond Bot 1

== External services ==

This plugin connects to the OpenAI API to generate chatbot responses.

It sends data to OpenAI only when a site visitor submits a chat message. Depending on the plugin configuration, the transmitted data can include:

* the visitor's chat message
* the configured bot system prompt
* relevant recent chat history needed to answer the request
* optional uploaded knowledge-file content assigned to the bot

This service is provided by OpenAI.

* Terms of Use: https://openai.com/policies/terms-of-use/
* Privacy Policy: https://openai.com/policies/privacy-policy/

Site owners are responsible for assessing whether their privacy notice and consent flows need to mention this external service.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/bkiai-chat/` directory, or install the plugin through the WordPress plugins screen.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Open the BKiAI Chat settings in the WordPress admin area.
4. Configure the general settings and Bot 1 settings as needed.
5. Embed the chat window on your website using the shortcode `[bkiai_chat bot="1"]`.
6. If you use the popup chat mode, configure it through the settings in Bot 1.
7. Save your settings and test the chat on the frontend.

== Frequently Asked Questions ==

= What is included in the free edition? =

The free edition includes one active bot, voice recording, design settings, popup mode for Bot 1, and one uploaded knowledge file for Bot 1.

= How do I embed the chatbot on my website? =

Use the shortcode `[bkiai_chat bot="1"]` to embed the chatbot on a page or post.

= Does the plugin use external services? =

Yes. The plugin sends chat requests to OpenAI in order to generate responses. Please review the external-services section above.

= Which models are available in the free edition? =

The free edition includes GPT-4o mini and GPT-4.1 mini.

== Screenshots ==

1. General settings overview of BKiAI Chat.
2. Frontend desktop chat view on a WordPress website.
3. Design settings for colours, border, and layout.
4. Bot 1 settings with shortcode usage and model selection.
5. Mobile frontend chat view on a smartphone.

== Changelog ==

= 3.3.0 =
* Improved free-edition frontend UX with right-aligned controls, clearer voice-button states, and a ready tone for browser voice input.
* Added a bot-specific source-reference toggle with server-side source filtering for Bot 1.
* Added public request protection settings plus an inline help popup in the free settings.
* Improved knowledge-file retrieval with chunk-based matching for Markdown, TXT, and CSV files.
* Moved BKiAI Chat to its own top-level admin menu and refreshed the free-edition settings flow.
* Cleaned the WordPress.org package and line endings for a store-ready update.

= 3.2.2 =
* Improved frontend voice button UX with right-aligned controls, red active state, and a short ready tone.
* Fixed transparent chat header logo rendering.
* Added bot-specific source-reference toggle and server-side source filtering for Bot 1.
* Added public burst protection settings for public chat requests in the free edition.
* Improved knowledge-file retrieval with chunk-based matching for Markdown, TXT, and CSV files.


= 3.2.0 =
* WordPress.org resubmission release with the cleaned free build, updated package structure, and streamlined settings experience.

= 3.1.11 =
* Removed remaining realtime/live-voice JavaScript from the free build and kept voice input limited to supported browser speech recognition.
* Cleaned dead locked-feature CSS selectors and repository wording in uninstall metadata.

= 3.1.10 =
* Clarified the real minimum message-area height and aligned the saved setting with the frontend minimum of 150px.

= 3.1.9 =
* Fix: restored popup page selection list and missing context/helper methods for the WP.org test build.

= 3.1.8 =
* Fixed missing AJAX chat handlers in the WP.org candidate build.
* Restored selectable page/post list for popup targeting.

= 3.1.7 =
* Removed remaining premium-only PHP handlers from the free build, including realtime voice, image generation, PDF generation, and local log-storage code.
* Cleaned the package structure for the WordPress.org re-submission and kept the in-plugin upgrade link focused on the external product page.

= 3.1.6 =
* Added a WordPress.org-safe upgrade link to the Pro and Expert product page inside the settings screen.
* Kept the free settings focused on usable free functionality without reintroducing locked premium tabs.

= 3.1.1 =
* Removed repository-internal compare and upgrade areas from the free build.
* Reduced the free build to one active bot and free-only options.
* Moved admin CSS and admin JavaScript out of the main plugin file.
* Clarified external-services documentation for OpenAI.
* Updated the plugin name to BKiAI Chat.

= 3.1.0 =
* Added a backend design setting for the default chat input-field height.
* Added animated three-dot loading feedback before streamed answers appear.
* Refined the streamed response speed to feel more natural and human-like.

== Upgrade Notice ==

= 3.3.0 =
* Store-ready free-edition update with improved frontend UX, request protection, source control, and knowledge-file retrieval.

= 3.2.0 =
* WordPress.org resubmission release with the cleaned free package and streamlined free-only code base.

= 3.1.11 =
* Final free-build cleanup before the resubmission, removing remaining realtime/live-voice JavaScript remnants.

= 3.1.10 =
* Aligns the message-area height setting with the effective frontend minimum of 150px.

= 3.1.9 =
* Fix: restored popup page selection list and missing context/helper methods for the WP.org test build.

= 3.1.8 =
* Fixed missing AJAX chat handlers in the WP.org candidate build.
* Restored selectable page/post list for popup targeting.

= 3.1.7 =
Repository cleanup release for the WordPress.org resubmission.

= 3.1.6 =
Adds a clean in-plugin link to the Pro and Expert product page.

= 3.1.1 =
Repository-focused cleanup release for the free edition.
