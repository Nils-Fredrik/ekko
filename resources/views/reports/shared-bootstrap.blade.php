<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head><meta charset="utf-8"><meta name="robots" content="noindex, nofollow, noarchive"><meta name="referrer" content="no-referrer"><title>Report</title></head>
<body>
<p id="status">{{ __('reports.share.loading') }}</p>
<script>
(async () => {
    const token = location.hash.slice(1);
    const notFound = @json(__('reports.share.not_found'));
    if (!/^[a-f0-9]{64}$/.test(token)) { document.getElementById('status').textContent = notFound; return; }
    try {
        const response = await fetch(location.pathname, {headers: {Authorization: `Bearer ${token}`}, credentials: 'omit', cache: 'no-store', redirect: 'error'});
        if (!response.ok) { document.getElementById('status').textContent = notFound; return; }
        const page = await response.text();
        document.open(); document.write(page); document.close();
    } catch { document.getElementById('status').textContent = notFound; }
})();
</script>
</body>
</html>
