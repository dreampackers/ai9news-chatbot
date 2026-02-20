<?php
/**
 * Settings manager — all sensitive values (API keys) are stored
 * in the WordPress options table (server-side only) and NEVER
 * exposed to the browser.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AI9CB_Settings {

    private static $instance  = null;
    private        $option_key = 'ai9cb_settings';
    private        $settings   = [];

    // Keys that must NEVER be returned to the frontend
    private $secret_keys = [
        'claude_api_key',
        'google_sheets_credentials',
        'admin_notify_email',
        'smtp_password',
    ];

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $stored = get_option( $this->option_key, [] );
        $this->settings = is_array( $stored ) ? $stored : [];
    }

    public function get( $key, $default = '' ) {
        return array_key_exists( $key, $this->settings )
            ? $this->settings[ $key ]
            : $default;
    }

    public function set( $key, $value ) {
        $this->settings[ $key ] = $value;
        update_option( $this->option_key, $this->settings );
    }

    public function save_all( array $data ) {
        foreach ( $data as $key => $value ) {
            // Sanitise per field type
            if ( in_array( $key, $this->secret_keys, true ) ) {
                $this->settings[ $key ] = sanitize_textarea_field( $value );
            } elseif ( $key === 'primary_color' ) {
                $this->settings[ $key ] = sanitize_hex_color( $value );
            } elseif ( is_bool( $value ) || in_array( $value, [ '0', '1', 0, 1 ], true ) ) {
                $this->settings[ $key ] = (bool) $value;
            } else {
                $this->settings[ $key ] = sanitize_textarea_field( $value );
            }
        }
        update_option( $this->option_key, $this->settings );
    }

    /**
     * Return settings safe for JS / REST (strips all secret keys).
     */
    public function get_public_settings() {
        $safe = $this->settings;
        foreach ( $this->secret_keys as $k ) {
            unset( $safe[ $k ] );
        }
        return $safe;
    }

    /**
     * Default settings schema (used to render the admin form).
     */
    public function get_schema() {
        return [
            // General
            'chatbot_enabled'   => [ 'label' => '챗봇 활성화',           'type' => 'checkbox', 'default' => true,     'section' => 'general' ],
            'bot_name'          => [ 'label' => '봇 이름',               'type' => 'text',     'default' => 'AI9 어시스턴트', 'section' => 'general' ],
            'greeting_message'  => [ 'label' => '첫 인사말',             'type' => 'textarea', 'default' => '안녕하세요! AI9News 챗봇입니다. 브랜드, 제휴, 광고 문의 등 무엇이든 물어보세요 😊', 'section' => 'general' ],
            'input_placeholder' => [ 'label' => '입력창 안내 문구',       'type' => 'text',     'default' => '메시지를 입력하세요...', 'section' => 'general' ],
            'email_prompt'      => [ 'label' => '이메일 수집 안내 문구', 'type' => 'textarea', 'default' => '더 나은 지원을 위해 이메일 주소를 알려주시겠어요?', 'section' => 'general' ],
            'primary_color'     => [ 'label' => '메인 컬러',             'type' => 'color',    'default' => '#2563EB', 'section' => 'general' ],
            'bot_avatar'        => [ 'label' => '봇 아바타 URL',         'type' => 'url',      'default' => '',        'section' => 'general' ],

            // AI
            'claude_api_key'    => [ 'label' => 'Claude API 키 (비밀)',  'type' => 'password', 'default' => '', 'section' => 'ai', 'secret' => true ],
            'claude_model'      => [ 'label' => 'Claude 모델',           'type' => 'select',   'default' => 'claude-opus-4-6',
                'options' => [
                    'claude-opus-4-6'    => 'claude-opus-4-6 (최고 성능)',
                    'claude-sonnet-4-6'  => 'claude-sonnet-4-6 (균형)',
                    'claude-haiku-4'     => 'claude-haiku-4 (빠름)',
                ],
                'section' => 'ai',
            ],
            'system_prompt'     => [ 'label' => '시스템 프롬프트',       'type' => 'textarea', 'default' => "당신은 AI9News 브랜드의 공식 AI 어시스턴트입니다.\n브랜드 소개, 콘텐츠, 제휴·광고 문의에 친절하고 전문적으로 답변하세요.\n모르는 내용은 솔직히 안내하고 담당자 연결을 권유하세요.\n항상 한국어로 답변하세요.", 'section' => 'ai' ],
            'max_tokens'        => [ 'label' => '최대 응답 토큰',        'type' => 'number',   'default' => 1024, 'section' => 'ai' ],

            // Google Sheets
            'google_sheets_credentials' => [ 'label' => 'Google 서비스 계정 JSON (비밀)', 'type' => 'textarea', 'default' => '', 'section' => 'sheets', 'secret' => true ],
            'kb_sheet_id'       => [ 'label' => '지식베이스 시트 ID',   'type' => 'text', 'default' => '', 'section' => 'sheets' ],
            'kb_sheet_name'     => [ 'label' => '지식베이스 탭 이름',   'type' => 'text', 'default' => 'KnowledgeBase', 'section' => 'sheets' ],
            'leads_sheet_id'    => [ 'label' => '리드 저장 시트 ID',    'type' => 'text', 'default' => '', 'section' => 'sheets' ],
            'leads_sheet_name'  => [ 'label' => '리드 탭 이름',         'type' => 'text', 'default' => 'Leads', 'section' => 'sheets' ],
            'conv_sheet_name'   => [ 'label' => '대화 탭 이름',         'type' => 'text', 'default' => 'Conversations', 'section' => 'sheets' ],

            // Notifications
            'admin_notify_email'=> [ 'label' => '관리자 알림 이메일',   'type' => 'email', 'default' => get_option( 'admin_email' ), 'section' => 'notify', 'secret' => true ],
            'urgent_threshold'  => [ 'label' => '긴급 판단 기준 점수 (0~1)', 'type' => 'number', 'default' => 0.7, 'section' => 'notify' ],
            'urgent_contact_message' => [ 'label' => '긴급 안내 메시지', 'type' => 'textarea', 'default' => '빠른 지원을 위해 아래 연락처로 직접 연락해 주세요.', 'section' => 'notify' ],
            'contact_phone'     => [ 'label' => '긴급 연락처 전화',     'type' => 'text',  'default' => '', 'section' => 'notify' ],
            'contact_email'     => [ 'label' => '긴급 연락처 이메일',   'type' => 'email', 'default' => '', 'section' => 'notify' ],
            'contact_kakao'     => [ 'label' => '카카오톡 채널 URL',    'type' => 'url',   'default' => '', 'section' => 'notify' ],
        ];
    }
}
