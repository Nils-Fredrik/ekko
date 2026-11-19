const status = document.getElementById('status');
const token = location.hash.slice(1);

if (!/^[a-f0-9]{64}$/.test(token)) {
    status.textContent = status.dataset.notFound;
} else {
    try {
        const response = await fetch(location.pathname, {
            headers: { Authorization: `Bearer ${token}` },
            credentials: 'omit',
            cache: 'no-store',
            redirect: 'error',
        });
        if (!response.ok) {
            status.textContent = status.dataset.notFound;
        } else {
            const page = await response.text();
            document.open();
            document.write(page);
            document.close();
        }
    } catch {
        status.textContent = status.dataset.notFound;
    }
}
