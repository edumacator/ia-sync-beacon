# IA Sync Beacon

> **A lightweight WordPress plugin that copies posts from an external “hub” site into your local site — and automatically localises every remote image, PDF, DOCX, PowerPoint, ZIP, or other file referenced inside those posts.**

---

\## Why you might need this

* **Keep posts in sync** between a master knowledge‑base site and multiple satellite sites.
* **No more broken images** when the hub site reorganises its Media Library.
* **Local file downloads** → faster, cache‑friendly, and comply with privacy policies.
* **Featured image automation**: the first imported `<img>` is set as the post thumbnail (optional).

---

\## Folder layout

```
wp-content/plugins/
└─ ia-sync-beacon/
   ├─ ia-sync-beacon.php      ← Main loader (only file with a plugin header)
   ├─ inc/
   │  └─ media-import.php     ← All sideload + DOM‑rewrite helpers
   └─ README.md               ← This file
```

> **Heads‑up:** If you cloned the repo directly into `wp-content/plugins/` you can rename the folder. What matters is that the *loader* keeps its header block.

---

\## Installation

1. Copy the **ia-sync-beacon** folder into `wp-content/plugins/`.

2. Edit `wp-config.php` **or** the plugin loader to set your hub domain:

   ```php
   // wp-config.php (preferred for multi‑env setups)
   define( 'IA_BEACON_HUB_HOST', 'foundation.fcsia.com' );
   ```

3. Activate **IA Sync Beacon** from **Plugins → Installed Plugins**.

4. Trigger your existing sync routine (WP‑CLI, cron, or manual button).

That’s it — every post save now pipes its content through the media‑import helper.

---

\## Configuration options

| Constant / Filter                         | Default                              | Purpose                                                                           |
| ----------------------------------------- | ------------------------------------ | --------------------------------------------------------------------------------- |
| `IA_BEACON_HUB_HOST`                      | `foundation.fcsia.com`               | Fully‑qualified domain of the hub site; protocol is stripped/normalised to HTTPS. |
| `ia_beacon_allowed_file_types` *(filter)* | `pdf doc docx ppt pptx xls xlsx zip` | Add or remove file extensions you want to import when linked with `<a>` tags.     |
| `ia_beacon_set_featured` *(filter)*       | `true`                               | Return `false` to skip the “first image becomes featured” behaviour.              |

Example filter snippet (drop in `functions.php` or a must‑use plugin):

```php
add_filter( 'ia_beacon_allowed_file_types', function( $types ) {
    $types[] = 'csv';
    return $types;
} );
```

---

\## How it works under the hood

1. **`save_post` hook** fires after *any* post or page is inserted/updated.
2. The helper calculates a **content hash**. If nothing changed, it bails.
3. Using **DOMDocument**, it scans for:

   * `<img src="https://foundation.fcsia.com/...">`
   * `<a href="https://foundation.fcsia.com/file.pdf">` (matches allowed extensions)
4. For each remote URL it:

   1. Forces HTTPS and calls `attachment_url_to_postid()` in case you already imported it.
   2. If not found, downloads the file via `download_url()` and stores it with `media_handle_sideload()`.
   3. Replaces the original URL in the HTML with the *local* attachment URL.
5. Saves the rewritten HTML back to `post_content` and, once per post, sets the first imported image as the featured image.

---

\## Developer notes

* **Namespace‑free** helpers for drop‑in simplicity. Wrap them in a class/namespace if you need strict isolation.
* Tested on **WordPress 6.5+** and **PHP 8.1+**.
* Adheres to WP‑coding‑standards (`composer require wp-coding-standards/wpcs`).
* No external dependencies — relies solely on core WP APIs.

\### Running the linter

```bash
composer install
composer run phpcs
```

---

\## Changelog

\### 1.2.0 • 2025‑06‑23

* Initial public release
* Feature: Media import & URL rewrite
* Feature: Featured‑image auto‑set

---

\## License

GPL v2 or later — do whatever you like, but keep the license intact.

---

\### Need help?

Open an issue or ping **@InnovationAcademy** on GitHub.
