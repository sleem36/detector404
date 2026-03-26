(function () {
    var canvas = document.getElementById('historyChart');
    if (!canvas || typeof window.MONITOR_SITE_ID === 'undefined') {
        return;
    }

    var activePeriod = '24h';
    var chart = null;

    function fetchHistory(period) {
        var url = 'api.php?action=history&site_id=' + encodeURIComponent(window.MONITOR_SITE_ID) +
            '&period=' + encodeURIComponent(period);
        return fetch(url).then(function (res) { return res.json(); });
    }

    function render(data) {
        var labels = data.map(function (row) { return row.timestamp; });
        var values = data.map(function (row) { return row.response_time_ms; });
        var pointColors = data.map(function (row) {
            return Number(row.is_available) === 1 ? '#16a34a' : '#dc2626';
        });

        if (chart) {
            chart.destroy();
        }

        chart = new Chart(canvas, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Время ответа (ms)',
                    data: values,
                    borderColor: '#2563eb',
                    backgroundColor: 'rgba(37, 99, 235, 0.15)',
                    pointBackgroundColor: pointColors,
                    pointRadius: 3,
                    fill: true,
                    tension: 0.2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });
    }

    function load(period) {
        fetchHistory(period).then(function (payload) {
            if (!payload.ok) {
                return;
            }
            render(payload.data || []);
        });
    }

    document.querySelectorAll('.period-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var period = btn.getAttribute('data-period') || '24h';
            activePeriod = period;
            document.querySelectorAll('.period-btn').forEach(function (b) {
                b.classList.remove('active');
            });
            btn.classList.add('active');
            load(activePeriod);
        });
    });

    load(activePeriod);
})();
