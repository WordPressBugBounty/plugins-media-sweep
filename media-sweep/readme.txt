=== Media Sweep – WordPress Media Cleaner ===
Contributors: wpcreatix
Donate link: https://wpcreatix.com/
Tags: images, files, cleanup, media, library
Stable tag: 1.1.3
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 7.4
License: GPL-2.0-or-later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Clean up your WordPress Media Library by finding and removing unused files. Safely scan, preview, and sweep away orphaned media to keep your site fast and organized.

== Description ==

**Media Sweep: The Ultimate WordPress Media Library Cleaner**

Media Sweep is a powerful WordPress plugin designed to help you reclaim valuable server space by identifying and safely removing unused media files from your WordPress site. Whether you're dealing with orphaned attachments, forgotten uploads, or files left behind by themes and plugins, Media Sweep provides a comprehensive solution to keep your media library clean and organized.

Perfect for bloggers, agencies, e-commerce sites, and any WordPress website looking to optimize storage space and improve site performance.

🚀 **Key Benefits:**

✅ **Reclaim Server Space** – Remove unused files and free up valuable storage
✅ **Improve Site Performance** – Faster backups and reduced server load
✅ **Safe & Reversible** – Files moved to trash folder, not permanently deleted
✅ **Smart Detection** – Advanced algorithms identify truly unused files
✅ **Batch Operations** – Process multiple files efficiently
✅ **Detailed Analytics** – Comprehensive scan reports and file insights

## 🎯 Core Features

### 🔍 Dual Scan Modes

**Media Library Scan**
* Scans all media attachments in your WordPress library
* Checks for usage in posts, pages, and custom fields
* Detects thumbnail and size variations
* Identifies gallery shortcodes and blocks
* Fast and efficient for large media libraries
* *Recommended for most WordPress sites*

**File System Scan**
* Deep scan of your uploads directory
* Finds orphaned files not in the media library
* Detects cache and temporary files
* Identifies theme and plugin generated files
* Comprehensive but may take longer to complete
* *Best for sites with file management plugins*

### 📊 Smart File Detection

* **Usage Analysis** – Checks posts, pages, custom fields, and widgets
* **Database Scanning** – Searches content for file references
* **Shortcode Detection** – Identifies gallery and media shortcodes
* **Block Analysis** – Scans Gutenberg blocks for media usage
* **Plugin Integration** – Detects files used by popular plugins
* **Theme Assets** – Identifies theme-related media files

### 🗂️ Advanced File Management

* **Status Classification** – Files categorized as In Use, Unused, or Orphaned
* **Bulk Actions** – Select and process multiple files at once
* **Smart Filtering** – Filter by file type, status, date, and directory
* **Search Functionality** – Quickly find specific files
* **Preview Mode** – View files before taking action
* **Detailed File Info** – Size, type, usage locations, and more

### 🛡️ Safety Features

* **Trash System** – Files moved to secure trash folder, not deleted
* **Restore Capability** – Easily restore accidentally removed files
* **Backup Integration** – Works with popular backup plugins
* **Progress Monitoring** – Real-time scan progress with pause/resume
* **System Resources** – Intelligent resource management prevents timeouts
* **Rollback Protection** – Safe operations with detailed logging

### 📈 Comprehensive Analytics

* **Scan History** – Track all previous scans and results
* **Storage Statistics** – See exactly how much space you've reclaimed
* **File Breakdown** – Detailed analysis by file type and status
* **Usage Patterns** – Understand your media library usage
* **Performance Metrics** – Scan duration and processing statistics

## 🚀 Easy 3-Step Process

**Step 1: Choose Scan Type**
* Select Media Library or File System scan based on your needs
* Configure advanced options for thorough analysis

**Step 2: Run the Scan**
* Watch real-time progress as Media Sweep analyzes your files
* Pause and resume scans as needed without losing progress

**Step 3: Review & Clean**
* Browse detected files with detailed information
* Use bulk actions to efficiently process multiple files
* Restore files from trash if needed

## 🔧 Advanced Configuration

* **Scan Options** – Deep scan, thumbnail inclusion, custom field checking
* **File Type Filters** – Focus on specific file types (images, documents, etc.)
* **Directory Exclusions** – Skip specific folders during scans
* **Performance Settings** – Adjust batch sizes and resource limits
* **Automation Rules** – Set up automatic cleanup schedules (planned for future versions)

## 💡 Perfect For

* **Content Creators** – Bloggers and publishers with large media libraries
* **Web Agencies** – Managing multiple client sites efficiently
* **E-commerce Sites** – Cleaning up product images and assets
* **Membership Sites** – Optimizing user-generated content
* **Portfolio Sites** – Managing large image collections
* **News Sites** – Handling high-volume media uploads

== Installation ==

**Automatic Installation (Recommended)**

1. Go to Plugins > Add New in your WordPress admin
2. Search for "Media Sweep"
3. Click "Install Now" and then "Activate"
4. Navigate to Media Sweep in your admin menu to get started

**Manual Installation**

1. Download the plugin ZIP file
2. Upload to `/wp-content/plugins/media-sweep/`
3. Activate through the Plugins menu in WordPress
4. Access Media Sweep from your admin dashboard

**Getting Started**

1. Visit Media Sweep > Dashboard
2. Click "Start New Scan"
3. Choose your scan type and options
4. Let Media Sweep analyze your files
5. Review results and clean up unused files

== Frequently Asked Questions ==

**Is it safe to delete files found by Media Sweep?**
Yes! Media Sweep uses advanced detection algorithms and moves files to a secure trash folder rather than permanently deleting them. You can always restore files if needed.

**What's the difference between Media Library and File System scans?**
Media Library scans focus on WordPress attachments and are faster, while File System scans check all files in your uploads directory for a more comprehensive cleanup.

**Can I restore deleted files?**
Absolutely! Files are moved to the Media Sweep trash folder and can be easily restored through the Trash section of the plugin.

**Will this work with page builders and plugins?**
Yes! Media Sweep includes detection for popular page builders, gallery plugins, and other media-related extensions.

**How much server space can I expect to reclaim?**
This varies greatly depending on your site, but users typically reclaim 10-50% of their media storage space, sometimes even more on older sites.

**Will this slow down my website?**
No, Media Sweep only runs when you initiate a scan and includes intelligent resource management to prevent server overload.

**Can I schedule automatic cleanups?**
Currently, Media Sweep requires manual scan initiation. Automatic scheduling may be added in future versions.

== Screenshots ==

1. **Dashboard Overview** – Clean, intuitive interface showing scan history and quick actions
2. **Scan Type Selection** – Choose between Media Library and File System scans with detailed explanations
3. **Real-time Scan Progress** – Watch your scan progress with detailed status updates and resource monitoring
4. **Scan Results** – Comprehensive file listing with status, size, and usage information
5. **File Details** – Detailed view showing where and how files are used across your site
6. **Trash Management** – Safe file removal with easy restore capabilities

== Changelog ==

= 1.1.3 =
* Fixed: the search box on the scan results screen always came back empty.

= 1.1.2 =
* New: you can now delete a file even when it is reported as in use. The confirmation shows where it was found so you can judge for yourself, and the file goes to Trash like any other, so you can restore it if you change your mind.
* Fixed: files were wrongly reported as in use because other plugins keep their own record of every file on your site. Image optimizers, security scanners, backup tools and caching plugins all do this. Those records are still shown in the file's notes, but they no longer count as usage on their own.
* Fixed: an image could report itself as in use, because some optimizer, CDN and rename plugins store the image's own file name back onto it.
* Fixed: a successful bulk clean-up used to report large numbers of "errors". Thumbnails are removed together with the media file they belong to, and each one was then counted as a failure. They are now reported as already handled, and the summary explains what happened.
* Fixed: the summary after a bulk action closed instantly, and clearing a whole page of results showed "no files found" instead of the remaining files. The summary now stays open with a link to the Trash, and the list moves back to the last page that still has results.
* New: the Trash screen now shows a thumbnail of each image instead of a generic file icon, so you can see what you are about to restore or delete.
* Fixed: the Trash folder's access protection is now re-applied to sites that already had a trash folder, and is written in a form that Apache 2.4 and IIS also understand.
* Safer cleanup: a file that has changed since the scan ran is now skipped instead of removed, with a message telling you to run a new scan first. Deleting from an old scan can no longer remove a file that has been replaced or put back into use.
* New: scan results older than a week now carry a clear warning that they may be out of date, so nothing gets deleted based on a stale list.
* Fixed: starting a new scan could land you on an old interrupted scan with no way past it. New scans always start fresh, and an interrupted scan is only offered when it is genuinely still running (or from the Resume button on the scans list).
* Fixed: restoring or permanently deleting a file from the Trash could fail on some servers, and the Trash screen showed a full server path instead of the folder name.
* Fixed: permanently deleting a file from the Trash now clears it from your file records instead of leaving it listed as recoverable.
* Fixed: a failed restore showed a raw technical message; it now explains the problem in plain language.

= 1.1.1 =
* Fixed: on sites where several registered image sizes share the same dimensions (common with WooCommerce, Getwid, Robo Gallery and similar plugins), the scan tried to record the same file twice and the resulting database notices broke the scan with "Your server interrupted the scan". Each file is now recorded once, and writing a file result is fully repeat-safe.
* Fixed: resuming a scan could re-record results it had already saved. Progress is now saved continuously while a scan runs, so a resumed scan picks up exactly where it stopped.
* Fixed: Media Sweep no longer lets database notices or other plugins' debug output reach its own responses, and the scanner can now recover a scan even if something else on the site prints output mid-request. Sites running with WP_DEBUG_DISPLAY enabled are no longer affected.
* Improved: scan requests shrink their workload further on hosts with very short request limits.

= 1.1.0 =
* New scan engine: your content is now read ONCE into an indexed reference index, then every attachment is checked with instant indexed lookups — scans that previously took hours (or died with "The response is not a valid JSON response.") now finish in minutes on any hosting.
* Fixed: scans no longer get stuck at 40% or fail with "The response is not a valid JSON response." on shared hosting — every scan request now completes well inside even the strictest server timeouts (works on 30-second hosts, no configuration needed).
* New: scans are checkpointed and resumable — closing the tab, a server hiccup, or even a PHP fatal now pauses the scan with a Resume button instead of losing progress.
* New: smarter detection — media used in Elementor and other page-builder layouts, widgets, site icon/logo settings, and WooCommerce category images is now correctly reported as in use (with the location named in the file's usage notes).
* New: WP-CLI support — run `wp media-sweep scan` for unattended scans of very large libraries.
* Improved: the scanner automatically adapts to slow servers (self-tuning delays, automatic retries with backoff) and never shows raw technical errors — and the detailed per-file usage reports you rely on are unchanged, still free, and now even more complete.
* Fixed: edited images are no longer marked "in use" by their own backup metadata.
* Under the hood: new mswp_scan_refs table and scan checkpoint columns, migrated automatically on update.

= 1.0.7 =
* New: a "More from WPCreatix" section that helps you discover the other WPCreatix plugins, with one-click install and activate right from the admin.
* Fixed: the "leave a review" banner could reappear after being dismissed — dismissing it is now remembered for good.

= 1.0.6 =
* Compatibility with WordPress 7.0
* New: optional "Delete All Data on Uninstall" setting (Settings → Data Management). When enabled, deleting the plugin now cleanly removes its database tables, options, scheduled tasks, and the Media Sweep trash folder. Disabled by default, so your scan history and trash are preserved unless you opt in — including on multisite, where each site's choice is respected.

= 1.0.5 =
* Fixed scan aborting at 40-53% with "WordPress database error: Processing the value for the following field failed: notes" on sites where one image is referenced in many posts/pages
* Notes column upgraded from TEXT to LONGTEXT and applied to existing installs automatically on upgrade
* Each file's recorded usage is now capped at 10 entries with a "+ N more usage locations" summary so the database stays lean
* A single problematic row no longer aborts the entire scan — it is now logged as an error and the rest of the batch continues
* Results table now shows the first usage location inline next to the filename so it is obvious where each file is used without clicking
* Fixed the usage-notes popover overflowing the viewport when a file has many usages — it now scrolls inside a fixed-height container

= 1.0.4 =
* Dynamic batch size based on server resources (max_execution_time and memory_limit)
* Smaller batches (20-30) for limited shared hosting, larger batches (50-100) for well-resourced servers
* Dynamic API request timeout scaled to server's max_execution_time (30s-300s)
* Improved user experience with smoother progress feedback during scans

= 1.0.3 =
* Fixed scan getting stuck at "0 / X files" on hosting with limited server resources
* Improved pause/resume mechanism to correctly maintain file count progress across interruptions
* Added API request timeout handling with automatic retry for slow servers
* Enhanced error display when scan encounters issues instead of silently failing
* Better resource management for servers with low max_execution_time or memory_limit
* Reduced batch size from 100 to 50 files for smoother progress updates and better user feedback

= 1.0.2 =
* Compatibility with WordPress 6.9
* Improved review notice banner with better user experience

= 1.0.1 =
* Fixed file status not being reactivated when previously trashed/deleted files are found again during new scans
* Improved file record management to automatically restore files that were moved back to original locations

= 1.0.0 - Initial Release =

**🎉 Launch Features:**

* **Dual Scan Modes** – Media Library and File System scanning capabilities
* **Smart Detection Engine** – Advanced algorithms for accurate unused file identification
* **Safe Trash System** – Secure file removal with restore capabilities
* **Comprehensive Analytics** – Detailed scan reports and file insights
* **Bulk Operations** – Efficient processing of multiple files
* **Real-time Progress** – Live scan monitoring with pause/resume functionality
* **Advanced Filtering** – Filter by status, file type, date, and directory
* **Search Functionality** – Quick file location and identification
* **Resource Management** – Intelligent system monitoring prevents timeouts
* **Modern UI/UX** – Clean, responsive interface optimized for all devices
* **Translation Ready** – Full internationalization support
* **Developer Friendly** – Extensive hooks and filters for customization

**🔧 Technical Highlights:**

* Built with modern React components and WordPress REST API
* Optimized database queries for large media libraries
* Memory-efficient processing with batch operations
* Compatible with popular caching and optimization plugins
* Follows WordPress coding standards and security best practices
* Extensive error handling and logging capabilities

**🛡️ Security & Performance:**

* Secure file operations with proper capability checks
* Nonce verification for all AJAX requests
* Sanitized input/output throughout the application
* Optimized for sites with thousands of media files
* Minimal server resource usage during scans

== Upgrade Notice ==

= 1.1.3 =
Fixes the search box on the scan results screen, which returned no results whatever you typed. Recommended for everyone.

= 1.0.6 =
Tested with WordPress 7.0 and adds an optional "Delete All Data on Uninstall" setting for a clean removal. Safe upgrade — data deletion is off by default.

= 1.0.0 =
Welcome to Media Sweep! This initial release provides everything you need to clean up your WordPress media library safely and efficiently. Start reclaiming server space today with our powerful dual-scan system and intelligent file detection.

== Support ==

Need help with Media Sweep? We're here to assist!

* **Support Forum:** [WordPress.org support forum](https://wordpress.org/support/plugin/media-sweep/)
* **Feature Requests:** [Submit ideas and suggestions](https://wpcreatix.com/contact/)
* **Bug Reports:** [Report issues for quick resolution](https://wpcreatix.com/contact/)

== Privacy ==

Media Sweep respects your privacy and does not collect or transmit any personal data. All file analysis and cleanup operations are performed locally on your server. No external services are contacted during plugin operation.

== Credits ==

Media Sweep is crafted with ❤️ by the [WPCreatix](https://wpcreatix.com/) team. We're passionate about creating tools that help WordPress users optimize and maintain their websites efficiently.

Special thanks to the WordPress community for their continuous feedback and support in making this plugin better.
