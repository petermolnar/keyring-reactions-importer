=== Keyring Reactions Importer ===
Contributors: cadeyrn
Tags: facebook, flickr, 500px, backfeed, indieweb, comments, likes, favorites
Requires at least: 3.0
Tested up to: 4.1
Stable tag: 0.2
License: GPLv3
License URI: http://www.gnu.org/licenses/gpl-3.0.html

A social reactions ( comments, like, favs, etc. ) importer.

== Description ==

A [backfeed](http://indiewebcamp.com/backfeed) plugin to have all the reaction from all the social networks you have a copy of your post at.

= How it works =
* it checks the `syndication_urls` post meta field populated either by the [Syndication Links](https://wordpress.org/plugins/syndication-links/) plugin or by hand (one syndicated url per line)
* based on the activated and available importers it will fire up scheduled jobs to import reactions for each and every post we have the syndication link for the [silo](http://indiewebcamp.com/silo) available

= Required plugins =
* [Keyring](https://wordpress.org/plugins/keyring)
* note: to use 500px, you'll need a [not-yet-merged addition to Keyring for 500px](https://github.com/petermolnar/keyring/blob/master/includes/services/extended/500px.php)



= Recommended plugins =
* [Syndication Links](https://wordpress.org/plugins/syndication-links/)

= Currently supported networks =

* [500px](https://500px.com/) - comments, favs, likes
* [Flickr](https://flickr.com/) - comments, favs
* [Facebook](https://facebook.com/) - comments, likes

The plugin uses the brilliant  [Keyring](https://wordpress.org/plugins/keyring/) for handling networks and us based on [Keyring Social Importers](https://wordpress.org/plugins/keyring-social-importers/); both from [Beau Lebens](http://dentedreality.com.au/).

== Installation ==

1. Upload contents of `keyring-reactions-importer.zip` to the `/wp-content/plugins/` directory
2. Go to Admin -> Tools -> Import
3. Activate the desired importer.
4. Make sure WP-Cron is not disabled fully in case you wish to use auto-import.

== Changelog ==

= 0.2 =
*2015-03-13*

* adding Flickr
* adding Facebook

= 0.1 =
*2015-03-12*

* first public release; 500px only
