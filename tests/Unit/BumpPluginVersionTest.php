<?php
declare(strict_types=1);

namespace WPVDB_Scripts\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;

use function bump_plugin_version;

final class BumpPluginVersionTest extends TestCase {
	/**
	 * @var list<string>
	 */
	private array $temp_dirs = [];

	protected function tearDown(): void {
		foreach ( $this->temp_dirs as $dir ) {
			$this->delete_tree( $dir );
		}

		foreach ( [ 'PLUGIN_FILE', 'VERSION_CONSTANT', 'PACKAGE_FILE', 'POT_FILE', 'POT_PROJECT', 'BLOCK_JSON_GLOB' ] as $name ) {
			putenv( $name );
		}
	}

	public function test_bump_updates_all_configured_version_surfaces(): void {
		$root = $this->make_fixture(
			[
				'demo-plugin.php'       => "<?php\n/**\n * Plugin Name: Demo plugin\n * Version: 0.1.2\n */\ndefine( 'DEMO_PLUGIN_VERSION', '0.1.2' );\n",
				'package.json'          => "{\n\t\"name\": \"demo-plugin\",\n\t\"version\": \"0.1.2\"\n}\n",
				'languages/demo.pot'    => "msgid \"\"\nmsgstr \"\"\n\"Project-Id-Version: Demo plugin 0.1.2\\n\"\n",
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

		self::assertSame( '0.2.0', bump_plugin_version( $root, 'minor' ) );
		self::assertStringContainsString( "* Version: 0.2.0\n", file_get_contents( $root . '/demo-plugin.php' ) ?: '' );
		self::assertStringContainsString( "define( 'DEMO_PLUGIN_VERSION', '0.2.0' );", file_get_contents( $root . '/demo-plugin.php' ) ?: '' );
		self::assertStringContainsString( '"version": "0.2.0"', file_get_contents( $root . '/package.json' ) ?: '' );
		self::assertStringContainsString( 'Project-Id-Version: Demo plugin 0.2.0\\n', file_get_contents( $root . '/languages/demo.pot' ) ?: '' );
		self::assertStringContainsString( '"version": "0.2.0"', file_get_contents( $root . '/src/example/block.json' ) ?: '' );
	}

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
	 * @param array<string, string> $files Files keyed by relative path.
	 */
	private function make_fixture( array $files ): string {
		$root = sys_get_temp_dir() . '/wpvdb-scripts-test-' . bin2hex( random_bytes( 6 ) );
		mkdir( $root, 0777, true );
		$this->temp_dirs[] = $root;

		foreach ( $files as $relative_path => $contents ) {
			$path = $root . '/' . $relative_path;
			$dir  = dirname( $path );
			if ( ! is_dir( $dir ) ) {
				mkdir( $dir, 0777, true );
			}
			file_put_contents( $path, $contents );
		}

		return $root;
	}

	/**
	 * @param array<string, string> $env Environment values.
	 */
	private function configure_env( array $env ): void {
		foreach ( $env as $name => $value ) {
			putenv( "{$name}={$value}" );
		}
	}

	private function delete_tree( string $path ): void {
		if ( ! is_dir( $path ) ) {
			return;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $item ) {
			$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
		}

		rmdir( $path );
	}
}
