<?php
/**
 * Google Sheets API v4 integration.
 * Uses a service account JSON key (stored server-side, never exposed).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AI9CB_Google_Sheets {

    private static $instance   = null;
    private        $access_token = null;
    private        $token_exp    = 0;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // ---------------------------------------------------------------
    // Public helpers called by the main plugin
    // ---------------------------------------------------------------

    /**
     * Read knowledge base rows from the KB sheet.
     * Expected columns: question | answer | category | tags
     *
     * @return array  [['question'=>'...','answer'=>'...'], ...]
     */
    public function get_knowledge_base() {
        $settings  = AI9CB_Settings::get_instance();
        $sheet_id  = $settings->get( 'kb_sheet_id' );
        $sheet_name = $settings->get( 'kb_sheet_name', 'KnowledgeBase' );

        if ( empty( $sheet_id ) ) {
            return [];
        }

        // Cache for 10 minutes to avoid excessive API calls
        $cache_key = 'ai9cb_kb_' . md5( $sheet_id . $sheet_name );
        $cached    = get_transient( $cache_key );
        if ( false !== $cached ) {
            return $cached;
        }

        $range  = urlencode( $sheet_name . '!A:D' );
        $url    = "https://sheets.googleapis.com/v4/spreadsheets/{$sheet_id}/values/{$range}";
        $result = $this->sheets_get( $url );

        if ( is_wp_error( $result ) || empty( $result['values'] ) ) {
            return [];
        }

        $rows    = $result['values'];
        $headers = array_map( 'strtolower', array_map( 'trim', array_shift( $rows ) ) );
        $data    = [];

        foreach ( $rows as $row ) {
            $entry = [];
            foreach ( $headers as $i => $header ) {
                $entry[ $header ] = $row[ $i ] ?? '';
            }
            if ( ! empty( $entry['question'] ) && ! empty( $entry['answer'] ) ) {
                $data[] = $entry;
            }
        }

        set_transient( $cache_key, $data, 10 * MINUTE_IN_SECONDS );
        return $data;
    }

    /**
     * Append a new lead row to the Leads sheet.
     * Columns: timestamp | email | name | session_id | funnel_stage
     */
    public function append_lead( $email, $name, $session_id, $funnel_stage = 'awareness' ) {
        $settings   = AI9CB_Settings::get_instance();
        $sheet_id   = $settings->get( 'leads_sheet_id' );
        $sheet_name = $settings->get( 'leads_sheet_name', 'Leads' );

        if ( empty( $sheet_id ) ) {
            return;
        }

        $this->append_row( $sheet_id, $sheet_name, [
            current_time( 'Y-m-d H:i:s' ),
            $email,
            $name,
            $session_id,
            $funnel_stage,
        ] );
    }

    /**
     * Append a conversation row.
     * Columns: timestamp | email | user_message | bot_response | sentiment
     */
    public function append_conversation( $email, $user_message, $bot_response, $sentiment ) {
        $settings   = AI9CB_Settings::get_instance();
        $sheet_id   = $settings->get( 'leads_sheet_id' );
        $sheet_name = $settings->get( 'conv_sheet_name', 'Conversations' );

        if ( empty( $sheet_id ) ) {
            return;
        }

        $this->append_row( $sheet_id, $sheet_name, [
            current_time( 'Y-m-d H:i:s' ),
            $email,
            mb_substr( $user_message,  0, 500 ),
            mb_substr( $bot_response,  0, 1000 ),
            $sentiment,
        ] );
    }

    // ---------------------------------------------------------------
    // Low-level Google Sheets helpers
    // ---------------------------------------------------------------

    private function append_row( $sheet_id, $sheet_name, array $values ) {
        $range  = urlencode( $sheet_name . '!A1' );
        $url    = "https://sheets.googleapis.com/v4/spreadsheets/{$sheet_id}/values/{$range}:append?valueInputOption=USER_ENTERED&insertDataOption=INSERT_ROWS";

        $this->sheets_post( $url, [
            'range'          => $sheet_name . '!A1',
            'majorDimension' => 'ROWS',
            'values'         => [ $values ],
        ] );
    }

    private function sheets_get( $url ) {
        $token = $this->get_access_token();
        if ( ! $token ) {
            return new WP_Error( 'no_token', 'Google Sheets 인증 토큰을 가져올 수 없습니다.' );
        }

        $response = wp_remote_get( $url, [
            'timeout' => 15,
            'headers' => [ 'Authorization' => 'Bearer ' . $token ],
        ] );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return json_decode( wp_remote_retrieve_body( $response ), true );
    }

    private function sheets_post( $url, $body_data ) {
        $token = $this->get_access_token();
        if ( ! $token ) {
            return;
        }

        wp_remote_post( $url, [
            'timeout' => 15,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode( $body_data ),
        ] );
    }

    // ---------------------------------------------------------------
    // OAuth2 service-account token
    // ---------------------------------------------------------------

    /**
     * Get a short-lived access token using the service account JSON.
     * Tokens are cached in a transient until 60 s before expiry.
     */
    private function get_access_token() {
        // Return cached token if still valid
        if ( $this->access_token && time() < $this->token_exp - 60 ) {
            return $this->access_token;
        }

        $cache_key    = 'ai9cb_gsa_token';
        $cached_token = get_transient( $cache_key );
        if ( $cached_token ) {
            $this->access_token = $cached_token;
            return $this->access_token;
        }

        // Load service account JSON from settings
        $settings = AI9CB_Settings::get_instance();
        $sa_json  = $settings->get( 'google_sheets_credentials' );

        if ( empty( $sa_json ) ) {
            error_log( '[AI9CB] Google Sheets: service account JSON not configured.' );
            return null;
        }

        $sa = json_decode( $sa_json, true );
        if ( ! $sa || empty( $sa['private_key'] ) || empty( $sa['client_email'] ) ) {
            error_log( '[AI9CB] Google Sheets: invalid service account JSON.' );
            return null;
        }

        // Build JWT
        $now    = time();
        $header  = $this->base64url( wp_json_encode( [ 'alg' => 'RS256', 'typ' => 'JWT' ] ) );
        $payload = $this->base64url( wp_json_encode( [
            'iss'   => $sa['client_email'],
            'scope' => 'https://www.googleapis.com/auth/spreadsheets',
            'aud'   => 'https://oauth2.googleapis.com/token',
            'exp'   => $now + 3600,
            'iat'   => $now,
        ] ) );

        $signing_input = $header . '.' . $payload;
        $key           = openssl_pkey_get_private( $sa['private_key'] );

        if ( ! $key ) {
            error_log( '[AI9CB] Google Sheets: could not load private key.' );
            return null;
        }

        $signature = '';
        if ( ! openssl_sign( $signing_input, $signature, $key, 'SHA256' ) ) {
            error_log( '[AI9CB] Google Sheets: JWT signing failed.' );
            return null;
        }

        $jwt = $signing_input . '.' . $this->base64url( $signature );

        // Exchange JWT for access token
        $response = wp_remote_post( 'https://oauth2.googleapis.com/token', [
            'timeout' => 15,
            'body'    => [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ],
        ] );

        if ( is_wp_error( $response ) ) {
            error_log( '[AI9CB] Google OAuth token error: ' . $response->get_error_message() );
            return null;
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( empty( $data['access_token'] ) ) {
            error_log( '[AI9CB] Google OAuth: no access_token in response.' );
            return null;
        }

        $this->access_token = $data['access_token'];
        $this->token_exp    = $now + ( (int) ( $data['expires_in'] ?? 3600 ) );

        // Cache for (expiry - 60) seconds
        set_transient( $cache_key, $this->access_token, max( 60, $this->token_exp - $now - 60 ) );

        return $this->access_token;
    }

    private function base64url( $data ) {
        return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
    }

    /**
     * Clear cached KB and token (used after settings save).
     */
    public static function clear_cache() {
        delete_transient( 'ai9cb_gsa_token' );
        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_ai9cb_kb_%'" );
        $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_ai9cb_kb_%'" );
    }
}
