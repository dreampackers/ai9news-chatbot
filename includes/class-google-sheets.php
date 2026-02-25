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
        $settings   = AI9CB_Settings::get_instance();
        $sheet_id   = $settings->get( 'kb_sheet_id' );
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

        // FIX: use rawurlencode for URL path segments
        $range  = rawurlencode( $sheet_name . '!A:D' );
        $url    = "https://sheets.googleapis.com/v4/spreadsheets/{$sheet_id}/values/{$range}";
        $result = $this->sheets_get( $url );

        if ( is_wp_error( $result ) || empty( $result['values'] ) ) {
            if ( is_wp_error( $result ) ) {
                error_log( '[AI9CB] get_knowledge_base error: ' . $result->get_error_message() );
            }
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
        $site       = $settings->get( 'site_identifier', '' );

        if ( empty( $sheet_id ) ) {
            error_log( '[AI9CB] append_lead: leads_sheet_id is not configured.' );
            return false;
        }

        return $this->append_row( $sheet_id, $sheet_name, [
            current_time( 'Y-m-d H:i:s' ),
            $site,
            $email,
            $name,
            $session_id,
            $funnel_stage,
        ] );
    }

    /**
     * Append a conversation row.
     * Columns: timestamp | site | email | user_message | bot_response | sentiment
     *        | input_tokens | output_tokens | model | cost_usd | cost_krw
     *
     * @param string $email
     * @param string $user_message
     * @param string $bot_response
     * @param string $sentiment
     * @param array  $usage  Keys: input_tokens, output_tokens, model
     */
    public function append_conversation( $email, $user_message, $bot_response, $sentiment, $usage = [] ) {
        $settings   = AI9CB_Settings::get_instance();
        $sheet_id   = $settings->get( 'leads_sheet_id' );
        $sheet_name = $settings->get( 'conv_sheet_name', 'Conversations' );
        $site       = $settings->get( 'site_identifier', '' );

        if ( empty( $sheet_id ) ) {
            error_log( '[AI9CB] append_conversation: leads_sheet_id is not configured.' );
            return false;
        }

        $input_tokens  = (int) ( $usage['input_tokens']  ?? 0 );
        $output_tokens = (int) ( $usage['output_tokens'] ?? 0 );
        $model         = $usage['model'] ?? '';

        [ $cost_usd, $cost_krw ] = $this->calc_token_cost( $model, $input_tokens, $output_tokens );

        return $this->append_row( $sheet_id, $sheet_name, [
            current_time( 'Y-m-d H:i:s' ),
            $site,
            $email,
            mb_substr( $user_message, 0, 500 ),
            mb_substr( $bot_response, 0, 1000 ),
            $sentiment,
            $input_tokens,
            $output_tokens,
            $model,
            number_format( $cost_usd, 6, '.', '' ),
            number_format( $cost_krw, 2,  '.', '' ),
        ] );
    }

    /**
     * Calculate API cost for a single request.
     *
     * Pricing table (USD per 1 M tokens, Anthropic 2025):
     *   claude-opus-4-6   : in $15.00 / out $75.00
     *   claude-sonnet-4-6 : in  $3.00 / out $15.00
     *   claude-haiku-4    : in  $0.80 / out  $4.00
     *
     * @param  string $model
     * @param  int    $input_tokens
     * @param  int    $output_tokens
     * @return array  [ float $cost_usd, float $cost_krw ]
     */
    private function calc_token_cost( $model, $input_tokens, $output_tokens ) {
        // USD per 1,000,000 tokens  [ input_rate, output_rate ]
        $pricing = [
            'claude-opus-4-6'   => [ 15.00, 75.00 ],
            'claude-sonnet-4-6' => [  3.00, 15.00 ],
            'claude-haiku-4'    => [  0.80,  4.00 ],
        ];

        $rates = null;
        // Exact match first, then prefix match (handles versioned IDs like claude-haiku-4-5-20251001)
        if ( isset( $pricing[ $model ] ) ) {
            $rates = $pricing[ $model ];
        } else {
            foreach ( $pricing as $key => $p ) {
                if ( strpos( $model, $key ) === 0 ) {
                    $rates = $p;
                    break;
                }
            }
        }

        if ( ! $rates ) {
            return [ 0.0, 0.0 ];
        }

        $cost_usd = ( $input_tokens * $rates[0] + $output_tokens * $rates[1] ) / 1_000_000;
        $cost_krw = $cost_usd * 1400; // 1 USD ≈ 1,400 KRW (업데이트 필요 시 수정)

        return [ $cost_usd, $cost_krw ];
    }

    /**
     * Test the connection: token acquisition + read access to the leads sheet.
     * Returns array with 'success' bool and 'message' string.
     */
    public function test_connection() {
        $settings = AI9CB_Settings::get_instance();
        $sa_json  = $settings->get( 'google_sheets_credentials' );

        if ( empty( $sa_json ) ) {
            return [ 'success' => false, 'message' => '서비스 계정 JSON이 입력되지 않았습니다.' ];
        }

        $sa = $this->decode_sa_json( $sa_json );
        if ( ! $sa ) {
            return [ 'success' => false, 'message' => 'JSON 파싱 실패 — json_last_error: ' . json_last_error_msg() . ' | 파일을 직접 열어 내용을 복사해주세요.' ];
        }
        if ( empty( $sa['private_key'] ) ) {
            return [ 'success' => false, 'message' => 'JSON에 private_key 항목이 없습니다.' ];
        }
        if ( empty( $sa['client_email'] ) ) {
            return [ 'success' => false, 'message' => 'JSON에 client_email 항목이 없습니다.' ];
        }

        // Step 1: obtain token
        delete_transient( 'ai9cb_gsa_token' );
        $this->access_token = null;
        $this->token_exp    = 0;

        $token = $this->get_access_token();
        if ( ! $token ) {
            // get_access_token already logged the detail; return last log context
            return [ 'success' => false, 'message' => 'OAuth 토큰 발급 실패. error_log를 확인하세요. (서비스 계정 이메일 및 키 형식 확인)' ];
        }

        // Step 2: try to read from the leads sheet
        $sheet_id   = $settings->get( 'leads_sheet_id' );
        $sheet_name = $settings->get( 'leads_sheet_name', 'Leads' );

        if ( empty( $sheet_id ) ) {
            return [ 'success' => true, 'message' => '토큰 발급 성공. (리드 시트 ID 미설정 — 쓰기 테스트 불가)' ];
        }

        $range  = rawurlencode( $sheet_name . '!A1:A1' );
        $url    = "https://sheets.googleapis.com/v4/spreadsheets/{$sheet_id}/values/{$range}";
        $result = $this->sheets_get( $url );

        if ( is_wp_error( $result ) ) {
            return [ 'success' => false, 'message' => '시트 읽기 실패: ' . $result->get_error_message() ];
        }

        if ( isset( $result['error'] ) ) {
            $msg = $result['error']['message'] ?? 'Unknown Sheets error';
            $code = $result['error']['code'] ?? 0;
            return [ 'success' => false, 'message' => "Sheets API 오류 ({$code}): {$msg}" ];
        }

        return [ 'success' => true, 'message' => '연결 성공! 토큰 발급 및 시트 읽기 정상.' ];
    }

    // ---------------------------------------------------------------
    // Low-level Google Sheets helpers
    // ---------------------------------------------------------------

    /**
     * FIX: use rawurlencode (not urlencode) for URL path segments.
     * FIX: capture and log the response from sheets_post.
     */
    private function append_row( $sheet_id, $sheet_name, array $values ) {
        // FIX: rawurlencode instead of urlencode — handles spaces and special chars in sheet names correctly
        $range_enc = rawurlencode( $sheet_name . '!A1' );
        $url       = "https://sheets.googleapis.com/v4/spreadsheets/{$sheet_id}/values/{$range_enc}:append"
                   . '?valueInputOption=USER_ENTERED&insertDataOption=INSERT_ROWS';

        $result = $this->sheets_post( $url, [
            'range'          => $sheet_name . '!A1',
            'majorDimension' => 'ROWS',
            'values'         => [ $values ],
        ] );

        // FIX: log any errors returned by sheets_post
        if ( is_wp_error( $result ) ) {
            error_log( '[AI9CB] append_row WP error: ' . $result->get_error_message() );
            return false;
        }

        if ( isset( $result['error'] ) ) {
            $code = $result['error']['code']    ?? 0;
            $msg  = $result['error']['message'] ?? 'Unknown';
            error_log( "[AI9CB] append_row Sheets API error ({$code}): {$msg} | sheet_id={$sheet_id} tab={$sheet_name}" );
            return false;
        }

        return true;
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
            error_log( '[AI9CB] sheets_get wp_remote_get error: ' . $response->get_error_message() . ' | url=' . $url );
            return $response;
        }

        $http_code = wp_remote_retrieve_response_code( $response );
        $body      = wp_remote_retrieve_body( $response );
        $data      = json_decode( $body, true );

        if ( $http_code !== 200 ) {
            $err = $data['error']['message'] ?? $body;
            error_log( "[AI9CB] sheets_get HTTP {$http_code}: {$err} | url={$url}" );
        }

        return $data;
    }

    /**
     * FIX: capture and return the response body so callers can detect errors.
     * Previously the response was completely ignored.
     */
    private function sheets_post( $url, $body_data ) {
        $token = $this->get_access_token();
        if ( ! $token ) {
            error_log( '[AI9CB] sheets_post: no access token — skipping write.' );
            return new WP_Error( 'no_token', '액세스 토큰 없음' );
        }

        $response = wp_remote_post( $url, [
            'timeout' => 15,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode( $body_data ),
        ] );

        if ( is_wp_error( $response ) ) {
            error_log( '[AI9CB] sheets_post wp_remote_post error: ' . $response->get_error_message() );
            return $response;
        }

        $http_code = wp_remote_retrieve_response_code( $response );
        $body      = wp_remote_retrieve_body( $response );
        $data      = json_decode( $body, true );

        // FIX: log non-2xx responses with full details
        if ( $http_code < 200 || $http_code >= 300 ) {
            $err = $data['error']['message'] ?? $body;
            error_log( "[AI9CB] sheets_post HTTP {$http_code}: {$err} | url={$url}" );
        }

        return $data ?? [];
    }

    // ---------------------------------------------------------------
    // OAuth2 service-account token
    // ---------------------------------------------------------------

    /**
     * Get a short-lived access token using the service account JSON.
     * Tokens are cached in a transient until 60 s before expiry.
     */
    private function get_access_token() {
        // In-memory cache: valid only if token_exp is set
        if ( $this->access_token && $this->token_exp > 0 && time() < $this->token_exp - 60 ) {
            return $this->access_token;
        }

        $cache_key    = 'ai9cb_gsa_token';
        $cached       = get_transient( $cache_key );
        if ( $cached && is_array( $cached ) && ! empty( $cached['token'] ) ) {
            // FIX: restore token_exp so the in-memory check works on subsequent calls
            $this->access_token = $cached['token'];
            $this->token_exp    = $cached['exp'];
            return $this->access_token;
        }

        // Load service account JSON from settings
        $settings = AI9CB_Settings::get_instance();
        $sa_json  = $settings->get( 'google_sheets_credentials' );

        if ( empty( $sa_json ) ) {
            error_log( '[AI9CB] Google Sheets: 서비스 계정 JSON이 설정되지 않았습니다.' );
            return null;
        }

        $sa = $this->decode_sa_json( $sa_json );
        if ( ! $sa ) {
            error_log( '[AI9CB] Google Sheets: JSON 파싱 실패 — json_last_error: ' . json_last_error_msg() . ' | DB 저장값에 슬래시가 포함되어 있을 수 있습니다. 설정을 다시 저장해주세요.' );
            return null;
        }
        if ( empty( $sa['private_key'] ) ) {
            error_log( '[AI9CB] Google Sheets: JSON에 private_key 항목 없음.' );
            return null;
        }
        if ( empty( $sa['client_email'] ) ) {
            error_log( '[AI9CB] Google Sheets: JSON에 client_email 항목 없음.' );
            return null;
        }

        // Ensure the private key has real newlines (handles cases where \n is stored as literal \\n)
        $private_key = $sa['private_key'];
        if ( strpos( $private_key, "\\n" ) !== false && strpos( $private_key, "\n" ) === false ) {
            $private_key = str_replace( '\\n', "\n", $private_key );
        }

        // Build JWT
        $now     = time();
        $header  = $this->base64url( wp_json_encode( [ 'alg' => 'RS256', 'typ' => 'JWT' ] ) );
        $payload = $this->base64url( wp_json_encode( [
            'iss'   => $sa['client_email'],
            'scope' => 'https://www.googleapis.com/auth/spreadsheets',
            'aud'   => 'https://oauth2.googleapis.com/token',
            'exp'   => $now + 3600,
            'iat'   => $now,
        ] ) );

        $signing_input = $header . '.' . $payload;

        $key = openssl_pkey_get_private( $private_key );
        if ( ! $key ) {
            error_log( '[AI9CB] Google Sheets: openssl_pkey_get_private 실패 — private_key 형식 확인 필요. OpenSSL 오류: ' . openssl_error_string() );
            return null;
        }

        $signature = '';
        // FIX: use OPENSSL_ALGO_SHA256 constant for reliability
        if ( ! openssl_sign( $signing_input, $signature, $key, OPENSSL_ALGO_SHA256 ) ) {
            error_log( '[AI9CB] Google Sheets: JWT 서명 실패 — OpenSSL 오류: ' . openssl_error_string() );
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
            error_log( '[AI9CB] Google OAuth 요청 실패: ' . $response->get_error_message() );
            return null;
        }

        $http_code = wp_remote_retrieve_response_code( $response );
        $body      = wp_remote_retrieve_body( $response );
        $data      = json_decode( $body, true );

        // FIX: log the full error response, not just "no access_token"
        if ( empty( $data['access_token'] ) ) {
            $err_type = $data['error']             ?? 'unknown';
            $err_desc = $data['error_description'] ?? $body;
            error_log( "[AI9CB] Google OAuth 토큰 발급 실패 (HTTP {$http_code}) — error: {$err_type} | {$err_desc}" );
            return null;
        }

        $this->access_token = $data['access_token'];
        $expires_in         = (int) ( $data['expires_in'] ?? 3600 );
        $this->token_exp    = $now + $expires_in;

        // FIX: cache both token and expiry together so token_exp can be restored
        $ttl = max( 60, $expires_in - 60 );
        set_transient( $cache_key, [ 'token' => $this->access_token, 'exp' => $this->token_exp ], $ttl );

        return $this->access_token;
    }

    private function base64url( $data ) {
        return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
    }

    /**
     * Decode the service-account JSON tolerantly.
     *
     * WordPress stores $_POST values with wp_magic_quotes() (addslashes) applied.
     * If handle_save() was called without wp_unslash() the JSON ends up with
     * escaped quotes in the DB  →  {"type":"..."} becomes {\"type\":\"...\"}
     * making json_decode() return null.
     *
     * Strategy: try native parse first, then one stripslashes pass, then two.
     *
     * @param  string $sa_json  Raw string from settings/DB.
     * @return array|null  Decoded associative array, or null on failure.
     */
    private function decode_sa_json( $sa_json ) {
        $sa = json_decode( $sa_json, true );
        if ( is_array( $sa ) && ! empty( $sa ) ) {
            return $sa;
        }

        // One level of WordPress magic-quotes slashing
        $once = stripslashes( $sa_json );
        $sa   = json_decode( $once, true );
        if ( is_array( $sa ) && ! empty( $sa ) ) {
            error_log( '[AI9CB] decode_sa_json: fixed one level of magic-quote slashing.' );
            return $sa;
        }

        // Two levels (can happen when already-slashed value is resaved without unslash)
        $twice = stripslashes( $once );
        $sa    = json_decode( $twice, true );
        if ( is_array( $sa ) && ! empty( $sa ) ) {
            error_log( '[AI9CB] decode_sa_json: fixed two levels of magic-quote slashing.' );
            return $sa;
        }

        return null;
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
