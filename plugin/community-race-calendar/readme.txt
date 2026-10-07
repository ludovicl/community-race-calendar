=== Community Race Calendar ===
Contributors: TODO-your-wordpress-org-username
Tags: calendar, races, running, members, map
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.5.25
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Private race calendar for a running community: members list races, register, share rides, publish results and browse a map.

== Description ==

Community Race Calendar adds a members-only calendar of races to your WordPress site. It reuses your existing WordPress accounts and your existing memberships. It never creates accounts, passwords, PINs, payments or subscriptions of its own.

**Features**

* Monthly calendar of races with formats (distances or courses), official website and optional logo.
* Registration of members on one or several formats of a race.
* Map view of the races that have GPS coordinates (optional: the map background comes from an external service and is switched on by the administrator, see "External services").
* Carpooling: members offer or look for a seat for a race.
* Personal results and a weekly review of finished races that can be copied and shared.
* Yearly summary with key figures and rankings.
* Comments on each race, duplicate detection when a race is added twice, and a reversible trash for the author of a race.
* Branding settings: calendar name, introduction text, colors with an automatic contrast check, font stack and maximum width.
* WordPress privacy tools: personal data export and erasure are supported.

**Access control**

Access is decided on the server for every REST request. The current version ships a single access provider, which relies on the **WooCommerce Memberships** plugin (a separate, third-party plugin that is not distributed on WordPress.org). Only users with an active membership (optionally restricted to selected membership plans) and administrators can read or write calendar data. Without WooCommerce Memberships and without the `community_race_calendar_user_has_access` filter described in the FAQ, only administrators can use the calendar, and the settings page shows a notice. The administrator interface and the calendar texts are written in French (see the FAQ about languages).

**Shortcode**

Place `[community_race_calendar]` on a page. The settings page can create that page for you. The page is marked `noindex`, is never cached, and sends `Referrer-Policy: no-referrer`.

**Free and complete**

Every feature is available in this version. Nothing is locked behind a payment, and the plugin displays no advertising or credit on your public site.

== External services ==

This plugin can contact two third-party services. Both are optional and described below. No other service is contacted, and no JavaScript or CSS file is loaded from a third-party server.

= 1. Geoapify (place search), optional, configured by the administrator =

* What it is used for: suggests place names while a member types the starting point of a race, and returns GPS coordinates so that the race can be shown on the map.
* When: only if the site administrator has pasted a Geoapify API key in Settings > Community Calendar (or defined `CRCAL_GEOAPIFY_API_KEY` in `wp-config.php`). Without a key, the plugin never contacts Geoapify and members type the place freely. The request is sent when a member types at least three characters in the place field of the race form, and when the administrator clicks the key verification buttons.
* What is sent: the text typed in the place field, the language (`fr`) and the administrator's API key, from your web server to `https://api.geoapify.com/v1/geocode/autocomplete`. The visitor's browser does not contact Geoapify, and the API key is never sent to the browser. Results are cached on your server for 24 hours.
* The API key belongs to the site owner, is never bundled with the plugin, and is stored in the WordPress options table (not autoloaded).
* Service provider: Geoapify (KEPTAGO LTD). Terms of use: https://www.geoapify.com/terms-and-conditions/ Privacy policy: https://www.geoapify.com/privacy-policy/
* Note: Geoapify's terms require a Geoapify attribution when you use their Free plan. You are responsible for complying with the terms of the plan you choose.

= 2. OpenFreeMap (map tiles), optional, switched on by the administrator =

* What it is used for: background map (vector tiles, map style, icons and map labels) displayed in the Map view and in the small preview of a selected place.
* When: the map is controlled by the setting Settings > Community Calendar > Map ("Afficher la carte des courses"). On a new installation the setting is **off**: the plugin makes no request to OpenFreeMap, and a neutral message replaces the map while the calendar and the other views keep working. When the administrator turns it on, requests are made in the visitor's browser, only on the calendar page and only for a logged-in member whose membership is active, when the Map view or a map preview is opened. Sites that were already using the plugin before this setting existed keep the map on after the update, so that nothing changes for them; the administrator can turn it off at any time. The map library itself (MapLibre GL JS) and its worker are bundled with the plugin and served from your own site.
* What is sent: the member's browser requests files from `https://tiles.openfreemap.org/` (the style `https://tiles.openfreemap.org/styles/liberty`, tiles, sprites and glyph files). Like any web request, this reveals the member's IP address, browser type and the map area being displayed to the service. The plugin sets no cookie for it, no account or key is needed, and no calendar data or member identifier is sent. The calendar page sends `Referrer-Policy: no-referrer`.
* Service provider: Hyperknot Software Kft. (OpenFreeMap). Terms of use: https://openfreemap.org/tos/ Privacy policy: https://openfreemap.org/privacy
* Map data: OpenStreetMap contributors (https://www.openstreetmap.org/copyright) and OpenMapTiles (https://www.openmaptiles.org/).

== Privacy ==

The plugin stores races, registrations, comments, carpool offers and results in dedicated tables, and shows to other active members the name of each member. The setting Settings > Community Calendar > Member names ("Noms des membres") decides which one: when ticked, the first and last name (WordPress profile, otherwise WooCommerce billing name, otherwise public display name), which can be searched by first or last name; when unticked, only the public display name chosen by each member. The setting is ticked for sites that already used the plugin before it existed, and unticked on new installations. Login names are never searched and email addresses are never displayed in full. Members' data can be exported and erased with the WordPress privacy tools. Two optional external services may receive data, see "External services": Geoapify (server side, only with an administrator key, receives the typed place text) and OpenFreeMap (member's browser, only when the map is switched on, receives the member's IP address and the displayed map area). Mention them in your site's privacy policy if you enable them. Registrations, results, carpool offers and the member's name are visible to the other active members of the calendar.

== Source code ==

The JavaScript and CSS files in the `assets` directory are compiled and minified from the sources located in the `frontend` directory of the public repository:

https://github.com/ludovicl/community-race-calendar

Build steps (Node.js 20 or later, npm; the lock file pins every version):

1. `cd frontend`
2. `npm ci`
3. `npm run build`

The build writes `app.js`, `app.css`, the `chunks` folder and the MapLibre worker into `plugin/community-race-calendar/assets`. The build is reproducible: rebuilding from the committed sources with the locked dependencies gives byte-identical files, and the repository's continuous integration fails if the compiled files differ from the committed ones. React and ReactDOM are not bundled: the interface uses the copies that WordPress ships (script handles `wp-element` and `react-jsx-runtime`, React 18.3.1 in WordPress 6.7 to 7.1), declared by small adapter files in `frontend/src/wp-shims/`. The third-party libraries bundled in the compiled files (MapLibre GL JS and its dependencies, Lucide icons) and their licenses are listed in `third-party-licenses.txt`.

== Installation ==

1. Make sure WooCommerce and WooCommerce Memberships are installed and active.
2. Upload the plugin folder to `/wp-content/plugins/`, or install the ZIP from Plugins > Add New > Upload Plugin, then activate it.
3. Open Settings > Community Calendar, then set the calendar name, the colors, the membership page and who can add races.
4. Optional: paste a Geoapify API key in the Geoapify section to enable place suggestions, and tick "Afficher la carte des courses" in the Map section if you accept that members' browsers load the map background from OpenFreeMap (see "External services").
5. Click the button to create the calendar page (a draft containing the shortcode), review it and publish it.
6. Optional: define `CRCAL_PRIVATE_UPLOAD_DIR` in `wp-config.php` (a writable directory outside the WordPress root) to store race logos outside the web root.

== Frequently Asked Questions ==

= Does it work without WooCommerce Memberships? =

Not out of the box. The only access provider shipped with this version is WooCommerce Memberships, so without it only administrators can open the calendar. A developer can open it to other users with the `community_race_calendar_user_has_access` filter, which receives the result of the provider, the user ID and the settings, and returns a boolean. Example, all logged-in users: `add_filter( 'community_race_calendar_user_has_access', function ( $granted, $user_id ) { return $user_id > 0; }, 10, 2 );`. Example, a role: `add_filter( 'community_race_calendar_user_has_access', function ( $granted, $user_id ) { $user = get_userdata( $user_id ); return $user && in_array( 'subscriber', (array) $user->roles, true ); }, 10, 2 );`. Administrators of the calendar always keep access. The filter has no settings screen yet.

= Are the logos private? =

Logos are re-encoded and served to members through an authenticated REST route. With the default storage in the WordPress uploads folder, the plugin adds an empty `index.php` so that servers do not list the folder, but the direct file URL (random name) can still be opened by anyone who knows it, so treat logos as public content. Use `CRCAL_PRIVATE_UPLOAD_DIR` for a private directory outside the web root.

= Which data is stored and what happens on uninstall? =

Races, formats, registrations, comments, carpool offers and results are stored in six dedicated tables that use your WordPress table prefix. Deactivation and uninstall keep these tables and the images unless you enable the "delete all data on uninstall" option in the danger zone of the settings page, which requires typing a confirmation word. The saved Geoapify key, the rate limit counters and the summary cache are always removed on uninstall.

= Which personal data is shown to other members? =

Depends on the setting "Noms des membres". Ticked (default for existing sites): active members see the first and last name of other active members (WordPress profile, otherwise WooCommerce billing name, otherwise the public display name) in registrations, comments, carpool offers, results, the weekly review and the yearly summary, and can find them by first or last name. Unticked (default for new installations): only the public display name chosen by each member ("Display name publicly as" in the profile) is shown and searched; WordPress sets it to the login name by default. In both cases email addresses are never displayed in full (the part before an "@" is kept when a name is an email address), login names are not searchable, and the member search is limited to 60 requests per minute and per user. Personal data export and erasure through the WordPress privacy tools are supported.

= How do I adapt the appearance? =

In Settings > Community Calendar, section Appearance: the calendar name and introduction text, four colors (main color, background, cards and panels, text) and two layout options. Every value is checked against a strict allow-list when saved; an invalid value is refused with a message and the default is kept, and no other CSS can be injected.

* Font: a list of up to 8 font families separated by commas, for example `Georgia, serif` or `'Open Sans', Arial, sans-serif`. Only letters, digits, spaces and hyphens are accepted, and a name may be wrapped in a matching pair of single or double quotes. The plugin ships no font: the fonts must already be available on your site (theme or another plugin). Empty (default) uses the font of your theme.
* Maximum content width: a number followed by `px` (320 to 2400), `rem` (20 to 150) or `%` (50 to 100). Default `1200px`.
* Text color: `#rgb` or `#rrggbb`. Colors that would make the text unreadable on the background or card colors (contrast below 4.5:1) are refused.

The values are applied through the CSS custom properties `--crc-font`, `--crc-max-width` and `--crc-ink` of the calendar, so several sites can use the same plugin with their own visual identity.

= Is any data sent to a third party? =

See the "External services" section. Place search (Geoapify) stays off until you add a key. The map background (OpenFreeMap) stays off on a new installation until you tick the Map option in the settings; when it is on, the members' browsers request it from OpenFreeMap.

= In which language is the plugin? =

The source strings of the administration screens and of the calendar interface are written in French, and no translation file is shipped yet. The calendar interface texts are fixed in the compiled JavaScript and are not translatable with the WordPress translation tools in this version.

= Where does React come from? =

From WordPress itself (the `wp-element` script). The plugin does not embed its own copy. The interface was tested with the React 18.3.1 shipped by WordPress 6.7 to 7.1.

== Screenshots ==

== Changelog ==

= Unreleased =
* New "Carte" setting: the OpenFreeMap map background is off on new installations and on for existing ones (no change after an update). When it is off, no request is sent to OpenFreeMap and a message replaces the map.
* New "Noms des membres" setting: first and last names stay shown on existing sites (no change after an update); new installations show only the public display name. The member search no longer matches login names and is limited to 60 requests per minute per user.
* Logos: an empty `index.php` is added to the storage folder.
* The interface uses the React shipped with WordPress (wp-element) instead of an embedded copy; the main script is registered as a WordPress script module.
* New `community_race_calendar_user_has_access` filter to grant or refuse access with custom rules.
* New appearance settings: font stack, maximum content width and text color, validated by strict allow-lists.
* All internal identifiers (options, transients, constants, hooks, REST error codes, meta keys) now use the unique prefix `crcal_` / `CRCAL_` instead of a shorter three-letter prefix. Data stored under the old names is no longer read (the plugin was never published).
* Database queries use the `%i` identifier placeholder; file operations use the WordPress filesystem API.

= 0.5.25 =
* First public release on WordPress.org.
* Reuse the previous edition of a race: name suggestions also search past editions and prefill place, coordinates, website, formats and logo. A logo that is already stored is reused instead of being stored twice.
* Neutral default theme with system fonts.

== Upgrade Notice ==

= 0.5.25 =
First public release.
