<?php
/**
 * Version bump helper tests.
 *
 * @package WPVDB_Scripts
 */

declare(strict_types=1);

namespace WPVDB_Scripts\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;

use function bump_plugin_version;
use function read_required_file;

/**
 * Tests plugin version bump behavior.
 *
 * @covers ::bump_plugin_version
 */
final class BumpPluginVersionTest extends TestCase {
	/**
	 * Temporary fixture directories.
	 *
	 * @var list<string>
	 */
	private array $temp_dirs = [];

	/**
	 * Previous environment values.
	 *
	 * @var array<string, string|false>
	 */
	private array $previous_env = [];

	/**
	 * Clean temporary fixtures and environment.
	 */
	protected function tearDown(): void {
		foreach ( $this->temp_dirs as $dir ) {
			$this->delete_tree( $dir );
		}

		foreach ( [ 'PLUGIN_FILE', 'VERSION_CONSTANT', 'PACKAGE_FILE', 'POT_FILE', 'POT_PROJECT', 'BLOCK_JSON_GLOB' ] as $name ) {
			if ( array_key_exists( $name, $this->previous_env ) && false !== $this->previous_env[ $name ] ) {
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Tests isolate CLI helper environment variables.
				putenv( "{$name}={$this->previous_env[ $name ]}" );
			} else {
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Tests isolate CLI helper environment variables.
				putenv( $name );
			}
		}

		$this->previous_env = [];
	}

	/**
	 * Test configured version surfaces are bumped together.
	 *
	 * @covers ::bump_plugin_version
	 */
	public function test_bump_updates_all_configured_version_surfaces(): void {
		$root = $this->make_fixture(
			[
				'demo-plugin.php'        => "<?php\n/**\n * Plugin Name: Demo plugin\n * Version: 0.1.2\n */\ndefine( 'DEMO_PLUGIN_VERSION', '0.1.2' );\n",
				'package.json'           => "{\n\t\"name\": \"demo-plugin\",\n\t\"version\": \"0.1.2\"\n}\n",
				'languages/demo.pot'     => "msgid \"\"\nmsgstr \"\"\n\"Project-Id-Version: Demo plugin 0.1.2\\n\"\n",
				'src/example/block.json' => "{\n\t\"name\": \"demo/example\",\n\t\"version\": \"0.1.2\"\n}\n",
			]
		);
		$this->configure_env(
			[
				'PLUGIN_FILE'      => 'demo-plugin.php',
				'VERSION_CONSTANT' => 'DEMO_PLUGIN_VERSION',
				'PACKAGE_FILE'     => 'package.json',
				'POT_FILE'         => 'languages/demo.pot',
				'POT_PROJECT'      => 'Demo plugin',
				'BLOCK_JSON_GLOB'  => 'src/*/block.json',
			]
		);

		self::assertSame( '0.2.0', bump_plugin_version( $root, 'minor' ), 'Minor bumps should update the returned version.' );
		self::assertStringContainsString( "* Version: 0.2.0\n", read_required_file( $root . '/demo-plugin.php' ), 'Plugin headers should be bumped.' );
		self::assertStringContainsString( "define( 'DEMO_PLUGIN_VERSION', '0.2.0' );", read_required_file( $root . '/demo-plugin.php' ), 'Version constants should be bumped.' );
		self::assertStringContainsString( '"version": "0.2.0"', read_required_file( $root . '/package.json' ), 'Package versions should be bumped.' );
		self::assertStringContainsString( 'Project-Id-Version: Demo plugin 0.2.0\\n', read_required_file( $root . '/languages/demo.pot' ), 'POT project versions should be bumped.' );
		self::assertStringContainsString( '"version": "0.2.0"', read_required_file( $root . '/src/example/block.json' ), 'Block metadata versions should be bumped.' );
	}

	/**
	 * Test prerelease versions are rejected.
	 *
	 * @covers ::bump_plugin_version
	 */
	public function test_bump_rejects_prerelease_versions(): void {
		$root = $this->make_fixture(
			[
				'demo-plugin.php' => "<?php\n/**\n * Plugin Name: Demo plugin\n * Version: 1.2.3-rc.1\n */\n",
			]
		);
		$this->configure_env(
			[
				'PLUGIN_FILE' => 'demo-plugin.php',
			]
		);

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Only stable x.y.z versions can be bumped automatically: 1.2.3-rc.1' );

		bump_plugin_version( $root, 'patch' );
	}

	/**
	 * Test ambiguous version surfaces fail closed.
	 *
	 * @covers ::bump_plugin_version
	 */
	public function test_bump_fails_closed_when_a_surface_is_ambiguous(): void {
		$root = $this->make_fixture(
			[
				'demo-plugin.php' => "<?php\n/**\n * Plugin Name: Demo plugin\n * Version: 1.2.3\n * Version: 1.2.3\n */\n",
			]
		);
		$this->configure_env(
			[
				'PLUGIN_FILE' => 'demo-plugin.php',
			]
		);

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Unable to find plugin header Version.' );

		bump_plugin_version( $root, 'patch' );
	}

	/**
	 * Make a temporary fixture directory.
	 *
	 * @param array<string, string> $files Files keyed by relative path.
	 */
	private function make_fixture( array $files ): string {
		$root = sys_get_temp_dir() . '/wpvdb-scripts-test-' . bin2hex( random_bytes( 6 ) );
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.directory_mkdir -- Tests create isolated temporary fixture directories.
		mkdir( $root, 0777, true );
		$this->temp_dirs[] = $root;

		foreach ( $files as $relative_path => $contents ) {
			$path = $root . '/' . $relative_path;
			$dir  = dirname( $path );
			if ( ! is_dir( $dir ) ) {
				// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.directory_mkdir -- Tests create isolated temporary fixture directories.
				mkdir( $dir, 0777, true );
			}
			// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents -- Tests create isolated temporary fixture files.
			file_put_contents( $path, $contents );
		}

		return $root;
	}

	/**
	 * Configure environment variables for the helper.
	 *
	 * @param array<string, string> $env Environment values.
	 */
	private function configure_env( array $env ): void {
		foreach ( $env as $name => $value ) {
			if ( ! array_key_exists( $name, $this->previous_env ) ) {
				$this->previous_env[ $name ] = getenv( $name );
			}

			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Tests configure CLI helper environment variables.
			putenv( "{$name}={$value}" );
		}
	}

	/**
	 * Delete a temporary fixture tree.
	 *
	 * @param string $path Directory path.
	 */
	private function delete_tree( string $path ): void {
		if ( ! is_dir( $path ) ) {
			return;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $item ) {
			// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.directory_rmdir, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink -- Tests clean isolated temporary fixtures.
			$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
		}

		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.directory_rmdir -- Tests clean isolated temporary fixture directories.
		rmdir( $path );
	}
}
