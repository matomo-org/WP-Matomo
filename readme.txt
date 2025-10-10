=== Connect Matomo (WP-Matomo, WP-Piwik) ===

Contributors: Braekling
Requires at least: 5.0
Tested up to: 6.8
Stable tag: 1.0.30
Donate link: https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=6046779
Tags: matomo, tracking, statistics, stats, analytics

Adds Matomo (former Piwik) statistics to your WordPress dashboard and is also able to add the Matomo Tracking Code to your blog.

== Description ==

If you are not yet using Matomo On-Premise, Matomo Cloud or hosting your own instance of Matomo, please use the [Matomo for WordPress plugin](https://wordpress.org/plugins/matomo/). 

This plugin uses the Matomo API to show your Matomo statistics in your WordPress dashboard. It's also able to add the Matomo tracking code to your blog and to do some modifications to the tracking code. Additionally, WP-Matomo supports WordPress networks and manages multiple sites and their tracking codes.

To use this plugin the Matomo web analytics application is required. If you do not already have a Matomo setup (e.g., provided by your web hosting service), you have two simple options: use either a [self-hosted Matomo](http://matomo.org/) or a [cloud-hosted Matomo by InnoCraft](https://www.innocraft.cloud/?pk_campaign=WP-Piwik).

**Requirements:** PHP 7.0 (or higher), WordPress 5.0 (or higher), Matomo 4.0 (or higher)
 
**Languages:** English, Albanian, Chinese, Dutch, French, German, Greek, Hungarian, Italian, Polish, Portuguese (Brazil). Partially supported: Azerbaijani, Belarusian, Hindi, Lithuanian, Luxembourgish, Norwegian, Persian, Romanian, Russian, Spanish, Swedish, Turkish, Ukrainian

= What is Matomo? =

[youtube https://youtu.be/Qc2kooLNDiU]
[Learn more.](https://matomo.org/what-is-matomo/)

= First steps =
- Learn how to install your own Matomo instance: [Requirements](https://matomo.org/docs/requirements/), [Installation](https://matomo.org/docs/installation-optimization/).
- If you need support about Matomo, please have a look at the [Matomo forums](https://forum.matomo.org/).
- Finally, you can start [setting up WP-Matomo](https://matomo.org/blog/2015/05/wordpress-integration-wp-piwik-1-0/).

= Shortcodes =
You can use following shortcodes if activated:

    [wp-piwik module="overview" title="" period="day" date="yesterday"]
Shows overview table like WP-Matomo's overview dashboard. See Matomo API documentation on VisitsSummary.get to get more information on period and day. Multiple data arrays will be cumulated. If you fill the title attribute, its content will be shown in the table's title.

    [wp-piwik module="opt-out" language="en" width="100%" height="200px"]
Shows the Matomo opt-out Iframe. You can change the Iframe's language by the language attribute (e.g. de for German language) and its width and height using the corresponding attributes.

    [wp-piwik module="post" range="last30" key="sum_daily_nb_uniq_visitors"]
Shows the chosen keys value related to the current post. You can define a range (format: lastN, previousN or YYYY-MM-DD,YYYY-MM-DD) and the desired value's key (e.g., sum_daily_nb_uniq_visitors, nb_visits or nb_hits - for details see Matomo's API method Actions.getPageUrl using a range).

    [wp-piwik]
is equal to *[wp-piwik module="overview" title="" period="day" date="yesterday"]*.

= Credits and Acknowledgements =

* Graphs powered by [Chart.js](https://www.chartjs.org) (MIT License).
* All translators at Transifex and WordPress.
* Anyone who donates to the WP-Matomo project, including the Matomo team!
* All users who send me mails containing criticism, commendation, feature requests and bug reports - you help me to make WP-Matomo much better!

Thank you all!

== Frequently Asked Questions ==

= Where can I find the Matomo URL and the Matomo auth token? =

To use this plugin you will need your own Matomo instance. If you do not already have a Matomo setup, you have two simple options: use either a [self-hosted Matomo](http://matomo.org/) or [cloud-hosted Matomo by InnoCraft](https://www.innocraft.cloud/?pk_campaign=WP-Piwik).

As soon as Matomo works, you'll be able to configure WP-Matomo: The Matomo URL is the same URL you use to access your Matomo, e.g. for the demo site: http://demo.matomo.org. The auth token is some kind of a secret password, which allows WP-Matomo to get the necessary data from Matomo. To get your auth token, log in to Matomo, click at the preferences gear icon (top right) and click at "API" (left sidebar menu, near the bottom).

You can get a more detailed description here: https://matomo.org/blog/2015/05/wordpress-integration-wp-piwik-1-0/

= I get this message: "WP-Matomo (WP-Piwik) was not able to connect to Matomo (Piwik) using our configuration". How to proceed? =

First, please make sure your configuration is valid, e.g., if you are using the right Matomo URL (see description above). Then, go to the "Support" tab and run the test script. This test script will try to get some information from Matomo and shows the full response. Usually, the response output gives a clear hint what's wrong:

The response output contains...
- **bool(false)** and **HTTP/1.1 403 Forbidden**: WP-Matomo is not allowed to connect to Matomo. Please check your Matomo server's configuration. Maybe you are using a password protection via .htaccess or you are blocking requests from localhost/127.0.0.1. If you aren’t sure about this, please contact your web hoster for support.
- **bool(false)** and **HTTP/1.1 404 Not Found**: The Matomo URL is wrong. Try to copy & paste the URL you use to access Matomo itself via browser.
- **bool(false)** and no further HTTP response code: The Matomo server does not respond. Very often, this is caused by firewall or mod_security settings. Check your server logfiles to get further information. If you aren’t sure about this, please contact your web hoster for support.

If this does not help as well, feel free to open a [topic in the support forum](https://wordpress.org/support/plugin/wp-piwik/). Please share all available information including the test script result, if possible.

= PHP Compatibility Checker reports PHP7 compatbility issues with WP-Matomo. =

The Compatibility Checker shows two false positives. WP-Matomo is 100% PHP7 compatible, you can ignore the report.

= Overview shortcode shows no unique visitors using a yearly range. =

See [Matomo FAQ](http://piwik.org/faq/how-to/#faq_113).

= WP-Matomo only shows the first 100 sites of my multisite network. How can I get all other sites? =

The Matomo API is limited to 100 sites by default. Add the following line to the section [General] of Matomo's config/config.ini.php file:

    API_datatable_default_limit = 1000

= Tracking does not work on HostGator! =

Try to enable the "avoid mod_security" option (WP-Matomo settings, Tracking tab) or create a mod_security whitelist.

= Can I contribute to WP-Matomo as a translator? =

You like to contribute to WP-Matomo translations? Please use the [Transifex translation community](https://www.transifex.com/projects/p/wp-piwik/).

Of course, I will add missing languages if requested, and I will also upload the existing language files of older WP-Matomo releases.

If you can't (or don not want to) use transifex, you can also translate languages/wp-piwik.pot delivered with WP-Matomo.

Thank you very much! :-)

== Installation ==

= General Notes =
* First, you have to set up a running Matomo instance. You can get Matomo [here](http://matomo.org/) and its documentation [here](http://matomo.org/docs/).
* If you want to update your Matomo instance, you should set your WordPress blog to maintenance while the update process is running.

= Install WP-Matomo on a simple WordPress blog =

1. Upload the full `wp-piwik` directory into your `wp-content/plugins` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Open the new 'Settings/WP-Matomo (WP-Piwik) Settings' menu and follow the instructions to configure your Matomo connection. Save settings.
4. If you have view access to multiple site stats and did not enable "auto config", choose your blog and save settings again.
5. Look at 'Dashboard/WP-Matomo (WP-Piwik)' to see your site stats.

= Install WP-Matomo on a WordPress blog network (WPMU/WP multisite) =

There are two differents methods to use WP-Matomo in a multisite environment:

* As a Site Specific Plugin it behaves like a plugin installed on a simple WordPress blog. Each user can enable, configure and use WP-Matomo on his own. Users can even use their own Matomo instances (and accordingly they have to). 
* Using WP-Matomo as a Network Plugin equates to a central approach. A single Matomo instance is used and the site admin configures the plugin completely. Users are just allowed to see their own statistics, site admins can see each blog's stats.

*Site Specific Plugin*

Just add WP-Matomo to your /wp-content/plugins folder and enable the Plugins page for individual site administrators. Each user has to enable and configure WP-Matomo on his own if he want to use the plugin.

*Network Plugin*

The Network Plugin support is still experimental. Please test it on your own (e.g. using a local copy of your WP multisite) before you use it in an user context.

Add WP-Matomo to your /wp-content/plugins folder and enable it as [Network Plugin](http://codex.wordpress.org/Create_A_Network#WordPress_Plugins). Users can access their own statistics, site admins can access each blog's statistics and the plugin's configuration.

== Screenshots ==

1. WP-Matomo settings.
2. WP-Matomo statistics page.
3. Closer look to a pie chart.
4. WordPress toolbar graph.
5. Matomo: Here you'll find your auth token.

== Changelog ==

= 1.0.30 =
* Fix settings behavior
* Fix auto configuration in PHP API mode
* Fix Opt-Out shortcode language attribute
* Remove deprecated dynamic property (PHP 8.2)

= 1.0.29 =
* Rename plugin to "Connect Matomo"
* Fix a XSS vulnerability

= 1.0.28 =
* Encode plugin display name
* Option to set requireConsent or requireCookieConsent
* Avoid notice on empty ecommerce data
* Improve search tracking performance (thanks to theodejager, [details](https://github.com/braekling/WP-Matomo/pull/111))
* Fix multisite sub-site tracking (thanks to ulkoalex, [details](https://github.com/braekling/WP-Matomo/issues/107))
* Remove excess form closing tag (thanks to ulkoalex, [details](https://github.com/braekling/WP-Matomo/pull/113))

= 1.0.27 =
* Fix a CSRF vulnerability
* Fix JavaScript typos on settings page which broke some interface functionality
* Fix proxy path on multisite networks (thanks to caveman99, [details](https://github.com/braekling/WP-Matomo/pull/98))
* Fix array key warnings (thanks to goaround, [details](https://github.com/braekling/WP-Matomo/pull/102))
* Fixed a bug in proxy config.php to avoid adding the protocol twice to the Matomo URL 
* Proxy script will run proxy/config.local.php before proxy/config.php to set an individual WordPress root directory via $wpRootDir

= 1.0.26 =
* Fix feed tracking via proxy script (thanks to nicobilliotte, [details](https://github.com/braekling/WP-Matomo/pull/92))
* Add piwik.php proxy script wrapper to make sure proxy tracking codes continue working if piwik.php is used
* Fix for stalled blog options on network activation (thanks to ulkoalex, [details](https://github.com/braekling/WP-Matomo/pull/94))
* Fix wrong title on city statistics (thanks to ulkoalex, [details](https://github.com/braekling/WP-Matomo/pull/95))
* [Fix "call to undefined method" error on network admin](https://wordpress.org/support/topic/fatal-error-call-to-undefined-method-wp_piwikadminnetworkgetpluginurl/)
* Do not allow to set empty WP-Matomo display name
* Rename Piwik link on dashboard widget to Matomo

= 1.0.25 =
* Replace jqplot and jquery.sparklines with [Chart.js](https://www.chartjs.org)
* Allow to show overview stats for last 60 and 90 days
* Allow to select the per post stats range from today to last 90 days
* Optionally remove Matomo's script tag's type attribute, see https://wordpress.org/support/topic/how-to-remove-unnecessary-type-attribute-for-javascript/.
* Fix/update proxy script (thanks to nicobilliotte and Rasp8e, https://github.com/braekling/WP-Matomo/pull/91)
* Make plugin working if deployed in a custom folder (thanks to utolosa002, https://github.com/braekling/WP-Matomo/pull/88)

= 1.0.24 =
* Hotfix to avoid deprecated jQuery.support.boxModel in jqPlot (https://github.com/jqPlot/jqPlot/issues/123)
* Enabling metaboxes on particular Custom Post Types (thanks to goaround, https://github.com/braekling/WP-Matomo/pull/83)

= 1.0.23 =
* Handle tracking codes containing matomo.js/.php instead of piwik.js/.php
* Fixed target="_BLANK" property (thanks to tsteur)

= 1.0.22 =
* Bugfix: Innocraft cloud URL *.matomo.cloud will work
* Option to configure filter_limit parameter (see expert settings)
* Replaced piwik.php proxy script by matomo.php proxy script

= 1.0.21 =
* Bugfix: Get HTTP mode working again

= 1.0.20 =
* Support for new Innocraft cloud URL (*.matomo.cloud)
* Changed naming from Piwik to Matomo
* Added City, Type and Model views
* Bugfix: Avoid warnings on empty results

= 1.0.19 =
* Security fix: Escape request var
* Language updates

= 1.0.18 =
* WPML.org support: Use different site IDs for different languages
* Ecommerce widgets

= 1.0.17 =
* Header issue solved which caused incompatibilities with other plugins like Yoast SEO
* Update of InnoCraft cloud links (InnoCraft is the team behind Matomo)
* Bugfix: Avoid a broken page if Matomo is misconfigured and WordPress debugging enabled

= 1.0.16 =
* Added InnoCraft Cloud support (the new service created by the people behind Matomo). Piwik.pro is still usable via HTTP mode, the configuration will be updated automatically.
* Added search functionality to site browser
* Added preload DNS option, see https://matomo.org/blog/2017/04/important-performance-optimizations-load-piwik-javascript-tracker-faster/
* Added option to set link and download classes (expert settings)
* Added option to choose which post types should be considered for annotations
* Bugfix: Opening Matomo stats of a specific network site does not lead to the sitebrowser anymore
* Bugfix: Avoid unnecessary notices
* Bugfix: Avoid a warning in proxy script
* Bugfix: NoScript code is working again
* Replaced deprecated wp_get_sites

= 1.0.15 =
* Allow to modify the tracked user ID using the filter "wp-piwik_tracking_user_id"
* Bugfix: Output of "post" shortcode was incorrectly placed, see https://wordpress.org/support/topic/post-shortcode-values-are-incorrectly-placed/
* Bugfix: Usage of WP_PROXY_BYPASS_HOSTS, see https://wordpress.org/support/topic/bug-considering-wp_proxy_bypass_hosts-in-proxy-setups/
* Bugfix: Proxy script did not work with cURL, see https://github.com/braekling/WP-Matomo/issues/48
* Bugfix: RSS feed tracking did not use proxy URL

= 1.0.14 =
* Action "wp-piwik_site_created" was extended by a site ID parameter, so it will deliver the Matomo site ID of the created site
* Bugfix: Fixed an issue with Matomo site creation
* Bugfix: Allow changes of a manually defined tracking code on networks, see https://github.com/braekling/WP-Matomo/issues/46

= 1.0.13 =
* Language updates
* Readme typo fixes (thx to ujdhesa)
* Perform your own code after site creation by using the action "wp-piwik_site_created"
* Improved caching ID to avoid interferences, see https://github.com/braekling/WP-Matomo/issues/42

= 1.0.12 =
* Removed notices and warnings
* Allow to modify the tracking code using the filter "wp-piwik_tracking_code"
* Network: Don't show plugin overview settings link on individual sites

= 1.0.11 =
* Security improvements
* Removed some division by zero warnings
* Option to disable SSL host verification (additional to peer verification)
* Overview widget: Do not show unique visitors row if value is not available
* Bugfix: Post shortcode is fixed and will work again

= 1.0.10 =
* Security fix

= 1.0.9 =
* Language updates
* Bugfix: Deprecated get_currentuserinfo() replace. Thx to the infinity, see https://github.com/braekling/WP-Matomo/pull/21
* Bugfix: Overview widget will show proper values even if a period > 1 day is selected, see https://wordpress.org/support/topic/weird-numbers-im-wp-piwik

= 1.0.8 =
* Feature: Show "per post stats" and the "custom variable meta box" also on page and custom post edit
* Bugfix: Fixed user tracking (moved the user tracking changes from general modifications to runtime modifications)
* Bugfix: Fixed namespace error. Thx to thelfensdrfer, see https://github.com/braekling/WP-Matomo/pull/18
* Bugfix: Warning on blog name change, see https://wordpress.org/support/topic/wp-piwik-triggers-warning-when-changing-blog-name

= 1.0.7 =
* Feature: User ID Tracking. Thx to Danw33, see https://github.com/braekling/WP-Matomo/pull/16
* Feature: Site ID parameter added to opt-out shortcode. Thx to christianhennen, see https://github.com/braekling/WP-Matomo/pull/17
* Feature: Allow a local config file to affect the proxy script, see https://wordpress.org/support/topic/proxy-config-require-wp-loadphp-path
* Bugfix: No script tag is not auto-inserted if the tracking code is manually defined.

= 1.0.6 =
* Language updates
* Encoding & gettext fixes
* Better error messages. Thx to mcguffin, see https://github.com/braekling/WP-Matomo/pull/14

= 1.0.5 =
* Several language updates.
* Important security fix: XSS vulnerability

= 1.0.4 =
* Several language updates.
* Feature: Offer setDownloadExtensions option (see expert settings).
* Feature: Consider configured HTTP connection method in proxy script.
* Widget: Visitor country added.
* Bugfix: Annotations on scheduled posts will work.
* Bugfix: Donation button will work again.

= 1.0.3 =
* Several language updates.
* Switch to JSON renderer (Matomo 3 compatibility preparation)
* Workaround: PHP API will work with Matomo 2.14+, see https://github.com/piwik/piwik/issues/8311 for further information.
* Feature: Heartbeat timer support
* Feature: Expanded token & URL/path input fields
* Bugfix: Site duplication fix.
* Bugfix: Avoid notice on empty overview response.
* Bugfix: Return request error responses.
* Bugfix: Opt-out URL fixed.
* Bugfix: Capabilities: "Do not track"-filter and "show stats"-limit will work on multisites as expected again.

= 1.0.2 =
* Several language updates.
* Feature: Disable update notifications (expert settings).
* Feature: Choose between cURL and fopen if both are available (expert settings).
* Feature: Choose between POST and GET (expert settings).
* Widget: System details added.
* Widget: SEO widget re-enabled.
* Update: Replaced deprecated Matomo API calls.
* Bugfix: Settings link (toolbar, network mode) fixed.
* Bugfix: Encode blog titles in PHP mode.
* Bugfix: Pie charts won't show to long legends if more than 10 items are available.

= 1.0.1 =
* Several language updates, amongst others Portuges (Brazil) finished. See https://www.transifex.com/organization/piwik/dashboard/wp-piwik for further information.
* Bugfix: If WP-Matomo is not configured properly or the connection to Matomo could not be established, the toolbar graph won't cause a JavaScript error anymore.

= 1.0.0 =
* Feature: Expand "other" values on click
* Bugfix: Avoid notices on invalid file path (PHP API)
* Bugfix: Cookie lifetime input boxes are in some cases shown or hidden by mistake
* Network (multisite): Updated plugin to use wp_get_sites if possible
* Test script: Settings dump added

= 0.10.1.0 =
* Bugfix: Fixed memory & timeout issue on multisites

= 0.10.0.9 =
* Add clear cache function.
* Add clear settings (reset) function.

= 0.10.0.8 =
* Bugfix: Sitebrowser link (settings page, support) fixed
* Bugfix: Use new settings directly after saving (reloading is not necessary anymore)
* Optimized caching behaviour
* Language update (German, French)

= 0.10.0.7 =
* Bugfix: Opt-out shortcode output fix
* Bugfix: Opt-out shortcode will also work in "pro" and "php" mode
* Bugfix: Test script link (settings page, support) fixed
* Bugfix: Removed test script errors and notices
* Bugfix: Keep sure the revision ID is stored and avoid re-installing the plugin again and again
* Bugfix: http/pro - after configuration the settings page had to be reloaded once to start working
* Typo fixes
 
= 0.10.0.6 =
* Bugfix: Option storage bug if WP-Matomo is used as single site plugin on blog networks
* Bugfix: WP-Matomo will work without Matomo superuser access, again
* Bugfix: Choosing the site without auto config works again

= 0.10.0.5 =
* Bugfix: In some cases the update message did not disappear -> fixed
* Important change: If you want to upgrade from 0.8.x to 0.10.x, please install 0.9.9.18 first: https://downloads.wordpress.org/plugin/wp-piwik.0.9.9.18.zip

= 0.10.0.4 =
* Bugfix: Settings link in admin notices fixed
* Bugfix: Shortcode result will appear where expected
* Bugfix: 0.9.9.18 settings will be kept (if WP-Matomo was not reconfigured after updating to 0.10.0.3, yet)
* Feature: If Matomo returns an error instead of a tracking code, this error will be visible

= 0.10.0.3 =
* Public beta of WP-Matomo 1.0
* Full refactored code
* Feature: Limit referral cookie lifetime
* Feature: Enable content tracking

= 0.9.9.18 =
* Improvement: Define additional file extensions for tracking downloads
* Improvement: Added a POT file to support translators (Note: 1.0 will change a lot, so please don't spend too much time in translating the current version, e.g., by creating an all new translation. With 1.0 I will also offer a translation platform to support your work.)
* Improvement: If necessary, you can force Matomo to use HTTP or HTTPS now (e.g., to avoid redirections from http to https)
* Avoided a naming collision with Woo Theme

= 0.9.9.17 =
* Improvement: Updated the Matomo proxy script and added cURL support if url_fopen is not available
* Bugfix: Setup bug, see https://wordpress.org/support/topic/piwik-urlpath-not-saved
* Bugfix: CDN URL notice, see https://wordpress.org/support/topic/tracking-cdn-blank-gives-php-notice-which-breaks-the-trackback-js-code
* Bugfix: Fixed zlib compression notice, see https://wordpress.org/support/topic/v09914-is-bad
* Bugfix: Proxy script label links to proxy script checkbox
* Fixed a typo in German language file

= 0.9.9.16 =
* Bugfix: PHP API causes plain text output issue (see 0.9.9.11)
* Bugfix: Shortcode output translated

= 0.9.9.15 =
* Bugfix: One more commit error
* Bugfix: Adding up problem related to the overview widget
* Bugfix: Fixes missing brackets on ob_start
* Hotfix: Adds /0.9.9.15 to js/index.php to force a reload
* Bugifx: Replaced broken support link
* Added a bitcoin donation link

= 0.9.9.14 =
* Bugfix: Commit errors in 0.9.9.13

= 0.9.9.13 =
* Improvement: Only activate/ load admin components if an admin page is actually loaded. Thanks to Michael!
* Bugfix: Proxy tracking will work again. Matomo 2.7 or higher is recommended.
* Bugfix: Avoid a PHP notice in dashboard
* NOTE: If you update Matomo and use the "add tracking code" feature, please also update your WP-Matomo tracking code: Just open the WP-Matomo tracking code settings and save them again. 

= 0.9.9.12 =
* Bugfix: Avoid forced relogin on site change (WP network)
* Bugfix: Avoid multiple annotations on post updates
* Bugfix: Use mergeSubdomains instead of mergeAliasURLs
* Feature: Added mergeAliasURLs as additional feature

= 0.9.9.11 =
* Bugfix: PHP API causes plain text output issue, see http://wordpress.org/support/topic/bug-cant-access-to-tabs-in-setting-after-configuration
* Bugfix: PHP API causes WordPress multisite login issue, see http://wordpress.org/support/topic/causes-multisite-superadmin-subsite-login-problem
* Bugfix: Removed PHP warning if annotations are enabled and annotations will work again, see http://wordpress.org/support/topic/warning-message-everywhere-in-the-backend-call_user_func_array-expects
* Feature: "Track visitors across all subdomains" script changes are done by Matomo now, see http://wordpress.org/support/topic/track-across-subdomains-wp-on-subdomain?replies=2
* Update: Flattr API update

= 0.9.9.10 =
* Bugfix: Multisite login issue, see http://wordpress.org/support/topic/0999-multisite-frontend-not-logged-in
* Bugfix: wpMandrill compatibility, see http://wordpress.org/support/topic/version-0999-conflicts-with-wpmandrill
* Feature: Show page views (actions) in "visitors last 30"

= 0.9.9.9 =
* Update: PHP API will use namespaces (Matomo 2.x compatibility)
* Update: Matomo URL isn't necessary to use PHP API anymore.
* Feature: Limit cookie lifetime
* Feature: Track visitors across all subdomains
* Feature: Disable custom var box if necessary
* Feature: Choose if you like to add the tracking code to your site's footer or header
* Feature: New shortcode (post)
* Feature: Add data-cfasync=false to script tag if necessary.
* Feature: Add annotations on new posts, see http://linuxundich.de/webhosting/beim-veroeffentlichen-von-wordpress-posts-eine-anmerkung-in-piwik-setzen/
* Bugfix: Do not load sparklines plugin if toolbar not shown
* Bugfix: PHP API will work again (urlencoding removed)
* jqPlot and jquery.sparkline updated
* Partly refactored code

= 0.9.9.8 =
* Feature: Per post stats (shown at the edit post page)
* Feature: Track RSS views using a measurement pixel

= 0.9.9.7 =
* Bugfix: Error messages won't by cached anymore
* Bugfix: Custom vars will now be added properly
* Bugfix: Missing slash in proxy mode added
* Feature: Track users on admin pages

= 0.9.9.6 =
* Bugfix: Proxy script will work again
* Option: Enable/disable one week caching
* Load config file using full path to avoid side effects

= 0.9.9.5 =
* Fatal error on statistics settings page fixed

= 0.9.9.4 =
* Use Transients API (one week caching)
* Option: Track visitors without JavaScript, see http://piwik.org/faq/how-to/#faq_176

= 0.9.9.3 = 
* Sparkline script update (IE 10 compatibility)
* Syntax error fixes

= 0.9.9.2 =
* Bugfix regarding tracking code changes in proxy mode, see http://wordpress.org/support/topic/problem-with-https-in-proxy-mode
* Feature: Change text "WP-Matomo" in menu items and dashboard widgets
* Code cleanup ("new" first step)
* Debugging: Logger added
* Avoid double slash (//) in tracking code

= 0.9.9.1 =
* CDN support: http and https separated, see http://wordpress.org/support/topic/request-cdn-support-1
* Made <noscript> code optional. Move <noscript> code to site footer.

= 0.9.9.0 =
* Matomo 1.11 compatibility fixes (Matomo 1.11 required now!) 
* Depending on Matomo 1.11 WP-Matomo will use async tracking now
* CDN support added, see http://wordpress.org/support/topic/request-cdn-support-1

= 0.9.8.1 =
* Warning on empty data removed (overview table)
* Removed a possible deadlock
* Bugfix: Apply tracking code settings everytime the tracking code is updated
* Reset/uninstall script bugfix regarding network mode

= 0.9.8 =
* WordPress 3.5 compatibility fix: http://wordpress.org/support/topic/v35-errors-fix?replies=5 (Thanks Christian Foellmann!)
* Advanced Search Result Analytics, see http://piwik.org/docs/javascript-tracking/#toc-tracking-internal-search-keywords-categories-and-no-result-search-keywords
* Site Search stats added
* Use js/index.php: Replaces piwik.js and piwik.php by js/ (instead of piwik.js only)
* Connection timeout setting added
* Full reset option added
* Uninstall script added
* Stats metaboxes: Date formatted
* Use proxy settings defined in wp-config.php
* Matomo.php proxy script added (see http://piwik.org/faq/how-to/#faq_132)
* Bugfix: After upgrade, Matomo automatically places cookies again (http://wordpress.org/support/topic/after-upgrade-piwik-automatically-places-cookies-again)

= 0.9.7 =
* Shortcodes added
* WP-Matomo will rename sites in Matomo if site name changes in WordPress
* Bugfix: Tracking code changes should stay active after WP-Matomo updates

= 0.9.6.3 =
* Matomo 1.9+ compatibility fix (Matomo 1.9 required!)
* Browser version details added

= 0.9.6.2 =
* Bugfix: ["Create Matomo site" link (network dashboard)](http://wordpress.org/support/topic/plugin-wp-piwik-you-attempted-to-access-the-networks-dashboard-but-you-do-not)

= 0.9.6.1 =
* Toolbar graph bugfix

= 0.9.6 =
* Option: Disable SSL peer verification (REST API)
* Option: Use own user agent
* Test script displays additional information (e.g. response headers)
* Using WordPress metabox concept properly
* Bugfix: Sparkline script only loaded if required
* Stats site supports up to 3 columns
* Network admin stats: Choose site using a paged table (instead of a select box).
* Feature: [Custom variables](http://piwik.org/docs/javascript-tracking/#toc-custom-variables), using [custom post meta boxes](http://wp.smashingmagazine.com/2011/10/04/create-custom-post-meta-boxes-wordpress/).
* Some minor bugfixes

= 0.9.5 =
* WordPress 3.4 compatible (workaround)

= 0.9.4 = 
* Requires at least Matomo 1.8.2!
* Choose between HTTP API or PHP API
* Show graph on WordPress Toolbar
* Add option to disable cookies - Thanks to Mathias T.!
* Update bugfix: Upgrading from WP-Matomo 0.8.7 or less will work again
* Some minor bugfixes

= 0.9.3 =
* Bugfix: Adding a new site will work again.

= 0.9.2 =
* Uses $wpdb->blogs instead of $wpdb->prefix.'blogs' to keep it compatible to different database plugins
* Bugfix: SEO dashboard widget will work even if "last 30" is selected
* Bugfix: New created blogs won't show "Please specify a value for 'idSite'." anymore.
* Bugfix: New network sites without title will be created
* Bugfix: Upgrading from old versions will work again
* Tabbed settings
* Debug tools added (testscript, site configuration overview and WP-Matomo reset)
* Support forum RSS feed
* Language updates
* Optionally use of set_time_limit(0) on stats page time out

= 0.9.1 =
* Bugfix: Usage as "Site Specific Plugin" [mixed up the different sites settings](http://wordpress.org/support/topic/plugin-wp-piwik-as-simple-plugin-with-multisite-fills-auth-with-last-used-token) (network mode)
* Hotfix: Avoid "Unknown site/blog" message without giving a chance to choose an existing site. Thank you, Taimon!

= 0.9.0 =
* Auto-configuration
* No code change required to enable WPMU mode anymore (Still experimental. Please create a backup before trying 0.9.0!)
* All features in WPMU available
* Bugfix: Removed unnecessary API calls done with each site request - Thank you, Martin B.!
* Bugfix: [No stats on dashboard](http://wordpress.org/support/topic/no-stats-on-dashboard-new-install) (sometimes this issue still occured, should be fixed now)
* Code cleanup (still not finished)
* Minor UI fixes
* Minor language/gettext improvements
* Security improvements
* Show SEO rank stats (very slow, caching will be added in 0.9.1)
* WordPress dashboard SEO rank widget (very slow, caching will be added in 0.9.1)
* New option: use js/index.php
* New option: avoid mod_security
* Multisite: Order blog list alphabetically (Network Admin stats site)
* Settings: Order site list alphabetically (site list shown if order conf is disabled)

= 0.8.10 =
* jqplot update (IE 9 compatibility) - Thank you, Martin!
* Bugfix: [No stats on dashboard](http://wordpress.org/support/topic/no-stats-on-dashboard-new-install)
* Layout fix: [Graph width on dashboard](http://wordpress.org/support/topic/stats-graph-in-dashboard-changed)
* Minor code cleanup

= 0.8.9 =
* WP 3.2 compatible, metabox support

= 0.8.8 =
* Bugfix: Will also work with index.php in Matomo path
* Bugfix: last30 dashboard widget - show correct bounce rate

= 0.8.7 =
* New language files (Azerbaijani, Greek, Russian)
* Fixed hardcoded database prefix (WPMU-Matomo)
* Minor bugfixes: avoid some PHP warnings

= 0.8.6 =
* Added an optional visitor chart to the WordPress dashboard
* [WPMU/multisite bug](http://wordpress.org/support/topic/plugin-wp-piwik-multisite-update-procedure) fixed
* Minor bugfixes

= 0.8.5 =
* Select default date (today or yesterday) shown on statistics page
* Bugfix: Shortcut links are shown again
* German language file update
* Minor optical fixes (text length)

= 0.8.4 =
* New stats in overview box
* WP 3.x compability fixes (capability and deprecated function warnings)
* Some minor bugfixes
* New config handling
* Code clean up (not finished)

= 0.8.3 =
* Matomo 1.1+ compatibility fix

= 0.8.2 =
* Bugfix: [WPMU URL update bug](http://wordpress.org/support/topic/plugin-wp-piwik-jscode-not-updated-when-saving-new-url-in-wpmu-mode)

= 0.8.1 =
* Use load_plugin_textdomain instead of load_textdomain
* Fixed js/css links if symbolic links are used
* Changed experimental WPMU support to experimental WP multisite support
* Try curl() before fopen() to avoid an [OpenSSL bug](http://wordpress.org/support/topic/plugin-wp-piwik-problems-reaching-an-ssl-installation-of-piwiki)
* Added Norwegian language file by Gormer
* Don't worry - new features will follow soon ;)

= 0.8.0 =
* Using jqPlot instead of Google Chart API
* Some facelifting
* Some minor bugfixes

= 0.7.1 =
* Track 404-pages in an own category
* Get some page (and article) details
* Language updates

= 0.7.0 =
* Bugfix: Percent calculation fixed
* Bugfix: Visitor chart: No label overlapping if < 50 visitory/day
* Visitor chart: Added a red unique visitor average line
* Visitor table: Added a TOTAL stats line
* Pie charts: Show top 9 + "others", new color range
* Option: Show Matomo shortcut in overview box
* Some performance optimization

= 0.6.4 =
* Unnecessary debug output removed
* German language file update
* WordPress dashboard widget: last 30 days view added

= 0.6.3 =
* Click at a visitor stats day-row to load its details
* Add stats overview to your WordPress dashboard

= 0.6.0 =
* Added experimental WPMU support
* Switch to disable Google Chart API
* Added Albanian [sq] language file
* Added Belorussian [be_BY] language file

= 0.5.0 =
* Display statistics to selected user roles
* Some HTML fixes (settings page)

= 0.4.0 =
* Tracking filter added
* Resolution stats
* Operating System stats
* Plugin stats

= 0.3.2 =
* If allow_url_fopen is disabled in php.ini, WP-Matomo tries to use CURL instead of file_get_contents

= 0.3.1 =
* WordPress 2.8 compatible
* Bugfix: Warnings on WP 2.8 plugins site
* Dashboard revised
* Partly optimized code

= 0.3.0 =
* WP-Matomo dashboard widgetized
* Stats-boxes sortable and closeable
* German language file added
* Browser stats and bounced visitors

= 0.2.0 =
* First public version
