/**
 * Formats event names the same way laravel-echo does, so `listen('OrderCreated')` matches
 * `App\Events\OrderCreated` and `listen('.custom-name')` matches `custom-name`.
 */
export class EventFormatter {
    constructor(private namespace: string | false) {
    }

    format(event: string): string {
        if (['.', '\\'].includes(event.charAt(0))) {
            return event.substring(1);
        }

        if (this.namespace) {
            event = this.namespace + '.' + event;
        }

        return event.replace(/\./g, '\\');
    }

    setNamespace(namespace: string | false): void {
        this.namespace = namespace;
    }
}
