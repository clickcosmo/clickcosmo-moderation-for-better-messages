<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class MBM_BM {

    private static $instance = null;

    const OPTION_KEY = 'mbm_bm_settings';

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct() {
        /*
         * This filter is registered as soon as WordPress loads this plugin.
         * A normal plugin is early enough; it does not need to be an MU-plugin.
         */
        add_filter(
            'better_messages_is_moderation_enabled',
            array( $this, 'force_pre_moderation' ),
            10,
            4
        );

        add_action( 'template_redirect', array( $this, 'handle_moderation_action' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );

        add_shortcode(
            'message_board_moderation_bm',
            array( $this, 'moderation_shortcode' )
        );

        add_action(
            'better_messages_message_after_save',
            array( $this, 'notify_moderators' ),
            20,
            1
        );

        add_action( 'plugins_loaded', array( $this, 'register_prz_addon' ), 20 );
        add_action( 'admin_menu', array( $this, 'register_settings_page' ), 21 );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
        add_action( 'admin_notices', array( $this, 'dependency_notice' ) );
    }

    public function register_assets() {
		wp_register_style(
			'mbm-bm-moderation',
			MBM_BM_URL . 'assets/message-board-moderation.css',
			array(),
			filemtime( MBM_BM_DIR . 'assets/message-board-moderation.css' )
		);
    }

    private function defaults() {
        return array(
            'boards'           => '',
            'moderation_url'   => '/message-moderation/',
            'email_notify'     => 1,
            'page_text_color'  => '#fff',
            'board_heading_color' => '#fff',
            'badge_background_color' => '#f2f2f2',
            'badge_text_color' => '#222',
            'card_background_color' => '#fff',
            'card_text_color' => '#222',
            'card_border_color' => '#ddd',
            'meta_text_color' => '#777',
            'approve_background_color' => '#2e7d32',
            'approve_text_color' => '#fff',
            'reject_background_color' => '#c62828',
            'reject_text_color' => '#fff',
            'notice_background_color' => '#f5f5f5',
            'notice_text_color' => '#222',
            'attachment_border_color' => '#ddd',
            'content_max_width' => 900,
            'card_padding' => 20,
            'card_border_radius' => 12,
            'button_border_radius' => 7,
            'attachment_max_height' => 700,
        );
    }

    private function settings() {
        $saved = get_option( self::OPTION_KEY, array() );

        if ( ! is_array( $saved ) ) {
            $saved = array();
        }

        return wp_parse_args( $saved, $this->defaults() );
    }

    /**
     * Boards are stored one per line:
     * 123|Board Name
     */
    public function get_boards() {
        $settings = $this->settings();
        $raw      = isset( $settings['boards'] ) ? (string) $settings['boards'] : '';
        $boards   = array();

        foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
            $line = trim( $line );

            if ( '' === $line ) {
                continue;
            }

            $parts = array_map( 'trim', explode( '|', $line, 2 ) );

            if ( empty( $parts[0] ) || ! ctype_digit( $parts[0] ) ) {
                continue;
            }

            $thread_id = absint( $parts[0] );

            if ( ! $thread_id ) {
                continue;
            }

            $name = isset( $parts[1] ) && '' !== $parts[1]
                ? sanitize_text_field( $parts[1] )
                : sprintf( 'Board %d', $thread_id );

            $boards[ $thread_id ] = $name;
        }

        return $boards;
    }

    public function force_pre_moderation( $enabled, $user_id, $thread_id, $is_new_conversation ) {
        $boards = $this->get_boards();

        if ( isset( $boards[ (int) $thread_id ] ) ) {
            return true;
        }

        return $enabled;
    }

    private function better_messages_ready() {
        return function_exists( 'Better_Messages' );
    }

    private function prz_setlist_builder_ready() {
        return defined( 'PRJ_SB_PLUGIN_FILE' )
            && defined( 'PRJ_SB_VERSION' )
            && function_exists( 'prj_sb_get_addons_map' );
    }

    public function dependency_notice() {
        if ( ! current_user_can( 'activate_plugins' ) || $this->better_messages_ready() ) {
            return;
        }

        echo '<div class="notice notice-warning"><p>';
        echo esc_html__(
            'Message Board Moderation for Better Messages requires Better Messages to be active.',
            'message-board-moderation-for-bm'
        );
        echo '</p></div>';
    }

    private function user_can_access_thread( $thread_id ) {
        if ( ! is_user_logged_in() || ! $this->better_messages_ready() ) {
            return false;
        }

        $thread_id = absint( $thread_id );
        $boards    = $this->get_boards();

        if ( ! isset( $boards[ $thread_id ] ) ) {
            return false;
        }

        if ( current_user_can( 'manage_options' ) ) {
            return true;
        }

        return (bool) Better_Messages()->functions->is_thread_moderator(
            $thread_id,
            get_current_user_id()
        );
    }

    private function user_can_access_any_board() {
        if ( ! is_user_logged_in() || ! $this->better_messages_ready() ) {
            return false;
        }

        if ( current_user_can( 'manage_options' ) ) {
            return true;
        }

        foreach ( $this->get_boards() as $thread_id => $board_name ) {
            if ( $this->user_can_access_thread( $thread_id ) ) {
                return true;
            }
        }

        return false;
    }

    public function handle_moderation_action() {
        if ( empty( $_POST['mbm_bm_action'] ) ) {
            return;
        }

        if (
            empty( $_POST['mbm_bm_nonce'] ) ||
            ! wp_verify_nonce(
                sanitize_text_field( wp_unslash( $_POST['mbm_bm_nonce'] ) ),
                'mbm_bm_moderation'
            )
        ) {
            return;
        }

        if ( ! $this->better_messages_ready() ) {
            return;
        }

        $message_id = isset( $_POST['message_id'] )
            ? absint( $_POST['message_id'] )
            : 0;

        $action = sanitize_key( wp_unslash( $_POST['mbm_bm_action'] ) );

        $return_url = isset( $_POST['mbm_bm_return_url'] )
            ? wp_unslash( $_POST['mbm_bm_return_url'] )
            : '';

        if ( ! $message_id ) {
            return;
        }

        $message = Better_Messages()->functions->get_message( $message_id );

        if ( ! $message ) {
            return;
        }

        $thread_id = (int) $message->thread_id;

        if ( ! (int) $message->is_pending ) {
            return;
        }

        if ( ! $this->user_can_access_thread( $thread_id ) ) {
            return;
        }

        if ( 'approve' === $action ) {
            Better_Messages()->moderation->approve_message(
                $message_id,
                get_current_user_id()
            );

            $this->redirect_after_action( 'approved', $return_url );
        }

        if ( 'reject' === $action ) {
            Better_Messages()->functions->delete_message(
                $message_id,
                $thread_id
            );

            $this->redirect_after_action( 'rejected', $return_url );
        }
    }

    private function redirect_after_action( $status, $return_url = '' ) {
        $referer = '';

        if ( $return_url ) {
            $return_url = esc_url_raw( wp_unslash( $return_url ) );

            if ( wp_validate_redirect( $return_url, false ) ) {
                $referer = remove_query_arg( 'mbm_bm_mod', $return_url );
            }
        }

        if ( ! $referer ) {
            $referer = wp_get_referer();

            if ( $referer ) {
                $referer = remove_query_arg( 'mbm_bm_mod', $referer );
            }
        }

        if ( ! $referer ) {
            $settings = $this->settings();
            $referer  = home_url( $settings['moderation_url'] );
        }

        wp_safe_redirect(
            add_query_arg(
                'mbm_bm_mod',
                sanitize_key( $status ),
                $referer
            )
        );
        exit;
    }

    public function moderation_shortcode() {
        if ( ! is_user_logged_in() ) {
            return '<p>' .
                esc_html__(
                    'Please sign in to access message moderation.',
                    'message-board-moderation-for-bm'
                ) .
                '</p>';
        }

        if ( ! $this->better_messages_ready() ) {
            return '<p>' .
                esc_html__(
                    'Better Messages is unavailable.',
                    'message-board-moderation-for-bm'
                ) .
                '</p>';
        }

        if ( ! $this->user_can_access_any_board() ) {
            return '<p>' .
                esc_html__(
                    'You do not have permission to access message moderation.',
                    'message-board-moderation-for-bm'
                ) .
                '</p>';
        }

        wp_enqueue_style( 'mbm-bm-moderation' );
        $this->add_frontend_variables();

        global $wpdb;

        $boards         = $this->get_boards();
        $messages_table = bm_get_table( 'messages' );
        $allowed_boards = array();

        foreach ( $boards as $thread_id => $board_name ) {
            if ( $this->user_can_access_thread( $thread_id ) ) {
                $allowed_boards[ (int) $thread_id ] = $board_name;
            }
        }

        ob_start();
        ?>
        <div class="mbm-bm-moderation">

            <h2><?php esc_html_e( 'Message Moderation', 'message-board-moderation-for-bm' ); ?></h2>

            <p>
                <?php esc_html_e( 'Review messages waiting for approval.', 'message-board-moderation-for-bm' ); ?>
            </p>

            <?php if ( isset( $_GET['mbm_bm_mod'] ) ) : ?>
                <?php
                $status = sanitize_key( wp_unslash( $_GET['mbm_bm_mod'] ) );
                ?>
                <?php if ( 'approved' === $status ) : ?>
                    <div class="mbm-bm-notice">
                        <?php esc_html_e( 'Message approved.', 'message-board-moderation-for-bm' ); ?>
                    </div>
                <?php elseif ( 'rejected' === $status ) : ?>
                    <div class="mbm-bm-notice">
                        <?php esc_html_e( 'Message rejected.', 'message-board-moderation-for-bm' ); ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <?php foreach ( $allowed_boards as $thread_id => $board_name ) : ?>

                <?php
                $messages = $wpdb->get_results(
                    $wpdb->prepare(
                        "
                        SELECT
                            id,
                            thread_id,
                            sender_id,
                            message,
                            date_sent,
                            created_at
                        FROM {$messages_table}
                        WHERE thread_id = %d
                          AND is_pending = 1
                          AND created_at > 0
                          AND message != '<!-- BBPM START THREAD -->'
                        ORDER BY created_at ASC
                        ",
                        $thread_id
                    )
                );
                ?>

                <section class="mbm-bm-board">

                    <div class="mbm-bm-board-header">
                        <h3><?php echo esc_html( $board_name ); ?></h3>

                        <span class="mbm-bm-count">
                            <?php
                            echo esc_html(
                                sprintf(
                                    _n(
                                        '%d pending',
                                        '%d pending',
                                        count( $messages ),
                                        'message-board-moderation-for-bm'
                                    ),
                                    count( $messages )
                                )
                            );
                            ?>
                        </span>
                    </div>

                    <?php if ( empty( $messages ) ) : ?>

                        <div class="mbm-bm-empty">
                            <?php esc_html_e( 'No messages are waiting for approval.', 'message-board-moderation-for-bm' ); ?>
                        </div>

                    <?php else : ?>

                        <div class="mbm-bm-list">

                            <?php foreach ( $messages as $message ) : ?>
                                <?php
                                $sender = get_userdata( (int) $message->sender_id );

                                $sender_name = $sender
                                    ? $sender->display_name
                                    : sprintf( 'User #%d', (int) $message->sender_id );
                                ?>

                                <article class="mbm-bm-item">

                                    <div class="mbm-bm-meta">
                                        <strong><?php echo esc_html( $sender_name ); ?></strong>

                                        <?php if ( ! empty( $message->date_sent ) ) : ?>
                                            <span>
                                                <?php
                                                echo esc_html(
                                                    mysql2date(
                                                        'F j, Y g:i a',
                                                        $message->date_sent
                                                    )
                                                );
                                                ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>

                                    <div class="mbm-bm-message">
                                        <?php echo $this->render_message_content( $message ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                    </div>

                                    <div class="mbm-bm-actions">
                                        <form method="post">
                                            <?php
                                            wp_nonce_field(
                                                'mbm_bm_moderation',
                                                'mbm_bm_nonce'
                                            );
                                            ?>

                                            <input
                                                type="hidden"
                                                name="message_id"
                                                value="<?php echo esc_attr( $message->id ); ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="mbm_bm_return_url"
                                                value="<?php echo esc_url( remove_query_arg( 'mbm_bm_mod' ) ); ?>"
                                            >

                                            <button
                                                type="submit"
                                                name="mbm_bm_action"
                                                value="approve"
                                                class="mbm-bm-approve"
                                            >
                                                <?php esc_html_e( 'Approve', 'message-board-moderation-for-bm' ); ?>
                                            </button>

                                            <button
                                                type="submit"
                                                name="mbm_bm_action"
                                                value="reject"
                                                class="mbm-bm-reject"
                                                onclick="return confirm('<?php echo esc_js( __( 'Reject and delete this message?', 'message-board-moderation-for-bm' ) ); ?>');"
                                            >
                                                <?php esc_html_e( 'Reject', 'message-board-moderation-for-bm' ); ?>
                                            </button>
                                        </form>
                                    </div>

                                </article>
                            <?php endforeach; ?>

                        </div>

                    <?php endif; ?>

                </section>

            <?php endforeach; ?>

        </div>
        <?php

        return ob_get_clean();
    }

    /**
     * Render text plus Better Messages file attachments.
     *
     * Better Messages stores chat files as WordPress attachment posts and
     * associates them to the message with the
     * "bp-better-messages-message-id" post meta key.
     */
    private function render_message_content( $message ) {
        $html = '';

        if ( ! empty( $message->message ) ) {
            $html .= wp_kses_post(
                wpautop( $message->message )
            );
        }

        $attachments = $this->get_message_attachments(
            (int) $message->id,
            (int) $message->thread_id
        );

        if ( ! empty( $attachments ) ) {
            $html .= '<div class="mbm-bm-attachments">';

            foreach ( $attachments as $attachment ) {
                $html .= $this->render_attachment(
                    $attachment,
                    (int) $message->id,
                    (int) $message->thread_id
                );
            }

            $html .= '</div>';
        }

        if ( '' === trim( wp_strip_all_tags( $html ) ) && empty( $attachments ) ) {
            $html = '<em>' .
                esc_html__(
                    'This message has no text content.',
                    'message-board-moderation-for-bm'
                ) .
                '</em>';
        }

        return $html;
    }

    private function get_message_attachments( $message_id, $thread_id ) {
        global $wpdb;

        $message_id = absint( $message_id );

        if ( ! $message_id ) {
            return array();
        }

        /*
         * Query the postmeta table directly rather than the Media Library API.
         * Current Better Messages versions intentionally hide chat attachments
         * from normal WordPress media API listings.
         */
        $attachment_ids = $wpdb->get_col(
            $wpdb->prepare(
                "
                SELECT DISTINCT pm.post_id
                FROM {$wpdb->postmeta} pm
                INNER JOIN {$wpdb->posts} p
                    ON p.ID = pm.post_id
                WHERE pm.meta_key = %s
                  AND pm.meta_value = %d
                  AND p.post_type = 'attachment'
                ORDER BY p.ID ASC
                ",
                'bp-better-messages-message-id',
                $message_id
            )
        );

        if ( empty( $attachment_ids ) ) {
            return array();
        }

        $attachments = array();

        foreach ( $attachment_ids as $attachment_id ) {
            $attachment = get_post( absint( $attachment_id ) );

            if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
                continue;
            }

            /*
             * If BM stored a thread id on the attachment, make sure it belongs
             * to the thread currently being moderated.
             */
            $stored_thread_id = absint(
                get_post_meta(
                    $attachment->ID,
                    'bp-better-messages-thread-id',
                    true
                )
            );

            if ( $stored_thread_id && $stored_thread_id !== absint( $thread_id ) ) {
                continue;
            }

            $attachments[] = $attachment;
        }

        return $attachments;
    }

    private function render_attachment( $attachment, $message_id, $thread_id ) {
        $attachment_id = (int) $attachment->ID;
        $mime_type     = (string) $attachment->post_mime_type;
        $url           = wp_get_attachment_url( $attachment_id );

        if ( ! $url ) {
            return '';
        }

        $url = apply_filters(
            'better_messages_attachment_url',
            $url,
            $attachment_id,
            $message_id,
            $thread_id
        );

        $name = get_post_meta(
            $attachment_id,
            'bp-better-messages-original-name',
            true
        );

        if ( empty( $name ) ) {
            $name = wp_basename( get_attached_file( $attachment_id ) );
        }

        if ( 0 === strpos( $mime_type, 'image/' ) ) {
            $image = wp_get_attachment_image(
                $attachment_id,
                'large',
                false,
                array(
                    'class'   => 'mbm-bm-attachment-image',
                    'loading' => 'lazy',
                )
            );

            if ( ! $image ) {
                $image = sprintf(
                    '<img class="mbm-bm-attachment-image" src="%1$s" alt="%2$s" loading="lazy">',
                    esc_url( $url ),
                    esc_attr( $name )
                );
            }

            return sprintf(
                '<a class="mbm-bm-attachment mbm-bm-attachment-image-link" href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
                esc_url( $url ),
                $image
            );
        }

        return sprintf(
            '<a class="mbm-bm-attachment mbm-bm-attachment-file" href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
            esc_url( $url ),
            esc_html( $name ? $name : __( 'View attachment', 'message-board-moderation-for-bm' ) )
        );
    }

    public function notify_moderators( $message ) {
        if (
            ! $message ||
            empty( $message->id ) ||
            ! $this->better_messages_ready()
        ) {
            return;
        }

        $settings = $this->settings();

        if ( empty( $settings['email_notify'] ) ) {
            return;
        }

        $boards    = $this->get_boards();
        $thread_id = (int) $message->thread_id;

        if ( ! isset( $boards[ $thread_id ] ) ) {
            return;
        }

        if ( empty( $message->is_pending ) ) {
            return;
        }

        $already_sent = Better_Messages()->functions->get_message_meta(
            $message->id,
            'mbm_bm_mod_notification_sent',
            true
        );

        if ( $already_sent ) {
            return;
        }

        $recipients = Better_Messages()->functions->get_recipients( $thread_id );

        if ( empty( $recipients ) ) {
            return;
        }

        $moderator_emails = array();

        foreach ( $recipients as $recipient ) {
            $user_id = isset( $recipient->user_id )
                ? (int) $recipient->user_id
                : 0;

            if ( ! $user_id ) {
                continue;
            }

            $is_moderator =
                Better_Messages()->functions->is_thread_moderator(
                    $thread_id,
                    $user_id
                )
                ||
                Better_Messages()->functions->is_thread_super_moderator(
                    $user_id,
                    $thread_id
                );

            if ( ! $is_moderator ) {
                continue;
            }

            $user = get_userdata( $user_id );

            if ( $user && is_email( $user->user_email ) ) {
                $moderator_emails[] = $user->user_email;
            }
        }

        $moderator_emails = array_unique( $moderator_emails );

        if ( empty( $moderator_emails ) ) {
            return;
        }

        $sender = get_userdata( (int) $message->sender_id );

        $sender_name = $sender
            ? $sender->display_name
            : __( 'A user', 'message-board-moderation-for-bm' );

        $moderation_url = home_url( $settings['moderation_url'] );

        $subject = sprintf(
            __( '%s post waiting for approval', 'message-board-moderation-for-bm' ),
            $boards[ $thread_id ]
        );

        $body  = sprintf(
            __( "A new post is waiting for approval in %s.\n\n", 'message-board-moderation-for-bm' ),
            $boards[ $thread_id ]
        );
        $body .= sprintf(
            __( "Submitted by: %s\n\n", 'message-board-moderation-for-bm' ),
            $sender_name
        );
        $body .= __( "Review it here:\n", 'message-board-moderation-for-bm' );
        $body .= $moderation_url . "\n";

        $sent = wp_mail(
            $moderator_emails,
            $subject,
            $body
        );

        if ( $sent ) {
            Better_Messages()->functions->update_message_meta(
                $message->id,
                'mbm_bm_mod_notification_sent',
                '1'
            );
        }
    }

    public function register_settings_page() {
        if ( $this->prz_setlist_builder_ready() ) {
            return;
        }

        add_menu_page(
            __( 'Moderation for Better Messages', 'message-board-moderation-for-bm' ),
            __( 'Moderation for Better Messages', 'message-board-moderation-for-bm' ),
            'manage_options',
            'message-board-moderation-for-bm',
            array( $this, 'render_settings_page' ),
            'dashicons-shield-alt',
            20
        );
    }

    public function register_prz_addon() {
        if ( ! $this->prz_setlist_builder_ready() ) {
            return;
        }

        add_filter( 'prj_sb_addons_map', array( $this, 'add_prz_addon' ) );
    }

    public function add_prz_addon( $addons ) {
        if ( ! is_array( $addons ) ) {
            $addons = array();
        }

        $addons['message_board_moderation'] = array(
            'label'           => __( 'Message Board Moderation', 'message-board-moderation-for-bm' ),
            'dashboard_label' => __( 'Message Moderation', 'message-board-moderation-for-bm' ),
            'description'     => __( 'Moderate selected Better Messages message boards.', 'message-board-moderation-for-bm' ),
            'plugin_file'     => plugin_basename( MBM_BM_FILE ),
            'open_url'        => admin_url( 'admin.php?page=message-board-moderation-for-bm' ),
            'open_label'      => __( 'Open Moderation', 'message-board-moderation-for-bm' ),
        );

        return $addons;
    }

    public function register_settings() {
        register_setting(
            'mbm_bm_settings_group',
            self::OPTION_KEY,
            array( $this, 'sanitize_settings' )
        );
    }

    public function sanitize_settings( $input ) {
        $output = $this->defaults();
        $input  = is_array( $input ) ? $input : array();

        $output['boards'] = isset( $input['boards'] )
            ? sanitize_textarea_field( $input['boards'] )
            : '';

        $moderation_url = isset( $input['moderation_url'] )
            ? trim( sanitize_text_field( $input['moderation_url'] ) )
            : '/message-moderation/';

        if ( '' === $moderation_url ) {
            $moderation_url = '/message-moderation/';
        }

        if ( '/' !== $moderation_url[0] ) {
            $moderation_url = '/' . $moderation_url;
        }

        $output['moderation_url'] = $moderation_url;
        $output['email_notify']   = empty( $input['email_notify'] ) ? 0 : 1;

        $color_keys = array(
            'page_text_color',
            'board_heading_color',
            'badge_background_color',
            'badge_text_color',
            'card_background_color',
            'card_text_color',
            'card_border_color',
            'meta_text_color',
            'approve_background_color',
            'approve_text_color',
            'reject_background_color',
            'reject_text_color',
            'notice_background_color',
            'notice_text_color',
            'attachment_border_color',
        );

        foreach ( $color_keys as $key ) {
            $color = isset( $input[ $key ] ) ? sanitize_hex_color( $input[ $key ] ) : '';
            if ( $color ) {
                $output[ $key ] = $color;
            }
        }

        $number_limits = array(
            'content_max_width' => array( 320, 2000 ),
            'card_padding' => array( 0, 100 ),
            'card_border_radius' => array( 0, 50 ),
            'button_border_radius' => array( 0, 50 ),
            'attachment_max_height' => array( 100, 2000 ),
        );

        foreach ( $number_limits as $key => $limits ) {
            if ( isset( $input[ $key ] ) && '' !== $input[ $key ] ) {
                $output[ $key ] = min( $limits[1], max( $limits[0], absint( $input[ $key ] ) ) );
            }
        }

        return $output;
    }

    public function enqueue_admin_assets( $hook ) {
        if ( false === strpos( $hook, 'message-board-moderation-for-bm' ) ) {
            return;
        }

        wp_enqueue_style( 'wp-color-picker' );
        wp_enqueue_script( 'wp-color-picker' );
        wp_add_inline_script(
            'wp-color-picker',
            "jQuery(function ($) { $('.mbm-bm-color-field').wpColorPicker(); });"
        );
    }

    private function add_frontend_variables() {
        $settings = $this->settings();
        $variables = array(
            '--mbm-page-text' => $settings['page_text_color'],
            '--mbm-board-heading' => $settings['board_heading_color'],
            '--mbm-badge-bg' => $settings['badge_background_color'],
            '--mbm-badge-text' => $settings['badge_text_color'],
            '--mbm-card-bg' => $settings['card_background_color'],
            '--mbm-card-text' => $settings['card_text_color'],
            '--mbm-card-border' => $settings['card_border_color'],
            '--mbm-meta-text' => $settings['meta_text_color'],
            '--mbm-approve-bg' => $settings['approve_background_color'],
            '--mbm-approve-text' => $settings['approve_text_color'],
            '--mbm-reject-bg' => $settings['reject_background_color'],
            '--mbm-reject-text' => $settings['reject_text_color'],
            '--mbm-notice-bg' => $settings['notice_background_color'],
            '--mbm-notice-text' => $settings['notice_text_color'],
            '--mbm-attachment-border' => $settings['attachment_border_color'],
            '--mbm-content-width' => absint( $settings['content_max_width'] ) . 'px',
            '--mbm-card-padding' => absint( $settings['card_padding'] ) . 'px',
            '--mbm-card-radius' => absint( $settings['card_border_radius'] ) . 'px',
            '--mbm-button-radius' => absint( $settings['button_border_radius'] ) . 'px',
            '--mbm-attachment-height' => absint( $settings['attachment_max_height'] ) . 'px',
        );

        $css = '.mbm-bm-moderation{';
        foreach ( $variables as $name => $value ) {
            $css .= $name . ':' . esc_attr( $value ) . ';';
        }
        $css .= '}';
        wp_add_inline_style( 'mbm-bm-moderation', $css );
    }

    public function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $settings = $this->settings();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Message Board Moderation for Better Messages', 'message-board-moderation-for-bm' ); ?></h1>

            <form method="post" action="options.php">
                <?php settings_fields( 'mbm_bm_settings_group' ); ?>

                <table class="form-table" role="presentation">

                    <tr>
                        <th colspan="2"><h2><?php esc_html_e( 'Moderation Boards', 'message-board-moderation-for-bm' ); ?></h2></th>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="mbm-bm-boards">
                                <?php esc_html_e( 'Moderated boards', 'message-board-moderation-for-bm' ); ?>
                            </label>
                        </th>
                        <td>
                            <textarea
                                id="mbm-bm-boards"
                                name="<?php echo esc_attr( self::OPTION_KEY ); ?>[boards]"
                                rows="8"
                                class="large-text code"
                            ><?php echo esc_textarea( $settings['boards'] ); ?></textarea>

                            <p class="description">
                                <?php esc_html_e( 'One board per line using: thread_id|Board Name', 'message-board-moderation-for-bm' ); ?>
                            </p>
                            <p class="description">
                                <?php esc_html_e( 'Example: 123|Community Events', 'message-board-moderation-for-bm' ); ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th colspan="2"><h2><?php esc_html_e( 'Moderation Page', 'message-board-moderation-for-bm' ); ?></h2></th>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="mbm-bm-moderation-url">
                                <?php esc_html_e( 'Moderation page path', 'message-board-moderation-for-bm' ); ?>
                            </label>
                        </th>
                        <td>
                            <input
                                id="mbm-bm-moderation-url"
                                type="text"
                                class="regular-text"
                                name="<?php echo esc_attr( self::OPTION_KEY ); ?>[moderation_url]"
                                value="<?php echo esc_attr( $settings['moderation_url'] ); ?>"
                            >
                            <p class="description">
                                <?php esc_html_e( 'Create a WordPress page at this path and place [message_board_moderation_bm] on it.', 'message-board-moderation-for-bm' ); ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th colspan="2"><h2><?php esc_html_e( 'Notifications', 'message-board-moderation-for-bm' ); ?></h2></th>
                    </tr>
                    <tr>
                        <th scope="row">
                            <?php esc_html_e( 'Moderator email notifications', 'message-board-moderation-for-bm' ); ?>
                        </th>
                        <td>
                            <label>
                                <input
                                    type="checkbox"
                                    name="<?php echo esc_attr( self::OPTION_KEY ); ?>[email_notify]"
                                    value="1"
                                    <?php checked( ! empty( $settings['email_notify'] ) ); ?>
                                >
                                <?php esc_html_e( 'Email Better Messages thread moderators when a pending post is submitted.', 'message-board-moderation-for-bm' ); ?>
                            </label>
                        </td>
                    </tr>

                    <tr>
                        <th colspan="2"><h2><?php esc_html_e( 'Frontend Appearance', 'message-board-moderation-for-bm' ); ?></h2></th>
                    </tr>
                    <?php
                    $color_fields = array(
                        'page_text_color' => 'Page heading / intro text color',
                        'board_heading_color' => 'Board heading color',
                        'badge_background_color' => 'Badge background color',
                        'badge_text_color' => 'Badge text color',
                        'card_background_color' => 'Card background color',
                        'card_text_color' => 'Card text color',
                        'card_border_color' => 'Card border color',
                        'meta_text_color' => 'Secondary/meta text color',
                        'approve_background_color' => 'Approve button background',
                        'approve_text_color' => 'Approve button text',
                        'reject_background_color' => 'Reject button background',
                        'reject_text_color' => 'Reject button text',
                        'notice_background_color' => 'Notice/empty-state background',
                        'notice_text_color' => 'Notice/empty-state text',
                        'attachment_border_color' => 'Attachment/file border color',
                    );
                    foreach ( $color_fields as $key => $label ) :
                        ?>
                        <tr>
                            <th scope="row"><label for="mbm-bm-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
                            <td>
                                <input
                                    id="mbm-bm-<?php echo esc_attr( $key ); ?>"
                                    class="mbm-bm-color-field"
                                    type="text"
                                    name="<?php echo esc_attr( self::OPTION_KEY ); ?>[<?php echo esc_attr( $key ); ?>]"
                                    value="<?php echo esc_attr( $settings[ $key ] ); ?>"
                                    data-default-color="<?php echo esc_attr( $this->defaults()[ $key ] ); ?>"
                                >
                            </td>
                        </tr>
                    <?php endforeach; ?>

                    <tr>
                        <th colspan="2"><h2><?php esc_html_e( 'Layout', 'message-board-moderation-for-bm' ); ?></h2></th>
                    </tr>
                    <?php
                    $number_fields = array(
                        'content_max_width' => array( 'Content max width', 320, 2000 ),
                        'card_padding' => array( 'Message card padding', 0, 100 ),
                        'card_border_radius' => array( 'Message card border radius', 0, 50 ),
                        'button_border_radius' => array( 'Button border radius', 0, 50 ),
                        'attachment_max_height' => array( 'Maximum attachment image height', 100, 2000 ),
                    );
                    foreach ( $number_fields as $key => $field ) :
                        ?>
                        <tr>
                            <th scope="row"><label for="mbm-bm-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $field[0] ); ?></label></th>
                            <td>
                                <input
                                    id="mbm-bm-<?php echo esc_attr( $key ); ?>"
                                    type="number"
                                    min="<?php echo esc_attr( $field[1] ); ?>"
                                    max="<?php echo esc_attr( $field[2] ); ?>"
                                    step="1"
                                    name="<?php echo esc_attr( self::OPTION_KEY ); ?>[<?php echo esc_attr( $key ); ?>]"
                                    value="<?php echo esc_attr( $settings[ $key ] ); ?>"
                                > px
                                <p class="description"><?php esc_html_e( 'Enter a whole-pixel value.', 'message-board-moderation-for-bm' ); ?></p>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                </table>

                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}
