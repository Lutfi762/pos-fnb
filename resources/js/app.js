import { installOffline } from './offline';

/*
 * The app keeps selling when the connection drops. The service worker caches
 * the built assets and a copy of the offline till; everything else always
 * goes to the server, so a stale page can never pass for a live one.
 */
installOffline();
