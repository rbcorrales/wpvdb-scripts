<?php
/**
 * Bump WordPress plugin version metadata.
 *
 * @package WPVDB_Scripts
 */

// phpcs:disable WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fwrite
// phpcs:disable WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents
// phpcs:disable WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown

declare(strict_types=1);

if ( is_bump_plugin_version_entrypoint( $argv ?? [] ) ) {
	$exit_code = bump_plugin_version_cli( $argv );
	exit( $exit_code ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Integer process status, not output.
}

/**
 * Whether this file is running as the CLI entrypoint.
 *
 * @param array<int, string> $argv CLI argv.
 * @return bool
 */
function is_bump_plugin_version_entrypoint( array $argv ): bool {
	return 'cli' === PHP_SAPI && isset( $argv[0] ) && realpath( $argv[0] ) === __FILE__;
}

/**
 * CLI entrypoint.
 *
 * @param array<int, string> $argv CLI argv.
 * @return int Process exit code.
 */
function bump_plugin_version_cli( array $argv ): int {
	if ( 'cli' !== PHP_SAPI ) {
		fwrite( STDERR, "This script must run from the command line.\n" );
		return 1;
	}

	$root = getcwd();

	if ( false === $root ) {
		fwrite( STDERR, "Unable to determine repository root.\n" );
		return 1;
	}

	$bump_type = $argv[1] ?? 'patch';

	try {
		$new_version = bump_plugin_version( $root, $bump_type );
		fwrite( STDOUT, "Bumped plugin version to {$new_version}\n" );
		return 0;
	} catch ( Throwable $throwable ) {
		fwrite( STDERR, $throwable->getMessage() . "\n" );
		return 1;
	}
}

/**
 * Bump plugin metadata files.
 *
 * @param string $root     Repository root.
 * @param string $bump_type Version bump type.
 *
 * @return string New version.
 * @throws RuntimeException When metadata cannot be updated.
 */
function bump_plugin_version( string $root, string $bump_type ): string {
	$plugin_file     = env_string( 'PLUGIN_FILE' );
	$constant_name   = env_string( 'VERSION_CONSTANT', '' );
	$package_file    = env_string( 'PACKAGE_FILE', '' );
	$pot_file        = env_string( 'POT_FILE', '' );
	$pot_project     = env_string( 'POT_PROJECT', '' );
	$block_json_glob = env_string( 'BLOCK_JSON_GLOB', '' );

	if ( ! in_array( $bump_type, [ 'major', 'minor', 'patch' ], true ) ) {
		throw new RuntimeException( "Unsupported bump type: {$bump_type}" );
	}

	$plugin_path     = path_join( $root, $plugin_file );
	$plugin_contents = read_required_file( $plugin_path );

	$current_version = capture_plugin_version( $plugin_contents );
	$new_version     = bump_semver( $current_version, $bump_type );

	$writes                 = [];
	$writes[ $plugin_path ] = replace_once(
		'/^([ \t]*\*[ \t]+Version:[ \t]*)' . preg_quote( $current_version, '/' ) . '/m',
		'${1}' . $new_version,
		$plugin_contents,
		$plugin_file
	);

	if ( '' !== $constant_name ) {
		$writes[ $plugin_path ] = replace_one_of_once(
			constant_patterns( $constant_name ),
			'${1}' . $new_version . '${2}',
			$writes[ $plugin_path ],
			$plugin_file
		);
	}

	if ( '' !== $package_file ) {
		$package_path            = path_join( $root, $package_file );
		$package_contents        = read_required_file( $package_path );
		$writes[ $package_path ] = replace_once(
			'/("version"\s*:\s*")[^"]*(")/',
			'${1}' . $new_version . '${2}',
			$package_contents,
			$package_file
		);
	}

	if ( '' !== $block_json_glob ) {
		$block_json_files = glob( path_join( $root, $block_json_glob ) );

		if ( false === $block_json_files ) {
			$block_json_files = [];
		}

		if ( [] === $block_json_files ) {
			throw new RuntimeException( "No block metadata files matched {$block_json_glob}" );
		}

		foreach ( $block_json_files as $block_json_path ) {
			$block_json_contents        = read_required_file( $block_json_path );
			$writes[ $block_json_path ] = replace_once(
				'/("version"\s*:\s*")[^"]*(")/',
				'${1}' . $new_version . '${2}',
				$block_json_contents,
				relative_path( $root, $block_json_path )
			);
		}
	}

	if ( '' !== $pot_file ) {
		$pot_path            = path_join( $root, $pot_file );
		$pot_contents        = read_required_file( $pot_path );
		$project_version     = '' !== $pot_project ? "{$pot_project} {$new_version}" : $new_version;
		$writes[ $pot_path ] = replace_once(
			'/("Project-Id-Version:\s*)[^\n"]*(\\\\n")/',
			'${1}' . $project_version . '${2}',
			$pot_contents,
			$pot_file
		);
	}

	foreach ( $writes as $path => $contents ) {
		if ( file_put_contents( $path, $contents ) === false ) {
			throw new RuntimeException( "Unable to write {$path}" );
		}
	}

	return $new_version;
}

/**
 * Read an environment variable.
 *
 * @param string $name    Environment variable name.
 * @param string $default_value Default value.
 *
 * @return string Environment value.
 */
function env_string( string $name, string $default_value = '' ): string {
	$value = getenv( $name );

	if ( false === $value || '' === $value ) {
		return $default_value;
	}

	return $value;
}

/**
 * Join a base path and a child path.
 *
 * @param string $base Base path.
 * @param string $path Child path.
 *
 * @return string Joined path.
 */
function path_join( string $base, string $path ): string {
	if ( '' === $path ) {
		return $base;
	}

	return rtrim( $base, '/' ) . '/' . ltrim( $path, '/' );
}

/**
 * Read a file or throw.
 *
 * @param string $path File path.
 *
 * @return string File contents.
 * @throws RuntimeException When the file cannot be read.
 */
function read_required_file( string $path ): string {
	$contents = file_get_contents( $path );

	if ( false === $contents ) {
		throw new RuntimeException( "Unable to read {$path}" );
	}

	return $contents;
}

/**
 * Capture a plugin header version.
 *
 * @param string $contents Plugin file contents.
 *
 * @return string Version.
 * @throws RuntimeException When the version cannot be found.
 */
function capture_plugin_version( string $contents ): string {
	$matched = preg_match_all( '/^[ \t]*\*[ \t]+Version:[ \t]*(\d+\.\d+\.\d+(?:[-+]\S+)?)/m', $contents, $matches );

	if ( 1 !== $matched ) {
		throw new RuntimeException( 'Unable to find plugin header Version.' );
	}

	return $matches[1][0];
}

/**
 * Bump a strict semantic version.
 *
 * @param string $version  Current version.
 * @param string $bump_type Bump type.
 *
 * @return string Bumped version.
 * @throws RuntimeException When the version is not stable.
 */
function bump_semver( string $version, string $bump_type ): string {
	$matched = preg_match( '/^(\d+)\.(\d+)\.(\d+)$/', $version, $matches );

	if ( 1 !== $matched ) {
		throw new RuntimeException( "Only stable x.y.z versions can be bumped automatically: {$version}" );
	}

	$major = (int) $matches[1];
	$minor = (int) $matches[2];
	$patch = (int) $matches[3];

	if ( 'major' === $bump_type ) {
		return ( $major + 1 ) . '.0.0';
	}

	if ( 'minor' === $bump_type ) {
		return $major . '.' . ( $minor + 1 ) . '.0';
	}

	return $major . '.' . $minor . '.' . ( $patch + 1 );
}

/**
 * Replace a regex exactly once.
 *
 * @param string $pattern     Regular expression.
 * @param string $replacement Replacement text.
 * @param string $contents    Input contents.
 * @param string $label       Error label.
 *
 * @return string Updated contents.
 * @throws RuntimeException When the replacement does not happen once.
 */
function replace_once( string $pattern, string $replacement, string $contents, string $label ): string {
	$matched = preg_match_all( $pattern, $contents );

	if ( 1 !== $matched ) {
		throw new RuntimeException( "Unable to update version in {$label}" );
	}

	$updated = preg_replace( $pattern, $replacement, $contents );

	if ( null === $updated ) {
		throw new RuntimeException( "Unable to update version in {$label}" );
	}

	return $updated;
}

/**
 * Build version constant patterns.
 *
 * @param string $constant_name Version constant name.
 * @return list<string>
 */
function constant_patterns( string $constant_name ): array {
	return [
		'/(const\s+' . preg_quote( $constant_name, '/' ) . "\s*=\s*')[^']*(';)/",
		'/(define\(\s*[\'"]' . preg_quote( $constant_name, '/' ) . '[\'"]\s*,\s*[\'"])[^\'"]*([\'"]\s*\);)/',
	];
}

/**
 * Replace the first matching pattern from a list exactly once.
 *
 * @param list<string> $patterns    Regular expressions.
 * @param string       $replacement Replacement text.
 * @param string       $contents    Input contents.
 * @param string       $label       Error label.
 *
 * @return string Updated contents.
 * @throws RuntimeException When no pattern matches exactly once.
 */
function replace_one_of_once( array $patterns, string $replacement, string $contents, string $label ): string { // phpcs:ignore Squiz.Commenting.FunctionComment.IncorrectTypeHint
	$total_matches   = 0;
	$matched_pattern = null;

	foreach ( $patterns as $pattern ) {
		$matched = preg_match_all( $pattern, $contents );

		if ( 0 === $matched ) {
			continue;
		}

		$total_matches  += $matched;
		$matched_pattern = $pattern;
	}

	if ( 1 !== $total_matches || null === $matched_pattern ) {
		throw new RuntimeException( "Unable to update version in {$label}" );
	}

	$updated = preg_replace( $matched_pattern, $replacement, $contents );

	if ( null === $updated ) {
		throw new RuntimeException( "Unable to update version in {$label}" );
	}

	return $updated;
}

/**
 * Convert an absolute path into a relative path for messages.
 *
 * @param string $root Repository root.
 * @param string $path Absolute path.
 *
 * @return string Relative path.
 */
function relative_path( string $root, string $path ): string {
	$prefix = rtrim( $root, '/' ) . '/';

	if ( str_starts_with( $path, $prefix ) ) {
		return substr( $path, strlen( $prefix ) );
	}

	return $path;
}
