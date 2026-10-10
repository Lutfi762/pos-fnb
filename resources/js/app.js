import { installOffline } from './offline';
import { countUp, weekChart } from './dashboard';
import { qrisCode } from './qris';

/*
 * The app keeps selling when the connection drops. The service worker caches
 * the built assets and a copy of the offline till; everything else always
 * goes to the server, so a stale page can never pass for a live one.
 */
installOffline();

document.addEventListener('alpine:init', () => {
    window.Alpine.data('qrisCode', qrisCode);
    window.Alpine.data('countUp', countUp);
    window.Alpine.data('weekChart', weekChart);
});
