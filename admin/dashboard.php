<?php
/**
 * Admin marketing funnel dashboard.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AI9CB_Admin_Dashboard_Page {

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function render() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( '권한이 없습니다.' );
        }

        global $wpdb;
        $funnel = AI9CB_Funnel_Tracker::get_instance();

        // Stats
        $stage_counts  = $funnel->get_stage_counts();
        $total_leads   = array_sum( $stage_counts );
        $total_conv    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}ai9cb_conversations" );
        $conv_rate     = $funnel->get_conversion_rate();
        $urgent_count  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}ai9cb_conversations WHERE is_urgent = 1 AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)" );
        $sentiment_bd  = $funnel->get_sentiment_breakdown();
        $timeline      = $funnel->get_leads_timeline( 30 );

        // Recent urgent messages
        $urgent_msgs = $wpdb->get_results(
            "SELECT c.*, l.email FROM {$wpdb->prefix}ai9cb_conversations c
             LEFT JOIN {$wpdb->prefix}ai9cb_leads l ON l.id = c.lead_id
             WHERE c.is_urgent = 1
             ORDER BY c.created_at DESC LIMIT 5"
        );

        // Timeline JSON
        $timeline_labels = [];
        $timeline_data   = [];
        $date_range      = [];
        for ( $i = 29; $i >= 0; $i-- ) {
            $date_range[ date( 'Y-m-d', strtotime( "-{$i} days" ) ) ] = 0;
        }
        foreach ( $timeline as $t ) {
            $date_range[ $t->date ] = (int) $t->cnt;
        }
        foreach ( $date_range as $d => $c ) {
            $timeline_labels[] = date( 'm/d', strtotime( $d ) );
            $timeline_data[]   = $c;
        }

        $funnel_labels = [ '인지', '관심', '고려', '의향', '전환' ];
        $funnel_data   = [
            $stage_counts['awareness'],
            $stage_counts['interest'],
            $stage_counts['consideration'],
            $stage_counts['intent'],
            $stage_counts['conversion'],
        ];

        $sent_labels = [];
        $sent_data   = [];
        $sent_colors = [ 'positive' => '#10b981', 'neutral' => '#94a3b8', 'negative' => '#f59e0b', 'urgent' => '#ef4444' ];
        $sent_names  = [ 'positive' => '긍정', 'neutral' => '중립', 'negative' => '부정', 'urgent' => '긴급' ];
        $sent_bg     = [];
        foreach ( $sent_names as $k => $v ) {
            if ( isset( $sentiment_bd[ $k ] ) ) {
                $sent_labels[] = $v;
                $sent_data[]   = $sentiment_bd[ $k ];
                $sent_bg[]     = $sent_colors[ $k ];
            }
        }
        ?>
        <div class="wrap ai9cb-wrap">
          <h1>AI9 챗봇 — 마케팅 퍼널 대시보드</h1>
          <p class="ai9cb-subtitle">고객 여정 분석 · 감정 인사이트 · 전환율 추적</p>

          <!-- KPI Cards -->
          <div class="ai9cb-kpi-grid">
            <div class="ai9cb-kpi">
              <div class="ai9cb-kpi-icon">👥</div>
              <div class="ai9cb-kpi-value"><?php echo number_format( $total_leads ); ?></div>
              <div class="ai9cb-kpi-label">총 리드</div>
            </div>
            <div class="ai9cb-kpi">
              <div class="ai9cb-kpi-icon">💬</div>
              <div class="ai9cb-kpi-value"><?php echo number_format( $total_conv ); ?></div>
              <div class="ai9cb-kpi-label">총 대화수</div>
            </div>
            <div class="ai9cb-kpi">
              <div class="ai9cb-kpi-icon">🎯</div>
              <div class="ai9cb-kpi-value"><?php echo $conv_rate; ?>%</div>
              <div class="ai9cb-kpi-label">퍼널 전환율 (의향+전환)</div>
            </div>
            <div class="ai9cb-kpi ai9cb-kpi-urgent <?php echo $urgent_count > 0 ? 'has-urgent' : ''; ?>">
              <div class="ai9cb-kpi-icon">⚠️</div>
              <div class="ai9cb-kpi-value"><?php echo number_format( $urgent_count ); ?></div>
              <div class="ai9cb-kpi-label">최근 7일 긴급 메시지</div>
            </div>
          </div>

          <!-- Charts Row 1 -->
          <div class="ai9cb-charts-row">
            <div class="ai9cb-chart-card ai9cb-chart-wide">
              <h3>📈 신규 리드 추이 (최근 30일)</h3>
              <canvas id="timelineChart" height="80"></canvas>
            </div>
            <div class="ai9cb-chart-card">
              <h3>💬 감정 분포</h3>
              <canvas id="sentimentChart" height="180"></canvas>
            </div>
          </div>

          <!-- Funnel Visualization -->
          <div class="ai9cb-chart-card">
            <h3>🔽 마케팅 퍼널 현황</h3>
            <div class="ai9cb-funnel-container">
              <?php
              $stages = [
                  'awareness'     => [ 'label' => '인지 (Awareness)',        'desc' => '챗봇 첫 접촉',           'color' => '#94a3b8' ],
                  'interest'      => [ 'label' => '관심 (Interest)',          'desc' => '2회 이상 대화',           'color' => '#60a5fa' ],
                  'consideration' => [ 'label' => '고려 (Consideration)',     'desc' => '5회+ 대화 또는 관심 표현', 'color' => '#a78bfa' ],
                  'intent'        => [ 'label' => '의향 (Intent)',            'desc' => '이메일 제공 또는 가격 문의', 'color' => '#f59e0b' ],
                  'conversion'    => [ 'label' => '전환 (Conversion)',        'desc' => '구매 / 데모 / 문의 신청',   'color' => '#10b981' ],
              ];
              $max = max( 1, max( array_values( $stage_counts ) ) );
              $widths = [ 100, 82, 66, 50, 36 ];
              $i = 0;
              foreach ( $stages as $key => $s ) :
                  $count = $stage_counts[ $key ] ?? 0;
                  $pct   = $total_leads > 0 ? round( $count / $total_leads * 100, 1 ) : 0;
              ?>
              <div class="ai9cb-funnel-step" style="width:<?php echo $widths[ $i ]; ?>%; border-color:<?php echo esc_attr( $s['color'] ); ?>; background:<?php echo esc_attr( $s['color'] ); ?>22;">
                <div class="ai9cb-funnel-label"><?php echo esc_html( $s['label'] ); ?></div>
                <div class="ai9cb-funnel-count"><?php echo number_format( $count ); ?>명 <span class="ai9cb-funnel-pct">(<?php echo $pct; ?>%)</span></div>
                <div class="ai9cb-funnel-desc"><?php echo esc_html( $s['desc'] ); ?></div>
              </div>
              <?php $i++; endforeach; ?>
            </div>
          </div>

          <!-- Funnel Bar Chart -->
          <div class="ai9cb-chart-card">
            <h3>📊 퍼널 단계별 리드 수</h3>
            <canvas id="funnelChart" height="60"></canvas>
          </div>

          <!-- Urgent Messages -->
          <?php if ( ! empty( $urgent_msgs ) ) : ?>
          <div class="ai9cb-chart-card">
            <h3>⚠️ 최근 긴급 메시지</h3>
            <table class="wp-list-table widefat fixed striped ai9cb-table">
              <thead>
                <tr>
                  <th style="width:140px;">시각</th>
                  <th style="width:180px;">이메일</th>
                  <th>메시지</th>
                  <th style="width:80px;">알림</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ( $urgent_msgs as $msg ) : ?>
                  <tr>
                    <td><?php echo esc_html( $msg->created_at ); ?></td>
                    <td><?php echo esc_html( $msg->email ?: '—' ); ?></td>
                    <td><?php echo esc_html( mb_substr( $msg->user_message, 0, 150 ) ); ?></td>
                    <td><?php echo $msg->admin_notified ? '<span class="ai9cb-badge badge-success">전송됨</span>' : '<span class="ai9cb-badge badge-secondary">미전송</span>'; ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
            <p><a href="<?php echo esc_url( admin_url( 'admin.php?page=ai9cb-conversations&sentiment=urgent' ) ); ?>" class="button">전체 긴급 메시지 보기 &rarr;</a></p>
          </div>
          <?php endif; ?>

          <!-- Funnel Strategy -->
          <div class="ai9cb-chart-card ai9cb-strategy">
            <h3>🎯 퍼널 마케팅 전략 가이드</h3>
            <div class="ai9cb-strategy-grid">
              <div class="ai9cb-strategy-card" style="border-color:#94a3b8">
                <h4>인지 단계</h4>
                <p>챗봇에 처음 접한 방문자입니다.</p>
                <ul>
                  <li>친근한 첫 인사로 브랜드 각인</li>
                  <li>핵심 서비스 간략 소개 제공</li>
                  <li>이메일 수집 유도 (부드럽게)</li>
                </ul>
              </div>
              <div class="ai9cb-strategy-card" style="border-color:#60a5fa">
                <h4>관심 단계</h4>
                <p>2회 이상 대화한 잠재 고객입니다.</p>
                <ul>
                  <li>맞춤형 콘텐츠 추천</li>
                  <li>케이스 스터디 / 성과 공유</li>
                  <li>뉴스레터 구독 제안</li>
                </ul>
              </div>
              <div class="ai9cb-strategy-card" style="border-color:#a78bfa">
                <h4>고려 단계</h4>
                <p>적극적으로 정보를 탐색 중입니다.</p>
                <ul>
                  <li>상세 서비스 안내 자동화</li>
                  <li>비교 자료 / FAQ 제공</li>
                  <li>무료 상담 / 데모 제안</li>
                </ul>
              </div>
              <div class="ai9cb-strategy-card" style="border-color:#f59e0b">
                <h4>의향 단계</h4>
                <p>구매 의향이 높은 핫 리드입니다.</p>
                <ul>
                  <li>이메일 팔로업 자동화 실행</li>
                  <li>한정 혜택 / 프로모션 제시</li>
                  <li>담당자 직접 연결 권유</li>
                </ul>
              </div>
              <div class="ai9cb-strategy-card" style="border-color:#10b981">
                <h4>전환 단계</h4>
                <p>구매/문의 신청한 고객입니다.</p>
                <ul>
                  <li>즉각적인 팔로업 연락</li>
                  <li>온보딩 안내 자동화</li>
                  <li>만족도 조사 후 리뷰 요청</li>
                </ul>
              </div>
            </div>
          </div>

        </div>

        <script>
        (function() {
          // Timeline chart
          new Chart(document.getElementById('timelineChart'), {
            type: 'line',
            data: {
              labels: <?php echo wp_json_encode( $timeline_labels ); ?>,
              datasets: [{
                label: '신규 리드',
                data: <?php echo wp_json_encode( $timeline_data ); ?>,
                borderColor: '#2563eb',
                backgroundColor: 'rgba(37,99,235,0.1)',
                tension: 0.3,
                fill: true,
                pointRadius: 3,
              }]
            },
            options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } } }
          });

          // Funnel bar chart
          new Chart(document.getElementById('funnelChart'), {
            type: 'bar',
            data: {
              labels: <?php echo wp_json_encode( $funnel_labels ); ?>,
              datasets: [{
                label: '리드 수',
                data: <?php echo wp_json_encode( $funnel_data ); ?>,
                backgroundColor: ['#94a3b8','#60a5fa','#a78bfa','#f59e0b','#10b981'],
                borderRadius: 6,
              }]
            },
            options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } } }
          });

          <?php if ( ! empty( $sent_data ) ) : ?>
          // Sentiment chart
          new Chart(document.getElementById('sentimentChart'), {
            type: 'doughnut',
            data: {
              labels: <?php echo wp_json_encode( $sent_labels ); ?>,
              datasets: [{
                data: <?php echo wp_json_encode( $sent_data ); ?>,
                backgroundColor: <?php echo wp_json_encode( $sent_bg ); ?>,
                borderWidth: 2,
              }]
            },
            options: { responsive: true, plugins: { legend: { position: 'bottom' } }, cutout: '65%' }
          });
          <?php endif; ?>
        })();
        </script>
        <?php
    }
}
