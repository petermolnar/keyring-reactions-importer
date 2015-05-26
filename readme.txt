=== Keyring Reactions Importer ===
Contributors: cadeyrn
Tags: instagram, facebook, flickr, 500px, backfeed, indieweb, comments, likes, favorites
Requires at least: 3.0
Tested up to: 4.2.1
Stable tag: 1.1
License: GPLv3
License URI: http://www.gnu.org/licenses/gpl-3.0.html

A social reactions ( comments, like, favs, etc. ) importer.

== Description ==

A [backfeed](http://indiewebcamp.com/backfeed) plugin to have all the reaction from all the social networks you have a copy of your post at.

= Required plugins =
* [Keyring](https://wordpress.org/plugins/keyring)
* note: to use 500px, you'll need a [not-yet-merged addition to Keyring for 500px](https://github.com/petermolnar/keyring/blob/master/includes/services/extended/500px.php)

[Keyring](https://wordpress.org/plugins/keyring/) is a dependency; the plugin will not function without it!

= How it works =
The plugin checks the `syndication_urls` post meta field populated either by the [Syndication Links](https://wordpress.org/plugins/syndication-links/) plugin or by hand: one syndicated url per line.

For example, it should look like:

`https://www.facebook.com/your-facebook-user/posts/facebook-post-id
https://www.flickr.com/photos/your-flickr-user/flickr-post-id`

In case the auto-import is enabled it will fire up scheduled cron job once every day ( not changeable currently ) for each network auto-import is enabled on. The job will query X ( depending on the service it's querying ) posts per request, then fire up a new cron in the background until all posts are processed.

= Known issues =
* due to the nature of the plugin it's highly recommended to use [system cron](https://support.hostgator.com/articles/specialized-help/technical/wordpress/how-to-replace-wordpress-cron-with-a-real-cron-job) for WordPress instead of the built-in version
* the plugin can be heavy on load; in this case please consider limiting the import date range on the settings page.

= Currently supported networks =

* [500px](https://500px.com/) - comments, favs, likes
* [Flickr](https://flickr.com/) - comments, favs
* [Facebook](https://facebook.com/) - comments, likes
* [Instagram](https://instagram.com) - comments, likes


= Credit =
Countless thanks for the [Keyring Social Importers](https://wordpress.org/plugins/keyring-social-importers/) and the Keyring plugin from [Beau Lebens](http://dentedreality.com.au/).

== Installation ==

1. Upload contents of `keyring-reactions-importer.zip` to the `/wp-content/plugins/` directory
2. Go to Admin -> Tools -> Import
3. Activate the desired importer.
4. Make sure WP-Cron is not disabled fully in case you wish to use auto-import.

== Changelog ==

= 1.1 =
*2015-05-26*

* Facebook broke it's API... again. Fixed.


= 1.0 =
*2015-05-01*

* initial stable release

= 0.3 =
*2015-04-16*

* adding Instagram

= 0.2 =
*2015-03-13*

* adding Flickr
* adding Facebook

= 0.1 =
*2015-03-12*

* first public release; 500px only
