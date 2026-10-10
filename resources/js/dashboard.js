/*
| The dashboard's moving parts: numbers that count up to their value, and the
| seven-day chart.
|
| Chart.js is imported only when a chart is actually on the page, so the till
| on a tablet never downloads it. Colours are read from the CSS tokens at
| draw time and the chart is rebuilt when the theme flips, so dark mode never
| shows a light-mode chart. Anyone who asked for less motion gets none.
*/

const rupiah = new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 });
const plain = new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 });

const reducedMotion = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;

// Rp50.000, the way x-money prints it: no space after "Rp".
const money = (value) => rupiah.format(value).replace(/\s/g, '');

let chartModule = null;

async function loadChart() {
    if (!chartModule) {
        chartModule = import('chart.js').then(({ Chart, BarController, BarElement, LineController, LineElement, PointElement, CategoryScale, LinearScale, Tooltip }) => {
            Chart.register(BarController, BarElement, LineController, LineElement, PointElement, CategoryScale, LinearScale, Tooltip);

            return Chart;
        });
    }

    return chartModule;
}

function token(name) {
    return getComputedStyle(document.documentElement).getPropertyValue(name).trim();
}

// Canvas colours cannot rely on color-mix(), so a token is faded by hand.
function faded(color, alpha) {
    const hex = color.replace('#', '');

    if (!/^[0-9a-f]{6}$/i.test(hex)) {
        return color;
    }

    const [r, g, b] = [0, 2, 4].map((i) => parseInt(hex.slice(i, i + 2), 16));

    return `rgba(${r}, ${g}, ${b}, ${alpha})`;
}

// Last value shown per figure. A refresh re-creates the element (its key
// carries the value), so the new number rolls on from the old one instead of
// starting again from zero.
const shown = new Map();

export function countUp(target, isMoney, key) {
    return {
        display: isMoney ? money(target) : plain.format(target),

        init() {
            this.animate(shown.get(key) ?? 0, target);
            shown.set(key, target);
        },

        animate(from, to) {
            const format = (n) => (isMoney ? money(Math.round(n)) : plain.format(Math.round(n)));

            if (reducedMotion() || from === to) {
                this.display = format(to);

                return;
            }

            const started = performance.now();
            const duration = 700;

            const step = (now) => {
                const t = Math.min(1, (now - started) / duration);
                const eased = 1 - Math.pow(1 - t, 3);

                this.display = format(from + (to - from) * eased);

                if (t < 1) {
                    requestAnimationFrame(step);
                }
            };

            requestAnimationFrame(step);
        },
    };
}

export function weekChart(initial, labels) {
    let chart = null;
    let observer = null;

    return {
        week: initial,

        async init() {
            const Chart = await loadChart();

            this.build(Chart, true);

            // Flux flips the theme with a class on <html>. Colours are baked
            // into a Chart.js instance, so the chart is rebuilt, not patched,
            // and only when the theme really changed.
            let dark = document.documentElement.classList.contains('dark');

            observer = new MutationObserver(() => {
                const now = document.documentElement.classList.contains('dark');

                if (now !== dark) {
                    dark = now;
                    this.build(Chart, false);
                }
            });
            observer.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
        },

        // A theme change swaps colours in place: no entrance animation again.
        build(Chart, animate) {
            if (!this.$refs.canvas) {
                return;
            }

            const config = this.config();

            if (!animate) {
                config.options.animation = false;
            }

            chart?.destroy();
            chart = new Chart(this.$refs.canvas, config);
        },

        // New numbers from a refresh: same chart, bars move to their new height.
        update(week) {
            this.week = week;

            if (!chart) {
                return;
            }

            const next = this.config();

            chart.data.labels = next.data.labels;
            next.data.datasets.forEach((dataset, index) => {
                chart.data.datasets[index].data = dataset.data;
                chart.data.datasets[index].backgroundColor = dataset.backgroundColor;
            });

            chart.update(reducedMotion() ? 'none' : undefined);
        },

        destroy() {
            observer?.disconnect();
            chart?.destroy();
            chart = null;
        },

        config() {
            const brand = token('--brand');
            const muted = token('--text-muted');
            const border = token('--border');
            const text = token('--text');
            const surface = token('--surface');

            return {
                data: {
                    labels: this.week.map((day) => day.label),
                    datasets: [
                        {
                            type: 'bar',
                            label: labels.thisWeek,
                            data: this.week.map((day) => day.net),
                            // Today is still being written: drawn lighter.
                            backgroundColor: this.week.map((day) => (day.current ? faded(brand, 0.45) : brand)),
                            borderRadius: 6,
                            maxBarThickness: 44,
                            order: 2,
                        },
                        {
                            type: 'line',
                            label: labels.lastWeek,
                            data: this.week.map((day) => day.previous),
                            borderColor: muted,
                            backgroundColor: muted,
                            borderDash: [5, 4],
                            borderWidth: 2,
                            pointRadius: 3,
                            pointBackgroundColor: surface,
                            tension: 0.3,
                            order: 1,
                        },
                    ],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: reducedMotion() ? false : { duration: 700, easing: 'easeOutCubic' },
                    interaction: { mode: 'index', intersect: false },
                    scales: {
                        x: {
                            grid: { display: false },
                            ticks: { color: muted, font: { size: 13 }, maxRotation: 0, autoSkip: true },
                            border: { color: border },
                        },
                        y: {
                            beginAtZero: true,
                            grid: { color: border },
                            border: { display: false },
                            ticks: {
                                color: muted,
                                font: { size: 13 },
                                maxTicksLimit: 5,
                                callback: (value) => (value >= 1000000 ? `${plain.format(value / 1000000)} jt` : value >= 1000 ? `${plain.format(value / 1000)} rb` : value),
                            },
                        },
                    },
                    plugins: {
                        tooltip: {
                            backgroundColor: text,
                            titleColor: surface,
                            bodyColor: surface,
                            padding: 10,
                            callbacks: {
                                title: (items) => {
                                    const day = this.week[items[0].dataIndex];

                                    return day.current ? `${day.label} (${labels.today})` : day.label;
                                },
                                label: (item) => `${item.dataset.label}: ${money(item.raw)}`,
                            },
                        },
                    },
                },
            };
        },
    };
}
