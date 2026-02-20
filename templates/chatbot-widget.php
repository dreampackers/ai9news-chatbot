<?php
/**
 * Chatbot floating widget HTML.
 * Rendered in wp_footer — no sensitive data here.
 */
if ( ! defined( 'ABSPATH' ) ) exit;
?>

<!-- AI9News Chatbot Launcher -->
<button id="ai9cb-launcher" aria-label="챗봇 열기/닫기" aria-haspopup="dialog">
  <!-- Chat icon -->
  <svg class="ai9cb-icon-chat" width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
  </svg>
  <!-- Close icon -->
  <svg class="ai9cb-icon-close" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true">
    <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
  </svg>
  <!-- Unread badge -->
  <span class="ai9cb-unread" aria-live="polite"></span>
</button>

<!-- Chat window -->
<div id="ai9cb-window" role="dialog" aria-modal="true" aria-label="AI9 챗봇">

  <!-- Header -->
  <div id="ai9cb-header">
    <div class="ai9cb-avatar" id="ai9cb-bot-avatar">🤖</div>
    <div class="ai9cb-header-info">
      <div class="ai9cb-bot-name" id="ai9cb-bot-name">AI9 어시스턴트</div>
      <div class="ai9cb-status">
        <span class="ai9cb-status-dot"></span>온라인
      </div>
    </div>
    <button class="ai9cb-minimize" aria-label="챗봇 닫기" title="닫기">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true">
        <polyline points="18 15 12 9 6 15"/>
      </svg>
    </button>
  </div>

  <!-- Messages -->
  <div id="ai9cb-messages" role="log" aria-live="polite" aria-atomic="false"></div>

  <!-- Input area -->
  <div id="ai9cb-input-area">
    <textarea
      id="ai9cb-input"
      rows="1"
      placeholder="메시지를 입력하세요..."
      aria-label="메시지 입력"
      maxlength="2000"
    ></textarea>
    <button id="ai9cb-send" aria-label="전송">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/>
      </svg>
    </button>
  </div>

  <!-- Footer -->
  <div id="ai9cb-footer">Powered by <strong>AI9News</strong> · AI</div>

</div>
