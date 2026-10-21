=== Smart Content SEO ===
Contributors: yourwporgusername
Tags: ai, content, seo, design, ai writer
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Let AI write SEO posts with photos and turn any post into a visually designed article with its own layout, inside WordPress.

== Description ==

Smart Content SEO turns ordinary blog posts into visually designed articles: timelines for steps, comparison tables, card grids, sliders, galleries, stat blocks, FAQ accordions and callouts, chosen by AI for each post. Every article gets its own hand-crafted HTML and CSS that follows your site's colours and fonts.

**Write with AI**

* Enter a title, a short description, SEO keywords and a target length (about 1,000 to 2,500 words).
* The AI plans an outline first and gives every section its own word budget: a short introduction, the most words for the sections that answer the search intent, an FAQ and a conclusion. Review and edit the outline (headings, word counts, key points, photos) before writing.
* The post is written straight into the WordPress editor, with as many relevant photos as possible: photos already in your Media Library are preferred when the AI sees they fit a section; free stock photos (Pexels or Openverse) fill the rest.
* Replace any photo in one click with another free photo or an image from your Media Library.

**Convert to Smart Content**

* Click "Convert to Smart Content" in the post editor. The AI redesigns the post; your original post is kept, and "Revert to original post" brings it back at any time.
* Preview on desktop, tablet and mobile widths inside your own theme.
* Select any part of the article, leave a comment ("make this a slider", "add a comparison table here") and ask the AI to apply all comments in one go. Every revision is saved as a version you can restore.
* Change the post's primary and accent colours instantly, without another AI call.
* Publish or schedule like any other post. For posts already live, the new design only goes live when you click "Update post".
* Smart Content posts stay in the normal Posts list, with a badge, a column and filters.
* Each article's CSS is scoped to that post and every class is prefixed, so it never clashes with your theme.

**Bring your own AI**

Choose Claude (Anthropic), ChatGPT (OpenAI) or Google Gemini in the settings and use your own API key. Google Gemini's Flash models offer a free tier.

== Installation ==

1. Upload the plugin and activate it.
2. Go to **Smart Content** in the admin menu. Choose an AI provider, paste your API key, click "Detect from site" to pick up your theme's colours and fonts, then save.
3. Optional: add a free Pexels API key for better stock photos.
4. Open any post: use **AI Writer** to write a new post, or **Convert to Smart Content** to redesign an existing one.

API keys can also be defined in `wp-config.php` instead of the database:

`define( 'SMC_API_KEY', '...' );     // Claude`
`define( 'SMC_OPENAI_KEY', '...' );  // ChatGPT`
`define( 'SMC_GEMINI_KEY', '...' );  // Gemini`
`define( 'SMC_PEXELS_KEY', '...' );  // Pexels (optional)`

== Frequently Asked Questions ==

= Do I need an API key? =

Yes. The plugin uses your own key for Claude, ChatGPT or Gemini; any AI cost is billed to that account. Gemini's Flash models have a free tier.

= Can I go back to my original post? =

Yes. The post as written in the WordPress editor is kept. "Revert to original post" restores it and removes the AI design and its versions.

= Will converting change my live post right away? =

No. For published posts, the new design is a draft until you review it and click "Update post" in the Smart Content editor. Drafts follow the design immediately.

= Does it work with the classic editor? =

Yes, with both the block editor and the classic editor.

= Can I try it without an API key? =

For development, add `define( 'SMC_MOCK_AI', true );` to `wp-config.php`: the plugin returns fixed sample content instead of calling an AI.

== External services ==

This plugin connects to third-party services only when you click a button that needs them (create outline, write post, convert, redesign, ask AI to revise, search or replace photos).

**AI provider (the one you choose in the settings)** — receives the post title and content, your instructions and comments, and small thumbnails of the related images. When creating an outline with "Prefer Media Library images" enabled, thumbnails of up to 30 Media Library images that look related to the post are also sent so the AI can choose among them.

* Anthropic (Claude): https://api.anthropic.com — [Terms](https://www.anthropic.com/legal/commercial-terms), [Privacy](https://www.anthropic.com/legal/privacy)
* OpenAI (ChatGPT): https://api.openai.com — [Terms](https://openai.com/policies/services-agreement), [Privacy](https://openai.com/policies/privacy-policy)
* Google (Gemini): https://generativelanguage.googleapis.com — [Terms](https://ai.google.dev/gemini-api/terms), [Privacy](https://policies.google.com/privacy). On Gemini's free tier, Google may use submitted data to improve its products.

**Stock photos** — when the AI writes a post or you search for a replacement photo, only the search keywords are sent. Photos you pick are downloaded into your Media Library; the author and source are stored in the image description.

* Pexels (used when a Pexels API key is set): https://api.pexels.com — [Terms](https://www.pexels.com/terms-of-service/), [Privacy](https://www.pexels.com/privacy-policy/)
* Openverse (CC0 and public-domain images only): https://api.openverse.org — [Terms](https://docs.openverse.org/terms_of_service.html), [Privacy](https://wordpress.org/about/privacy/)

== Changelog ==

= 0.3.0 =
* AI Writer: outline with per-section word budgets, writing with Media Library and free stock photos, one-click photo replacement.
* Choice of AI provider: Claude, ChatGPT or Google Gemini.
* Smart Content posts listed in the normal Posts list with a badge, column and filters.
* Convert and revert from the post editor.
* Translation-ready; Vietnamese translation included.
