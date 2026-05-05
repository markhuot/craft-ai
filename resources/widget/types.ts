/**
 * Bootstrap data injected by the PHP-side
 * `View::EVENT_AFTER_RENDER_PAGE_TEMPLATE` listener. The same `<script>` tag
 * is read by the entry module to seed the React tree, so every URL the
 * widget needs to talk to is resolved server-side and frozen at page load.
 */
export interface WidgetBootstrap {
  jsUrl: string;
  cssUrl: string;
  sessionsUrl: string;
  newSessionUrl: string;
  sessionsIndexUrl: string;
  messagesUrl: string;
  sendUrl: string;
  csrfTokenName: string;
  csrfTokenValue: string;
}
