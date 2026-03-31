/**
 * Posts webhook events to a Drupal callback URL.
 *
 * Events are queued and sent serially to preserve ordering.
 * Failures are logged but do not fail the execution.
 */
export class WebhookEmitter {
  /**
   * @param {string} callbackUrl - The Drupal webhook endpoint URL.
   * @param {string} callbackToken - HMAC token for authentication.
   * @param {string[]} events - Event types to subscribe to (empty = all).
   */
  constructor(callbackUrl, callbackToken, events = []) {
    this.callbackUrl = callbackUrl;
    this.callbackToken = callbackToken;
    this.subscribedEvents = new Set(events);
    this.queue = [];
    this.sending = false;
  }

  /**
   * Emit an event to the webhook.
   *
   * @param {object} event - Event payload with at least a `type` field.
   */
  async emit(event) {
    if (!this.callbackUrl) return;
    if (this.subscribedEvents.size > 0 && !this.subscribedEvents.has(event.type)) return;

    this.queue.push(event);
    if (!this.sending) {
      await this.flush();
    }
  }

  /**
   * Flush the event queue, sending events serially.
   */
  async flush() {
    this.sending = true;
    while (this.queue.length > 0) {
      const event = this.queue.shift();
      try {
        const response = await fetch(this.callbackUrl, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-Webhook-Token': this.callbackToken,
            'X-Event-Type': event.type,
          },
          body: JSON.stringify(event),
          signal: AbortSignal.timeout(10000),
        });
        if (!response.ok) {
          console.error(`Webhook ${event.type}: HTTP ${response.status}`);
        }
      } catch (err) {
        console.error(`Webhook emit failed for ${event.type}:`, err.message);
      }
    }
    this.sending = false;
  }

  /**
   * Wait for all pending events to be sent.
   */
  async drain() {
    while (this.queue.length > 0 || this.sending) {
      await new Promise(resolve => setTimeout(resolve, 50));
    }
  }
}
