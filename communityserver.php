<?php

/*

Plugin Name: Community Server Importer
Plugin URI: http://www.bettersoftwarenow.com/community-server-to-wordpress-converter/
Description: An admin plugin that allows users to convert their Community Server blog to WordPress. 
Version: v0.5
Author: Kristopher Cargile
Author URI: http://www.bettersoftwarenow.com/


NOTE: This importer generates a WXR file using SQL and imports it. 
The script is based largely on the WP->WP importer that is included with 
WordPress. Note that your database should be either MSSQL2K or MSSQL2K5. While 
it's possible that other SQL-XML compliant platforms may work, they haven't been 
tested. 

IMPORTANT: If you set USE_NATIVE_SQL to TRUE, the importer will 
attempt to use the MSSQL db-lib DLL. Note that the assembly that is 
distributed with PHP is the INCORRECT VERSION of the DLL, and you MUST 
have the updated version installed for the import to succeed. It is unlikely 
that you will be able to do this in a shared hosting environment, so if in 
doubt, keep this option unselected to use ODBC instead. You can verify the 
correct version using phpinfo(). You need ntwdblib.dll v2000.80.2039.0. Also 
note that this library has been deprecated by MS, which, because of PHP's 
lack of meaningful support for MSSQL, may eventually cause this imported to 
fail. Take luck.

More information for working around this bug can be found on the Internet.
 
If you are using ODBC, you will need to create an ODBC connection. See your
OS documentation for for more information on how to do this.
Your feedback is always appreciated. If you have any comments, suggestions, 
or bug reports, please post a message on my blog at the address above.

Please see the readme file included with this plugin for detailed installation 
and support information. If you didn't receive the readme for some reason, it 
can be downloaded from the URL above.

Copyright (c) 2008 Cargile Technology Group, LLC 

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA

*/

define('CS_DEBUG', FALSE);
define('USE_NATIVE_SQL', TRUE);

class CSImport {
	
	function CSImport() { }
	
	function dispatch() {
		
		if (empty ($_GET['step'])) {
			$step = 0;
		}
		else {
			$step = (int) $_GET['step'];
		}

		$this->render_header();
		
		$cs_util = new CSImportHelper();
		$wp_util = new WPImportHelper();
		
		switch ($step) {
			case 0 :
				$this->render_greeting();
				$cs_util->render_db_form();
				break;
			case 1 :
				check_admin_referer('import-communityserver');
				if (!$cs_exportfile = $cs_util->do_export(sanitize_user($_POST['dbname'], true), sanitize_user($_POST['dbuser'], true), $_POST['dbpass'], sanitize_user($_POST['dbschema'], true), sanitize_user($_POST['dbhost'], true), sanitize_user($_POST['dbport'], true))) {
					echo '<p style="color:#ff0000;font-weight:bold;">ERROR: There was an unexpected error exporting your Community Server blog. This could be because your database connection information is incorrect, your CS SQL Server was down, or PHP doesn\'t have write permissions on your server. Please check these settings and try again.</p>';
				}
				else {
					$cs_util->render_query_success();
				}
				break;
			case 2:
				check_admin_referer('import-communityserver');
				$wp_util->file = CSImportHelper::get_export_url();
				$wp_util->select_authors();
				break;
			case 3:
				check_admin_referer('import-communityserver');
				$result = $wp_util->do_import(CSImportHelper::get_export_url());
				if (is_wp_error($result)) {
					echo $result->get_error_message();
				}
				break;
		}
		
		$this->render_footer();
	}

	function render_header() {
		echo '<div class="wrap">';
		echo '<h2>'.__('Import a Community Server Blog').'</h2>';
	}

	function render_footer() {
		echo '</div>';
	}

	function render_greeting() {
				
		echo '<p>'.__('Howdy! Enter your Community Server database connection information below, and we&#8217;ll import the posts, comments, and categories into this blog.');
		echo '<p>'.__('No permanent changes will be made to your source database, your database will not become unavailable during this process, and your connection information is not stored or sent to any undisclosed third-parties by this process.').'</p>';
		echo '<p>'.__('A copy of the XML exported from Community Server will be created in your WordPress blog root at <b>' . CSImportHelper::get_export_url() . '</b>, which can be useful for debugging and disaster recovery. If this file already exists, it <b>WILL</b> be overwritten.').'</p>';
		echo '<p>'.__('Depending on the size of your Community Server database, this process could take several minutes.').'</p>';
	}
}

class CSImportHelper {
	
	const OUT_FILE_NAME = 'csexport.xml';
	
	private $dbUser;
	private $dbPassword;
	private $dbName;
	private $dbSchema;
	private $dbHost;
	private $dbPort;
	
	public function CSImportHelper() {
		
		if (CS_DEBUG) {
			error_reporting(E_ALL ^ E_NOTICE);
		}
		else {
			error_reporting(0);
		}
	}
	
	public function do_export($name, $username, $password, $schema = 'dbo', $host = 'localhost', $port = '1433') {
		
		// set connection properties
		$this->set_db_info($name, $username, $password, $schema, $host, $port);
		
		// execute query
		$rslt = $this->query_cs_data();		
		if (!$rslt) {
			return $rslt;
		}

		// translate to WXR format
		$rslt = $this->translate_to_wxr($rslt);	
		if (!$rslt) {
			return $rslt;
		}
		
		// save results to disk
		$rslt = $this->persist_export($rslt);
		if (!$rslt) {
			return $rslt;
		}
		
		return $rslt;
	}
	
	public function render_db_form() {
		
		echo '<form action="admin.php?import=communityserver&amp;step=1" method="post">';
		wp_nonce_field('import-communityserver');
		echo '<table class="form-table">';
		printf('<tr><th><label for="dbuser">%s</label></th><td><input type="text" name="dbuser" id="dbuser" /></td></tr>', __('CS DB User:'));
		printf('<tr><th><label for="dbpass">%s</label></th><td><input type="password" name="dbpass" id="dbpass" /></td></tr>', __('CS DB Password:'));
		
		if (USE_NATIVE_SQL) {
			printf('<tr><th><label for="dbname">%s</label></th><td><input type="text" name="dbname" id="dbname" /></td></tr>', __('CS DB Name:'));
			printf('<tr><th><label for="dbhost">%s</label></th><td><input type="text" name="dbhost" nameid="dbhost" value="localhost" /></td></tr>', __('CS DB Host:'));
			printf('<tr><th><label for="dbport">%s</label></th><td><input type="text" name="dbport" nameid="dbport" value="1433" /></td></tr>', __('CS DB Port:'));
		}
		else {
			printf('<tr><th><label for="dbname">%s</label></th><td><input type="text" name="dbname" id="dbname" /> (your ODBC DSN name)</td></tr>', __('CS DSN:'));
		}
		
		printf('<tr><th><label for="dbschema">%s</label></th><td><input type="text" name="dbschema" id="dbschema" value="dbo"/> (you probably don\'t need to change this)</td></tr>', __('CS DB Schema:'));
		echo '</table>';
		echo '<p class="submit"><input type="submit" name="submit" value="'.attribute_escape(__('Get CS Data')).'" /></p>';
		echo '</form>';
	}

	public function render_query_success() {
		
		echo '<p style="color:#336600;font-weight:bold;"><b>Your data was retrieved from Community Server successfully! The XML export was saved to <a href="' . CSImportHelper::get_export_url() . '">' . CSImportHelper::get_export_url() . '</a>.</b></p>';
		echo '<p>Click the \'Next\' button below to complete the import process.</p>';
		echo '<form action="admin.php?import=communityserver&amp;step=2" method="post">';
		wp_nonce_field('import-communityserver');	
		printf('<p class="submit"><input type="submit" name="submit" value="%s" /></p>', attribute_escape(__('Next')));
		echo '</form>';
	}
	
	public static function get_export_url() {
				
		return get_bloginfo('url') . '/' . CSImportHelper::OUT_FILE_NAME;
	}
	
	private function set_db_info($name, $username, $password, $schema, $host, $port) {	
		
		$this->dbName = $name;
		$this->dbUser = $username;
		$this->dbPassword = $password;
		$this->dbSchema = $schema;
		$this->dbHost = $host;
		$this->dbPort = $port; 
	}
	
	private function build_function_metaparse() {
		
		$query =
		"		
		CREATE FUNCTION [$this->dbSchema].[FetchExtendendAttributeValue] (
			
			@Key NVARCHAR(4000), 
			@Keys NVARCHAR(4000), 
			@Values NVARCHAR(4000)
		)
		
		RETURNS NVARCHAR(4000)
		AS
		BEGIN
		DECLARE @Value NVARCHAR(4000)
		DECLARE @CharIndex INT
		DECLARE @StartIndex INT
		DECLARE @Len INT
		
		SET @CharIndex = CHARINDEX(@Key + ':s',@Keys);
		
		IF(@CharIndex = 0)
		RETURN NULL
		
		IF(@CharIndex > 1)
		BEGIN
		  SET @Keys = STUFF(@Keys,1,@CharIndex-1,'');
		END
		
		SET @Keys = STUFF(@Keys,1,LEN(@Key+':S:'),'');
		SET @CharIndex = CHARINDEX(':',@Keys);
		SET @StartIndex = SUBSTRING(@Keys, 1, @CharIndex-1);
		SET @Keys = STUFF(@Keys,1,@CharIndex,'');
		SET @CharIndex = CHARINDEX(':',@Keys);
		SET @Len = SUBSTRING(@Keys,1,@CharIndex-1);
		SET @Value = SUBSTRING(@Values,@StartIndex+1,@Len);
		
		RETURN @Value;
		END
		";
		
		return $query;
	}
	
	private function drop_function_metaparse() {
		
		$query = 
		"
		IF  EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'[$this->dbSchema].[FetchExtendendAttributeValue]') AND type in (N'FN', N'IF', N'TF', N'FS', N'FT'))
		DROP FUNCTION [$this->dbSchema].[FetchExtendendAttributeValue]
		";
		
		return $query;
	}

	private function build_query_posts() {

		$query = 
		"
		DECLARE @xmlCategories VARCHAR(MAX);
		SET @xmlCategories = 
		(
			SELECT * FROM 
			(
				SELECT DISTINCT
					1 AS Tag, 
					NULL AS Parent, 
					category.[Name] AS [category!1!name],
					NULL AS [category!2!name]
				FROM dbo.cs_Post_Categories category
				WHERE category.ParentId = 0
				
				UNION ALL
				
				SELECT DISTINCT
					2 AS Tag, 
					1 AS Parent,
					parent.[Name],
					child.[Name]
				FROM dbo.cs_Post_Categories child
				INNER JOIN dbo.cs_Post_Categories parent ON child.ParentId = parent.CategoryId
				WHERE child.ParentId != 0
				
			) AS [xmloutput]
			
			ORDER BY [category!1!name], Tag
			FOR XML EXPLICIT, ROOT('categories')
		);
		
		DECLARE @xmlPosts VARCHAR(MAX);
		SET @xmlPosts = 
		(
			SELECT * FROM
			(	
				SELECT
					1 AS Tag, 
					NULL AS Parent, 
					item.PostID AS [item!1!post_id!ELEMENT],
					item.Subject AS [item!1!title!ELEMENT],
					NULL AS [category!2!!ELEMENT],
					NULL AS [pubDate!3!post_date!ELEMENT],
					NULL AS [pubDate!3!post_date_gmt!ELEMENT],
					item.PostAuthor AS [item!1!creator!CDATA],
					item.Body AS [item!1!encoded!CDATA],
					CASE item.IsApproved WHEN 1 THEN 'publish' ELSE NULL END AS [item!1!status!ELEMENT],
					NULL AS [comment!4!comment_id!ELEMENT],
					NULL AS [comment!4!comment_date!ELEMENT],
					NULL AS [comment!4!comment_date_gmt!ELEMENT],
					NULL AS [comment!4!comment_author!ELEMENT],
					NULL AS [comment!4!comment_author_url!ELEMENT],
					NULL AS [comment!4!comment_author_ip!ELEMENT],
					NULL AS [comment!4!comment_content!CDATA],
					NULL AS [comment!4!comment_parent!ELEMENT],
					NULL AS [comment!4!comment_approved!ELEMENT]
				FROM $this->dbSchema.cs_Posts item 
				WHERE item.sectionID = 3 AND
				item.postlevel = 1
				
				UNION ALL
				
				SELECT
					2 AS Tag,
					1 AS Parent,
					item.PostID,
					item.Subject,
					category.[Name],
					NULL,
					NULL,
					item.PostAuthor,
					item.Body,
					CASE item.IsApproved WHEN 1 THEN 'publish' ELSE NULL END,
					NULL,
					NULL,
					NULL,
					NULL,
					NULL,
					NULL,
					NULL,
					NULL,
					NULL
				FROM   $this->dbSchema.cs_Posts item 
				INNER JOIN $this->dbSchema.cs_Posts_InCategories catitem ON catitem.PostId = item.PostId
				INNER JOIN $this->dbSchema.cs_Post_Categories category ON category.CategoryId = catitem.CategoryId
				WHERE item.sectionID = 3 AND
				item.postlevel = 1
				
				UNION ALL
				
				SELECT
					3 AS Tag,
					1 AS Parent,
					item.PostID,
					item.Subject,
					NULL,
					CONVERT(VARCHAR(19), item.PostDate, 120),
					CONVERT(VARCHAR(19), DATEADD(Hour, DATEDIFF(Hour, GETUTCDATE(), GETDATE()), item.PostDate), 120),
					item.PostAuthor,
					item.Body,
					CASE item.IsApproved WHEN 1 THEN 'publish' ELSE NULL END,
					NULL,
					NULL,
					NULL,
					NULL,
					NULL,
					NULL,
					NULL,
					NULL,
					NULL
				FROM   $this->dbSchema.cs_Posts item 
				WHERE item.sectionID = 3 AND
				item.postlevel = 1
				
				UNION ALL
				
				SELECT
					4 AS Tag,
					1 AS Parent,
					item.PostID,
					item.Subject,
					NULL,
					CONVERT(VARCHAR(19), item.PostDate, 120),
					CONVERT(VARCHAR(19), DATEADD(Hour, DATEDIFF(Hour, GETUTCDATE(), GETDATE()), item.PostDate), 120),
					item.PostAuthor,
					item.Body,
					CASE item.IsApproved WHEN 1 THEN 'publish' ELSE NULL END,
					comment.PostID,
					CONVERT(VARCHAR(19), comment.PostDate, 120),
					CONVERT(VARCHAR(19), DATEADD(Hour, DATEDIFF(Hour, GETUTCDATE(), GETDATE()), comment.PostDate), 120),
					$this->dbSchema.FetchExtendendAttributeValue('SubmittedUserName', comment.PropertyNames, comment.PropertyValues),
					$this->dbSchema.FetchExtendendAttributeValue('TitleUrl', comment.PropertyNames, comment.PropertyValues),
					comment.IPAddress,
					comment.Body,
					comment.ParentID,
					comment.IsApproved
				FROM $this->dbSchema.cs_Posts item 
				INNER JOIN $this->dbSchema.cs_Posts comment ON comment.ParentId = item.PostID 
				WHERE comment.sectionid = 3 AND
				comment.postlevel = 2 AND
				comment.posttype = 1 AND
				comment.applicationposttype <> 8
			
			) AS [xmloutput]
			
			ORDER BY [item!1!post_id!ELEMENT], Tag
			FOR XML EXPLICIT, ROOT('channel')
		);
		SELECT @xmlPosts, @xmlCategories;
		";
		
		return $query;
	}
	
	private function build_xsl() {
		
		$xsl = 
		'
			<xsl:stylesheet version="1.0" xmlns:xsl="http://www.w3.org/1999/XSL/Transform" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:wfw="http://wellformedweb.org/CommentAPI/" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:wp="http://wordpress.org/export/1.0/">
				<xsl:output method="xml" encoding="ISO-8859-1" cdata-section-elements="dc:creator content:encoded wp:comment_content wp:cat_name wp:tag_name category" />
				
				<xsl:variable name="lcletters">abcdefghijklmnopqrstuvwxyz</xsl:variable>
				<xsl:variable name="ucletters">ABCDEFGHIJKLMNOPQRSTUVWXYZ</xsl:variable>
				
				<xsl:template match="/">
					<rss version="2.0" 
						xmlns:content="http://purl.org/rss/1.0/modules/content/" 
						xmlns:wfw="http://wellformedweb.org/CommentAPI/" 
						xmlns:dc="http://purl.org/dc/elements/1.1/" 
						xmlns:wp="http://wordpress.org/export/1.0/">
						<xsl:call-template name="channels" />
					</rss>
				</xsl:template>
				
				<xsl:template name="category_declarations">
					<xsl:for-each select="./categories//*">
						<wp:category>
							<wp:category_nicename><xsl:value-of select="@name" /></wp:category_nicename>
							<wp:category_parent><xsl:value-of select="../@name" /></wp:category_parent>
							<wp:cat_name><xsl:value-of select="@name" /></wp:cat_name>
						</wp:category>
						<wp:tag>
							<wp:tag_slug><xsl:value-of select="translate(@name, $ucletters, $lcletters)" /></wp:tag_slug>
							<wp:tag_name><xsl:value-of select="@name" /></wp:tag_name>
						</wp:tag>
					</xsl:for-each>
				</xsl:template>
				
				<xsl:template name="channels">
					<xsl:for-each select="channel">
						<channel>
							<title>' . get_bloginfo() . '</title>
						    <link>' . get_bloginfo('url') . '</link>
						    <description>' . get_bloginfo('description') . '</description>
						    <pubDate>' . date('Y-m-d H:i:s') . '</pubDate>
						    <generator>bettersoftwarenow.com CS-WP Importer</generator>
						    <language>en</language>
						    <wp:wxr_version>1.0</wp:wxr_version>
						    <wp:base_site_url>' . get_bloginfo('url') . '</wp:base_site_url>
						    <wp:base_blog_url>' . get_bloginfo('wpurl') . '</wp:base_blog_url>
						    <xsl:call-template name="category_declarations" />
						    <xsl:call-template name="items" />
					    </channel>
					</xsl:for-each>
				</xsl:template>
				
				<xsl:template name="items">
					<xsl:for-each select="item">
						<item>
							<wp:post_id><xsl:value-of select="post_id" /></wp:post_id>
							<title><xsl:value-of select="title" /></title>
					      	<dc:creator><xsl:value-of select="creator" /></dc:creator>
					      	<content:encoded>
					      		<xsl:value-of select="encoded" />
					      	</content:encoded>
					      	<wp:status><xsl:value-of select="status" /></wp:status>
					      	<pubDate>
					        	<wp:post_date><xsl:value-of select="./pubDate/post_date" /></wp:post_date>
					        	<wp:post_date_gmt><xsl:value-of select="./pubDate/post_date_gmt" /></wp:post_date_gmt>
					      	</pubDate>
					      	<xsl:call-template name="categories" />
					      	<xsl:call-template name="comments" />
						</item>
					</xsl:for-each>
				</xsl:template>
				
				<xsl:template name="categories">
					<xsl:for-each select="category">
						<category><xsl:value-of select="." /></category>
						<category domain="category">
							<xsl:attribute name="nicename">
								<xsl:value-of select="translate(., $ucletters, $lcletters)"/>
							</xsl:attribute>
							<xsl:value-of select="." />
						</category>
						<category domain="tag"><xsl:value-of select="." /></category>
					</xsl:for-each>
				</xsl:template>
				
				<xsl:template name="comments">
					<xsl:for-each select="comment">
						<wp:comment>
					    	<wp:comment_id><xsl:value-of select="comment_id" /></wp:comment_id>
					        <wp:comment_date><xsl:value-of select="comment_date" /></wp:comment_date>
					        <wp:comment_date_gmt><xsl:value-of select="comment_date_gmt" /></wp:comment_date_gmt>
					        <wp:comment_author><xsl:value-of select="comment_author" /></wp:comment_author>
					        <wp:comment_author_url><xsl:value-of select="comment_author_url" /></wp:comment_author_url>
					        <wp:comment_author_ip><xsl:value-of select="comment_author_ip" /></wp:comment_author_ip>
					        <wp:comment_content><xsl:value-of select="comment_content" /></wp:comment_content>
					        <wp:comment_parent><xsl:value-of select="comment_parent" /></wp:comment_parent>
					        <wp:comment_approved><xsl:value-of select="comment_approved" /></wp:comment_approved>
					    </wp:comment>
					</xsl:for-each>
				</xsl:template>
				
			</xsl:stylesheet>
		';
		
		$xslDoc = DOMDocument::loadXML($xsl);
		
		return $xslDoc;
	}
	
	private function query_cs_data() {
		
		if (!$this->dbName) {
			$this->report('<p style="color:#ff0000;font-weight:bold;">ERROR: A valid database name was not provided!</p>');
			return false;
		}
		
		if (!$this->dbUser) {
			$this->report('<p style="color:#ff0000;font-weight:bold;">ERROR: Database username was not provided!</p>');
			return false;
		}
		
		if (!$this->dbPassword) {
			$this->report('<p style="color:#ff0000;font-weight:bold;">ERROR: Database password was not provided!</p>');
			return false;
		}
		
		if (USE_NATIVE_SQL) {
			$xmlrslts = $this->exec_mssql_query();	
	 	}
	 	else {
	 		$xmlrslts = $this->exec_odbc_query();
	 	}
	 	
		if (!$xmlrslts) {
			$this->report('<p style="color:#ff0000;font-weight:bold;">ERROR: No CS records were found in the database.</p>');
			return false;
		}
		
		$xmlDoc = new DOMDocument('1.0', 'ISO-8859-1');
		$xmlDoc->formatOutput = true;
		
		$xmlDoc->loadXML(mb_convert_encoding($xmlrslts[0], 'ISO-8859-1'));
		if (!$xmlDoc) {
			$this->report('<p style="color:#ff0000;font-weight:bold;">ERROR: XML could not be generated from query output.</p>');
			return false;
		}
		
		// inject the category information
		$catfrag = $xmlDoc->createDocumentFragment();
		$catfrag->appendXML(mb_convert_encoding($xmlrslts[1], 'ISO-8859-1'));
		if (!$catfrag) {
			$this->report('<p style="color:#ff0000;font-weight:bold;">ERROR: XML could not be generated from query output.</p>');
			return false;
		}
		
		// get ref to <channel> node and append
		$chanNodeList = $xmlDoc->getElementsByTagName('channel');
		$chanNode = $chanNodeList->item(0);
		$chanNode->appendChild($catfrag);
		
		$this->report("<p>Sucessfully queried the CS database at $this->dbHost</p>");
		
		return $xmlDoc;
	}
	
	private function exec_mssql_query() {
		
		set_magic_quotes_runtime(0);
			
		$oldtextlimit = ini_get('mssql.textlimit');
		$oldtextsize = ini_get('mssql.textsize');
		
		ini_set('mssql.textlimit', '2147483647');
		ini_set('mssql.textsize', '2147483647');
		
		$csdb = mssql_connect($this->dbHost . ',' . $this->dbPort, $this->dbUser, $this->dbPassword);
		
		if (!$csdb) {
			$this->report("<p style=\"color:#ff0000;font-weight:bold;\">ERROR: Could not connect to the specified SQL server at <b>$this->dbHost</b>. Check your connection details and try again.</p>");
	 		return false;
	 	}
	 	
	 	if (!mssql_select_db($this->dbName, $csdb)) {
	 		$this->report("<p style=\"color:#ff0000;font-weight:bold;\">ERROR: Could not connect to the specified database at <b>$this->dbName</b>. Check your connection details and try again.</p>");
	 		return false;
	 	}
	 	
	 	$query = 'BEGIN TRANSACTION';
	 	mssql_query($query, $csdb);
	 	
	 	$query = $this->drop_function_metaparse();
	 	mssql_query($query, $csdb);
	 	
	 	$query = $this->build_function_metaparse();
	 	mssql_query($query, $csdb);
	 	
	 	$query = $this->build_query_posts();
		$rslt = mssql_query($query, $csdb);
		
		if (!$rslt) {
			
			$this->report('<p style="color:#ff0000;font-weight:bold;">ERROR: No CS records were found in the database. Transaction was rolled back.</p>');
			
			$query = 'ROLLBACK TRANSACTION';
			mssql_query($query, $csdb);
			mssql_close($csdb);
			
			return false;
		}
		
		$query = $this->drop_function_metaparse();
	 	mssql_query($query, $csdb);
		
		$query = 'COMMIT TRANSACTION';
		mssql_query($query, $csdb);
		
		// note that there will only be one result from our query
		$xmlrslts = mssql_fetch_row($rslt);
		
		mssql_close($csdb);
		
		ini_set('mssql.textlimit', $oldtextlimit);
		ini_set('mssql.textsize', $oldtextsize);
		
		return $xmlrslts;		
	}
	
	private function exec_odbc_query() {
		
		die("Not implemented.");
	}

	private function translate_to_wxr($unformattedXml) {
		
		$xslt = new XSLTProcessor();
		$xslt->importStylesheet($this->build_xsl());
		$rslt = $xslt->transformToXml($unformattedXml);
		
		$rsltDoc = DOMDocument::loadXML($rslt);
		$rsltDoc->formatOutput = true;
		
		$this->report('<p>Sucessfully translated CS export XML to WXR format</p>');
		
		return $rsltDoc;
	}
	
	private function persist_export($formattedXml) {
		
		$filewithpath = '..\\' . CSImportHelper::OUT_FILE_NAME;
		
		if (file_exists($filewithpath)) {
			unlink($filewithpath);
		}
		
		if (!$formattedXml->save($filewithpath)) {
			$this->report("<p style=\"color:#ff0000;font-weight:bold;\">ERROR: Could not write to output to: $this->get_current_url()" . "Make sure your PHP installation has write access to the WP installation folder.</p>");
			return false;
		}
		
		$this->report('<p>Sucessfully wrote CS export file to: ' . CSImportHelper::get_export_url() . '</p>');
		
		return $filewithpath;
	}
	
	private function report($msg) {
		
		if (CS_DEBUG) {
			echo $msg;
		}
	}
}

class WPImportHelper {
	
	var $post_ids_processed = array ();
	var $orphans = array ();
	var $file;
	var $mtnames = array ();
	var $newauthornames = array ();
	var $allauthornames = array ();

	var $author_ids = array ();
	var $tags = array ();
	var $categories = array ();

	var $j = -1;
	var $fetch_attachments = false;
	var $url_remap = array ();
	
	function unhtmlentities($string) { // From php.net for < 4.3 compat
		$trans_tbl = get_html_translation_table(HTML_ENTITIES);
		$trans_tbl = array_flip($trans_tbl);
		return strtr($string, $trans_tbl);
	}

	function get_tag( $string, $tag ) {
		global $wpdb;
		preg_match("|<$tag.*?>(.*?)</$tag>|is", $string, $return);
		$return = preg_replace('|^<!\[CDATA\[(.*)\]\]>$|s', '$1', $return[1]);
		$return = $wpdb->escape( trim( $return ) );
		return $return;
	}

	function has_gzip() {
		return is_callable('gzopen');
	}

	function fopen($filename, $mode='r') {
		if ( $this->has_gzip() )
			return gzopen($filename, $mode);
		return fopen($filename, $mode);
	}

	function feof($fp) {
		if ( $this->has_gzip() )
			return gzeof($fp);
		return feof($fp);
	}

	function fgets($fp, $len=8192) {
		if ( $this->has_gzip() )
			return gzgets($fp, $len);
		return fgets($fp, $len);
	}

	function fclose($fp) {
		if ( $this->has_gzip() )
			return gzclose($fp);
		return fclose($fp);
	}

	function get_entries($process_post_func=NULL) {
		set_magic_quotes_runtime(0);

		$doing_entry = false;
		$is_wxr_file = false;

		$fp = $this->fopen($this->file, 'r');
		if ($fp) {
			while ( !$this->feof($fp) ) {
				$importline = rtrim($this->fgets($fp));

				// this doesn't check that the file is perfectly valid but will at least confirm that it's not the wrong format altogether
				if ( !$is_wxr_file && preg_match('|xmlns:wp="http://wordpress[.]org/export/\d+[.]\d+/"|', $importline) )
					$is_wxr_file = true;

				if ( false !== strpos($importline, '<wp:category>') ) {
					preg_match('|<wp:category>(.*?)</wp:category>|is', $importline, $category);
					$this->categories[] = $category[1];
					continue;
				}
				if ( false !== strpos($importline, '<wp:tag>') ) {
					preg_match('|<wp:tag>(.*?)</wp:tag>|is', $importline, $tag);
					$this->tags[] = $tag[1];
					continue;
				}
				if ( false !== strpos($importline, '<item>') ) {
					$this->post = '';
					$doing_entry = true;
					continue;
				}
				if ( false !== strpos($importline, '</item>') ) {
					$doing_entry = false;
					if ($process_post_func)
						call_user_func($process_post_func, $this->post);
					continue;
				}
				if ( $doing_entry ) {
					$this->post .= $importline . "\n";
				}
			}

			$this->fclose($fp);
		}

		return $is_wxr_file;

	}

	function get_wp_authors() {
		// We need to find unique values of author names, while preserving the order, so this function emulates the unique_value(); php function, without the sorting.
		$temp = $this->allauthornames;
		$authors[0] = array_shift($temp);
		$y = count($temp) + 1;
		for ($x = 1; $x < $y; $x ++) {
			$next = array_shift($temp);
			if (!(in_array($next, $authors)))
				array_push($authors, "$next");
		}

		return $authors;
	}

	function get_authors_from_post() {
		global $current_user;

		// this will populate $this->author_ids with a list of author_names => user_ids

		foreach ( $_POST['author_in'] as $i => $in_author_name ) {

			if ( !empty($_POST['user_select'][$i]) ) {
				// an existing user was selected in the dropdown list
				$user = get_userdata( intval($_POST['user_select'][$i]) );
				if ( isset($user->ID) )
					$this->author_ids[$in_author_name] = $user->ID;
			}
			elseif ( $this->allow_create_users() ) {
				// nothing was selected in the dropdown list, so we'll use the name in the text field

				$new_author_name = trim($_POST['user_create'][$i]);
				// if the user didn't enter a name, assume they want to use the same name as in the import file
				if ( empty($new_author_name) )
					$new_author_name = $in_author_name;

				$user_id = username_exists($new_author_name);
				if ( !$user_id ) {
					$user_id = wp_create_user($new_author_name, wp_generate_password());
				}

				$this->author_ids[$in_author_name] = $user_id;
			}

			// failsafe: if the user_id was invalid, default to the current user
			if ( empty($this->author_ids[$in_author_name]) ) {
				$this->author_ids[$in_author_name] = intval($current_user->ID);
			}
		}

	}

	function wp_authors_form() {
?>
<p><?php _e('To make it easier for you to edit and save the imported posts and drafts, you may want to change the name of the author of the posts. For example, you may want to import all the entries as <code>admin</code>s entries.'); ?></p>
<?php
	if ( $this->allow_create_users() ) {
		echo '<p>'.__('If a new user is created by WordPress, a password will be randomly generated. Manually change the user\'s details if necessary.')."</p>\n";
	}


		$authors = $this->get_wp_authors();
		echo '<ol id="authors">';
		echo '<form action="?import=communityserver&amp;step=3&amp;id=' . $this->id . '" method="post">';
		wp_nonce_field('import-communityserver');
		$j = -1;
		foreach ($authors as $author) {
			++ $j;
			echo '<li>'.__('Import author:').' <strong>'.$author.'</strong><br />';
			$this->users_form($j, $author);
			echo '</li>';
		}

		if ( $this->allow_fetch_attachments() ) {
?>
</ol>
<p>
	<br /><br />
	<input type="checkbox" value="1" name="attachments" id="import-attachments" />
	<label for="import-attachments"><?php _e('Download and import file attachments') ?></label>
</p>

<?php
		}

		echo '<p class="submit"><input type="submit" value="'.attribute_escape( __('Finish!') ).'">'.'</p>';
		echo '</form>';

	}

	function users_form($n, $author) {

		if ( $this->allow_create_users() ) {
			printf('<label>'.__('Create user %1$s or map to existing'), ' <input type="text" value="'.$author.'" name="'.'user_create['.intval($n).']'.'" maxlength="30"></label> <br />');
		}
		else {
			echo __('Map to existing').'<br />';
		}

		// keep track of $n => $author name
		echo '<input type="hidden" name="author_in['.intval($n).']" value="'.htmlspecialchars($author).'" />';

		$users = get_users_of_blog();
?><select name="user_select[<?php echo $n; ?>]">
	<option value="0"><?php _e('- Select -'); ?></option>
	<?php
		foreach ($users as $user) {
			echo '<option value="'.$user->user_id.'">'.$user->user_login.'</option>';
		}
?>
	</select>
	<?php
	}

	function select_authors() {
		$is_wxr_file = $this->get_entries(array(&$this, 'process_author'));
		if ( $is_wxr_file ) {
			$this->wp_authors_form();
		}
		else {
			echo '<p style="color:#ff0000;font-weight:bold;">ERROR: Please upload a valid WXR (WordPress eXtended RSS) export file.</p>';
		}
	}

	// fetch the user ID for a given author name, respecting the mapping preferences
	function checkauthor($author) {
		global $current_user;

		if ( !empty($this->author_ids[$author]) )
			return $this->author_ids[$author];

		// failsafe: map to the current user
		return $current_user->ID;
	}

	function process_categories() {
		global $wpdb;

		$cat_names = (array) get_terms('category', 'fields=names');

		while ( $c = array_shift($this->categories) ) {
			$cat_name = trim($this->get_tag( $c, 'wp:cat_name' ));

			// If the category exists we leave it alone
			if ( in_array($cat_name, $cat_names) )
				continue;

			$category_nicename	= $this->get_tag( $c, 'wp:category_nicename' );
			$posts_private		= (int) $this->get_tag( $c, 'wp:posts_private' );
			$links_private		= (int) $this->get_tag( $c, 'wp:links_private' );

			$parent = $this->get_tag( $c, 'wp:category_parent' );

			if ( empty($parent) )
				$category_parent = '0';
			else
				$category_parent = category_exists($parent);

			$catarr = compact('category_nicename', 'category_parent', 'posts_private', 'links_private', 'posts_private', 'cat_name');

			$cat_ID = wp_insert_category($catarr);
		}
	}

	function process_tags() {
		global $wpdb;

		$tag_names = (array) get_terms('post_tag', 'fields=names');

		while ( $c = array_shift($this->tags) ) {
			$tag_name = trim($this->get_tag( $c, 'wp:tag_name' ));

			// If the category exists we leave it alone
			if ( in_array($tag_name, $tag_names) )
				continue;

			$slug = $this->get_tag( $c, 'wp:tag_slug' );
			$description = $this->get_tag( $c, 'wp:tag_description' );

			$tagarr = compact('slug', 'description');

			$tag_ID = wp_insert_term($tag_name, 'post_tag', $tagarr);
		}
	}

	function process_author($post) {
		$author = $this->get_tag( $post, 'dc:creator' );
		if ($author)
			$this->allauthornames[] = $author;
	}

	function process_posts() {
		$i = -1;
		echo '<ol>';

		$this->get_entries(array(&$this, 'process_post'));

		echo '</ol>';

		wp_import_cleanup($this->id);
		do_action('import_done', 'wordpress');

		echo '<h3>'.sprintf(__('All done.').' <a href="%s">'.__('Have fun!').'</a>', get_option('home')).'</h3>';
	}

	function process_post($post) {
		global $wpdb;

		$post_ID = (int) $this->get_tag( $post, 'wp:post_id' );
  		if ( $post_ID && !empty($this->post_ids_processed[$post_ID]) ) // Processed already
			return 0;
		
		set_time_limit( 60 );

		// There are only ever one of these
		$post_title     = $this->get_tag( $post, 'title' );
		$post_date      = $this->get_tag( $post, 'wp:post_date' );
		$post_date_gmt  = $this->get_tag( $post, 'wp:post_date_gmt' );
		$comment_status = $this->get_tag( $post, 'wp:comment_status' );
		$ping_status    = $this->get_tag( $post, 'wp:ping_status' );
		$post_status    = $this->get_tag( $post, 'wp:status' );
		$post_name      = $this->get_tag( $post, 'wp:post_name' );
		$post_parent    = $this->get_tag( $post, 'wp:post_parent' );
		$menu_order     = $this->get_tag( $post, 'wp:menu_order' );
		$post_type      = $this->get_tag( $post, 'wp:post_type' );
		$post_password  = $this->get_tag( $post, 'wp:post_password' );
		$guid           = $this->get_tag( $post, 'guid' );
		$post_author    = $this->get_tag( $post, 'dc:creator' );

		$post_excerpt = $this->get_tag( $post, 'excerpt:encoded' );
		$post_excerpt = preg_replace('|<(/?[A-Z]+)|e', "'<' . strtolower('$1')", $post_excerpt);
		$post_excerpt = str_replace('<br>', '<br />', $post_excerpt);
		$post_excerpt = str_replace('<hr>', '<hr />', $post_excerpt);

		$post_content = $this->get_tag( $post, 'content:encoded' );
		$post_content = preg_replace('|<(/?[A-Z]+)|e', "'<' . strtolower('$1')", $post_content);
		$post_content = str_replace('<br>', '<br />', $post_content);
		$post_content = str_replace('<hr>', '<hr />', $post_content);

		preg_match_all('|<category domain="tag">(.*?)</category>|is', $post, $tags);
		$tags = $tags[1];

		$tag_index = 0;
		foreach ($tags as $tag) {
			$tags[$tag_index] = $wpdb->escape($this->unhtmlentities(str_replace(array ('<![CDATA[', ']]>'), '', $tag)));
			$tag_index++;
		}

		preg_match_all('|<category>(.*?)</category>|is', $post, $categories);
		$categories = $categories[1];

		$cat_index = 0;
		foreach ($categories as $category) {
			$categories[$cat_index] = $wpdb->escape($this->unhtmlentities(str_replace(array ('<![CDATA[', ']]>'), '', $category)));
			$cat_index++;
		}

		$post_exists = post_exists($post_title, '', $post_date);

		if ( $post_exists ) {
			echo '<li>';
			printf(__('Post <em>%s</em> already exists.'), stripslashes($post_title));
		} else {

			// If it has parent, process parent first.
			$post_parent = (int) $post_parent;
			if ($post_parent) {
				// if we already know the parent, map it to the local ID
				if ( $parent = $this->post_ids_processed[$post_parent] ) {
					$post_parent = $parent;  // new ID of the parent
				}
				else {
					// record the parent for later
					$this->orphans[intval($post_ID)] = $post_parent;
				}
			}

			echo '<li>';

			$post_author = $this->checkauthor($post_author); //just so that if a post already exists, new users are not created by checkauthor

			$postdata = compact('post_author', 'post_date', 'post_date_gmt', 'post_content', 'post_excerpt', 'post_title', 'post_status', 'post_name', 'comment_status', 'ping_status', 'guid', 'post_parent', 'menu_order', 'post_type', 'post_password');
			if ($post_type == 'attachment') {
				$remote_url = $this->get_tag( $post, 'wp:attachment_url' );
				if ( !$remote_url )
					$remote_url = $guid;

				$comment_post_ID = $post_id = $this->process_attachment($postdata, $remote_url);
				if ( !$post_id or is_wp_error($post_id) )
					return $post_id;
			}
			else {
				printf(__('Importing post <em>%s</em>...'), stripslashes($post_title));
				$comment_post_ID = $post_id = wp_insert_post($postdata);
			}

			if ( is_wp_error( $post_id ) )
				return $post_id;

			// Memorize old and new ID.
			if ( $post_id && $post_ID ) {
				$this->post_ids_processed[intval($post_ID)] = intval($post_id);
			}

			// Add categories.
			if (count($categories) > 0) {
				$post_cats = array();
				foreach ($categories as $category) {
					$slug = sanitize_term_field('slug', $category, 0, 'category', 'db');
					$cat = get_term_by('slug', $slug, 'category');
					$cat_ID = 0;
					if ( ! empty($cat) )
						$cat_ID = $cat->term_id;
					if ($cat_ID == 0) {
						$category = $wpdb->escape($category);
						$cat_ID = wp_insert_category(array('cat_name' => $category));
					}
					$post_cats[] = $cat_ID;
				}
				wp_set_post_categories($post_id, $post_cats);
			}

			// Add tags.
			if (count($tags) > 0) {
				$post_tags = array();
				foreach ($tags as $tag) {
					$slug = sanitize_term_field('slug', $tag, 0, 'post_tag', 'db');
					$tag_obj = get_term_by('slug', $slug, 'post_tag');
					$tag_id = 0;
					if ( ! empty($tag_obj) )
						$tag_id = $tag_obj->term_id;
					if ( $tag_id == 0 ) {
						$tag = $wpdb->escape($tag);
						$tag_id = wp_insert_term($tag, 'post_tag');
						$tag_id = $tag_id['term_id'];
					}
					$post_tags[] = intval($tag_id);
				}
				wp_set_post_tags($post_id, $post_tags);
			}
		}

		// Now for comments
		preg_match_all('|<wp:comment>(.*?)</wp:comment>|is', $post, $comments);
		$comments = $comments[1];
		$num_comments = 0;
		if ( $comments) { foreach ($comments as $comment) {
			$comment_author       = $this->get_tag( $comment, 'wp:comment_author');
			$comment_author_email = $this->get_tag( $comment, 'wp:comment_author_email');
			$comment_author_IP    = $this->get_tag( $comment, 'wp:comment_author_IP');
			$comment_author_url   = $this->get_tag( $comment, 'wp:comment_author_url');
			$comment_date         = $this->get_tag( $comment, 'wp:comment_date');
			$comment_date_gmt     = $this->get_tag( $comment, 'wp:comment_date_gmt');
			$comment_content      = $this->get_tag( $comment, 'wp:comment_content');
			$comment_approved     = $this->get_tag( $comment, 'wp:comment_approved');
			$comment_type         = $this->get_tag( $comment, 'wp:comment_type');
			$comment_parent       = $this->get_tag( $comment, 'wp:comment_parent');

			// if this is a new post we can skip the comment_exists() check
			if ( !$post_exists || !comment_exists($comment_author, $comment_date) ) {
				$commentdata = compact('comment_post_ID', 'comment_author', 'comment_author_url', 'comment_author_email', 'comment_author_IP', 'comment_date', 'comment_date_gmt', 'comment_content', 'comment_approved', 'comment_type', 'comment_parent');
				wp_insert_comment($commentdata);
				$num_comments++;
			}
		} }

		if ( $num_comments )
			printf(' '.__ngettext('(%s comment)', '(%s comments)', $num_comments), $num_comments);

		// Now for post meta
		preg_match_all('|<wp:postmeta>(.*?)</wp:postmeta>|is', $post, $postmeta);
		$postmeta = $postmeta[1];
		if ( $postmeta) { foreach ($postmeta as $p) {
			$key   = $this->get_tag( $p, 'wp:meta_key' );
			$value = $this->get_tag( $p, 'wp:meta_value' );
			$value = stripslashes($value); // add_post_meta() will escape.

			$this->process_post_meta($post_id, $key, $value);

		} }

		do_action('import_post_added', $post_id);
		print "</li>\n";
	}

	function process_post_meta($post_id, $key, $value) {
		// the filter can return false to skip a particular metadata key
		$_key = apply_filters('import_post_meta_key', $key);
		if ( $_key ) {
			add_post_meta( $post_id, $_key, $value );
			do_action('import_post_meta', $post_id, $_key, $value);
		}
	}

	function process_attachment($postdata, $remote_url) {
		if ($this->fetch_attachments and $remote_url) {
			printf( __('Importing attachment <em>%s</em>... '), htmlspecialchars($remote_url) );
			$upload = $this->fetch_remote_file($postdata, $remote_url);
			if ( is_wp_error($upload) ) {
				printf( __('Remote file error: %s'), htmlspecialchars($upload->get_error_message()) );
				return $upload;
			}
			else {
				print '('.size_format(filesize($upload['file'])).')';
			}

			if ( $info = wp_check_filetype($upload['file']) ) {
				$postdata['post_mime_type'] = $info['type'];
			}
			else {
				print __('Invalid file type');
				return;
			}

			$postdata['guid'] = $upload['url'];

			// as per wp-admin/includes/upload.php
			$post_id = wp_insert_attachment($postdata, $upload['file']);
			wp_update_attachment_metadata( $post_id, wp_generate_attachment_metadata( $post_id, $upload['file'] ) );

			// remap the thumbnail url.  this isn't perfect because we're just guessing the original url.
			if ( preg_match('@^image/@', $info['type']) && $thumb_url = wp_get_attachment_thumb_url($post_id) ) {
				$parts = pathinfo($remote_url);
				$ext = $parts['extension'];
				$name = basename($parts['basename'], ".{$ext}");
				$this->url_remap[$parts['dirname'] . '/' . $name . '.thumbnail.' . $ext] = $thumb_url;
			}

			return $post_id;
		}
		else {
			printf( __('Skipping attachment <em>%s</em>'), htmlspecialchars($remote_url) );
		}
	}

	function fetch_remote_file($post, $url) {
		$upload = wp_upload_dir($post['post_date']);

		// extract the file name and extension from the url
		$file_name = basename($url);

		// get placeholder file in the upload dir with a unique sanitized filename
		$upload = wp_upload_bits( $file_name, 0, '', $post['post_date']);
		if ( $upload['error'] ) {
			echo $upload['error'];
			return new WP_Error( 'upload_dir_error', $upload['error'] );
		}

		// fetch the remote url and write it to the placeholder file
		$headers = wp_get_http($url, $upload['file']);

		// make sure the fetch was successful
		if ( $headers['response'] != '200' ) {
			@unlink($upload['file']);
			return new WP_Error( 'import_file_error', sprintf(__('Remote file returned error response %d'), intval($headers['response'])) );
		}
		elseif ( isset($headers['content-length']) && filesize($upload['file']) != $headers['content-length'] ) {
			@unlink($upload['file']);
			return new WP_Error( 'import_file_error', __('Remote file is incorrect size') );
		}

		$max_size = $this->max_attachment_size();
		if ( !empty($max_size) and filesize($upload['file']) > $max_size ) {
			@unlink($upload['file']);
			return new WP_Error( 'import_file_error', sprintf(__('Remote file is too large, limit is %s', size_format($max_size))) );
		}

		// keep track of the old and new urls so we can substitute them later
		$this->url_remap[$url] = $upload['url'];
		// if the remote url is redirected somewhere else, keep track of the destination too
		if ( $headers['x-final-location'] != $url )
			$this->url_remap[$headers['x-final-location']] = $upload['url'];

		return $upload;

	}

	// sort by strlen, longest string first
	function cmpr_strlen($a, $b) {
		return strlen($b) - strlen($a);
	}

	// update url references in post bodies to point to the new local files
	function backfill_attachment_urls() {

		// make sure we do the longest urls first, in case one is a substring of another
		uksort($this->url_remap, array(&$this, 'cmpr_strlen'));

		global $wpdb;
		foreach ($this->url_remap as $from_url => $to_url) {
			// remap urls in post_content
			$wpdb->query( $wpdb->prepare("UPDATE {$wpdb->posts} SET post_content = REPLACE(post_content, '%s', '%s')", $from_url, $to_url) );
			// remap enclosure urls
			$result = $wpdb->query( $wpdb->prepare("UPDATE {$wpdb->postmeta} SET meta_value = REPLACE(meta_value, '%s', '%s') WHERE meta_key='enclosure'", $from_url, $to_url) );
		}
	}

	// update the post_parent of orphans now that we know the local id's of all parents
	function backfill_parents() {
		global $wpdb;

		foreach ($this->orphans as $child_id => $parent_id) {
			$local_child_id = $this->post_ids_processed[$child_id];
			$local_parent_id = $this->post_ids_processed[$parent_id];
			if ($local_child_id and $local_parent_id) {
				$wpdb->query( $wpdb->prepare("UPDATE {$wpdb->posts} SET post_parent = %d WHERE ID = %d", $local_parent_id, $local_child_id));
			}
		}
	}

	function is_valid_meta_key($key) {
		// skip _wp_attached_file metadata since we'll regenerate it from scratch
		if ( $key == '_wp_attached_file' )
			return false;
		return $key;
	}

	// give the user the option of creating new users to represent authors in the import file?
	function allow_create_users() {
		return apply_filters('import_allow_create_users', true);
	}

	// give the user the option of downloading and importing attached files
	function allow_fetch_attachments() {
		return apply_filters('import_allow_fetch_attachments', true);
	}

	function max_attachment_size() {
		// can be overridden with a filter - 0 means no limit
		return apply_filters('import_attachment_size_limit', 0);
	}

	function import_start() {
		wp_defer_term_counting(true);
		wp_defer_comment_counting(true);
		do_action('import_start');
	}

	function import_end() {
		do_action('import_end');

		// clear the caches after backfilling
		foreach ($this->post_ids_processed as $post_id)
			clean_post_cache($post_id);

		wp_defer_term_counting(false);
		wp_defer_comment_counting(false);
	}

	function do_import($filePath) {
		
		$this->fetch_attachments = ($this->allow_fetch_attachments() && (bool) $fetch_attachments);
		add_filter('import_post_meta_key', array($this, 'is_valid_meta_key'));
		
		$this->file = $filePath;

		$this->import_start();
		$this->get_authors_from_post();
		$this->get_entries();
		$this->process_categories();
		$this->process_tags();
		$result = $this->process_posts();
		$this->backfill_parents();
		$this->backfill_attachment_urls();
		$this->import_end();

		if (is_wp_error( $result )) {
			return $result;
		}
	}
}

$cs_import = new CSImport();
register_importer('communityserver', 'Community Server', __('Import posts, comments, and categories from Community Server.'), array ($cs_import, 'dispatch'));

?>
