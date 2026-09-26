/*
 * ULIDs minted in the browser. crypto.randomUUID only exists on HTTPS, and a
 * till on a local network is often plain HTTP, so this uses getRandomValues,
 * which every browser has. The server uses ULIDs too, so keys look alike.
 */
const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

export function ulid(now = Date.now()) {
    let time = '';
    let remaining = now;

    for (let i = 0; i < 10; i++) {
        time = ALPHABET[remaining % 32] + time;
        remaining = Math.floor(remaining / 32);
    }

    const bytes = new Uint8Array(16);
    crypto.getRandomValues(bytes);

    let random = '';

    for (const byte of bytes) {
        random += ALPHABET[byte % 32];
    }

    return time + random;
}
