<?php
/**
 * Plugin Name: AI9News Floating Chatbot
 * Plugin URI:  https://ai9news.com
 * Description: AI 기반 플로팅 챗봇 - 브랜드 문의 응답, 감정 분석, 고객 퍼널 관리, Google Sheets 연동
 * Version:     1.0.0
 * Author:      AI9News
 * Author URI:  https://ai9news.com
 * License:     GPL v2 or later
 * Text Domain: ai9news-chatbot
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Plugin constants
define( 'AI9CB_VERSION',     '1.0.0' );
define( 'AI9CB_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'AI9CB_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );
define( 'AI9CB_PLUGIN_FILE', __FILE__ );

// Load includes
require_once AI9CB_PLUGIN_DIR . 'includes/class-settings.php';
require_once AI9CB_PLUGIN_DIR . 'includes/class-google-sheets.php';
require_once AI9CB_PLUGIN_DIR . 'includes/class-sentiment.php';
require_once AI9CB_PLUGIN_DIR . 'includes/class-admin-notify.php';
require_once AI9CB_PLUGIN_DIR . 'includes/class-funnel-tracker.php';
require_once AI9CB_PLUGIN_DIR . 'includes/class-chatbot-api.php';

// Load admin pages
if ( is_admin() ) {
    require_once AI9CB_PLUGIN_DIR . 'admin/settings.php';
    require_once AI9CB_PLUGIN_DIR . 'admin/dashboard.php';
}

/**
 * Main plugin class
 */
class AI9News_Chatbot {

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'init',            [ $this, 'init' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_frontend' ] );
        add_action( 'wp_footer',       [ $this, 'render_widget' ] );
        add_action( 'rest_api_init',   [ $this, 'register_rest_routes' ] );
    }

    public function init() {
        // Create DB tables on first run
        if ( false === get_option( 'ai9cb_db_version' ) ) {
            $this->create_tables();
            update_option( 'ai9cb_db_version', AI9CB_VERSION );
        }
    }

    // ---------------------------------------------------------------
    // Database tables
    // ---------------------------------------------------------------
    public function create_tables() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        $sql_leads = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ai9cb_leads (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            email         VARCHAR(200)    NOT NULL DEFAULT '',
            name          VARCHAR(200)    NOT NULL DEFAULT '',
            funnel_stage  VARCHAR(50)     NOT NULL DEFAULT 'awareness',
            created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            metadata      LONGTEXT,
            PRIMARY KEY (id),
            KEY email (email),
            KEY funnel_stage (funnel_stage)
        ) $charset;";

        $sql_conversations = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ai9cb_conversations (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            lead_id       BIGINT UNSIGNED NOT NULL DEFAULT 0,
            session_id    VARCHAR(100)    NOT NULL DEFAULT '',
            user_message  LONGTEXT        NOT NULL,
            bot_response  LONGTEXT        NOT NULL,
            sentiment     VARCHAR(50)     NOT NULL DEFAULT 'neutral',
            sentiment_score FLOAT         NOT NULL DEFAULT 0,
            is_urgent     TINYINT(1)      NOT NULL DEFAULT 0,
            admin_notified TINYINT(1)     NOT NULL DEFAULT 0,
            created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY lead_id (lead_id),
            KEY session_id (session_id),
            KEY sentiment (sentiment),
            KEY is_urgent (is_urgent),
            KEY created_at (created_at)
        ) $charset;";

        $sql_funnel_events = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ai9cb_funnel_events (
            id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            lead_id    BIGINT UNSIGNED NOT NULL DEFAULT 0,
            event_type VARCHAR(100)    NOT NULL DEFAULT '',
            event_data LONGTEXT,
            created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY lead_id (lead_id),
            KEY event_type (event_type),
            KEY created_at (created_at)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql_leads );
        dbDelta( $sql_conversations );
        dbDelta( $sql_funnel_events );
    }

    // ---------------------------------------------------------------
    // Frontend assets
    // ---------------------------------------------------------------
    public function enqueue_frontend() {
        $settings = AI9CB_Settings::get_instance();
        if ( ! $settings->get( 'chatbot_enabled', true ) ) {
            return;
        }

        wp_enqueue_style(
            'ai9cb-chatbot',
            AI9CB_PLUGIN_URL . 'assets/css/chatbot.css',
            [],
            AI9CB_VERSION
        );

        wp_enqueue_script(
            'ai9cb-chatbot',
            AI9CB_PLUGIN_URL . 'assets/js/chatbot.js',
            [ 'jquery' ],
            AI9CB_VERSION,
            true
        );

        // Pass configuration to JS — NO secret keys exposed
        wp_localize_script( 'ai9cb-chatbot', 'ai9cbConfig', [
            'restUrl'      => esc_url_raw( rest_url( 'ai9cb/v1' ) ),
            'nonce'        => wp_create_nonce( 'wp_rest' ),
            'greeting'     => esc_js( $settings->get( 'greeting_message', '안녕하세요! 무엇을 도와드릴까요? 😊' ) ),
            'botName'      => esc_js( $settings->get( 'bot_name', 'AI9 어시스턴트' ) ),
            'botAvatar'    => esc_url( $settings->get( 'bot_avatar', AI9CB_PLUGIN_URL . 'assets/css/bot-avatar.svg' ) ),
            'primaryColor' => esc_attr( $settings->get( 'primary_color', '#2563EB' ) ),
            'placeholder'  => esc_js( $settings->get( 'input_placeholder', '메시지를 입력하세요...' ) ),
            'emailPrompt'  => esc_js( $settings->get( 'email_prompt', '더 나은 지원을 위해 이메일 주소를 알려주시겠어요?' ) ),
        ] );
    }

    // ---------------------------------------------------------------
    // Chatbot widget HTML
    // ---------------------------------------------------------------
    public function render_widget() {
        $settings = AI9CB_Settings::get_instance();
        if ( ! $settings->get( 'chatbot_enabled', true ) ) {
            return;
        }
        include AI9CB_PLUGIN_DIR . 'templates/chatbot-widget.php';
    }

    // ---------------------------------------------------------------
    // REST API routes
    // ---------------------------------------------------------------
    public function register_rest_routes() {
        // Send a chat message
        register_rest_route( 'ai9cb/v1', '/chat', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handle_chat' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'message'    => [ 'required' => true,  'sanitize_callback' => 'sanitize_textarea_field' ],
                'session_id' => [ 'required' => true,  'sanitize_callback' => 'sanitize_text_field' ],
                'email'      => [ 'required' => false, 'sanitize_callback' => 'sanitize_email' ],
                'name'       => [ 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ],
            ],
        ] );

        // Save email (lead capture)
        register_rest_route( 'ai9cb/v1', '/lead', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handle_lead' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'email'      => [ 'required' => true,  'sanitize_callback' => 'sanitize_email' ],
                'name'       => [ 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ],
                'session_id' => [ 'required' => true,  'sanitize_callback' => 'sanitize_text_field' ],
            ],
        ] );
    }

    // ---------------------------------------------------------------
    // Chat handler
    // ---------------------------------------------------------------
    public function handle_chat( WP_REST_Request $request ) {
        // Rate limiting — max 30 req / 5 min per IP
        if ( ! $this->check_rate_limit() ) {
            return new WP_Error( 'too_many_requests', '요청이 너무 많습니다. 잠시 후 다시 시도해주세요.', [ 'status' => 429 ] );
        }

        $message    = $request->get_param( 'message' );
        $session_id = $request->get_param( 'session_id' );
        $email      = $request->get_param( 'email' ) ?: '';
        $name       = $request->get_param( 'name' ) ?: '';

        // Resolve lead
        $funnel  = AI9CB_Funnel_Tracker::get_instance();
        $lead_id = $funnel->get_or_create_lead( $session_id, $email, $name );

        // Get AI response (includes sentiment)
        $api      = AI9CB_Chatbot_API::get_instance();
        $response = $api->chat( $message, $session_id, $lead_id );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        // Persist conversation
        $this->save_conversation( $lead_id, $session_id, $message, $response );

        // Funnel stage update
        $funnel->update_stage( $lead_id, $response );

        // Admin notification if urgent
        if ( ! empty( $response['is_urgent'] ) ) {
            $notify = AI9CB_Admin_Notify::get_instance();
            $notify->send_urgent_alert( $lead_id, $email, $message, $response['bot_response'] );

            // Mark notified
            global $wpdb;
            $wpdb->update(
                $wpdb->prefix . 'ai9cb_conversations',
                [ 'admin_notified' => 1 ],
                [ 'lead_id' => $lead_id ],
                [ '%d' ],
                [ '%d' ]
            );
        }

        return rest_ensure_response( [
            'reply'      => $response['bot_response'],
            'sentiment'  => $response['sentiment'],
            'is_urgent'  => $response['is_urgent'],
            'contact_info' => $response['is_urgent'] ? $this->get_contact_info() : null,
        ] );
    }

    // ---------------------------------------------------------------
    // Lead handler
    // ---------------------------------------------------------------
    public function handle_lead( WP_REST_Request $request ) {
        $email      = $request->get_param( 'email' );
        $name       = $request->get_param( 'name' ) ?: '';
        $session_id = $request->get_param( 'session_id' );

        if ( ! is_email( $email ) ) {
            return new WP_Error( 'invalid_email', '유효한 이메일 주소를 입력해주세요.', [ 'status' => 400 ] );
        }

        $funnel  = AI9CB_Funnel_Tracker::get_instance();
        $lead_id = $funnel->get_or_create_lead( $session_id, $email, $name );

        // Sync to Google Sheets
        $sheets = AI9CB_Google_Sheets::get_instance();
        $sheets->append_lead( $email, $name, $session_id );

        return rest_ensure_response( [ 'success' => true, 'lead_id' => $lead_id ] );
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------
    private function save_conversation( $lead_id, $session_id, $message, $response ) {
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'ai9cb_conversations',
            [
                'lead_id'       => $lead_id,
                'session_id'    => $session_id,
                'user_message'  => $message,
                'bot_response'  => $response['bot_response'],
                'sentiment'     => $response['sentiment'],
                'sentiment_score' => $response['sentiment_score'],
                'is_urgent'     => $response['is_urgent'] ? 1 : 0,
                'created_at'    => current_time( 'mysql' ),
            ],
            [ '%d', '%s', '%s', '%s', '%s', '%f', '%d', '%s' ]
        );

        // Save to Google Sheets
        $sheets = AI9CB_Google_Sheets::get_instance();
        $email  = $this->get_lead_email( $lead_id );
        $sheets->append_conversation( $email, $message, $response['bot_response'], $response['sentiment'], [
            'input_tokens'  => $response['input_tokens']  ?? 0,
            'output_tokens' => $response['output_tokens'] ?? 0,
            'model'         => $response['model']         ?? '',
        ] );
    }

    private function get_lead_email( $lead_id ) {
        global $wpdb;
        return $wpdb->get_var( $wpdb->prepare(
            "SELECT email FROM {$wpdb->prefix}ai9cb_leads WHERE id = %d",
            $lead_id
        ) ) ?: '';
    }

    private function get_contact_info() {
        $s = AI9CB_Settings::get_instance();
        return [
            'phone'   => $s->get( 'contact_phone', '' ),
            'email'   => $s->get( 'contact_email', '' ),
            'kakao'   => $s->get( 'contact_kakao', '' ),
            'message' => $s->get( 'urgent_contact_message', '긴급 문의는 아래 연락처로 직접 연락해 주세요.' ),
        ];
    }

    private function check_rate_limit() {
        $ip  = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $key = 'ai9cb_rl_' . md5( $ip );
        $count = (int) get_transient( $key );
        if ( $count >= 30 ) {
            return false;
        }
        set_transient( $key, $count + 1, 5 * MINUTE_IN_SECONDS );
        return true;
    }
}

// Activation / deactivation hooks
register_activation_hook( __FILE__, function () {
    AI9News_Chatbot::get_instance()->create_tables();
    flush_rewrite_rules();
} );

register_deactivation_hook( __FILE__, function () {
    flush_rewrite_rules();
} );

// Boot
add_action( 'plugins_loaded', function () {
    AI9News_Chatbot::get_instance();
    AI9CB_Settings::get_instance();
    if ( is_admin() ) {
        AI9CB_Admin_Settings_Page::get_instance();
        AI9CB_Admin_Dashboard_Page::get_instance();
    }
} );

// AJAX: clear cache (admin only)
add_action( 'wp_ajax_ai9cb_clear_cache', function () {
    check_ajax_referer( 'ai9cb_admin', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Forbidden', 403 );
    }
    AI9CB_Google_Sheets::clear_cache();
    wp_send_json_success( 'Cache cleared' );
} );
