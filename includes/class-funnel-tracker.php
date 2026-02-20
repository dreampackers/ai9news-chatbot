<?php
/**
 * Marketing funnel tracker.
 *
 * Funnel stages:
 *   awareness     → first chat interaction
 *   interest      → asked 2+ questions
 *   consideration → asked 5+ questions or showed product interest
 *   intent        → provided email, or asked about pricing/purchase
 *   conversion    → requested demo / contact / purchase
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AI9CB_Funnel_Tracker {

    private static $instance = null;

    // Keywords that indicate intent/conversion stages
    private $intent_keywords = [
        '가격', '비용', '요금', '구매', '신청', '결제', '계약', '견적', '문의', '상담',
        'price', 'pricing', 'buy', 'purchase', 'subscribe', 'plan', 'cost',
    ];

    private $conversion_keywords = [
        '바로 신청', '지금 신청', '구매하고', '계약하고', '데모', '미팅', '전화 주세요',
        '연락 주세요', 'contact me', 'book a demo', 'sign up',
    ];

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Get or create a lead record for this session.
     *
     * @return int  lead ID
     */
    public function get_or_create_lead( $session_id, $email = '', $name = '' ) {
        global $wpdb;

        // Try by session_id first
        $lead_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ai9cb_leads WHERE id IN (
                SELECT lead_id FROM {$wpdb->prefix}ai9cb_conversations WHERE session_id = %s LIMIT 1
             ) LIMIT 1",
            $session_id
        ) );

        if ( $lead_id ) {
            // Update email/name if now available
            if ( $email ) {
                $wpdb->update(
                    $wpdb->prefix . 'ai9cb_leads',
                    [ 'email' => $email, 'name' => $name ],
                    [ 'id' => $lead_id ],
                    [ '%s', '%s' ],
                    [ '%d' ]
                );
            }
            return (int) $lead_id;
        }

        // Try by email
        if ( $email ) {
            $lead_id = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}ai9cb_leads WHERE email = %s LIMIT 1",
                $email
            ) );
            if ( $lead_id ) {
                return (int) $lead_id;
            }
        }

        // Create new lead
        $wpdb->insert(
            $wpdb->prefix . 'ai9cb_leads',
            [
                'email'        => $email,
                'name'         => $name,
                'funnel_stage' => 'awareness',
                'created_at'   => current_time( 'mysql' ),
                'updated_at'   => current_time( 'mysql' ),
            ],
            [ '%s', '%s', '%s', '%s', '%s' ]
        );

        $lead_id = (int) $wpdb->insert_id;

        // Log funnel event
        $this->log_event( $lead_id, 'funnel_enter', [ 'stage' => 'awareness', 'session_id' => $session_id ] );

        return $lead_id;
    }

    /**
     * Update funnel stage based on conversation signals.
     *
     * @param int   $lead_id
     * @param array $response  Bot response array from API
     */
    public function update_stage( $lead_id, $response ) {
        global $wpdb;

        $lead = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ai9cb_leads WHERE id = %d",
            $lead_id
        ) );

        if ( ! $lead ) {
            return;
        }

        $current_stage   = $lead->funnel_stage;
        $message_count   = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ai9cb_conversations WHERE lead_id = %d",
            $lead_id
        ) );

        $has_email = ! empty( $lead->email );
        $is_urgent = ! empty( $response['is_urgent'] );

        // Determine messages combined for keyword check
        $combined_text = mb_strtolower( $response['bot_response'] ?? '' );

        $new_stage = $this->calc_stage(
            $current_stage,
            $message_count,
            $has_email,
            $is_urgent,
            $combined_text
        );

        if ( $new_stage !== $current_stage ) {
            $wpdb->update(
                $wpdb->prefix . 'ai9cb_leads',
                [ 'funnel_stage' => $new_stage, 'updated_at' => current_time( 'mysql' ) ],
                [ 'id' => $lead_id ],
                [ '%s', '%s' ],
                [ '%d' ]
            );

            $this->log_event( $lead_id, 'stage_change', [
                'from'  => $current_stage,
                'to'    => $new_stage,
                'count' => $message_count,
            ] );
        }
    }

    // ---------------------------------------------------------------
    // Stage calculation logic
    // ---------------------------------------------------------------
    private function calc_stage( $current, $count, $has_email, $is_urgent, $text ) {
        $stages = [ 'awareness', 'interest', 'consideration', 'intent', 'conversion' ];
        $idx    = array_search( $current, $stages, true );

        $candidate = $current;

        if ( $count >= 2 && $idx < 1 ) {
            $candidate = 'interest';
            $idx = 1;
        }

        if ( $count >= 5 && $idx < 2 ) {
            $candidate = 'consideration';
            $idx = 2;
        }

        if ( ( $has_email || $is_urgent || $this->has_keywords( $text, $this->intent_keywords ) ) && $idx < 3 ) {
            $candidate = 'intent';
            $idx = 3;
        }

        if ( $this->has_keywords( $text, $this->conversion_keywords ) && $idx < 4 ) {
            $candidate = 'conversion';
        }

        return $candidate;
    }

    private function has_keywords( $text, $keywords ) {
        foreach ( $keywords as $kw ) {
            if ( mb_strpos( $text, mb_strtolower( $kw ) ) !== false ) {
                return true;
            }
        }
        return false;
    }

    // ---------------------------------------------------------------
    // Event logging
    // ---------------------------------------------------------------
    public function log_event( $lead_id, $event_type, $data = [] ) {
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'ai9cb_funnel_events',
            [
                'lead_id'    => $lead_id,
                'event_type' => $event_type,
                'event_data' => wp_json_encode( $data ),
                'created_at' => current_time( 'mysql' ),
            ],
            [ '%d', '%s', '%s', '%s' ]
        );
    }

    // ---------------------------------------------------------------
    // Dashboard statistics
    // ---------------------------------------------------------------

    /**
     * Funnel stage counts for the dashboard.
     *
     * @return array  [ 'awareness'=>N, 'interest'=>N, ... ]
     */
    public function get_stage_counts() {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT funnel_stage, COUNT(*) AS cnt
             FROM {$wpdb->prefix}ai9cb_leads
             GROUP BY funnel_stage"
        );

        $stages = array_fill_keys( [ 'awareness', 'interest', 'consideration', 'intent', 'conversion' ], 0 );
        foreach ( $rows as $row ) {
            $stages[ $row->funnel_stage ] = (int) $row->cnt;
        }
        return $stages;
    }

    /**
     * Total leads added in the last N days, grouped by date.
     */
    public function get_leads_timeline( $days = 30 ) {
        global $wpdb;
        $since = date( 'Y-m-d', strtotime( "-{$days} days" ) );
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT DATE(created_at) AS date, COUNT(*) AS cnt
             FROM {$wpdb->prefix}ai9cb_leads
             WHERE created_at >= %s
             GROUP BY DATE(created_at)
             ORDER BY date ASC",
            $since
        ) );
    }

    /**
     * Sentiment breakdown of all conversations.
     */
    public function get_sentiment_breakdown() {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT sentiment, COUNT(*) AS cnt
             FROM {$wpdb->prefix}ai9cb_conversations
             GROUP BY sentiment"
        );
        $out = [];
        foreach ( $rows as $row ) {
            $out[ $row->sentiment ] = (int) $row->cnt;
        }
        return $out;
    }

    /**
     * Conversion rate: intent+conversion / total leads.
     */
    public function get_conversion_rate() {
        global $wpdb;
        $total     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}ai9cb_leads" );
        $converted = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ai9cb_leads WHERE funnel_stage IN ('intent','conversion')"
        );
        if ( $total === 0 ) {
            return 0.0;
        }
        return round( $converted / $total * 100, 1 );
    }
}
