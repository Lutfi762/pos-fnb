{{--
| Print styles shared by every exported document.
|
| Plain CSS 2.1 on purpose: dompdf reads no custom properties, flexbox, or
| grid, so the app's design tokens cannot reach it. The few greys and the
| brand green below are the token values written out, and this file is the
| one place in the app where colours are spelled as hex.
--}}
<style>
    @page { margin: 18mm 14mm; }
    * { box-sizing: border-box; }
    body { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 10.5pt; color: #101b18; margin: 0; }
    h1 { font-size: 16pt; margin: 0 0 4pt; color: #0a3a2e; }
    .brand { font-size: 9pt; letter-spacing: 1pt; text-transform: uppercase; color: #0e7157; margin-bottom: 6pt; }
    .meta { width: 100%; border-collapse: collapse; margin: 6pt 0 12pt; font-size: 9pt; color: #5a6763; }
    .meta td { padding: 1pt 8pt 1pt 0; vertical-align: top; }
    .meta td.label { width: 22%; }
    table.data { width: 100%; border-collapse: collapse; }
    table.data th { text-align: left; font-size: 8.5pt; font-weight: bold; color: #5a6763; border-bottom: 1pt solid #b9c4bc; padding: 4pt 5pt; }
    table.data td { padding: 4pt 5pt; border-bottom: 0.5pt solid #d9e0da; vertical-align: top; }
    table.data .num { text-align: right; white-space: nowrap; }
    table.data tfoot td { font-weight: bold; border-top: 1pt solid #101b18; border-bottom: none; }
    .muted { color: #5a6763; }
    .note { margin-top: 10pt; font-size: 8.5pt; color: #5a6763; }
    .totals { width: 55%; margin-left: 45%; border-collapse: collapse; margin-top: 8pt; }
    .totals td { padding: 2pt 5pt; }
    .totals .num { text-align: right; white-space: nowrap; }
    .totals .grand td { font-size: 12pt; font-weight: bold; border-top: 1pt solid #101b18; }
    .printbar { position: sticky; top: 0; background: #e6f2ee; color: #0a3a2e; padding: 10px 14px; display: flex; gap: 12px; align-items: center; justify-content: space-between; font-family: system-ui, sans-serif; font-size: 15px; }
    .printbar button { min-height: 44px; padding: 0 18px; border: 0; border-radius: 8px; background: #0e7157; color: #ffffff; font-size: 15px; cursor: pointer; }
    .page { padding: 0; }
    @media screen { .page { max-width: 1100px; margin: 0 auto; padding: 24px 16px; } }
    @media print { .printbar { display: none; } }
</style>
