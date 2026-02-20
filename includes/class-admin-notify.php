<?php
/**
 * Admin notification — sends email alert when a user message is urgent.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AI9CB_Admin_Notify {

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Send an urgent-message alert to the admin email.
     *
     * @param int    $lead_id
     * @param string $user_email
     * @param string $user_message
     * @param string $bot_response
     */
    public function send_urgent_alert( $lead_id, $user_email, $user_message, $bot_response ) {
        $settings    = AI9CB_Settings::get_instance();
        $admin_email = $settings->get( 'admin_notify_email', get_option( 'admin_email' ) );

        if ( empty( $admin_email ) ) {
            return;
        }

        // Prevent duplicate alerts within 10 minutes for the same lead
        $lock_key = 'ai9cb_notify_lock_' . $lead_id;
        if ( get_transient( $lock_key ) ) {
            return;
        }
        set_transient( $lock_key, 1, 10 * MINUTE_IN_SECONDS );

        $site_name = get_bloginfo( 'name' );
        $admin_url = admin_url( 'admin.php?page=ai9cb-dashboard&lead_id=' . $lead_id );
        $time      = current_time( 'Y-m-d H:i:s' );

        $subject = "[{$site_name}] ⚠️ 긴급 고객 문의 감지 — {$time}";

        $body = "
<!DOCTYPE html>
<html lang='ko'>
<head><meta charset='UTF-8'><style>
  body { font-family: -apple-system, sans-serif; background: #f4f4f4; margin: 0; padding: 20px; }
  .card { background: #fff; border-radius: 8px; padding: 24px; max-width: 600px; margin: 0 auto; box-shadow: 0 2px 8px rgba(0,0,0,.1); }
  .badge { display: inline-block; background: #ef4444; color: #fff; border-radius: 4px; padding: 2px 10px; font-size: 13px; font-weight: 700; }
  .label { color: #6b7280; font-size: 13px; margin-bottom: 4px; }
  .value { font-size: 15px; margin-bottom: 16px; }
  .bubble { background: #f3f4f6; border-left: 4px solid #ef4444; padding: 12px 16px; border-radius: 0 6px 6px 0; margin-bottom: 16px; }
  .btn { display: inline-block; background: #2563eb; color: #fff; text-decoration: none; padding: 10px 20px; border-radius: 6px; font-size: 14px; }
</style></head>
<body>
<div class='card'>
  <p><span class='badge'>긴급 알림</span></p>
  <h2 style='margin-top:8px;'>고객 긴급 문의가 감지되었습니다</h2>
  <div class='label'>시각</div><div class='value'>{$time}</div>
  <div class='label'>고객 이메일</div><div class='value'>" . esc_html( $user_email ?: '미수집' ) . "</div>
  <div class='label'>고객 메시지</div>
  <div class='bubble'>" . nl2br( esc_html( $user_message ) ) . "</div>
  <div class='label'>AI 응답</div>
  <div class='bubble'>" . nl2br( esc_html( mb_substr( $bot_response, 0, 300 ) ) ) . ( mb_strlen( $bot_response ) > 300 ? '...' : '' ) . "</div>
  <p><a class='btn' href='" . esc_url( $admin_url ) . "'>대시보드에서 확인하기</a></p>
</div>
</body></html>";

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $site_name . ' <' . get_option( 'admin_email' ) . '>',
        ];

        wp_mail( $admin_email, $subject, $body, $headers );
        error_log( "[AI9CB] Urgent alert sent to $admin_email for lead #$lead_id" );
    }
}
