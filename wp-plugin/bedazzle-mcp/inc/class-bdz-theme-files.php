<?php
/**
 * Theme file tools for the Bedazzle MCP extension.
 *
 * Provides list / read / write over files inside a theme directory, scoped to
 * the WordPress theme root and defended against path traversal. This is what
 * lets the build scaffold a child theme and hand-write CSS/JS/templates through
 * the connector instead of by hand.
 *
 * Writes are deliberately limited to a text-file allowlist. Binary assets
 * (fonts, images) go through the media library or a later, explicit tool.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BDZ_Theme_Files {

	/** Extensions we permit writing. Reading is allowed for any file. */
	const WRITABLE_EXTENSIONS = array( 'php', 'css', 'js', 'json', 'html', 'txt', 'md', 'svg', 'pot', 'po' );

	/** Hard cap on write size (bytes) — a guard, not a real limit for source files. */
	const MAX_WRITE_BYTES = 2000000;

	public function tools() {
		$theme_prop = array(
			'type'        => 'string',
			'description' => 'Theme folder name under the theme root (e.g. "astra-child"). Omit to use the active theme (get_stylesheet). For write, a folder that does not exist yet is created — this is how a new child theme is scaffolded.',
		);

		return array(
			array(
				'name'        => 'bdz_theme_list_files',
				'description' => 'List files inside a theme directory (recursive), relative to the theme folder. Use before reading/writing to see what exists.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'theme' => $theme_prop,
					),
				),
			),
			array(
				'name'        => 'bdz_theme_read_file',
				'description' => 'Read a single file from a theme directory. Always read before overwriting.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'relative_path' => array(
							'type'        => 'string',
							'description' => 'Path relative to the theme folder, e.g. "style.css" or "woocommerce/single-product.php".',
						),
						'theme'         => $theme_prop,
					),
					'required'   => array( 'relative_path' ),
				),
			),
			array(
				'name'        => 'bdz_theme_write_file',
				'description' => 'Write (create or overwrite) a text file in a theme directory, creating subfolders as needed. Restricted to source file types (php, css, js, json, html, txt, md, svg, pot, po). Overwrites the whole file — always send complete file contents.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'relative_path' => array(
							'type'        => 'string',
							'description' => 'Path relative to the theme folder, e.g. "functions.php".',
						),
						'contents'      => array(
							'type'        => 'string',
							'description' => 'Full file contents. Overwrites any existing file.',
						),
						'theme'         => $theme_prop,
					),
					'required'   => array( 'relative_path', 'contents' ),
				),
			),
		);
	}

	public function handle( $tool, $args ) {
		switch ( $tool ) {
			case 'bdz_theme_list_files':
				return $this->list_files( $args );
			case 'bdz_theme_read_file':
				return $this->read_file( $args );
			case 'bdz_theme_write_file':
				return $this->write_file( $args );
		}
		return null; // Not ours.
	}

	/**
	 * Resolve a theme folder name to an absolute directory under the theme root.
	 * Does not require the folder to exist (write creates it), but the parent
	 * theme root must exist and the slug must be a safe segment.
	 *
	 * @return array{ok:bool, dir?:string, slug?:string, error?:string}
	 */
	private function theme_dir( $args ) {
		$slug = isset( $args['theme'] ) && '' !== $args['theme'] ? (string) $args['theme'] : get_stylesheet();
		if ( ! preg_match( '/^[A-Za-z0-9_-]+$/', $slug ) ) {
			return array( 'ok' => false, 'error' => 'Invalid theme folder name: ' . $slug );
		}
		$root = realpath( get_theme_root() );
		if ( false === $root ) {
			return array( 'ok' => false, 'error' => 'Theme root not found.' );
		}
		return array( 'ok' => true, 'dir' => $root . DIRECTORY_SEPARATOR . $slug, 'slug' => $slug, 'root' => $root );
	}

	/**
	 * Turn a caller-supplied relative path + theme dir into a safe absolute path.
	 * Rejects traversal and anything that would escape the theme root.
	 *
	 * @return array{ok:bool, path?:string, error?:string}
	 */
	private function resolve_path( $theme_dir, $theme_root, $relative ) {
		$relative = (string) $relative;
		$relative = str_replace( '\\', '/', $relative );
		$relative = ltrim( $relative, '/' );

		if ( '' === $relative ) {
			return array( 'ok' => false, 'error' => 'relative_path is required.' );
		}
		// No traversal segments, no NUL bytes.
		if ( false !== strpos( $relative, "\0" ) || preg_match( '#(^|/)\.\.(/|$)#', $relative ) ) {
			return array( 'ok' => false, 'error' => 'Illegal path: ' . $relative );
		}

		$target = $theme_dir . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative );

		// Lexical containment check against the theme root (works even if the
		// file/dir does not exist yet, since realpath() would return false).
		$normalized_root = rtrim( $theme_root, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;
		if ( 0 !== strpos( $target, $normalized_root ) ) {
			return array( 'ok' => false, 'error' => 'Path escapes the theme root.' );
		}

		return array( 'ok' => true, 'path' => $target );
	}

	private function list_files( $args ) {
		$dir = $this->theme_dir( $args );
		if ( ! $dir['ok'] ) {
			return array( 'error' => $dir['error'] );
		}
		if ( ! is_dir( $dir['dir'] ) ) {
			return array(
				'theme'  => $dir['slug'],
				'exists' => false,
				'files'  => array(),
				'note'   => 'Theme folder does not exist yet. bdz_theme_write_file will create it.',
			);
		}

		$base  = $dir['dir'];
		$files = array();
		$it    = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);
		foreach ( $it as $file ) {
			if ( $file->isFile() ) {
				$rel     = ltrim( str_replace( $base, '', $file->getPathname() ), DIRECTORY_SEPARATOR );
				$rel     = str_replace( DIRECTORY_SEPARATOR, '/', $rel );
				$files[] = array( 'path' => $rel, 'size' => $file->getSize() );
			}
		}
		sort( $files );
		return array(
			'theme'  => $dir['slug'],
			'exists' => true,
			'count'  => count( $files ),
			'files'  => $files,
		);
	}

	private function read_file( $args ) {
		$dir = $this->theme_dir( $args );
		if ( ! $dir['ok'] ) {
			return array( 'error' => $dir['error'] );
		}
		$resolved = $this->resolve_path( $dir['dir'], $dir['root'], isset( $args['relative_path'] ) ? $args['relative_path'] : '' );
		if ( ! $resolved['ok'] ) {
			return array( 'error' => $resolved['error'] );
		}
		if ( ! is_file( $resolved['path'] ) ) {
			return array( 'error' => 'File not found: ' . $args['relative_path'] );
		}
		$contents = file_get_contents( $resolved['path'] );
		if ( false === $contents ) {
			return array( 'error' => 'Unable to read file.' );
		}
		return array(
			'theme'         => $dir['slug'],
			'relative_path' => str_replace( '\\', '/', (string) $args['relative_path'] ),
			'bytes'         => strlen( $contents ),
			'contents'      => $contents,
		);
	}

	private function write_file( $args ) {
		$dir = $this->theme_dir( $args );
		if ( ! $dir['ok'] ) {
			return array( 'error' => $dir['error'] );
		}
		$relative = isset( $args['relative_path'] ) ? (string) $args['relative_path'] : '';
		$resolved = $this->resolve_path( $dir['dir'], $dir['root'], $relative );
		if ( ! $resolved['ok'] ) {
			return array( 'error' => $resolved['error'] );
		}

		$ext = strtolower( pathinfo( $resolved['path'], PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, self::WRITABLE_EXTENSIONS, true ) ) {
			return array( 'error' => 'Refusing to write .' . $ext . ' files. Allowed: ' . implode( ', ', self::WRITABLE_EXTENSIONS ) );
		}

		$contents = isset( $args['contents'] ) ? (string) $args['contents'] : '';
		if ( strlen( $contents ) > self::MAX_WRITE_BYTES ) {
			return array( 'error' => 'Contents exceed max write size.' );
		}

		$parent = dirname( $resolved['path'] );
		if ( ! is_dir( $parent ) && ! wp_mkdir_p( $parent ) ) {
			return array( 'error' => 'Could not create directory: ' . $parent );
		}

		$existed = is_file( $resolved['path'] );
		$written = file_put_contents( $resolved['path'], $contents );
		if ( false === $written ) {
			return array( 'error' => 'Write failed (check filesystem permissions on the theme directory).' );
		}

		return array(
			'theme'         => $dir['slug'],
			'relative_path' => str_replace( '\\', '/', $relative ),
			'action'        => $existed ? 'updated' : 'created',
			'bytes'         => $written,
		);
	}
}
