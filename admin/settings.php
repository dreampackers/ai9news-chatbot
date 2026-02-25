<?php
/**
 * Admin settings page.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AI9CB_Admin_Settings_Page {

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu',            [ $this, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
        add_action( 'admin_post_ai9cb_save_settings', [ $this, 'handle_save' ] );
        add_action( 'wp_ajax_ai9cb_test_sheets', [ $this, 'ajax_test_sheets' ] );
    }

    // ---------------------------------------------------------------
    // AJAX: test Google Sheets connection
    // ---------------------------------------------------------------
    public function ajax_test_sheets() {
        check_ajax_referer( 'ai9cb_admin', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => '권한이 없습니다.' ], 403 );
        }

        // If JSON was submitted as part of the test (pre-save), temporarily apply it
        if ( ! empty( $_POST['credentials_json'] ) ) {
            $tmp_json = sanitize_textarea_field( wp_unslash( $_POST['credentials_json'] ) );
            $settings = AI9CB_Settings::get_instance();
            $original = $settings->get( 'google_sheets_credentials' );
            $settings->set( 'google_sheets_credentials', $tmp_json );
            AI9CB_Google_Sheets::clear_cache();
            $result = AI9CB_Google_Sheets::get_instance()->test_connection();
            // Restore original
            $settings->set( 'google_sheets_credentials', $original );
        } else {
            AI9CB_Google_Sheets::clear_cache();
            $result = AI9CB_Google_Sheets::get_instance()->test_connection();
        }

        if ( $result['success'] ) {
            wp_send_json_success( $result );
        } else {
            wp_send_json_error( $result );
        }
    }

    public function register_menu() {
        add_menu_page(
            'AI9 챗봇',
            'AI9 챗봇',
            'manage_options',
            'ai9cb-dashboard',
            [ AI9CB_Admin_Dashboard_Page::get_instance(), 'render' ],
            'dashicons-format-chat',
            58
        );

        add_submenu_page(
            'ai9cb-dashboard',
            '대시보드',
            '대시보드',
            'manage_options',
            'ai9cb-dashboard',
            [ AI9CB_Admin_Dashboard_Page::get_instance(), 'render' ]
        );

        add_submenu_page(
            'ai9cb-dashboard',
            '설정',
            '설정',
            'manage_options',
            'ai9cb-settings',
            [ $this, 'render' ]
        );

        add_submenu_page(
            'ai9cb-dashboard',
            '대화 로그',
            '대화 로그',
            'manage_options',
            'ai9cb-conversations',
            [ $this, 'render_conversations' ]
        );

        add_submenu_page(
            'ai9cb-dashboard',
            '리드 관리',
            '리드 관리',
            'manage_options',
            'ai9cb-leads',
            [ $this, 'render_leads' ]
        );
    }

    public function enqueue_admin_assets( $hook ) {
        if ( strpos( $hook, 'ai9cb' ) === false ) {
            return;
        }

        wp_enqueue_style(
            'ai9cb-admin',
            AI9CB_PLUGIN_URL . 'assets/css/admin.css',
            [],
            AI9CB_VERSION
        );

        // FIX: remove wp-color-picker from dependencies so a missing/slow
        // color-picker script never blocks admin.js from loading.
        // wp-color-picker is enqueued explicitly below and used conditionally in JS.
        wp_enqueue_script(
            'ai9cb-admin',
            AI9CB_PLUGIN_URL . 'assets/js/admin.js',
            [ 'jquery' ],   // ← was ['jquery','wp-color-picker']
            AI9CB_VERSION,
            true
        );

        // Enqueue color picker assets independently
        wp_enqueue_style( 'wp-color-picker' );
        wp_enqueue_script( 'wp-color-picker' );

        // Chart.js from CDN
        wp_enqueue_script(
            'chartjs',
            'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js',
            [],
            '4.4.0',
            true
        );

        wp_localize_script( 'ai9cb-admin', 'ai9cbAdmin', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'ai9cb_admin' ),
            'restUrl' => esc_url_raw( rest_url( 'ai9cb/v1' ) ),
        ] );
    }

    // ---------------------------------------------------------------
    // Settings page render
    // ---------------------------------------------------------------
    public function render() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( '권한이 없습니다.' );
        }

        $settings = AI9CB_Settings::get_instance();
        $schema   = $settings->get_schema();

        $sections = [
            'general' => [ 'label' => '기본 설정',             'icon' => '⚙️' ],
            'ai'      => [ 'label' => 'AI 모델 설정',          'icon' => '🤖' ],
            'sheets'  => [ 'label' => 'Google Sheets 연동',    'icon' => '📊' ],
            'notify'  => [ 'label' => '알림 및 연락처 설정',   'icon' => '🔔' ],
        ];

        $saved = get_query_var( 'ai9cb_saved', false );
        ?>
        <div class="wrap ai9cb-wrap">
          <h1>AI9 챗봇 — 설정</h1>

          <?php if ( isset( $_GET['saved'] ) ) : ?>
            <div class="notice notice-success is-dismissible"><p>✅ 설정이 저장되었습니다.</p></div>
          <?php endif; ?>

          <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'ai9cb_settings_save', 'ai9cb_nonce' ); ?>
            <input type="hidden" name="action" value="ai9cb_save_settings">

            <div class="ai9cb-settings-grid">
              <!-- Tab nav -->
              <div class="ai9cb-tabs">
                <?php foreach ( $sections as $key => $section ) : ?>
                  <a href="#section-<?php echo esc_attr( $key ); ?>" class="ai9cb-tab" data-tab="<?php echo esc_attr( $key ); ?>">
                    <?php echo $section['icon']; ?> <?php echo esc_html( $section['label'] ); ?>
                  </a>
                <?php endforeach; ?>
              </div>

              <!-- Fields -->
              <div class="ai9cb-settings-body">
                <?php foreach ( $sections as $sec_key => $section ) : ?>
                  <div id="section-<?php echo esc_attr( $sec_key ); ?>" class="ai9cb-section">
                    <h2><?php echo $section['icon']; ?> <?php echo esc_html( $section['label'] ); ?></h2>

                    <?php if ( $sec_key === 'ai' ) : ?>
                      <div class="ai9cb-notice-info">
                        🔐 API 키는 서버에만 저장되며, 브라우저나 프런트엔드에 절대 노출되지 않습니다.
                      </div>
                    <?php endif; ?>
                    <?php if ( $sec_key === 'sheets' ) : ?>
                      <div class="ai9cb-notice-info">
                        📋 Google Sheets 설정 방법: <a href="#" id="ai9cb-guide-toggle" style="font-weight:600;">아래 가이드 ▼</a> 참조
                      </div>
                      <div style="margin-bottom:16px;">
                        <button type="button" id="ai9cb-test-sheets" class="button button-secondary">
                          🔌 Google Sheets 연결 테스트
                        </button>
                        <span id="ai9cb-test-sheets-result" style="margin-left:10px;font-size:13px;"></span>
                      </div>
                    <?php endif; ?>

                    <table class="form-table">
                      <tbody>
                        <?php foreach ( $schema as $field_key => $field ) :
                          if ( ( $field['section'] ?? '' ) !== $sec_key ) continue;
                          $value = $settings->get( $field_key, $field['default'] ?? '' );
                        ?>
                        <tr>
                          <th scope="row">
                            <label for="ai9cb_<?php echo esc_attr( $field_key ); ?>">
                              <?php echo esc_html( $field['label'] ); ?>
                              <?php if ( ! empty( $field['secret'] ) ) : ?>
                                <span class="ai9cb-badge-secret">🔐 비밀</span>
                              <?php endif; ?>
                            </label>
                          </th>
                          <td>
                            <?php $this->render_field( $field_key, $field, $value ); ?>
                          </td>
                        </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
                <?php endforeach; ?>

                <!-- Google Sheets 상세 가이드 (기본 숨김 → 토글로 표시) -->
                <div id="sheets-guide" class="ai9cb-guide" style="display:none;">
                  <h2>📋 Google 서비스 계정 생성 및 JSON 키 다운로드 — 상세 가이드</h2>

                  <div class="ai9cb-guide-step">
                    <h3>STEP 1 — Google Cloud 프로젝트 생성</h3>
                    <ol>
                      <li><a href="https://console.cloud.google.com" target="_blank" rel="noopener">https://console.cloud.google.com</a> 접속 (Google 계정 로그인)</li>
                      <li>상단 프로젝트 선택 드롭다운 클릭 → <strong>새 프로젝트</strong>
                        <div class="ai9cb-guide-box">
                          프로젝트 이름: <code>ai9news-chatbot</code> ← 원하는 이름 입력<br>
                          위치: 조직 없음 (기본값)
                        </div>
                      </li>
                      <li><strong>만들기</strong> 클릭 → 생성 완료까지 10~20초 대기</li>
                    </ol>
                  </div>

                  <div class="ai9cb-guide-step">
                    <h3>STEP 2 — Google Sheets API 활성화</h3>
                    <ol>
                      <li>좌측 메뉴 → <strong>API 및 서비스</strong> → <strong>라이브러리</strong></li>
                      <li>검색창에 <code>Google Sheets API</code> 입력</li>
                      <li>결과 클릭 → <strong>사용 설정</strong> 버튼 클릭</li>
                    </ol>
                    <div class="ai9cb-guide-warn">⚠️ 이 단계를 빠뜨리면 나중에 "API not enabled" 오류가 납니다.</div>
                  </div>

                  <div class="ai9cb-guide-step">
                    <h3>STEP 3 — 서비스 계정 생성</h3>
                    <ol>
                      <li>좌측 메뉴 → <strong>API 및 서비스</strong> → <strong>사용자 인증 정보</strong></li>
                      <li>상단 <strong>+ 사용자 인증 정보 만들기</strong> → <strong>서비스 계정</strong> 선택
                        <div class="ai9cb-guide-box">
                          서비스 계정 이름: <code>ai9news-chatbot-sheets</code><br>
                          서비스 계정 ID:   <code>ai9news-chatbot-sheets</code> ← 자동 입력됨<br>
                          설명:             <code>AI9News 챗봇 Sheets 연동</code>
                        </div>
                      </li>
                      <li><strong>만들기 및 계속</strong> 클릭</li>
                      <li>역할 선택 단계 → <strong>편집자</strong> 선택 → <strong>계속</strong> 클릭</li>
                      <li><strong>완료</strong> 클릭</li>
                    </ol>
                  </div>

                  <div class="ai9cb-guide-step">
                    <h3>STEP 4 — JSON 키 다운로드</h3>
                    <ol>
                      <li>방금 만든 서비스 계정 이름 클릭</li>
                      <li>상단 탭 <strong>키</strong> 클릭</li>
                      <li><strong>키 추가</strong> → <strong>새 키 만들기</strong> 클릭</li>
                      <li>키 유형: <strong>JSON</strong> 선택 → <strong>만들기</strong> 클릭</li>
                      <li>JSON 파일이 자동으로 다운로드됨</li>
                    </ol>
                    <p>다운로드된 JSON 파일 내용 예시:</p>
                    <pre class="ai9cb-guide-code">{
  "type": "service_account",
  "project_id": "ai9news-chatbot",
  "private_key_id": "abc123...",
  "private_key": "-----BEGIN RSA PRIVATE KEY-----\n...\n-----END RSA PRIVATE KEY-----\n",
  "client_email": "ai9news-chatbot-sheets@ai9news-chatbot.iam.gserviceaccount.com",
  "client_id": "1234567890",
  "auth_uri": "https://accounts.google.com/o/oauth2/auth",
  "token_uri": "https://oauth2.googleapis.com/token",
  ...
}</pre>
                    <div class="ai9cb-guide-warn">🔐 이 파일은 비밀번호와 같습니다. 외부에 절대 공유하지 마세요.</div>
                  </div>

                  <div class="ai9cb-guide-step">
                    <h3>STEP 5 — 스프레드시트에 서비스 계정 공유</h3>
                    <ol>
                      <li>사용할 Google 스프레드시트 열기</li>
                      <li>우측 상단 <strong>공유</strong> 버튼 클릭</li>
                      <li>JSON 파일 안의 <code>client_email</code> 값 복사해서 붙여넣기
                        <div class="ai9cb-guide-box">
                          예: <code>ai9news-chatbot-sheets@ai9news-chatbot.iam.gserviceaccount.com</code>
                        </div>
                      </li>
                      <li>권한: <strong>편집자</strong> 선택 → <strong>보내기</strong> 클릭</li>
                    </ol>
                  </div>

                  <div class="ai9cb-guide-step">
                    <h3>STEP 6 — 플러그인 설정 페이지에 입력</h3>
                    <p>워드프레스 관리자 → AI9 챗봇 → 설정 → Google Sheets 연동 탭</p>
                    <table class="widefat">
                      <thead><tr><th>항목</th><th>입력값</th></tr></thead>
                      <tbody>
                        <tr><td>Google 서비스 계정 JSON</td><td>JSON 파일 전체 내용을 복사해서 붙여넣기</td></tr>
                        <tr><td>지식베이스 시트 ID</td><td>스프레드시트 URL에서 추출 (아래 참조)</td></tr>
                        <tr><td>리드 저장 시트 ID</td><td>동일하거나 별도 시트 ID</td></tr>
                      </tbody>
                    </table>
                    <p style="margin-top:12px;"><strong>시트 ID 찾는 방법:</strong></p>
                    <div class="ai9cb-guide-box" style="font-family:monospace;">
                      URL: https://docs.google.com/spreadsheets/d/<strong>[여기가_시트_ID]</strong>/edit#gid=0<br>
                      예: <code>1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgVE2upms</code>
                    </div>
                  </div>

                  <div class="ai9cb-guide-step">
                    <h3>스프레드시트 탭 구성 권장</h3>
                    <table class="widefat">
                      <thead><tr><th>탭 이름</th><th>용도</th><th>첫 행 헤더</th></tr></thead>
                      <tbody>
                        <tr><td><code>KnowledgeBase</code></td><td>지식베이스</td><td>question, answer, category, tags</td></tr>
                        <tr><td><code>Leads</code></td><td>리드 저장</td><td>자동 생성</td></tr>
                        <tr><td><code>Conversations</code></td><td>대화 저장</td><td>자동 생성</td></tr>
                      </tbody>
                    </table>
                    <p>탭을 하나의 시트에 모두 만들고, 설정에서 지식베이스 시트 ID와 리드 저장 시트 ID를 같은 값으로 입력하면 됩니다.</p>
                  </div>

                  <div class="ai9cb-guide-step">
                    <h3>자주 발생하는 오류</h3>
                    <table class="widefat">
                      <thead><tr><th>오류</th><th>원인</th><th>해결</th></tr></thead>
                      <tbody>
                        <tr><td><code>API not enabled</code></td><td>Sheets API 미활성화</td><td>STEP 2 반복</td></tr>
                        <tr><td><code>Permission denied</code></td><td>시트 공유 안 됨</td><td>STEP 5 반복</td></tr>
                        <tr><td><code>Invalid credentials</code></td><td>JSON이 잘못 입력됨</td><td>JSON 전체를 다시 복사 붙여넣기</td></tr>
                        <tr><td><code>Spreadsheet not found</code></td><td>시트 ID 오류</td><td>URL에서 ID 재확인</td></tr>
                      </tbody>
                    </table>
                    <p style="margin-top:10px;">설정 후 <strong>캐시 삭제</strong> 버튼을 한 번 눌러주면 즉시 반영됩니다.</p>
                  </div>
                </div>

                <p class="submit" style="margin-top:24px;">
                  <button type="submit" class="button button-primary button-large">
                    💾 설정 저장
                  </button>
                  <button type="button" id="ai9cb-clear-cache" class="button button-secondary" style="margin-left:8px;">
                    🗑️ 캐시 삭제
                  </button>
                </p>
              </div>
            </div>
          </form>
        </div>
        <?php
    }

    private function render_field( $key, $field, $value ) {
        $id   = 'ai9cb_' . esc_attr( $key );
        $name = 'ai9cb_settings[' . esc_attr( $key ) . ']';

        switch ( $field['type'] ) {
            case 'checkbox':
                echo "<label><input type='checkbox' id='{$id}' name='{$name}' value='1' " . checked( $value, true, false ) . "> " . esc_html( $field['label'] ) . "</label>";
                break;

            case 'select':
                echo "<select id='{$id}' name='{$name}'>";
                foreach ( $field['options'] as $opt_val => $opt_label ) {
                    echo "<option value='" . esc_attr( $opt_val ) . "' " . selected( $value, $opt_val, false ) . ">" . esc_html( $opt_label ) . "</option>";
                }
                echo "</select>";
                break;

            case 'textarea':
                $rows = ! empty( $field['secret'] ) ? 8 : 4;
                echo "<textarea id='{$id}' name='{$name}' class='large-text code' rows='{$rows}'>" . esc_textarea( $value ) . "</textarea>";
                break;

            case 'password':
                // Show masked input; on save, only update if non-empty
                echo "<input type='password' id='{$id}' name='{$name}' class='regular-text' value='" . esc_attr( $value ) . "' autocomplete='new-password'>";
                echo "<p class='description'>저장된 값이 있으면 비워두면 유지됩니다.</p>";
                break;

            case 'color':
                echo "<input type='text' id='{$id}' name='{$name}' class='ai9cb-color-picker' value='" . esc_attr( $value ) . "'>";
                break;

            case 'number':
                echo "<input type='number' id='{$id}' name='{$name}' class='small-text' value='" . esc_attr( $value ) . "' step='0.01'>";
                break;

            default:
                $input_type = in_array( $field['type'], [ 'email', 'url' ], true ) ? $field['type'] : 'text';
                echo "<input type='{$input_type}' id='{$id}' name='{$name}' class='regular-text' value='" . esc_attr( $value ) . "'>";
        }
    }

    // ---------------------------------------------------------------
    // Handle form save
    // ---------------------------------------------------------------
    public function handle_save() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( '권한이 없습니다.' );
        }

        check_admin_referer( 'ai9cb_settings_save', 'ai9cb_nonce' );

        // FIX: WordPress applies wp_magic_quotes() (addslashes) to all $_POST data.
        // Without wp_unslash(), JSON gets stored with escaped quotes {"type":...}
        // breaking json_decode later.
        $posted   = wp_unslash( $_POST['ai9cb_settings'] ?? [] );
        $settings = AI9CB_Settings::get_instance();
        $schema   = $settings->get_schema();

        $clean = [];
        foreach ( $schema as $key => $field ) {
            if ( $field['type'] === 'checkbox' ) {
                $clean[ $key ] = isset( $posted[ $key ] ) && $posted[ $key ] === '1';
            } elseif ( $field['type'] === 'password' ) {
                // Only update if a new value was typed
                if ( ! empty( $posted[ $key ] ) ) {
                    $clean[ $key ] = $posted[ $key ];
                }
            } else {
                $clean[ $key ] = $posted[ $key ] ?? ( $field['default'] ?? '' );
            }
        }

        $settings->save_all( $clean );
        AI9CB_Google_Sheets::clear_cache();

        wp_safe_redirect( admin_url( 'admin.php?page=ai9cb-settings&saved=1' ) );
        exit;
    }

    // ---------------------------------------------------------------
    // Conversations log
    // ---------------------------------------------------------------
    public function render_conversations() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die();
        global $wpdb;

        $page    = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
        $per     = 20;
        $offset  = ( $page - 1 ) * $per;
        $filter_sentiment = sanitize_text_field( $_GET['sentiment'] ?? '' );

        $where = $filter_sentiment ? $wpdb->prepare( "WHERE c.sentiment = %s", $filter_sentiment ) : '';

        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}ai9cb_conversations c $where" );
        $rows  = $wpdb->get_results( $wpdb->prepare(
            "SELECT c.*, l.email FROM {$wpdb->prefix}ai9cb_conversations c
             LEFT JOIN {$wpdb->prefix}ai9cb_leads l ON l.id = c.lead_id
             $where ORDER BY c.created_at DESC LIMIT %d OFFSET %d",
            $per, $offset
        ) );

        $sentiment_obj = AI9CB_Sentiment::get_instance();
        $total_pages   = (int) ceil( $total / $per );

        ?>
        <div class="wrap ai9cb-wrap">
          <h1>대화 로그 <span class="ai9cb-count"><?php echo number_format( $total ); ?>건</span></h1>

          <div class="ai9cb-filter-bar">
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=ai9cb-conversations' ) ); ?>" class="button <?php echo $filter_sentiment ? '' : 'button-primary'; ?>">전체</a>
            <?php foreach ( [ 'positive','neutral','negative','urgent' ] as $s ) : ?>
              <a href="<?php echo esc_url( admin_url( "admin.php?page=ai9cb-conversations&sentiment=$s" ) ); ?>" class="button <?php echo $filter_sentiment === $s ? 'button-primary' : ''; ?>">
                <?php echo esc_html( $sentiment_obj->label_kr( $s ) ); ?>
              </a>
            <?php endforeach; ?>
          </div>

          <table class="wp-list-table widefat fixed striped ai9cb-table">
            <thead>
              <tr>
                <th style="width:140px;">시각</th>
                <th style="width:160px;">이메일</th>
                <th>사용자 메시지</th>
                <th>AI 응답</th>
                <th style="width:80px;">감정</th>
                <th style="width:60px;">긴급</th>
              </tr>
            </thead>
            <tbody>
              <?php if ( empty( $rows ) ) : ?>
                <tr><td colspan="6" class="ai9cb-empty">대화 기록이 없습니다.</td></tr>
              <?php else : foreach ( $rows as $row ) : ?>
                <tr class="<?php echo $row->is_urgent ? 'ai9cb-urgent-row' : ''; ?>">
                  <td><?php echo esc_html( $row->created_at ); ?></td>
                  <td><?php echo esc_html( $row->email ?: '—' ); ?></td>
                  <td class="ai9cb-msg"><?php echo esc_html( mb_substr( $row->user_message, 0, 120 ) ); ?></td>
                  <td class="ai9cb-msg"><?php echo esc_html( mb_substr( $row->bot_response, 0, 120 ) ); ?></td>
                  <td><span class="ai9cb-badge <?php echo esc_attr( $sentiment_obj->badge_class( $row->sentiment ) ); ?>"><?php echo esc_html( $sentiment_obj->label_kr( $row->sentiment ) ); ?></span></td>
                  <td><?php echo $row->is_urgent ? '<span class="ai9cb-badge badge-danger">⚠️</span>' : '—'; ?></td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>

          <?php if ( $total_pages > 1 ) : ?>
            <div class="tablenav bottom">
              <div class="tablenav-pages">
                <?php
                echo paginate_links( [
                    'base'      => add_query_arg( 'paged', '%#%' ),
                    'format'    => '',
                    'current'   => $page,
                    'total'     => $total_pages,
                    'prev_text' => '&laquo;',
                    'next_text' => '&raquo;',
                ] );
                ?>
              </div>
            </div>
          <?php endif; ?>
        </div>
        <?php
    }

    // ---------------------------------------------------------------
    // Leads list
    // ---------------------------------------------------------------
    public function render_leads() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die();
        global $wpdb;

        $page   = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
        $per    = 20;
        $offset = ( $page - 1 ) * $per;

        $total  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}ai9cb_leads" );
        $rows   = $wpdb->get_results( $wpdb->prepare(
            "SELECT l.*,
                    (SELECT COUNT(*) FROM {$wpdb->prefix}ai9cb_conversations c WHERE c.lead_id = l.id) AS msg_count
             FROM {$wpdb->prefix}ai9cb_leads l
             ORDER BY l.updated_at DESC LIMIT %d OFFSET %d",
            $per, $offset
        ) );

        $stage_labels = [
            'awareness'     => [ 'label' => '인지',      'color' => '#94a3b8' ],
            'interest'      => [ 'label' => '관심',      'color' => '#60a5fa' ],
            'consideration' => [ 'label' => '고려',      'color' => '#a78bfa' ],
            'intent'        => [ 'label' => '의향',      'color' => '#f59e0b' ],
            'conversion'    => [ 'label' => '전환',      'color' => '#10b981' ],
        ];

        $total_pages = (int) ceil( $total / $per );
        ?>
        <div class="wrap ai9cb-wrap">
          <h1>리드 관리 <span class="ai9cb-count"><?php echo number_format( $total ); ?>명</span></h1>

          <table class="wp-list-table widefat fixed striped ai9cb-table">
            <thead>
              <tr>
                <th style="width:50px;">ID</th>
                <th>이메일</th>
                <th style="width:120px;">이름</th>
                <th style="width:100px;">퍼널 단계</th>
                <th style="width:60px;">메시지</th>
                <th style="width:140px;">최초 접촉</th>
                <th style="width:140px;">최근 활동</th>
                <th style="width:80px;">상세</th>
              </tr>
            </thead>
            <tbody>
              <?php if ( empty( $rows ) ) : ?>
                <tr><td colspan="8" class="ai9cb-empty">리드 데이터가 없습니다.</td></tr>
              <?php else : foreach ( $rows as $row ) :
                $stage = $stage_labels[ $row->funnel_stage ] ?? [ 'label' => $row->funnel_stage, 'color' => '#ccc' ];
              ?>
                <tr>
                  <td><?php echo (int) $row->id; ?></td>
                  <td><?php echo esc_html( $row->email ?: '—' ); ?></td>
                  <td><?php echo esc_html( $row->name ?: '—' ); ?></td>
                  <td>
                    <span class="ai9cb-stage-badge" style="background:<?php echo esc_attr( $stage['color'] ); ?>">
                      <?php echo esc_html( $stage['label'] ); ?>
                    </span>
                  </td>
                  <td><?php echo (int) $row->msg_count; ?></td>
                  <td><?php echo esc_html( $row->created_at ); ?></td>
                  <td><?php echo esc_html( $row->updated_at ); ?></td>
                  <td>
                    <a href="<?php echo esc_url( admin_url( "admin.php?page=ai9cb-conversations&lead_id={$row->id}" ) ); ?>" class="button button-small">보기</a>
                  </td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>

          <?php if ( $total_pages > 1 ) : ?>
            <div class="tablenav bottom">
              <div class="tablenav-pages">
                <?php echo paginate_links( [ 'base' => add_query_arg( 'paged', '%#%' ), 'format' => '', 'current' => $page, 'total' => $total_pages ] ); ?>
              </div>
            </div>
          <?php endif; ?>
        </div>
        <?php
    }
}
