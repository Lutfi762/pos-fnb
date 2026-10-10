@if ($printable)
    {{-- No PDF engine installed: the browser prints this page to PDF. --}}
    <div class="printbar">
        <span>{{ __('reports.print_hint') }}</span>
        <button type="button" onclick="window.print()">{{ __('reports.print') }}</button>
    </div>
    <script>window.addEventListener('load', () => window.print());</script>
@endif
