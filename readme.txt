=== NB AI Exchange ===
Contributors: netbound
Tags: ai, shortcodes, divi, css, chatgpt, claude, developer, images, dall-e, midjourney, editor
Requires at least: 5.0
Tested up to: 6.7
Stable tag: 1.3.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Bridge between WordPress/Divi and AI tools. Use AI directly in the post editor - send text, generate image prompts, import code, all without leaving your page.

== Description ==

**NB AI Exchange** is the missing link between WordPress/Divi and web-based AI assistants like ChatGPT, Claude, and Gemini. Stop paying for expensive AI plugins - just copy, paste, and let AI help you customize your site.

= NEW: In-Editor AI Panel (v1.3.0) =

A floating AI assistant panel appears right in the post/page editor:

* **Send to AI** - Select text, a shortcode, or your whole post → generate AI prompt → copy to ChatGPT/Claude
* **Generate Image Prompts** - Describe what you need → get optimized prompts for DALL-E, Midjourney, Stable Diffusion
* **Import Responses** - Paste AI code → install shortcodes or insert HTML directly
* **Import Images** - Paste AI image URL → download to Media Library → insert in post

No more switching tabs! The purple 🦸 button appears in the corner when editing.

= What Can AI Help You Build? =

* **PHP Shortcodes** - Forms, layouts, dynamic content
* **CSS Styling** - Blog cards, post layouts, category pages
* **Divi Code Modules** - Custom headers, footers, sections
* **Divi Theme Builder** - Post templates, archive layouts
* **AI Images** - Import DALL-E, Midjourney, Stable Diffusion images directly!
* **WordPress Customizations** - Any code snippet you need

= NEW: AI Image Import =

Import AI-generated images directly to your Media Library:

* **Paste URL** - From DALL-E, Midjourney, Stable Diffusion, Leonardo.ai
* **Paste Base64** - Some AI tools output base64 data
* **Auto-detects source** - Identifies DALL-E, Midjourney, etc.
* **Proper metadata** - Sets title, alt text, tracks source
* **Recent imports** - See your AI images at a glance

No more download-then-upload! Just paste and import.

= The Problem =

When you want AI to help customize WordPress or Divi, you spend time:
* Finding and copying existing code
* Explaining WordPress/Divi context to the AI
* Figuring out where to put the AI's response
* Manually installing or pasting the code
* Downloading AI images and re-uploading them

= The Solution =

NB AI Exchange handles all of that:

1. **Export** - Select code type, pick a prompt, copy with full context
2. **Paste in AI** - ChatGPT, Claude, Gemini - any AI assistant
3. **Import** - Paste the response, auto-detects PHP/CSS/HTML
4. **Use It** - Install shortcodes or copy CSS to the right place

= Features =

* **Multiple Code Types** - PHP shortcodes, CSS, Divi code modules, Theme Builder
* **Smart Context** - Automatically includes WP/Divi version info
* **Divi-Specific Prompts** - Blog styling, headers, footers, post layouts
* **Auto-Detection** - Recognizes CSS, PHP, HTML in AI responses
* **Destination Hints** - Tells you exactly where to paste the code
* **Shortcode Installation** - Registers PHP shortcodes directly (no FTP!)
* **Exchange History** - Track your AI conversations
* **Works with Elementor** - Shortcodes work in Elementor too!

= Divi Integration =

Detects when Divi is installed and provides:
* Divi-specific AI prompts (blog styling, headers, footers)
* Destination guidance (Theme Customizer, Code Module, Theme Builder)
* CSS class hints (.et_pb_* selectors)

= Use Cases =

* Style Divi Blog Module with modern card layouts
* Create custom headers/footers without buying plugins
* Generate CSS for post categories or archives
* Build form shortcodes with validation
* Refactor old code to modern standards

= Works With =

* **Page Builders:** Divi, Elementor, Gutenberg
* **AI Assistants:** ChatGPT, Claude, Gemini, Copilot, any AI

== Installation ==

1. Upload the `nb-ai-exchange` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu
3. Go to NetBound Tools → AI Exchange

== Frequently Asked Questions ==

= Where are AI-created shortcodes stored? =

They're stored in the WordPress database (options table), not as files. This means they persist through plugin updates and are backed up with your database.

= Is it safe to install AI-generated code? =

The plugin scans for dangerous functions (eval, exec, shell_exec, etc.) before installation. However, always review AI code carefully and test on a staging site first.

= Why only nb-* shortcodes? =

This focuses on NetBound Tools shortcodes for a cleaner interface. Future versions may include all shortcodes with filtering options.

= Can I edit installed shortcodes? =

Yes! Use the Export feature to copy the code, ask AI to modify it, then reinstall with the same tag to update it.

== Screenshots ==

1. Export panel - select code type, choose AI prompt, copy with context
2. Import panel - paste AI response, auto-detects code type
3. AI Images tab - paste URL or base64, preview, import to Media Library
4. My Shortcodes - view and manage AI-created shortcodes
5. History - track all your AI exchanges

== Changelog ==

= 1.3.0 =
* **IN-EDITOR AI PANEL!** Floating panel appears when editing posts/pages
* Send selected text, full post content, or shortcodes to AI
* Generate optimized image prompts for DALL-E, Midjourney, Stable Diffusion, Leonardo, Ideogram
* Import AI responses and install shortcodes without leaving the editor
* Import AI images directly into your post
* Toggle button in corner of editor (classic and block editor)
* Classic editor meta box for quick access

= 1.2.0 =
* **NEW: AI Image Import!** Paste URLs or base64 data from any AI tool
* Supports DALL-E, Midjourney, Stable Diffusion, Leonardo.ai, Adobe Firefly
* Auto-detects AI source and adds metadata
* Recent imports grid shows your AI images
* Images properly added to Media Library with alt text

= 1.1.0 =
* Added support for CSS, Divi Code Modules, Theme Builder JSON
* Added Divi-specific AI prompts (blog styling, headers, footers)
* Auto-detects Divi installation and version
* Multiple code type detection (PHP, CSS, HTML/JS)
* Destination guidance for Customizer, Code Module, child theme
* Copy Code button for non-shortcode types
* Updated readme with Divi integration details

= 1.0.0 =
* Initial release
* Shortcode browser with nb-* filtering
* Export with AI prompt templates
* Import and code extraction
* Shortcode installation to database
* Exchange history tracking
* Code viewer modal

== Upgrade Notice ==

= 1.3.0 =
Huge update: Use AI directly in the post editor! Floating panel for sending text, generating image prompts, and importing responses - all without leaving your page.
