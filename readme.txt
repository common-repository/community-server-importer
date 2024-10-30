=== Community Server Importer ===
Contributors: Kristopher Cargile
Tags: community server, converter, dottext, admin, importer, CS
Requires at least: 2.6
Tested up to: 2.6
Stable tag: 0.5

An admin plugin that allows users to convert their Community Server blog to WordPress.

== Description ==

An admin plugin that allows users to convert their Community Server blog to WordPress.

**IMPORTANT:** The beta version (v0.5) of the Community Server Importer only supports PHP hosting platforms that use version 2000.80.2039.0 of the MS native SQL driver (ntwdblib.dll). Future versions of the plugin will support platforms that do not meet this requirement; see the FAQ section for more information.

See the [Community Server Importer homepage](http://www.bettersoftwarenow.com/community-server-to-wordpress-converter/ "Community Server Importer homepage") for more information.

== Installation ==

Installation is a simple, two-step process:

   1. Download and extract the plugin.
   2. Upload communityserver.php to the /wp-admin/import/ directory of your website.

From your WordPress dashboard, you can now navigate to Manage > Import. Community Server should now be available in your list of available importers.

== Frequently Asked Questions ==

= Is this a converter or an importer? =

Both. The distinction for our purposes is purely semantic.

= What versions of WordPress does the importer support? =

The importer has only been tested with WordPress version 2.6.1. If you're using an older version of WordPress and have or would like to test the importer on it, please [contact me](http://www.bettersoftwarenow.com/make-contact/ "contact me").

= What versions of Community Server does the importer support? =

The importer has only been tested with Community Server 2007. If you're using an older version of Community Server and have or would like to test the importer on it, please [contact me](http://www.bettersoftwarenow.com/make-contact/ "contact me").

= What versions of SQL Server does the importer support? =

The beta version of importer uses SQL-XML features that are available in the 2000 and 2005 versions of Microsoft SQL Server. Future versions of the importer will not have this limitation.

= What if I don't have the ability to install the correct MS-SQL PHP library? =

The next version of the importer will provide wider hosting platform support by using ODBC for database connections. If you can't wait for the ODBC version, I may be able to provide assistance. [contact me](http://www.bettersoftwarenow.com/make-contact/ "contact me") for more information.

= How does the importer work? =

The importer works by exporting data from your Community Server database into a WXR-compliant XML file, and then importing said XML file into your WordPress database. The WXR format is the same that is used by the WordPress importer, so most of the CS import process is identical to (and interchangeable with) the WordPress import process.

= What objects are exported from the Community Server database? =

At present, the importer exports posts, comments, basic user information associated with posts, and tags/categories.

= Why are my categories and tags identical in the exported data? =

Because Community Server makes no distinction between these internally.

= Can I tweak the data before it is imported into my WordPress database? =

Yes. Because the importer creates a WXR-compliant XML file and saves it to your website's file system, you can exit the importer after the file is generated, download and edit the file, and then complete the import using the WordPress importer. If you choose to do this, be very careful not to change the schema of the XML document, lest your import fail miserably.

= Can I keep a copy of the data that is exported from Community Server? =

Yes. The XML file that is generated from the Community Server database is stored at the root of your website during the conversion, and is not removed when the import completes. This essentially gives you a "snapshot" of your CS site at the time you made the switch.

= The import fails because of the size of my WXR file. WTF!? =

You need to increase the value of the uploadmaxfilesize property in php.ini to a value that is equal to or greater than the size of your import file. Many shared hosting companies allow this on a per-user basis; see your provider's documentation for more information.

= Is the importer supported? =

On a limited basis, yes. I want to make the importer as useful and bullet-proof as possible, however, I cannot provide free support for miscellaneous issues not directly related to the plugin. Please [contact me](http://www.bettersoftwarenow.com/make-contact/ "contact me") if you need to.

= When will the ODBC version of the importer be ready? =

Soon.

= Is there anything else I need to know about migrating from Community Server? =

Maybe. Check [www.bettersoftwarenow.com](http://www.bettersoftwarenow.com "www.bettersoftwarenow.com") for more information and some other useful tools.

== Advanced Configuration Options == 

By default, detailed exception messages are supressed by the importer. If you're having problems and would like to turn on verbose debugging, set CS_DEBUG = TRUE in communityserver.php.

== Updates ==

Updates to the importer will be available here and <a href="#download">here</a> as they are released.

== Thanks ==

I'd like to sincerely thank Theodore Rosendorf at [Matador](http://www.matador.com/ "Matador") for patiently testing and providing feedback on serveral versions of the importer during the migration of the [TypeDesk](http://typedesk.com/ "TypeDesk")

Thanks also go out to [Doug Rohm](http://www.dougrohm.com/ "Doug Rohm") for providing the MS-SQL Server UDF to parse Community Server's extended properties into a usable format.
