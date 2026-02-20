<?php
/**
 * Sentiment helper — lightweight rule-based pre-filter.
 * Claude performs the actual deep analysis; this class provides
 * utility methods and keyword-based urgency hints.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AI9CB_Sentiment {

    private static $instance = null;

    // High-urgency Korean / English keywords
    private $urgent_keywords = [
        '환불', '사기', '고소', '신고', '법적', '소송', '경찰', '피해', '보상', '항의',
        '화가', '분노', '최악', '절대', '망했', '문제', '오류', '장애', '급함', '즉시',
        '당장', '긴급', '바로', '빨리', '도움', '위기', '심각',
        'refund', 'fraud', 'scam', 'lawsuit', 'urgent', 'emergency', 'critical',
        'immediately', 'asap', 'angry', 'furious', 'worst',
    ];

    // Negative tone words
    private $negative_keywords = [
        '불만', '실망', '짜증', '답답', '느림', '나쁨', '불편', '이상', '고장',
        'bad', 'terrible', 'disappointed', 'frustrating', 'slow', 'broken',
    ];

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Fast keyword-based urgency hint (used as fallback if Claude unavailable).
     *
     * @param  string $text
     * @return array  ['label'=>'...','score'=>0.0,'is_urgent'=>bool]
     */
    public function quick_analyse( $text ) {
        $lower     = mb_strtolower( $text );
        $is_urgent = false;
        $is_neg    = false;
        $score     = 0.0;

        foreach ( $this->urgent_keywords as $kw ) {
            if ( mb_strpos( $lower, $kw ) !== false ) {
                $is_urgent = true;
                $score     = 0.9;
                break;
            }
        }

        if ( ! $is_urgent ) {
            foreach ( $this->negative_keywords as $kw ) {
                if ( mb_strpos( $lower, $kw ) !== false ) {
                    $is_neg = true;
                    $score  = 0.6;
                    break;
                }
            }
        }

        $label = 'neutral';
        if ( $is_urgent ) {
            $label = 'urgent';
        } elseif ( $is_neg ) {
            $label = 'negative';
        }

        return [
            'label'     => $label,
            'score'     => $score,
            'is_urgent' => $is_urgent,
        ];
    }

    /**
     * Human-readable label in Korean.
     */
    public function label_kr( $label ) {
        $map = [
            'positive' => '긍정',
            'neutral'  => '중립',
            'negative' => '부정',
            'urgent'   => '긴급',
        ];
        return $map[ $label ] ?? '중립';
    }

    /**
     * CSS class for the badge in the admin dashboard.
     */
    public function badge_class( $label ) {
        $map = [
            'positive' => 'badge-success',
            'neutral'  => 'badge-secondary',
            'negative' => 'badge-warning',
            'urgent'   => 'badge-danger',
        ];
        return $map[ $label ] ?? 'badge-secondary';
    }
}
