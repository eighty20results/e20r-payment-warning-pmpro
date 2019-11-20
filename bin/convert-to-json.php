<?php

if ( ! isset( $argv[1] ) ) {
	die( 'Need to specify file containing API list' );
}

$name_info = explode( '.txt', $argv[1] );

if ( empty( $name_info ) ) {
	die( "{$argv[1]} doesn't have a .txt file extension!" );
}

$json_file_name = "{$name_info[0]}.json";
$file_name      = $argv[1];

if ( ! file_exists( $file_name ) ) {
	die( sprintf( 'The %s file isn\'t found in the bin/api_files/ directory', $argv[1] ) );
}

if ( false === ( $fh = fopen( $file_name, 'r' ) ) ) {
	die( sprintf( "Cannot open %s", $file_name ) );
}

$api_version_list = array();

while ( ! feof( $fh ) ) {
	$line = trim( fgets( $fh ) );
	
	if ( ! empty( $line ) ) {
		$api_version_list[] = "{$line}";
	}
}

fclose( $fh );

try {
	$json_data = json_encode( $api_version_list );
} catch ( \Exception $e ) {
	echo $e->getMessage();
}

$outfile = fopen( $json_file_name, 'w' );

if ( false === fwrite( $outfile, $json_data ) ) {
	die( sprintf( 'Error saving %s', $json_file_name ) );
}

fclose( $outfile );
