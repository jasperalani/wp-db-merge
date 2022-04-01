<?php

/**
 * merge.php
 * Imports a non-multisite wordpress database into a multisite wordpress database.
 *
 * By Jasper Alani <jasperalani@gmail.com> <github.com/jasperalani>
 *
 * Setup:
 * 1. Fill out $options['connection']
 * 2. Fill out $options['databases'] where single is a non-multisite wordpress database
 *    and multisite is a multisite wordpress database
 * 3. Create new site/blog using wp gui on the multisite, record the newly created blog ID
 * 4. Fill in $options['data']['blog_id'] with the previously recorded blog ID
 * 5. Fill out $options['data']['mariadb_extra_file'] with the path to your mariadb defaults extra file for authentication
 * 6. Set $options['data']['output_author_sql'] to true or false to force the sql that updates post_author to be written
 *    to a file for execution later.
 * 7. Set $options['data']['name'] to an arbitrary name.
 * 8. Run this file using the php executable
 */

global $options;
$options = [
	'connection' => [
		'host' => '127.0.0.1',
		'user' => 'root',
		'pass' => 'root'
	],
	'databases'  => [
		'single'    => 'single_db',
		'multisite' => 'multisite_db',
	],
	'data'       => [
		'name'               => 'combine_websites',
		'blog_id'            => 1,
		'mariadb_extra_file' => '~/.local.options',
		'output_author_sql'  => true,
	]
];

/**************** STOP EDITING HERE ****************/

/**
 * Process:
 * 1. Get all table names from db1
 * 2. Prefix table names with wp_1
 * 3. Import tables into db2
 * 4. Get all user ids
 * 5. Import each row from old wp_users table into new wp_users table with new id
 * 6. Record the new user id against the old user id
 * 7. Get all rows from wp_usermeta with the old user id
 * 8. Get all rows from wp_usermeta with old user id and import into new wp_usermeta table with new id
 * 9. Update wp_prefix_posts set post_author = new old where post_author = old id
 * 10. Insert usermeta on new id with value of old id
 */

function connectDB(): mysqli {
	global $options;
	$conn = new mysqli(
		$options['connection']['host'],
		$options['connection']['user'],
		$options['connection']['pass'],
	);

	if ( $conn->connect_error ) {
		die( "Connection failed: " . $conn->connect_error );
	}

	return $conn;
}

function query( $query ): ?array {
	global $db;
	$results = $db->query( $query );
	if ( ! $results ) {
		return [];
	}

	if ( $results->num_rows <= 0 ) {
		return [];
	}

	$return = [];
	foreach ( $results->fetch_all() as $result ) {
		if ( empty( $result[1] ) ) {
			array_push( $return, $result[0] );
		} else {
			array_push( $return, $result );
		}
	}

	return $return;
}

function use_new() {
	global $db, $options;
	$db->query( "use {$options['databases']['multisite']};" );
}

function use_old() {
	global $db, $options;
	$db->query( "use {$options['databases']['single']};" );
}

function mysqldump( $database, $table_names, $output_file ) {
	global $options;
	exec( "mysqldump --defaults-extra-file='{$options['data']['mariadb_extra_file']}' --host='{$options['connection']['host']}' $database $table_names > $output_file" );
}

function sed( $search, $replace, $input_file ) {
	exec( "sed -i 's/$search/$replace/g' $input_file" );
}

function mv( $file, $rename ) {
	exec( "mv $file $rename" );
}

function mysqlimport( $database, $file ) {
	global $options;
	exec( "mysql --defaults-extra-file='{$options['data']['mariadb_extra_file']}' --host='{$options['connection']['host']}' $database < $file" );
}

function delete_file( $file ) {
	if ( file_exists( $file ) ) {
		unlink( $file );
	}
}

global $db;
$db = connectDB();

/***** Code below this line *****/

$tmp_dir = getcwd() . '/tmp';
mkdir( $tmp_dir );

echo "Exporting and importing wordpress database tables...\n";

$get_table_names = <<<SQL
SELECT TABLE_NAME
FROM INFORMATION_SCHEMA.TABLES
WHERE TABLE_NAME LIKE 'wp%'
  AND TABLE_NAME NOT LIKE 'wp_users'
  AND TABLE_NAME NOT LIKE 'wp_usermeta'
  AND TABLE_SCHEMA = '{$options['databases']['single']}';
SQL;

$table_names     = query( $get_table_names );
$old_table_names = $table_names;

foreach ( $table_names as $key => $table_name ) {
	$prefixed_table_name = str_replace( 'wp_', "wp_{$options['data']['blog_id']}_", $table_name );

	$sql_file          = getcwd() . "/tmp/{$table_name}.sql";
	$prefixed_sql_file = getcwd() . "/tmp/{$prefixed_table_name}.sql";

	echo "Dumping: $table_name\n";

	mysqldump(
		$options['databases']['single'],
		$table_name,
		$sql_file
	);

	sed(
		$table_name,
		$prefixed_table_name,
		$sql_file
	);

	mv(
		$sql_file,
		$prefixed_sql_file
	);

	echo "Importing: $prefixed_table_name\n";

	mysqlimport(
		$options['databases']['multisite'],
		$prefixed_sql_file
	);

	delete_file( $prefixed_sql_file );
}

rmdir( $tmp_dir );

use_old();

echo "Exporting and importing wp_users and wp_usermeta tables...\n";

$user_ids = <<<SQL
SELECT * FROM wp_users;
SQL;

$results = query( $user_ids );

use_new();

$id_array = [];

foreach ( $results as $result ) {
	$old_user_id = $result[0];

	$insert = <<<SQL
	INSERT INTO wp_users
	(user_login,
	 user_pass,
	 user_nicename,
	 user_email,
	 user_url,
	 user_registered,
	 user_activation_key,
	 user_status,
	 display_name)
	VALUES ('{$result[1]}',
	        '{$result[2]}',
	        '{$result[3]}',
	        '{$result[4]}',
	        '{$result[5]}',
	        '{$result[6]}',
	        '{$result[7]}',
	        {$result[8]},
	        '{$db->escape_string( $result[9] )}');
SQL;

	$db->query( $insert );

	$new_user_id = $db->insert_id;

	array_push( $id_array,
	            [
		            'old' => $old_user_id,
		            'new' => $new_user_id,
	            ] );

	use_old();

	$usermeta = <<<SQL
SELECT * FROM wp_usermeta WHERE user_id = {$old_user_id}
SQL;

	$usermeta = query( $usermeta );

	if ( empty( $usermeta ) ) {
		continue;
	}

	use_new();

	foreach ( $usermeta as $umeta ) {
		$insert = <<<SQL
		INSERT INTO wp_usermeta
		(user_id,
		 meta_key,
		 meta_value)
		VALUES ({$new_user_id},
		        '{$umeta[2]}',
		        '{$umeta[3]}');
		SQL;
		$db->query( $insert );
	}
}

echo "Relinking authors in posts table...\n";

$update_post_author_file = getcwd() . '/update_post_author.sql';
delete_file( $update_post_author_file );

foreach ( $id_array as $ids ) {
	$update = <<<SQL
	UPDATE wp_{$options['data']['blog_id']}_posts
	SET post_author = {$ids['new']}
	WHERE post_author = {$ids['old']};\n
	SQL;

	if ( $options['data']['output_author_sql'] ) {
		file_put_contents( $update_post_author_file, $update, FILE_APPEND );
	} else {
		$db->query( $update );
	}
}

echo "Saving old user id as usermeta...\n";

foreach ( $id_array as $ids ) {
	$insert = <<<SQL
	INSERT INTO wp_usermeta
	(user_id,
	 meta_key,
	 meta_value)
	VALUES ({$ids['new']},
	        '{$options['data']['name']}_merge_old_user_id',
	        '{$ids['old']}');
	SQL;

	$db->query( $insert );
}

echo "Finished!\n";

