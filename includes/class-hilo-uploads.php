<?php
/**
 * Chat attachments.
 *
 * @package HiloChat
 */

defined( 'ABSPATH' ) || exit;

/**
 * Validates and stores attachments outside the media library.
 */
class HILO_Uploads {

	const MAX_BYTES = 8388608; // 8 MB raw (images are re-encoded and shrunk).
	const MAX_SIDE  = 1600;

	/**
	 * Allowed types: ext => mime.
	 *
	 * @var array
	 */
	private static $types = array(
		'jpg'  => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'png'  => 'image/png',
		'webp' => 'image/webp',
		'gif'  => 'image/gif',
		'pdf'  => 'application/pdf',
	);

	/**
	 * Base directory info.
	 *
	 * @return array{dir:string,url:string}
	 */
	private static function base() {
		$uploads = wp_upload_dir( null, false );
		return array(
			'dir' => trailingslashit( $uploads['basedir'] ) . 'hilo-chat',
			'url' => trailingslashit( $uploads['baseurl'] ) . 'hilo-chat',
		);
	}

	/**
	 * Stores an uploaded file.
	 *
	 * @param array $file Entry from $_FILES.
	 * @return array|WP_Error Attachment data.
	 */
	public static function store( $file ) {
		if ( empty( $file['tmp_name'] ) || ! empty( $file['error'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new WP_Error( 'hilo_upload', 'No se pudo recibir el archivo.', array( 'status' => 400 ) );
		}
		if ( (int) $file['size'] > self::MAX_BYTES ) {
			return new WP_Error( 'hilo_upload_size', 'El archivo supera el máximo de 8 MB.', array( 'status' => 413 ) );
		}

		$check = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], self::$types );
		$ext   = $check['ext'];
		$mime  = $check['type'];
		if ( ! $ext || ! $mime || ! in_array( $mime, self::$types, true ) ) {
			return new WP_Error( 'hilo_upload_type', 'Solo se permiten imágenes (JPG, PNG, WEBP, GIF) o PDF.', array( 'status' => 415 ) );
		}
		$is_image = 0 === strpos( $mime, 'image/' );
		if ( $is_image && ! @getimagesize( $file['tmp_name'] ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return new WP_Error( 'hilo_upload_type', 'La imagen no es válida.', array( 'status' => 415 ) );
		}

		$base   = self::base();
		$subdir = gmdate( 'Y/m' );
		$dir    = $base['dir'] . '/' . $subdir;
		if ( ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'hilo_upload', 'No se pudo guardar el archivo.', array( 'status' => 500 ) );
		}
		if ( ! file_exists( $dir . '/index.html' ) ) {
			file_put_contents( $dir . '/index.html', '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}

		$ext  = 'jpeg' === $ext ? 'jpg' : $ext;
		$name = bin2hex( random_bytes( 16 ) ) . '.' . $ext;
		$dest = $dir . '/' . $name;
		$data = array(
			'name' => sanitize_file_name( wp_basename( $file['name'] ) ),
			'mime' => $mime,
		);

		if ( $is_image && 'image/gif' !== $mime ) {
			// Re-encode: strips EXIF/GPS metadata and neutralizes polyglot files.
			$editor = wp_get_image_editor( $file['tmp_name'] );
			if ( is_wp_error( $editor ) ) {
				return new WP_Error( 'hilo_upload', 'No se pudo procesar la imagen.', array( 'status' => 415 ) );
			}
			$size = $editor->get_size();
			if ( $size['width'] > self::MAX_SIDE || $size['height'] > self::MAX_SIDE ) {
				$editor->resize( self::MAX_SIDE, self::MAX_SIDE, false );
			}
			$editor->set_quality( 82 );
			$saved = $editor->save( $dest, $mime );
			if ( is_wp_error( $saved ) ) {
				return new WP_Error( 'hilo_upload', 'No se pudo guardar la imagen.', array( 'status' => 500 ) );
			}
			$dest          = $saved['path'];
			$name          = wp_basename( $dest );
			$data['w']     = (int) $saved['width'];
			$data['h']     = (int) $saved['height'];
		} else {
			if ( ! move_uploaded_file( $file['tmp_name'], $dest ) ) {
				return new WP_Error( 'hilo_upload', 'No se pudo guardar el archivo.', array( 'status' => 500 ) );
			}
			if ( $is_image ) {
				$dim       = getimagesize( $dest );
				$data['w'] = (int) $dim[0];
				$data['h'] = (int) $dim[1];
			}
		}
		chmod( $dest, 0644 ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		$data['file']  = $subdir . '/' . $name;
		$data['url']   = $base['url'] . '/' . $subdir . '/' . $name;
		$data['size']  = (int) filesize( $dest );
		$data['image'] = $is_image;
		return $data;
	}

	/**
	 * Deletes a stored file by relative path.
	 *
	 * @param string $relative Relative path (Y/m/name.ext).
	 */
	public static function delete_file( $relative ) {
		if ( ! preg_match( '#^\d{4}/\d{2}/[a-f0-9]{32}\.[a-z]{3,4}$#', $relative ) ) {
			return;
		}
		$path = self::base()['dir'] . '/' . $relative;
		if ( file_exists( $path ) ) {
			wp_delete_file( $path );
		}
	}
}
