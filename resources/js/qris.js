import qrcode from 'qrcode-generator';

/*
| The QRIS code on the payment screen.
|
| Drawn here from the gateway's qr_string rather than fetched as an image, so
| nothing about the code ever leaves this page. The websocket, when there is
| one, only says "look again"; the page asks the server what happened.
*/
export function qrisCode({ qr, outlet, charge, live }) {
    return {
        channel: null,

        init() {
            this.draw();

            if (live) {
                this.listen(0);
            }
        },

        draw() {
            if (!qr || !this.$refs.code) {
                return;
            }

            // Error correction M: sturdy enough for a scratched screen,
            // small enough to scan from a phone held at arm's length.
            const code = qrcode(0, 'M');
            code.addData(qr);
            code.make();

            this.$refs.code.innerHTML = code.createSvgTag({ cellSize: 6, margin: 4, scalable: true });
        },

        // echo.js loads as its own module and can arrive after this runs.
        listen(attempt) {
            if (!window.Echo) {
                if (attempt < 20) {
                    setTimeout(() => this.listen(attempt + 1), 250);
                }

                return;
            }

            this.channel = `outlet.${outlet}.payments`;

            window.Echo.private(this.channel).listen('.charge.updated', (event) => {
                if (event.charge === charge) {
                    this.$wire.checkCharge();
                }
            });
        },

        destroy() {
            if (this.channel && window.Echo) {
                window.Echo.private(this.channel).stopListening('.charge.updated');
            }
        },
    };
}
