<?php
/**
 * Claude AI API integration.
 * All API calls are made server-side. The API key is NEVER sent to the browser.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AI9CB_Chatbot_API {

    private static $instance = null;

    // Session conversation history (cached per request for context window)
    private $conversation_cache = [];

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Main chat method.
     *
     * @param  string $user_message
     * @param  string $session_id
     * @param  int    $lead_id
     * @return array|WP_Error
     */
    public function chat( $user_message, $session_id, $lead_id ) {
        $settings = AI9CB_Settings::get_instance();
        $api_key  = $settings->get( 'claude_api_key' );

        if ( empty( $api_key ) ) {
            return new WP_Error( 'no_api_key', 'Claude API 키가 설정되지 않았습니다.', [ 'status' => 503 ] );
        }

        // Build context from Google Sheets knowledge base
        $kb_context = $this->get_knowledge_context( $user_message );

        // Build conversation history from DB (last 10 turns)
        $history = $this->get_history( $session_id );

        // Prepare messages for Claude
        $messages = $this->build_messages( $history, $user_message, $kb_context );

        // Build system prompt with KB context
        $system_prompt = $this->build_system_prompt( $kb_context );

        $model     = $settings->get( 'claude_model', 'claude-opus-4-6' );
        $max_tokens = (int) $settings->get( 'max_tokens', 1024 );

        $body = wp_json_encode( [
            'model'      => $model,
            'max_tokens' => $max_tokens,
            'system'     => $system_prompt,
            'messages'   => $messages,
        ] );

        $response = wp_remote_post(
            'https://api.anthropic.com/v1/messages',
            [
                'timeout' => 30,
                'headers' => [
                    'x-api-key'         => $api_key,
                    'anthropic-version' => '2023-06-01',
                    'content-type'      => 'application/json',
                ],
                'body' => $body,
            ]
        );

        if ( is_wp_error( $response ) ) {
            error_log( '[AI9CB] Claude API request failed: ' . $response->get_error_message() );
            return new WP_Error( 'api_error', 'AI 응답 중 오류가 발생했습니다. 잠시 후 다시 시도해 주세요.', [ 'status' => 502 ] );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code !== 200 || empty( $data['content'][0]['text'] ) ) {
            $err = $data['error']['message'] ?? 'Unknown error';
            error_log( "[AI9CB] Claude API error ($code): $err" );
            return new WP_Error( 'api_error', 'AI 응답 오류: ' . esc_html( $err ), [ 'status' => $code ] );
        }

        $bot_response = trim( $data['content'][0]['text'] );
        $usage        = $data['usage'] ?? [];

        // Sentiment analysis (second Claude call, lightweight)
        $sentiment_data = $this->analyse_sentiment( $user_message );

        return [
            'bot_response'    => $bot_response,
            'sentiment'       => $sentiment_data['label'],
            'sentiment_score' => $sentiment_data['score'],
            'is_urgent'       => $sentiment_data['is_urgent'],
            'input_tokens'    => (int) ( $usage['input_tokens']  ?? 0 ),
            'output_tokens'   => (int) ( $usage['output_tokens'] ?? 0 ),
            'model'           => $model,
        ];
    }

    /**
     * Analyse sentiment of user message via Claude.
     */
    private function analyse_sentiment( $message ) {
        $settings  = AI9CB_Settings::get_instance();
        $api_key   = $settings->get( 'claude_api_key' );
        $threshold = (float) $settings->get( 'urgent_threshold', 0.7 );

        if ( empty( $api_key ) ) {
            return [ 'label' => 'neutral', 'score' => 0.0, 'is_urgent' => false ];
        }

        $prompt = "다음 사용자 메시지의 감정을 분석하고 JSON으로만 응답하세요.\n" .
                  "형식: {\"label\":\"positive|neutral|negative|urgent\",\"score\":0.0~1.0,\"reason\":\"짧은 이유\"}\n" .
                  "urgent 는 즉각적인 도움이 필요하거나 매우 부정적·화가 난 경우입니다.\n\n" .
                  "메시지: " . $message;

        $body = wp_json_encode( [
            'model'      => 'claude-haiku-4',
            'max_tokens' => 150,
            'messages'   => [ [ 'role' => 'user', 'content' => $prompt ] ],
        ] );

        $response = wp_remote_post(
            'https://api.anthropic.com/v1/messages',
            [
                'timeout' => 10,
                'headers' => [
                    'x-api-key'         => $api_key,
                    'anthropic-version' => '2023-06-01',
                    'content-type'      => 'application/json',
                ],
                'body' => $body,
            ]
        );

        if ( is_wp_error( $response ) ) {
            return [ 'label' => 'neutral', 'score' => 0.0, 'is_urgent' => false ];
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        $text = trim( $data['content'][0]['text'] ?? '{}' );

        // Strip markdown code fences if present
        $text     = preg_replace( '/^```[a-z]*\n?|\n?```$/i', '', $text );
        $sentiment = json_decode( $text, true );

        if ( ! is_array( $sentiment ) ) {
            return [ 'label' => 'neutral', 'score' => 0.0, 'is_urgent' => false ];
        }

        $label    = sanitize_text_field( $sentiment['label'] ?? 'neutral' );
        $score    = (float) ( $sentiment['score'] ?? 0.0 );
        $is_urgent = ( $label === 'urgent' ) || ( $label === 'negative' && $score >= $threshold );

        return [
            'label'     => $label,
            'score'     => $score,
            'is_urgent' => $is_urgent,
        ];
    }

    /**
     * Retrieve recent conversation history from DB for a session.
     */
    private function get_history( $session_id, $limit = 10 ) {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT user_message, bot_response
             FROM {$wpdb->prefix}ai9cb_conversations
             WHERE session_id = %s
             ORDER BY created_at DESC
             LIMIT %d",
            $session_id,
            $limit
        ) );

        return array_reverse( $rows ); // oldest first
    }

    /**
     * Build messages array for Claude API.
     */
    private function build_messages( $history, $user_message, $kb_context ) {
        $messages = [];

        foreach ( $history as $row ) {
            $messages[] = [ 'role' => 'user',      'content' => $row->user_message ];
            $messages[] = [ 'role' => 'assistant', 'content' => $row->bot_response ];
        }

        $content = $user_message;
        if ( ! empty( $kb_context ) ) {
            $content = "[참고 정보]\n$kb_context\n\n[사용자 질문]\n$user_message";
        }

        $messages[] = [ 'role' => 'user', 'content' => $content ];
        return $messages;
    }

    /**
     * Get knowledge base context relevant to the user's message.
     */
    private function get_knowledge_context( $user_message ) {
        try {
            $sheets = AI9CB_Google_Sheets::get_instance();
            $kb     = $sheets->get_knowledge_base();

            if ( empty( $kb ) ) {
                return '';
            }

            // Simple keyword relevance: include rows whose question/answer contains
            // any word from the user message (≥3 chars)
            $words = array_filter( preg_split( '/\s+/u', mb_strtolower( $user_message ) ), function ( $w ) {
                return mb_strlen( $w ) >= 3;
            } );

            $relevant = [];
            foreach ( $kb as $entry ) {
                $haystack = mb_strtolower( ( $entry['question'] ?? '' ) . ' ' . ( $entry['answer'] ?? '' ) );
                foreach ( $words as $word ) {
                    if ( mb_strpos( $haystack, $word ) !== false ) {
                        $relevant[] = "Q: {$entry['question']}\nA: {$entry['answer']}";
                        break;
                    }
                }
                if ( count( $relevant ) >= 5 ) {
                    break; // cap to 5 entries to manage token usage
                }
            }

            return implode( "\n\n", $relevant );
        } catch ( Exception $e ) {
            error_log( '[AI9CB] KB context error: ' . $e->getMessage() );
            return '';
        }
    }

    /**
     * Build system prompt.
     */
    private function build_system_prompt( $kb_context ) {
        $settings = AI9CB_Settings::get_instance();
        $base     = $settings->get( 'system_prompt',
            "당신은 AI9News 브랜드의 공식 AI 어시스턴트입니다.\n" .
            "브랜드 소개, 콘텐츠, 제휴·광고 문의에 친절하고 전문적으로 답변하세요.\n" .
            "모르는 내용은 솔직히 안내하고 담당자 연결을 권유하세요.\n" .
            "항상 한국어로 답변하세요."
        );

        return $base;
    }
}
