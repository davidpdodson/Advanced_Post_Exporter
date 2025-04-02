<?php
/*
Plugin Name: Advanced Post Exporter
Description: Export any post type (including custom ones) to CSV. Choose which fields to export—standard post fields (displayed under “Standard Fields”) and ACF fields (displayed under “ACF Fields”). ACF fields are parsed by type (e.g. image fields output URLs) and text fields (like excerpt) are filtered for mis-encoded characters and HTML list conversion.
Version: 2.0
Author: Dave Dodson
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

/**
 * Replace mis-encoded characters in a text string.
 *
 * This function first converts the text from Windows-1252 to UTF-8, then decodes HTML entities,
 * and finally replaces common odd sequences with their proper counterparts.
 *
 * Replacements:
 * - ‚Äô and â€™ → ’ (curly apostrophe)
 * - ‚Äî and â€” → — (em dash)
 * - √± and Ã± → ñ
 * - ¬Æ → ®
 * - ‚Ñ¢ and â„¢ → ™
 * - √© and Ã© → é
 *
 * @param string $text The text to filter.
 * @return string
 */
function ape_replace_special_characters( $text ) {
    $text = mb_convert_encoding( $text, 'UTF-8', 'Windows-1252' );
    $text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
    $search  = array(
        '‚Äô', 'â€™',
        '‚Äî', 'â€”',
        '√±',  'Ã±',
        '¬Æ',
        '‚Ñ¢', 'â„¢',
        '√©',  'Ã©'
    );
    $replace = array(
        '’', '’',
        '—', '—',
        'ñ', 'ñ',
        '®',
        '™', '™',
        'é', 'é'
    );
    return str_replace( $search, $replace, $text );
}

/**
 * Convert HTML lists to plain text lists.
 *
 * Replaces <li> items with a dash and newline, then strips any remaining HTML tags.
 *
 * @param string $html The HTML content.
 * @return string
 */
function ape_html_list_to_plain_text( $html ) {
    $html = preg_replace( '/<li>(.*?)<\/li>/i', '- $1' . "\n", $html );
    return strip_tags( $html );
}

/**
 * Parse an ACF field value based on its field type.
 *
 * For example, if the field is an image, output its URL. If it’s a relationship, output the related post titles.
 *
 * @param string $field_name The ACF field name.
 * @param int    $post_id    The post ID.
 * @return mixed
 */
function ape_process_acf_field( $field_name, $post_id ) {
    $value = get_field( $field_name, $post_id );
    // Try to get the field object so we know the type.
    if ( function_exists( 'get_field_object' ) ) {
        $field_obj = get_field_object( $field_name, $post_id );
        if ( $field_obj ) {
            $type = $field_obj['type'];
            switch ( $type ) {
                case 'image':
                    if ( is_array( $value ) && isset( $value['url'] ) ) {
                        return $value['url'];
                    }
                    break;
                case 'gallery':
                    if ( is_array( $value ) ) {
                        $urls = array();
                        foreach ( $value as $img ) {
                            if ( is_array( $img ) && isset( $img['url'] ) ) {
                                $urls[] = $img['url'];
                            }
                        }
                        return implode( ',', $urls );
                    }
                    break;
                case 'relationship':
                case 'post_object':
                    if ( is_array( $value ) ) {
                        $titles = array();
                        foreach ( $value as $item ) {
                            if ( is_object( $item ) ) {
                                $titles[] = $item->post_title;
                            } elseif ( is_numeric( $item ) ) {
                                $titles[] = get_the_title( $item );
                            }
                        }
                        return implode( ',', $titles );
                    } elseif ( is_numeric( $value ) ) {
                        return get_the_title( $value );
                    }
                    break;
                // Add further cases for other ACF field types as needed.
            }
        }
    }
    // Default: if the value is an array, encode it as JSON.
    if ( is_array( $value ) ) {
        return json_encode( $value );
    }
    return $value;
}

/**
 * Return an array of standard WordPress post fields.
 *
 * Format: key => label.
 *
 * @return array
 */
function ape_get_standard_fields() {
    return array(
        'ID'                   => 'ID',
        'post_author'          => 'Author ID',
        'post_date'            => 'Date',
        'post_date_gmt'        => 'Date GMT',
        'post_title'           => 'Title',
        'post_excerpt'         => 'Excerpt',
        'post_status'          => 'Status',
        'comment_status'       => 'Comment Status',
        'ping_status'          => 'Ping Status',
        'post_password'        => 'Password',
        'post_name'            => 'Slug',
        'to_ping'              => 'To Ping',
        'pinged'               => 'Pinged',
        'post_modified'        => 'Modified Date',
        'post_modified_gmt'    => 'Modified Date GMT',
        'post_content_filtered'=> 'Filtered Content',
        'post_parent'          => 'Parent',
        'guid'                 => 'GUID',
        'menu_order'           => 'Menu Order',
        'post_type'            => 'Post Type',
        'post_mime_type'       => 'MIME Type',
        'comment_count'        => 'Comment Count'
    );
}

/**
 * Return an associative array of available ACF fields for a given post type.
 *
 * Format: field_name => field label.
 *
 * @param string $post_type
 * @return array
 */
function ape_get_acf_fields( $post_type ) {
    $acf_fields = array();
    if ( function_exists( 'acf_get_field_groups' ) && function_exists( 'acf_get_fields' ) ) {
        $groups = acf_get_field_groups( array( 'post_type' => $post_type ) );
        if ( $groups ) {
            foreach ( $groups as $group ) {
                $fields = acf_get_fields( $group['key'] );
                if ( $fields ) {
                    foreach ( $fields as $field ) {
                        // Use field 'name' as key and 'label' as display.
                        $acf_fields[ $field['name'] ] = $field['label'];
                    }
                }
            }
        }
    }
    return $acf_fields;
}

/**
 * Export posts of a given type and selected fields to CSV.
 *
 * @param string $post_type The post type to export.
 * @param array  $selected_fields An array of field identifiers, prefixed with "std:" or "acf:".
 */
function ape_export_csv( $post_type, $selected_fields ) {
    // Clear any previous output.
    if ( ob_get_length() ) {
        ob_end_clean();
    }

    // Set CSV headers.
    header( 'Content-Type: text/csv; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename=export-' . $post_type . '.csv' );

    $output = fopen( 'php://output', 'w' );

    // Build header row for CSV (remove prefixes for display).
    $header_row = array();
    foreach ( $selected_fields as $field ) {
        list( $prefix, $field_name ) = explode( ':', $field, 2 );
        $header_row[] = $field_name;
    }
    fputcsv( $output, $header_row );

    // Query posts.
    $args = array(
        'post_type'      => $post_type,
        'posts_per_page' => -1,
        'post_status'    => 'any'
    );
    $query = new WP_Query( $args );

    if ( $query->have_posts() ) {
        while ( $query->have_posts() ) {
            $query->the_post();
            $post = get_post();
            $row  = array();

            foreach ( $selected_fields as $field ) {
                list( $prefix, $field_name ) = explode( ':', $field, 2 );
                $value = '';
                if ( 'std' === $prefix ) {
                    // Standard post field.
                    if ( isset( $post->$field_name ) ) {
                        $value = $post->$field_name;
                        // If it’s an excerpt, apply our replacement.
                        if ( 'post_excerpt' === $field_name ) {
                            $value = ape_replace_special_characters( $value );
                        }
                    }
                } elseif ( 'acf' === $prefix ) {
                    // ACF field.
                    $value = ape_process_acf_field( $field_name, $post->ID );
                    // If the field is named "excerpt", also filter it.
                    if ( 'excerpt' === $field_name && is_string( $value ) ) {
                        $value = ape_replace_special_characters( $value );
                    }
                    // For fields that might contain HTML lists (e.g. ingredients or instruction), convert them.
                    if ( in_array( $field_name, array( 'ingredients', 'instruction' ) ) && is_string( $value ) ) {
                        $value = ape_html_list_to_plain_text( $value );
                    }
                }
                $row[] = $value;
            }
            fputcsv( $output, $row );
        }
        wp_reset_postdata();
    }
    fclose( $output );
    exit;
}

/**
 * Register the plugin admin menu.
 */
function ape_admin_menu() {
    add_menu_page(
        'Post Exporter',
        'Post Exporter',
        'manage_options',
        'post-exporter',
        'ape_admin_page',
        'dashicons-download',
        20
    );
}
add_action( 'admin_menu', 'ape_admin_menu' );

/**
 * Display the plugin admin page.
 *
 * The page shows a dropdown of post types and, when a post type is chosen,
 * displays checkboxes for standard fields (under "Standard Fields") and ACF fields (under "ACF Fields").
 * When the form is submitted with selected fields, CSV export is triggered.
 */
function ape_admin_page() {
    // If export is triggered, process and export CSV.
    if ( isset( $_POST['export_csv'] ) && ! empty( $_POST['export_post_type'] ) && ! empty( $_POST['fields'] ) ) {
        $post_type = sanitize_text_field( $_POST['export_post_type'] );
        $selected_fields = array_map( 'sanitize_text_field', $_POST['fields'] );
        ape_export_csv( $post_type, $selected_fields );
    }
    ?>
    <div class="wrap">
        <h1>Advanced Post Exporter</h1>
        <form method="post" action="">
            <table class="form-table">
                <tr valign="top">
                    <th scope="row"><label for="export_post_type">Select Post Type</label></th>
                    <td>
                        <select name="export_post_type" id="export_post_type" onchange="this.form.submit()">
                            <option value="">-- Select Post Type --</option>
                            <?php
                            $post_types = get_post_types( array( 'public' => true ), 'objects' );
                            $selected_post_type = isset( $_POST['export_post_type'] ) ? sanitize_text_field( $_POST['export_post_type'] ) : '';
                            foreach ( $post_types as $pt ) {
                                printf(
                                    '<option value="%s" %s>%s</option>',
                                    esc_attr( $pt->name ),
                                    selected( $selected_post_type, $pt->name, false ),
                                    esc_html( $pt->label )
                                );
                            }
                            ?>
                        </select>
                    </td>
                </tr>
            </table>
            <?php
            // Only show field checkboxes if a post type is selected.
            if ( ! empty( $selected_post_type ) ) {
                $std_fields = ape_get_standard_fields();
                $acf_fields = ape_get_acf_fields( $selected_post_type );
                ?>
                <h2>Standard Fields</h2>
                <fieldset style="border:1px solid #ccc; padding:10px;">
                    <?php foreach ( $std_fields as $key => $label ) : 
                        // Build checkbox value with prefix "std:"
                        $value = 'std:' . $key;
                        // Retain checked state if previously selected.
                        $checked = ( isset( $_POST['fields'] ) && in_array( $value, $_POST['fields'] ) ) ? 'checked' : '';
                        ?>
                        <label style="display:block; margin-bottom:5px;">
                            <input type="checkbox" name="fields[]" value="<?php echo esc_attr( $value ); ?>" <?php echo $checked; ?>/>
                            <?php echo esc_html( $label . ' (' . $key . ')' ); ?>
                        </label>
                    <?php endforeach; ?>
                </fieldset>
                <?php if ( ! empty( $acf_fields ) ) : ?>
                    <h2>ACF Fields</h2>
                    <fieldset style="border:1px solid #ccc; padding:10px;">
                        <?php foreach ( $acf_fields as $key => $label ) : 
                            $value = 'acf:' . $key;
                            $checked = ( isset( $_POST['fields'] ) && in_array( $value, $_POST['fields'] ) ) ? 'checked' : '';
                            ?>
                            <label style="display:block; margin-bottom:5px;">
                                <input type="checkbox" name="fields[]" value="<?php echo esc_attr( $value ); ?>" <?php echo $checked; ?>/>
                                <?php echo esc_html( $label . ' (' . $key . ')' ); ?>
                            </label>
                        <?php endforeach; ?>
                    </fieldset>
                <?php else: ?>
                    <p>No ACF fields found (or ACF is not active) for the selected post type.</p>
                <?php
                endif;
            }
            ?>
            <p class="submit">
                <input type="submit" name="export_csv" class="button button-primary" value="Export CSV" />
            </p>
        </form>
    </div>
    <?php
}
