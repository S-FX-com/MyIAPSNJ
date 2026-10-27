<?php
/**
 * Member headshots: validate, shrink and store an uploaded photo.
 *
 * The photo is checked (type, size, readable, big enough), turned upright
 * from its camera orientation, cropped to a centred square, scaled down to
 * SIZE px and re-saved as a JPEG at QUALITY, so a 6 MB phone photo becomes a
 * file of a few dozen KB with its location data gone. It is stored in the
 * Media Library (uploads/iapsnj-headshots/), and its address is what the
 * caller writes to the FluentCRM contact's `avatar` field.
 *
 * Each attachment records the contact it belongs to (META), so a replaced or
 * removed photo's file is deleted and nothing piles up.
 *
 * Only the member's own profile editor (My_IAPSNJ_Profile) calls store() /
 * delete_for_contact(); the CRM write itself stays there.
 *
 * @package MyIAPSNJ
 */

defined( 'ABSPATH' ) || exit;

final class My_IAPSNJ_Headshot {

    /** Side of the stored square, px. */
    const SIZE = 400;

    /** JPEG quality of the stored file. */
    const QUALITY = 82;

    /** Smallest side accepted, px (smaller is blurry once shown at 80–100 px). */
    const MIN_SIDE = 150;

    /** Largest upload, bytes (the server's own limit may be lower). */
    const MAX_BYTES = 10485760;

    /** Most pixels decoded (a decompression-bomb guard): 50 megapixels. */
    const MAX_PIXELS = 50000000;

    /** Attachment meta: id of the CRM contact the photo belongs to. */
    const META = '_my_iapsnj_headshot_contact';

    /** Folder inside the uploads directory. */
    const SUBDIR = 'iapsnj-headshots';

    /** FluentCRM's contacts.avatar column holds at most this many characters. */
    const URL_MAX = 190;

    /** @var array<string,string> accepted types: extensions → mime */
    const MIMES = [
        'jpg|jpeg|jpe' => 'image/jpeg',
        'png'          => 'image/png',
        'webp'         => 'image/webp',
    ];

    /** The largest upload this server takes: our cap or PHP's, whichever is lower. */
    public static function max_bytes(): int {
        $server = function_exists( 'wp_max_upload_size' ) ? (int) wp_max_upload_size() : self::MAX_BYTES;
        return $server > 0 ? min( self::MAX_BYTES, $server ) : self::MAX_BYTES;
    }

    /** Was a file chosen? (An empty file input posts UPLOAD_ERR_NO_FILE.) */
    public static function has_upload( $file ): bool {
        // Scalars only: a crafted field name (photo[]) posts arrays.
        return is_array( $file )
            && isset( $file['error'], $file['tmp_name'] )
            && is_scalar( $file['error'] ) && is_scalar( $file['tmp_name'] ) && is_scalar( $file['name'] ?? '' ) && is_scalar( $file['size'] ?? 0 )
            && (int) $file['error'] !== UPLOAD_ERR_NO_FILE;
    }

    /**
     * Is this upload acceptable? Nothing is moved or changed.
     *
     * @param array<string,mixed> $file one entry of $_FILES
     * @return string '' when fine, else a message for the member
     */
    public static function check( array $file ): string {
        $error = (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE );
        if ( $error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE ) {
            return self::too_big_message();
        }
        if ( $error !== UPLOAD_ERR_OK ) {
            return __( 'The photo could not be uploaded. Please try again.', 'my-iapsnj' );
        }
        $tmp = (string) ( $file['tmp_name'] ?? '' );
        if ( $tmp === '' || ! is_readable( $tmp ) ) {
            return __( 'The photo could not be uploaded. Please try again.', 'my-iapsnj' );
        }
        if ( (int) ( $file['size'] ?? 0 ) > self::max_bytes() ) {
            return self::too_big_message();
        }
        $type = wp_check_filetype_and_ext( $tmp, (string) ( $file['name'] ?? '' ), self::MIMES );
        if ( empty( $type['type'] ) || ! in_array( $type['type'], self::MIMES, true ) ) {
            return __( 'Please choose a JPG, PNG or WebP photo.', 'my-iapsnj' );
        }
        $info = @getimagesize( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- unreadable files are reported below
        if ( ! is_array( $info ) || empty( $info[0] ) || empty( $info[1] ) ) {
            return __( 'That file could not be read as a photo.', 'my-iapsnj' );
        }
        if ( min( (int) $info[0], (int) $info[1] ) < self::MIN_SIDE ) {
            /* translators: %d: smallest accepted side in pixels */
            return sprintf( __( 'The photo is too small. Please use one at least %d pixels on each side.', 'my-iapsnj' ), self::MIN_SIDE );
        }
        if ( (int) $info[0] * (int) $info[1] > self::MAX_PIXELS ) {
            return __( 'The photo has too many pixels to process. Please use a smaller picture.', 'my-iapsnj' );
        }
        return '';
    }

    private static function too_big_message(): string {
        /* translators: %s: size such as 10 MB */
        return sprintf( __( 'The photo is too large. Please use one under %s.', 'my-iapsnj' ), size_format( self::max_bytes() ) );
    }

    /**
     * Shrink and store a checked upload.
     *
     * @param array<string,mixed> $file       one entry of $_FILES (check() it first)
     * @param int                 $contact_id CRM contact the photo belongs to
     * @param int                 $user_id    WordPress user who uploaded it (the attachment's author)
     * @return array{url:string,id:int}|WP_Error
     */
    public static function store( array $file, int $contact_id, int $user_id ) {
        if ( ! function_exists( 'wp_handle_upload' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php'; // not loaded on the front end
        }
        wp_raise_memory_limit( 'image' );

        $to_folder = static function ( $dirs ) {
            $dirs['subdir'] = '/' . self::SUBDIR;
            $dirs['path']   = $dirs['basedir'] . $dirs['subdir'];
            $dirs['url']    = $dirs['baseurl'] . $dirs['subdir'];
            return $dirs;
        };
        add_filter( 'upload_dir', $to_folder );
        $up = wp_handle_upload( $file, [
            'test_form'                => false,
            'mimes'                    => self::MIMES,
            'unique_filename_callback' => static function ( $dir, $name, $ext ) {
                return 'src-' . wp_generate_password( 12, false ) . $ext;
            },
        ] );
        remove_filter( 'upload_dir', $to_folder );
        if ( ! is_array( $up ) || ! empty( $up['error'] ) || empty( $up['file'] ) ) {
            return new WP_Error( 'headshot_upload', (string) ( $up['error'] ?? __( 'The photo could not be uploaded. Please try again.', 'my-iapsnj' ) ) );
        }
        $source = (string) $up['file'];
        $work   = self::flatten_transparency( $source );

        $editor = wp_get_image_editor( $work );
        if ( is_wp_error( $editor ) ) {
            self::discard( [ $source, $work ] );
            return new WP_Error( 'headshot_editor', __( 'This server cannot process photos right now. Please contact IAPSNJ.', 'my-iapsnj' ) );
        }
        if ( method_exists( $editor, 'maybe_exif_rotate' ) ) {
            $editor->maybe_exif_rotate(); // upright, as the camera held it
        }
        $size = $editor->get_size();
        $w    = (int) ( $size['width'] ?? 0 );
        $h    = (int) ( $size['height'] ?? 0 );
        $side = min( $w, $h );
        if ( $side < 1 ) {
            self::discard( [ $source, $work ] );
            return new WP_Error( 'headshot_size', __( 'That file could not be read as a photo.', 'my-iapsnj' ) );
        }
        // A centred square; shrunk to SIZE, never enlarged.
        $out    = min( self::SIZE, $side );
        $result = $editor->crop( (int) floor( ( $w - $side ) / 2 ), (int) floor( ( $h - $side ) / 2 ), $side, $side, $out, $out );
        if ( is_wp_error( $result ) ) {
            self::discard( [ $source, $work ] );
            return new WP_Error( 'headshot_crop', __( 'The photo could not be processed. Please try another one.', 'my-iapsnj' ) );
        }
        $editor->set_quality( self::QUALITY );

        $final = dirname( $source ) . '/m' . $contact_id . '-' . wp_generate_password( 12, false ) . '.jpg';
        $saved = $editor->save( $final, 'image/jpeg' );
        self::discard( [ $source, $work ] );
        if ( is_wp_error( $saved ) || ! is_file( $final ) ) {
            return new WP_Error( 'headshot_save', __( 'The photo could not be saved. Please try again.', 'my-iapsnj' ) );
        }

        $id = wp_insert_attachment( [
            'post_mime_type' => 'image/jpeg',
            /* translators: %d: CRM contact id */
            'post_title'     => sprintf( __( 'Member headshot %d', 'my-iapsnj' ), $contact_id ),
            'post_content'   => '',
            'post_status'    => 'inherit',
            'post_author'    => $user_id,
        ], $final );
        if ( is_wp_error( $id ) || (int) $id < 1 ) {
            self::discard( [ $final ] );
            return new WP_Error( 'headshot_attach', __( 'The photo could not be saved. Please try again.', 'my-iapsnj' ) );
        }
        $id = (int) $id;
        update_post_meta( $id, self::META, $contact_id );
        wp_update_attachment_metadata( $id, [ 'width' => $out, 'height' => $out, 'file' => _wp_relative_upload_path( $final ) ] );

        $url = (string) wp_get_attachment_url( $id );
        if ( $url === '' || strlen( $url ) > self::URL_MAX ) {
            wp_delete_attachment( $id, true );
            return new WP_Error( 'headshot_url', __( 'The photo could not be saved. Please contact IAPSNJ.', 'my-iapsnj' ) );
        }
        return [ 'url' => $url, 'id' => $id ];
    }

    /**
     * A PNG / WebP with transparency would turn black as a JPEG: paint it on
     * white first (GD only; elsewhere the file is used as it is).
     *
     * @return string path to read (the original, or a flattened copy)
     */
    private static function flatten_transparency( string $path ): string {
        if ( ! function_exists( 'imagecreatetruecolor' ) ) {
            return $path;
        }
        $info = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        $mime = is_array( $info ) ? (string) ( $info['mime'] ?? '' ) : '';
        $load = $mime === 'image/png' ? 'imagecreatefrompng' : ( $mime === 'image/webp' ? 'imagecreatefromwebp' : '' );
        if ( $load === '' || ! function_exists( $load ) ) {
            return $path;
        }
        $img = @$load( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        if ( ! $img ) {
            return $path;
        }
        $w      = imagesx( $img );
        $h      = imagesy( $img );
        $canvas = imagecreatetruecolor( $w, $h );
        imagefill( $canvas, 0, 0, imagecolorallocate( $canvas, 255, 255, 255 ) );
        imagecopy( $canvas, $img, 0, 0, 0, 0, $w, $h );
        $flat = dirname( $path ) . '/flat-' . wp_generate_password( 12, false ) . '.jpg';
        $ok   = imagejpeg( $canvas, $flat, 95 );
        imagedestroy( $img );
        imagedestroy( $canvas );
        return $ok ? $flat : $path;
    }

    /** @param string[] $paths */
    private static function discard( array $paths ): void {
        foreach ( array_unique( $paths ) as $p ) {
            if ( $p !== '' && is_file( $p ) ) {
                wp_delete_file( $p );
            }
        }
    }

    /**
     * Delete the stored photos of a contact (all of them, or all but one).
     *
     * @param int $except attachment id to keep (the new photo)
     */
    public static function delete_for_contact( int $contact_id, int $except = 0 ): void {
        $ids = get_posts( [
            'post_type'      => 'attachment',
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_key'       => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
            'meta_value'     => $contact_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
            'no_found_rows'  => true,
        ] );
        foreach ( (array) $ids as $id ) {
            if ( (int) $id !== $except ) {
                wp_delete_attachment( (int) $id, true );
            }
        }
    }

    /** Is this address one of the photos stored here (not a Gravatar or another site's)? */
    public static function is_ours( string $url ): bool {
        if ( $url === '' ) {
            return false;
        }
        $dirs = wp_get_upload_dir();
        $base = set_url_scheme( rtrim( (string) $dirs['baseurl'], '/' ) . '/' . self::SUBDIR . '/' );
        return strpos( set_url_scheme( $url ), $base ) === 0;
    }

    /** The silhouette shown where there is no photo. */
    public static function placeholder_svg(): string {
        return '<svg viewBox="0 0 64 64" aria-hidden="true" focusable="false"><circle cx="32" cy="24" r="12"/><path d="M8 58c0-13 10-20 24-20s24 7 24 20z"/></svg>';
    }
}
